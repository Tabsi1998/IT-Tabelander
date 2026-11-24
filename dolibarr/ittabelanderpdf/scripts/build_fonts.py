#!/usr/bin/env python3
"""Rebuild fonts/ from the brand's WOFF files: WOFF 1.0 -> TTF -> TCPDF font files.

The WOFF files come from the website in the same repository (@fontsource, latin
subset, SIL Open Font License). WOFF 1.0 is the TTF tables, each zlib
compressed, so the standard library unpacks it. TCPDF's own converter
(TCPDF_FONTS::addTTFfont) then runs inside the local Dolibarr container
(scripts/dev_dolibarr.py up) and writes name.php, name.z and name.ctg.z.

    python scripts/build_fonts.py [--fontsource DIR]

Only needed when a font changes; the result is committed under fonts/.
"""

from __future__ import annotations

import argparse
import shutil
import struct
import subprocess
import sys
import tempfile
import zlib
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
# The website in the same repository: dolibarr/ittabelanderpdf/ -> web/ (yarn install there first).
FONTSOURCE = ROOT.parents[1] / "web" / "node_modules" / "@fontsource"
CONTAINER = "ittpdf-dolibarr-web"

# TCPDF font name -> (package, file). Names stay lowercase letters only: TCPDF
# derives the family from the file name.
FONTS = {
    "itsora": ("sora", "sora-latin-600-normal.woff"),
    "itsorab": ("sora", "sora-latin-700-normal.woff"),
    "itsans": ("source-sans-3", "source-sans-3-latin-400-normal.woff"),
    "itsanssb": ("source-sans-3", "source-sans-3-latin-600-normal.woff"),
    "itsansb": ("source-sans-3", "source-sans-3-latin-700-normal.woff"),
    "itmichroma": ("michroma", "michroma-latin-400-normal.woff"),
}
# Characters the documents need; the build stops when one is missing.
REQUIRED = "€§–·äöüÄÖÜß„“‚‘…"


def woff_to_ttf(data: bytes) -> bytes:
    signature, flavor, _length, count = struct.unpack(">4sIIH", data[:14])
    if signature != b"wOFF":
        raise ValueError("not a WOFF 1.0 file")
    tables = []
    for index in range(count):
        tag, offset, comp, orig, checksum = struct.unpack(">4sIIII", data[44 + 20 * index:64 + 20 * index])
        raw = data[offset:offset + comp]
        tables.append((tag, checksum, zlib.decompress(raw) if comp < orig else raw))
    tables.sort(key=lambda table: table[0])
    power = 1 << (count.bit_length() - 1)
    header = struct.pack(">IHHHH", flavor, count, power * 16, power.bit_length() - 1, count * 16 - power * 16)
    offset = 12 + 16 * count
    directory, body = b"", b""
    for tag, checksum, table in tables:
        directory += struct.pack(">4sIII", tag, checksum, offset + len(body), len(table))
        body += table + b"\0" * (-len(table) % 4)
    return header + directory + body


def covered(ttf: bytes) -> set[int]:
    """Code points of the format 4 cmap subtable - enough to check the special characters."""
    count = struct.unpack(">H", ttf[4:6])[0]
    for index in range(count):
        tag, _c, offset, _l = struct.unpack(">4sIII", ttf[12 + 16 * index:28 + 16 * index])
        if tag != b"cmap":
            continue
        subtables = struct.unpack(">H", ttf[offset + 2:offset + 4])[0]
        for sub in range(subtables):
            _p, _e, suboffset = struct.unpack(">HHI", ttf[offset + 4 + 8 * sub:offset + 12 + 8 * sub])
            start = offset + suboffset
            if struct.unpack(">H", ttf[start:start + 2])[0] != 4:
                continue
            segments = struct.unpack(">H", ttf[start + 6:start + 8])[0] // 2
            ends = struct.unpack(f">{segments}H", ttf[start + 14:start + 14 + 2 * segments])
            starts = struct.unpack(f">{segments}H", ttf[start + 16 + 2 * segments:start + 16 + 4 * segments])
            return {code for first, last in zip(starts, ends) for code in range(first, last + 1)}
    return set()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--fontsource", type=Path, default=FONTSOURCE)
    arguments = parser.parse_args()
    docker = shutil.which("docker") or r"C:\Program Files\Docker\Docker\resources\bin\docker.exe"
    work = Path(tempfile.mkdtemp(prefix="ittpdf-fonts-"))
    for name, (package, file) in FONTS.items():
        ttf = woff_to_ttf((arguments.fontsource / package / "files" / file).read_bytes())
        missing = [char for char in REQUIRED if ord(char) not in covered(ttf)]
        if missing:
            raise SystemExit(f"{file} lacks {''.join(missing)}")
        (work / f"{name}.ttf").write_bytes(ttf)
    subprocess.run([docker, "exec", CONTAINER, "rm", "-rf", "/tmp/ittpdf-fonts"], check=True)
    subprocess.run([docker, "cp", str(work), f"{CONTAINER}:/tmp/ittpdf-fonts"], check=True)
    script = (
        "require '/var/www/html/includes/tecnickcom/tcpdf/tcpdf.php';"
        "@mkdir('/tmp/ittpdf-fonts/out');"
        "foreach (glob('/tmp/ittpdf-fonts/*.ttf') as $f) {"
        " $n = TCPDF_FONTS::addTTFfont($f, 'TrueTypeUnicode', '', 32, '/tmp/ittpdf-fonts/out/');"
        " if (!$n) { fwrite(STDERR, 'failed '.$f.PHP_EOL); exit(1); } echo $n, PHP_EOL; }"
    )
    subprocess.run([docker, "exec", CONTAINER, "php", "-r", script], check=True)
    target = ROOT / "fonts"
    for old in target.glob("it*.*"):
        old.unlink()
    target.mkdir(exist_ok=True)
    subprocess.run([docker, "cp", f"{CONTAINER}:/tmp/ittpdf-fonts/out/.", str(target)], check=True)
    shutil.rmtree(work)
    print("\n".join(sorted(path.name for path in target.glob("it*.*"))))
    return 0


if __name__ == "__main__":
    sys.exit(main())
