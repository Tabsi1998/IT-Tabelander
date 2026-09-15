#!/usr/bin/env python3
"""Run every check the CI runs, on this computer - and the ones it skips.

GitHub is the second, independent confirmation. This is the first: every job of
.github/workflows/ci.yml, run with the developer's own tools and processor,
plus the gates the CI never runs.

Groups:
    repository   every shell script parses, no CRLF stored, whitespace across
                 every tracked line, Gitleaks over the history and over
                 uncommitted and new files
    backend      for Python 3.10 and 3.14, the CI matrix: a pinned
                 environment, pip check, a full compile, the server imports,
                 and the unit tests the CI runs - all against a copy of what
                 Git would commit, so a local .env never reaches a test
    integration  the API and regression suites the CI never runs: a MongoDB of
                 its own, the live FastAPI server over HTTPS as in production,
                 and both suites against it
    frontend     a frozen Yarn install and the production build with CI=true
    extra        what GitHub does not run: OSV over the lockfiles, ShellCheck,
                 and proof that every test file is run by some gate

Usage:
    python scripts/local_check.py                    everything but extra
    python scripts/local_check.py --all              everything
    python scripts/local_check.py --only backend     some groups
    python scripts/local_check.py --list             the steps, without running
    python scripts/local_check.py --record           refresh the ratchet baseline

Results go to .local-testing/, which Git ignores.
"""

from __future__ import annotations

import argparse
import json
import os
import platform
import re
import shutil
import socket
import ssl
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Callable

ROOT = Path(__file__).resolve().parents[1]
STATE = ROOT / ".local-testing"
LOGS = STATE / "logs"
BASELINE = ROOT / "scripts" / "ci-baseline.json"
WINDOWS = platform.system() == "Windows"

GROUPS = ("repository", "backend", "integration", "frontend", "extra")
DEFAULT_GROUPS = ("repository", "backend", "integration", "frontend")

BACKEND = ROOT / "backend"
FRONTEND = ROOT / "frontend"
SNAPSHOT = STATE / "snapshot"
SNAPSHOT_BACKEND = SNAPSHOT / "backend"
TLS = STATE / "tls"
# Virtual environments live outside the repository. A repository script that
# walks the whole tree - a documentation link check, for one - would otherwise
# read thousands of third-party files as if they were the project's own.
CACHE = Path.home() / ".local-ci" / ROOT.name

# The CI matrix. Both ends are checked, because a server may run either.
PYTHON_VERSIONS = ("3.10", "3.14")
INTEGRATION_PYTHON = "3.14"
NODE_MAJOR = 24

# The two test files the CI runs. The other two need a live server and a
# database; the integration group provides both.
CI_TEST_FILES = ("tests/test_unit_runtime.py", "tests/test_inquiry_dolibarr.py")
INTEGRATION_TEST_FILES = ("tests/test_api.py", "tests/test_regression_iter2.py")

# Ports of their own, so this repository can be checked while another one is.
API_PORT = 18011
BASE_URL = f"https://127.0.0.1:{API_PORT}"
MONGO_PORT = 27018
MONGO_CONTAINER = "it-tabelander-local-check-mongo"
# conftest.py refuses a database whose name does not contain "test".
INTEGRATION_DB = "it_tabelander_local_check_test"


# =============================================================================
# Shared core
#
# Every repository's local_check.py carries the same copy of this part. The
# header above it names the paths and the groups, the steps below it say what
# is checked, and nothing in here knows which repository it is in. Each copy
# stands on its own, so a repository can be checked right after a fresh clone.
# =============================================================================

PASS, FAIL, SKIP = "passed", "failed", "skipped"

# The MongoDB the CI workflows run as a service.
MONGO_IMAGE = "mongo:7.0.39-jammy"
# OSV-Scanner 2.6.0 and ShellCheck 0.11.0, pinned by digest: a scanner that
# updates itself between two runs would change the findings on its own.
OSV_IMAGE = "ghcr.io/google/osv-scanner@sha256:afd838850ac1a0fcc15ff4a041dc9ba11123c3f0d2666217a5f0fcf9222b55fa"
SHELLCHECK_IMAGE = "koalaman/shellcheck@sha256:bb596a0d169b85ddd81d8b6d3a2ff6d5baf5fca10b97f575ebc647c3dff62b3d"

# Git's well-known id of the empty tree. A diff against it covers every line.
EMPTY_TREE = "4b825dc642cb6eb9a060e54bf8d69288fbee4904"

TOOLCHAIN = Path.home() / ".local-toolchain"
PROGRESS = "local-check.progress.json"
REPORT = "local-check.json"

# Paths that belong to the local checks, never to the product. A snapshot of
# the repository leaves them out, so they cannot end up in a build or a scan.
LOCAL_TOOLING = (".ci-panel/", ".local-testing/")

# Variables whose names look like credentials. No check needs a real one, and a
# shell that happens to carry a live token or database password must not hand
# it to code under test. Only their names are printed.
SECRET_NAME = re.compile(
    r"TOKEN|SECRET|PASSW|CREDENTIAL|PRIVATE|API_?KEY|ENCRYPTION|_KEY$"
    r"|^(MONGO|SMTP|STRIPE|RESEND|JWT|DISCORD|ADMIN|DOLIBARR)_",
    re.IGNORECASE,
)


class StepFailed(Exception):
    """A step found a problem. The message says what, and how to fix it."""


class StepSkipped(Exception):
    """A step cannot run here. The message says why."""


@dataclass
class Step:
    group: str
    name: str
    describe: str
    action: Callable[["Context"], "str | None"]
    # Names of steps that must pass first. A bare name means the same group;
    # "group/name" reaches into another one.
    needs: tuple = ()

    @property
    def key(self) -> str:
        return f"{self.group}/{self.name}"

    def requirements(self) -> list[str]:
        return [need if "/" in need else f"{self.group}/{need}" for need in self.needs]


@dataclass
class Result:
    group: str
    name: str
    describe: str
    status: str
    detail: str = ""
    seconds: float = 0.0


@dataclass
class Context:
    env: dict
    dropped: list
    log_dir: Path
    record: bool = False
    containers: list = field(default_factory=list)
    processes: list = field(default_factory=list)
    cache: dict = field(default_factory=dict)

    def log(self, name: str, text: str) -> Path:
        self.log_dir.mkdir(parents=True, exist_ok=True)
        path = self.log_dir / f"{name}.log"
        path.write_text(text, encoding="utf-8", errors="replace")
        return path

    def run(self, *arguments, cwd: Path | None = None, env: dict | None = None,
            check: bool = True, timeout: int = 1800,
            stdin: str | None = None) -> subprocess.CompletedProcess:
        """Run one command in the cleaned environment and capture its output."""
        merged = dict(self.env)
        if env:
            merged.update(env)
        command = [str(part) for part in arguments]
        try:
            completed = subprocess.run(
                command, cwd=str(cwd or ROOT), env=merged, input=stdin,
                capture_output=True, text=True, encoding="utf-8", errors="replace",
                timeout=timeout)
        except FileNotFoundError as error:
            raise StepSkipped(f"{command[0]} is not installed: {error}") from error
        except subprocess.TimeoutExpired as error:
            raise StepFailed(f"{Path(command[0]).name} did not finish within {timeout} s") from error
        if check and completed.returncode != 0:
            raise StepFailed(tail(completed))
        return completed


def clean_environment(source: dict) -> tuple[dict, list]:
    """Split an environment into what the steps get and the names withheld."""
    kept, withheld = {}, []
    for name, value in source.items():
        if SECRET_NAME.search(name):
            withheld.append(name)
        else:
            kept[name] = value
    return kept, sorted(withheld)


def base_context(record: bool = False) -> Context:
    env, withheld = clean_environment(dict(os.environ))
    # GitHub sets CI. Some tools behave differently with it - Create React App
    # turns lint warnings into errors - so the local run sets it too.
    env["CI"] = "true"
    env.setdefault("PYTHONUTF8", "1")
    env["npm_config_fund"] = "false"
    env["npm_config_update_notifier"] = "false"
    env["COREPACK_ENABLE_DOWNLOAD_PROMPT"] = "0"
    env["DOTNET_CLI_TELEMETRY_OPTOUT"] = "1"
    STATE.mkdir(parents=True, exist_ok=True)
    return Context(env=env, dropped=withheld, log_dir=LOGS, record=record)


def tail(completed: subprocess.CompletedProcess, lines: int = 25) -> str:
    """The end of a failing command's output, which is where the reason is."""
    text = (completed.stdout or "") + (completed.stderr or "")
    kept = [line for line in text.splitlines() if line.strip()][-lines:]
    return "\n".join(kept) or f"exit code {completed.returncode}"


def tool(context: Context, name: str) -> str | None:
    return shutil.which(name, path=context.env.get("PATH"))


def require(context: Context, name: str, hint: str) -> str:
    found = tool(context, name)
    if not found:
        raise StepSkipped(f"{name} is not installed. {hint}")
    return found


def git(context: Context) -> str:
    return require(context, "git", "Install Git for Windows.")


def tracked(context: Context, *pathspecs: str, new: bool = False) -> list[str]:
    """Files Git knows, optionally with the new ones it would pick up."""
    arguments = ["ls-files", "-z", "--cached"]
    if new:
        arguments += ["--others", "--exclude-standard"]
    listed = context.run(git(context), *arguments, "--", *pathspecs).stdout.split("\0")
    return sorted({name for name in listed if name and (ROOT / name).is_file()})


def posix_bash(context: Context) -> str:
    """A real POSIX bash, never the WSL stub.

    System32\\bash.exe is the WSL launcher. It comes first on PATH in a plain
    PowerShell, it cannot read a Windows path, and it fails every script handed
    to it. A check that calls every deployment script broken teaches the
    developer to ignore it, so the stub is refused by name.
    """
    for folder in (r"C:\Program Files\Git\bin", r"C:\Program Files\Git\usr\bin"):
        candidate = Path(folder) / "bash.exe"
        if candidate.is_file():
            return str(candidate)
    found = tool(context, "bash")
    if found and "system32" not in found.lower():
        return found
    raise StepSkipped("no POSIX bash was found. Git for Windows ships one.")


def port_open(port: int) -> bool:
    with socket.socket() as probe:
        probe.settimeout(0.4)
        return probe.connect_ex(("127.0.0.1", port)) == 0


def docker(context: Context) -> str:
    if "docker" in context.cache:
        return context.cache["docker"]
    binary = require(context, "docker", "Install Docker Desktop.")
    probe = context.run(binary, "info", "--format", "{{.ServerVersion}}", check=False, timeout=90)
    if probe.returncode != 0:
        raise StepSkipped("the Docker engine is not running. Start Docker Desktop.")
    context.cache["docker"] = binary
    return binary


# ------------------------------------------------------------------ runtimes

def python_for(context: Context, version: str) -> str:
    """The interpreter of one Python version, found through the py launcher."""
    key = f"python-{version}"
    if key in context.cache:
        return context.cache[key]
    found = None
    launcher = tool(context, "py")
    if launcher:
        probe = context.run(launcher, f"-{version}", "-c", "import sys; print(sys.executable)",
                            check=False, timeout=120)
        if probe.returncode == 0 and probe.stdout.strip():
            found = probe.stdout.strip().splitlines()[-1]
    found = found or tool(context, f"python{version}")
    if not found:
        raise StepSkipped(f"Python {version} is not installed. winget install Python.Python.{version}")
    context.cache[key] = found
    return found


def venv_executable(folder: Path) -> Path:
    return folder / ("Scripts/python.exe" if WINDOWS else "bin/python")


def make_venv(context: Context, folder: Path, interpreter: str, *pip_arguments) -> str:
    """An environment of its own, so a result cannot depend on what else is installed."""
    exe = venv_executable(folder)
    if not exe.is_file():
        context.run(interpreter, "-m", "venv", folder, timeout=900)
    completed = context.run(exe, "-m", "pip", "install", "--quiet", "--disable-pip-version-check",
                            "--upgrade", "pip", *pip_arguments, timeout=3600)
    context.log(f"pip-{folder.name}", completed.stdout + completed.stderr)
    return str(exe)


def node_of(context: Context, major: int) -> str:
    """A Node of one major version: the pinned one, or the one on PATH if it matches.

    A newer Node must not quietly stand in for the one the CI uses. The runtime
    differences that break a deployment are the ones a version jump hides.
    """
    key = f"node-{major}"
    if key in context.cache:
        return context.cache[key]
    found = None
    executable = "node.exe" if WINDOWS else "node"
    if TOOLCHAIN.is_dir():
        for child in sorted(TOOLCHAIN.iterdir(), reverse=True):
            if child.is_dir() and child.name.startswith(f"node-v{major}."):
                for candidate in (child / executable, child / "bin" / executable):
                    if candidate.is_file():
                        found = str(candidate)
                        break
            if found:
                break
    if not found:
        system = tool(context, "node")
        if system:
            version = context.run(system, "--version", check=False, timeout=60).stdout.strip()
            if version.lstrip("v").split(".")[0] == str(major):
                found = system
    if not found:
        raise StepSkipped(f"Node {major} is not installed. Unpack node-v{major}.x into {TOOLCHAIN}")
    context.cache[key] = found
    return found


def npm_of(node: str) -> str:
    candidate = Path(node).with_name("npm.cmd" if WINDOWS else "npm")
    return str(candidate) if candidate.is_file() else "npm"


def node_path_env(context: Context, node: str) -> dict:
    """An environment in which that node, its npm and its corepack come first."""
    return {"PATH": os.pathsep.join([str(Path(node).parent), context.env.get("PATH", "")])}


def run_yarn(context: Context, cwd: Path, *arguments, node: str, check: bool = True,
             timeout: int = 3600) -> subprocess.CompletedProcess:
    """Yarn 1.22 through Corepack, with the chosen Node first on PATH.

    The lockfile is yarn.lock, so npm must never stand in: it would resolve a
    different tree than the one the deployment installs.
    """
    env = node_path_env(context, node)
    direct = shutil.which("yarn", path=env["PATH"])
    if direct:
        command = [direct]
    else:
        corepack = shutil.which("corepack", path=env["PATH"])
        if not corepack:
            raise StepSkipped("neither yarn nor corepack was found; Node ships corepack")
        command = [corepack, "yarn"]
    return context.run(*command, *arguments, cwd=cwd, env=env, check=check, timeout=timeout)


# ------------------------------------------------------------------ services

def start_mongo(context: Context, name: str, port: int, image: str = MONGO_IMAGE) -> str:
    """A MongoDB of the CI's image, in a container of its own on a port of its own.

    A fresh container per run means no test ever sees the previous run's data,
    and a port per repository means two repositories can be checked at once.
    """
    key = f"mongo:{name}"
    if key in context.cache:
        return context.cache[key]
    binary = docker(context)
    context.run(binary, "rm", "--force", name, check=False, timeout=120)
    if port_open(port):
        raise StepSkipped(f"port {port} is taken by something else; stop it and run again")
    context.run(binary, "run", "--detach", "--name", name, "--publish",
                f"127.0.0.1:{port}:27017", image, timeout=1800)
    context.containers.append(name)
    deadline = time.time() + 120
    while time.time() < deadline:
        ping = context.run(binary, "exec", name, "mongosh", "--quiet", "--eval",
                           "db.adminCommand({ping: 1}).ok", check=False, timeout=60)
        if ping.returncode == 0 and ping.stdout.strip().endswith("1"):
            url = f"mongodb://127.0.0.1:{port}"
            context.cache[key] = url
            return url
        time.sleep(2)
    raise StepFailed(f"{image} did not answer a ping within 120 s")


def start_process(context: Context, name: str, arguments: list, *, cwd: Path, env: dict,
                  url: str, seconds: int = 120, tls=None) -> None:
    """Start a server in the background and wait until it answers HTTP at all.

    Any HTTP answer counts, a 404 included: the question here is whether the
    process serves, and the checks that follow judge what it serves. For a
    server with a certificate of its own, tls is the ssl context that trusts it.
    """
    context.log_dir.mkdir(parents=True, exist_ok=True)
    path = context.log_dir / f"{name}.log"
    sink = path.open("w", encoding="utf-8", errors="replace")
    merged = dict(context.env)
    merged.update(env)
    process = subprocess.Popen([str(part) for part in arguments], cwd=str(cwd), env=merged,
                               stdout=sink, stderr=subprocess.STDOUT)
    context.processes.append((process, sink))
    deadline = time.time() + seconds
    while time.time() < deadline:
        if process.poll() is not None:
            sink.flush()
            output = path.read_text(encoding="utf-8", errors="replace")
            raise StepFailed(f"{name} exited with code {process.returncode} before it answered. "
                             f"Log: {path}\n{output[-2000:]}")
        try:
            with urllib.request.urlopen(url, timeout=5, context=tls):
                return
        except urllib.error.HTTPError:
            return
        except OSError:
            time.sleep(1)
    raise StepFailed(f"{name} did not answer {url} within {seconds} s. Log: {path}")


def stop_everything(context: Context) -> None:
    for process, sink in reversed(context.processes):
        if process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=20)
            except subprocess.TimeoutExpired:
                process.kill()
        sink.close()
    context.processes.clear()
    binary = shutil.which("docker", path=context.env.get("PATH"))
    for name in reversed(context.containers):
        if binary:
            subprocess.run([binary, "rm", "--force", name], capture_output=True, text=True,
                           timeout=180)
    context.containers.clear()


def snapshot(context: Context, target: Path, *pathspecs: str, linux: bool = False) -> int:
    """Copy what Git would commit into target: tracked files as they are now, and new ones.

    Ignored files stay behind - a local .env with real credentials, a virtual
    environment, build output - and so do the local check's own files. With
    linux=True, text files get the LF endings a checkout on the Linux runner
    has, because bash in a container stops at the first CR.
    """
    if target.exists():
        shutil.rmtree(target)
    count = 0
    for name in tracked(context, *pathspecs, new=True):
        if name.startswith(LOCAL_TOOLING):
            continue
        data = (ROOT / name).read_bytes()
        if linux and b"\r\n" in data and b"\0" not in data[:8000]:
            data = data.replace(b"\r\n", b"\n")
        destination = target / name
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(data)
        count += 1
    return count


# ------------------------------------------------------------------- ratchet

def load_baseline() -> dict:
    try:
        return json.loads(BASELINE.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}


def save_baseline(data: dict) -> None:
    BASELINE.parent.mkdir(parents=True, exist_ok=True)
    BASELINE.write_text(json.dumps(data, indent=2, sort_keys=True, ensure_ascii=False) + "\n",
                        encoding="utf-8", newline="\n")


def ratchet(context: Context, key: str, found: set, what: str) -> str:
    """Known findings are debt; new ones fail.

    A gate is green on the day it is switched on and honest from then on. Run
    with --record once debt has been paid down, so the baseline shrinks with it.
    """
    baseline = load_baseline()
    known = set(baseline.get(key, []))
    if context.record:
        baseline[key] = sorted(found)
        save_baseline(baseline)
        return f"baseline recorded: {len(found)} {what}"
    new = sorted(found - known)
    if new:
        shown = "\n  ".join(new[:20]) + ("\n  ..." if len(new) > 20 else "")
        raise StepFailed(f"{len(new)} new {what}:\n  {shown}\n"
                         f"Fix them, or run with --record to accept them into {BASELINE.name}.")
    note = f"{len(found)} known {what}" if found else f"no {what}"
    resolved = known - found
    if resolved:
        note += f"; {len(resolved)} resolved since the baseline, run --record to lock that in"
    return note


# ------------------------------------------------------ steps every repo shares

def shell_scripts(context: Context) -> str:
    """Every shell script parses, with the line endings Git stores.

    The CI checks the scripts it names. This checks every .sh in the repository:
    a restore or deploy script that only breaks during an incident is the worst
    place to find a typo.
    """
    bash = posix_bash(context)
    listed = tracked(context, "*.sh", new=True)
    if not listed:
        raise StepSkipped("no shell scripts in this repository")
    broken = []
    for name in listed:
        text = (ROOT / name).read_bytes().replace(b"\r\n", b"\n").decode("utf-8", "replace")
        completed = context.run(bash, "-n", check=False, timeout=120, stdin=text)
        if completed.returncode != 0:
            broken.append(f"{name}: {tail(completed, 2)}")
    if broken:
        raise StepFailed("these do not parse:\n  " + "\n  ".join(broken))
    return f"{len(listed)} scripts parse"


def line_endings(context: Context) -> str:
    """No CRLF in what Git stores for text files.

    Git for Windows converts line endings on the way out, so a file can look
    right here and still be stored with CRLF - and a shell script stored that
    way fails on a Linux server with 'bad interpreter'. This reads the index,
    which is what every other machine checks out.
    """
    found = set()
    for entry in context.run(git(context), "ls-files", "--eol", "-z").stdout.split("\0"):
        info, _, path = entry.partition("\t")
        fields = info.split()
        if not path or not fields:
            continue
        attributes = info.partition("attr/")[2].strip()
        if fields[0] in ("i/crlf", "i/mixed") and "-text" not in attributes:
            found.add(f"{path} ({fields[0][2:]})")
    return ratchet(context, "line-endings", found, "text files stored with CRLF")


def whitespace(context: Context) -> str:
    """git diff --check over everything, not over nothing.

    On a fresh checkout there is no diff, so a CI that runs git diff --check can
    never fail. Measured against the empty tree it covers every committed line:
    what is there today is recorded, and whitespace errors in uncommitted
    changes fail outright.
    """
    binary = git(context)
    committed = context.run(binary, "diff", "--check", EMPTY_TREE, "HEAD", check=False, timeout=900)
    found = set()
    for line in committed.stdout.splitlines():
        match = re.match(r"^(.+?):\d+: (.+?)\.?$", line)
        if match:
            found.add(f"{match.group(1)}: {match.group(2)}")
    pending = context.run(binary, "diff", "--check", "HEAD", check=False, timeout=900)
    fresh = [line for line in pending.stdout.splitlines() if re.match(r"^.+?:\d+: ", line)]
    if fresh:
        raise StepFailed("whitespace errors in uncommitted changes:\n  " + "\n  ".join(fresh[:20]))
    return ratchet(context, "whitespace", found, "committed whitespace errors")


def gitleaks_binary(context: Context) -> str:
    return require(context, "gitleaks", "winget install Gitleaks.Gitleaks")


def gitleaks_config() -> list[str]:
    config = ROOT / ".gitleaks.toml"
    return ["--config", str(config)] if config.is_file() else []


def gitleaks_history(context: Context) -> str:
    completed = context.run(gitleaks_binary(context), "git", ".", "--redact", "--no-banner",
                            *gitleaks_config(), "--log-opts=HEAD", check=False, timeout=1800)
    output = completed.stdout + completed.stderr
    context.log("gitleaks-history", output)
    if completed.returncode != 0:
        raise StepFailed("Gitleaks found secrets in the reachable history:\n" + tail(completed))
    commits = re.search(r"(\d+) commits scanned", output)
    return f"{commits.group(1) if commits else 'every'} commits are clean"


def gitleaks_worktree(context: Context) -> str:
    """The files Git does not have yet: changed ones and new ones.

    The history scan cannot see them, and neither can GitHub. Only what Git
    would pick up is scanned - ignored logs and build output are left out - so
    a finding here is always something that could be committed next.
    """
    binary = git(context)
    changed = set(context.run(binary, "diff", "--name-only", "-z", "HEAD").stdout.split("\0"))
    changed |= set(context.run(binary, "ls-files", "-z", "--others", "--exclude-standard").stdout.split("\0"))
    pending = sorted(name for name in changed
                     if name and not name.startswith(LOCAL_TOOLING) and (ROOT / name).is_file())
    if not pending:
        return "nothing uncommitted to scan"
    with tempfile.TemporaryDirectory(prefix="local-check-leaks-") as folder:
        for name in pending:
            destination = Path(folder) / name
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / name, destination)
        completed = context.run(gitleaks_binary(context), "dir", ".", "--redact", "--no-banner",
                                *gitleaks_config(), cwd=Path(folder), check=False, timeout=1800)
    context.log("gitleaks-worktree", completed.stdout + completed.stderr)
    if completed.returncode != 0:
        raise StepFailed("Gitleaks found secrets in uncommitted or new files:\n" + tail(completed))
    return f"{len(pending)} uncommitted or new files are clean"


def osv_scan(context: Context) -> str:
    """Known vulnerabilities in every lockfile, from the OSV database.

    One scanner reads package-lock.json, yarn.lock, requirements files, go.sum
    and NuGet locks alike, so every ecosystem in the repository is judged by
    the same source of advisories. Transitive resolution stays off: for a
    requirements file without a lock it picks the oldest allowed versions -
    h11 0.9.0 where 0.16.0 is installed - and reports advisories that do not
    apply.
    """
    binary = docker(context)
    completed = context.run(binary, "run", "--rm", "--mount",
                            f"type=bind,source={ROOT},target=/src,readonly", OSV_IMAGE,
                            "scan", "source", "--recursive", "--no-resolve", "--allow-no-lockfiles",
                            "--format", "json", "/src",
                            check=False, timeout=2400)
    output = completed.stdout + completed.stderr
    context.log("osv-scanner", output)
    if "No package sources found" in output:
        return "no lockfiles in this repository, nothing to scan"
    if completed.returncode not in (0, 1):
        raise StepFailed("OSV-Scanner could not scan the repository:\n" + tail(completed))
    try:
        report = json.loads(completed.stdout or "{}")
    except ValueError as error:
        raise StepFailed("OSV-Scanner produced no JSON report:\n" + tail(completed)) from error
    found = set()
    for result in report.get("results") or []:
        source = str((result.get("source") or {}).get("path", "")).replace("/src/", "", 1)
        for package in result.get("packages") or []:
            info = package.get("package") or {}
            for vulnerability in package.get("vulnerabilities") or []:
                found.add(f"{source}: {info.get('name')} {info.get('version')} {vulnerability.get('id')}")
    return ratchet(context, "osv", found, "known vulnerabilities in the lockfiles")


def shellcheck(context: Context) -> str:
    """ShellCheck over every tracked script: quoting, globbing and exit-code traps."""
    listed = tracked(context, "*.sh")
    if not listed:
        raise StepSkipped("no shell scripts in this repository")
    binary = docker(context)
    completed = context.run(binary, "run", "--rm", "--mount",
                            f"type=bind,source={ROOT},target=/mnt,readonly", SHELLCHECK_IMAGE,
                            "--format=json1", *[f"/mnt/{name}" for name in listed],
                            check=False, timeout=900)
    try:
        report = json.loads(completed.stdout or '{"comments": []}')
    except ValueError as error:
        raise StepFailed("ShellCheck produced no report:\n" + tail(completed)) from error
    found = {f"{comment['file'][5:]}: SC{comment['code']} ({comment['level']})"
             for comment in report.get("comments", [])}
    return ratchet(context, "shellcheck", found, "ShellCheck findings")


def pytest_counts(text: str) -> dict:
    """The counts from pytest's last summary line."""
    lines = [line for line in text.splitlines()
             if re.search(r"\d+ (passed|failed|skipped|errors?|deselected)", line)]
    counts: dict = {}
    if lines:
        for number, kind in re.findall(r"(\d+) (passed|failed|skipped|errors?|deselected)", lines[-1]):
            counts["errors" if kind.startswith("error") else kind] = int(number)
    return counts


def describe_counts(counts: dict) -> str:
    order = ("passed", "failed", "errors", "skipped", "deselected")
    return ", ".join(f"{counts[kind]} {kind}" for kind in order if counts.get(kind)) or "no tests reported"


# -------------------------------------------------------------------- runner

def head_info() -> dict:
    binary = shutil.which("git")
    if not binary:
        return {}

    def ask(*arguments: str) -> str:
        completed = subprocess.run([binary, *arguments], cwd=str(ROOT), capture_output=True,
                                   text=True, encoding="utf-8", errors="replace")
        return completed.stdout.strip()

    return {"branch": ask("branch", "--show-current"), "head": ask("rev-parse", "--short", "HEAD"),
            "subject": ask("log", "-1", "--format=%s"), "dirty": bool(ask("status", "--porcelain"))}


def write_progress(planned: list, results: list, current, started: str, finished: bool,
                   info: dict) -> None:
    """What has run so far, for the dashboard. Best effort: a locked file is skipped."""
    done = {(item.group, item.name): item for item in results}
    steps = []
    for step in planned:
        item = done.get((step.group, step.name))
        if item:
            steps.append({"group": item.group, "name": item.name, "describe": item.describe,
                          "status": item.status, "detail": item.detail, "seconds": item.seconds})
        else:
            steps.append({"group": step.group, "name": step.name, "describe": step.describe,
                          "status": "running" if step is current else "pending",
                          "detail": "", "seconds": 0})
    payload = {"repo": ROOT.name, "path": str(ROOT), "started": started,
               "finished": datetime.now(timezone.utc).isoformat(timespec="seconds") if finished else None,
               "git": info, "steps": steps}
    try:
        STATE.mkdir(parents=True, exist_ok=True)
        temporary = STATE / (PROGRESS + ".tmp")
        temporary.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding="utf-8")
        os.replace(temporary, STATE / PROGRESS)
    except OSError:
        pass


def execute(steps: list, context: Context) -> list:
    results: list = []
    passed: set = set()
    started = datetime.now(timezone.utc).isoformat(timespec="seconds")
    info = head_info()
    context.cache["git-info"] = info
    for step in steps:
        missing = [need for need in step.requirements() if need not in passed]
        if missing:
            detail = f"needs {', '.join(missing)} to pass first"
            results.append(Result(step.group, step.name, step.describe, SKIP, detail))
            print(f"SKIPPED         {step.key}: {detail}", flush=True)
            continue
        print(f"\n== {step.group}: {step.describe}", flush=True)
        write_progress(steps, results, step, started, False, info)
        clock = time.monotonic()
        try:
            detail = step.action(context) or ""
            status = PASS
            passed.add(step.key)
        except StepSkipped as reason:
            detail, status = str(reason), SKIP
        except StepFailed as reason:
            detail, status = str(reason), FAIL
        except Exception as reason:  # a bug in a check must not read as a clean run
            detail, status = f"the check itself failed: {reason!r}", FAIL
        seconds = round(time.monotonic() - clock, 1)
        results.append(Result(step.group, step.name, step.describe, status, detail, seconds))
        head = detail.splitlines()[0] if detail else ""
        print(f"{status.upper():<8} {seconds:6.1f} s  {head}", flush=True)
    write_progress(steps, results, None, started, True, info)
    return results


def summary(results: list, seconds: float) -> str:
    counts = {status: sum(1 for item in results if item.status == status) for status in (PASS, FAIL, SKIP)}
    lines = [f"\n{ROOT.name}: {counts[PASS]} passed, {counts[SKIP]} skipped, "
             f"{counts[FAIL]} failed in {seconds:.0f} s"]
    for item in results:
        head = item.detail.splitlines()[0] if item.detail else ""
        lines.append(f"  {item.status.upper():<8}{item.group:<12}{item.describe:<58}"
                     f"{item.seconds:7.1f} s  {head[:110]}")
    failed = [item for item in results if item.status == FAIL]
    if failed:
        lines.append("\nWhat failed, in full:")
        for item in failed:
            lines.append(f"\n  {item.group}/{item.name} - {item.describe}")
            lines.extend(f"    {line}" for line in item.detail.splitlines())
    return "\n".join(lines)


def main(argv: list | None = None) -> int:
    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(encoding="utf-8", errors="replace")
        except (AttributeError, ValueError):
            pass
    parser = argparse.ArgumentParser(description=f"Run every check for {ROOT.name} on this computer.")
    parser.add_argument("--only", help="comma-separated groups: " + ", ".join(GROUPS))
    parser.add_argument("--all", action="store_true", help="include the extra group, which GitHub does not run")
    parser.add_argument("--list", action="store_true", help="show the steps without running them")
    parser.add_argument("--record", action="store_true", help="accept today's findings into the ratchet baseline")
    parser.add_argument("--keep-services", action="store_true", help="leave containers and servers running")
    arguments = parser.parse_args(argv)

    if arguments.only:
        groups = {name.strip() for name in arguments.only.split(",") if name.strip()}
        unknown = groups - set(GROUPS)
        if unknown:
            parser.error("unknown groups: " + ", ".join(sorted(unknown)))
    elif arguments.all or arguments.record:
        groups = set(GROUPS)
    else:
        groups = set(DEFAULT_GROUPS)

    steps = plan(groups)
    if arguments.list:
        for step in steps:
            print(f"{step.group:<12} {step.describe}")
        return 0

    context = build_context(record=arguments.record)
    print(f"{ROOT.name}: {', '.join(group for group in GROUPS if group in groups)}", flush=True)
    if context.dropped:
        print("Withheld from every step (names only): " + ", ".join(context.dropped), flush=True)

    clock = time.monotonic()
    try:
        results = execute(steps, context)
    finally:
        if arguments.keep_services:
            print("Containers and servers are left running (--keep-services).")
        else:
            tear_down(context)
    seconds = time.monotonic() - clock

    report = {"repo": ROOT.name, "groups": [group for group in GROUPS if group in groups],
              "seconds": round(seconds, 1), "git": context.cache.get("git-info", {}),
              "results": [vars(item) for item in results]}
    (STATE / REPORT).write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    print(summary(results, seconds))
    print(f"Report: {STATE / REPORT}")
    return 1 if any(item.status == FAIL for item in results) else 0


# ================================================================ IT-Tabelander

# ---------------------------------------------------------------- repository

def repository_steps() -> list:
    return [
        Step("repository", "shell", "Every shell script parses", shell_scripts),
        Step("repository", "line-endings", "No CRLF stored for text files", line_endings),
        Step("repository", "whitespace", "Whitespace across every tracked line", whitespace),
        Step("repository", "gitleaks-history", "Gitleaks over the history", gitleaks_history),
        Step("repository", "gitleaks-worktree", "Gitleaks over uncommitted and new files",
             gitleaks_worktree),
    ]


# ------------------------------------------------------------------- backend

def venv_folder(version: str) -> Path:
    return CACHE / f"venv-{version}"


def backend_python(version: str) -> str:
    exe = venv_executable(venv_folder(version))
    if not exe.is_file():
        raise StepSkipped(f"the Python {version} environment is not built yet")
    return str(exe)


def backend_snapshot(context: Context) -> str:
    """A copy of the backend as Git would commit it.

    server.py loads backend/.env on import. A developer's .env holds real
    credentials - SMTP, Dolibarr - and a test that picks them up can send real
    mail. The CI has no .env; neither has this copy.
    """
    count = snapshot(context, SNAPSHOT, "backend")
    if not (SNAPSHOT_BACKEND / "server.py").is_file():
        raise StepFailed("the snapshot has no backend/server.py")
    (SNAPSHOT_BACKEND / "uploads").mkdir(parents=True, exist_ok=True)
    return f"{count} files, without .env files or virtual environments"


def environment_step(version: str):
    def action(context: Context) -> str:
        # tzdata: Windows has no zoneinfo database, so a test that builds a named
        # timezone fails here and passes on the Linux runner. It is a data package
        # for the local run and changes nothing in production.
        extra = ["tzdata"] if WINDOWS else []
        exe = make_venv(context, venv_folder(version), python_for(context, version),
                        "--requirement", BACKEND / "requirements-dev.txt", *extra)
        return context.run(exe, "--version", timeout=60).stdout.strip() + " with requirements-dev.txt"
    return action


def pip_check_step(version: str):
    def action(context: Context) -> str:
        completed = context.run(backend_python(version), "-m", "pip", "check", check=False, timeout=300)
        if completed.returncode != 0:
            raise StepFailed("installed packages have broken requirements:\n" + tail(completed))
        return "no broken requirements"
    return action


def compile_step(version: str):
    def action(context: Context) -> str:
        completed = context.run(backend_python(version), "-m", "compileall", "-q", SNAPSHOT_BACKEND,
                                check=False, timeout=900)
        if completed.returncode != 0:
            raise StepFailed(f"the backend does not compile on Python {version}:\n" + tail(completed))
        return "the backend compiles"
    return action


def import_step(version: str):
    def action(context: Context) -> str:
        completed = context.run(backend_python(version), "-c", "import server; print(server.app.title)",
                                cwd=SNAPSHOT_BACKEND, check=False, timeout=300)
        if completed.returncode != 0:
            raise StepFailed(f"the server does not import on Python {version}:\n" + tail(completed))
        return f"server imports as {completed.stdout.strip().splitlines()[-1]!r}"
    return action


def unit_step(version: str):
    def action(context: Context) -> str:
        completed = context.run(backend_python(version), "-m", "pytest", *CI_TEST_FILES, "-q",
                                "-p", "no:cacheprovider", cwd=SNAPSHOT_BACKEND, check=False,
                                timeout=1800)
        text = completed.stdout + completed.stderr
        path = context.log(f"unit-{version}", text)
        if completed.returncode != 0:
            raise StepFailed(f"the unit tests failed on Python {version}. Full output: {path}\n"
                             + tail(completed, 30))
        return describe_counts(pytest_counts(text))
    return action


def backend_steps() -> list:
    steps = [Step("backend", "snapshot", "A copy of what Git would commit, without .env",
                  backend_snapshot)]
    for version in PYTHON_VERSIONS:
        venv = f"venv-{version}"
        steps += [
            Step("backend", venv, f"Python {version}: a pinned environment", environment_step(version)),
            Step("backend", f"pip-check-{version}", f"Python {version}: pip check",
                 pip_check_step(version), (venv,)),
            Step("backend", f"compile-{version}", f"Python {version}: the backend compiles",
                 compile_step(version), (venv, "snapshot")),
            Step("backend", f"import-{version}", f"Python {version}: the server imports",
                 import_step(version), (venv, "snapshot")),
            Step("backend", f"unit-{version}", f"Python {version}: the unit tests the CI runs",
                 unit_step(version), (venv, "snapshot")),
        ]
    return steps


# --------------------------------------------------------------- integration

def integration_env(url: str) -> dict:
    """Throwaway settings that satisfy start.sh's validation. Nothing real."""
    return {
        "MONGO_URL": url,
        "DB_NAME": INTEGRATION_DB,
        "MONGO_SERVER_SELECTION_TIMEOUT_MS": "3000",
        "MONGO_CONNECT_TIMEOUT_MS": "3000",
        "MONGO_OPERATION_TIMEOUT_MS": "5000",
        "JWT_SECRET": "local-check-jwt-" + "0123456789abcdef" * 4,
        "ADMIN_EMAIL": "local-check-admin@example.com",
        "ADMIN_PASSWORD": "Local-Check-Admin-2026!",
        "CANONICAL_BASE_URL": BASE_URL,
        "CORS_ORIGINS": BASE_URL,
    }


def openssl(context: Context) -> str:
    for candidate in (r"C:\Program Files\Git\mingw64\bin\openssl.exe", r"C:\Program Files\Git\usr\bin\openssl.exe"):
        if Path(candidate).is_file():
            return candidate
    return require(context, "openssl", "Git for Windows ships one.")


def integration_certificate(context: Context) -> str:
    """A throwaway certificate, so the server speaks HTTPS as in production.

    The login sets its cookies with Secure and SameSite=None. A client returns a
    Secure cookie over HTTPS only, so against plain http://127.0.0.1 every
    request after the login would be anonymous, and the session tests would fail
    for a reason production never has. The certificate is valid for two days
    and trusted by this test run alone.
    """
    TLS.mkdir(parents=True, exist_ok=True)
    context.run(openssl(context), "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-sha256", "-days", "2",
                "-keyout", TLS / "key.pem", "-out", TLS / "cert.pem", "-subj", "/CN=localhost",
                "-addext", "subjectAltName=DNS:localhost,IP:127.0.0.1", timeout=120)
    return "a certificate for 127.0.0.1, valid for two days"


def integration_database(context: Context) -> str:
    context.cache["integration-url"] = start_mongo(context, MONGO_CONTAINER, MONGO_PORT)
    return f"{MONGO_IMAGE} on port {MONGO_PORT}"


def integration_server(context: Context) -> str:
    if port_open(API_PORT):
        raise StepSkipped(f"port {API_PORT} is taken; stop what uses it and run again")
    start_process(context, "integration-server",
                  [backend_python(INTEGRATION_PYTHON), "-m", "uvicorn", "server:app",
                   "--host", "127.0.0.1", "--port", str(API_PORT),
                   "--ssl-keyfile", TLS / "key.pem", "--ssl-certfile", TLS / "cert.pem"],
                  cwd=SNAPSHOT_BACKEND, env=integration_env(context.cache["integration-url"]),
                  url=f"{BASE_URL}/api/health", seconds=120,
                  tls=ssl.create_default_context(cafile=str(TLS / "cert.pem")))
    return f"uvicorn on Python {INTEGRATION_PYTHON} answers on {BASE_URL}"


def integration_tests(context: Context) -> str:
    """The API and regression suites against the live server.

    The CI never runs them: conftest.py skips both files unless
    IT_TABELANDER_RUN_INTEGRATION=1, the server is on localhost and the
    database name contains "test". All three hold here. A run in which the
    tests are skipped anyway fails, rather than reading as green.
    """
    env = integration_env(context.cache["integration-url"])
    env["IT_TABELANDER_RUN_INTEGRATION"] = "1"
    env["REACT_APP_BACKEND_URL"] = BASE_URL
    env["REQUESTS_CA_BUNDLE"] = str(TLS / "cert.pem")
    completed = context.run(backend_python(INTEGRATION_PYTHON), "-m", "pytest", *INTEGRATION_TEST_FILES,
                            "-q", "-rfEs", "-p", "no:cacheprovider", cwd=SNAPSHOT_BACKEND, env=env,
                            check=False, timeout=3600)
    text = completed.stdout + completed.stderr
    path = context.log("integration-tests", text)
    counts = pytest_counts(text)
    if completed.returncode != 0:
        failed = [line for line in text.splitlines() if line.startswith(("FAILED", "ERROR"))]
        raise StepFailed(f"the integration suites failed ({describe_counts(counts)}). Full output: {path}\n"
                         + "\n".join(failed[:15] or [tail(completed, 30)]))
    if not counts.get("passed"):
        raise StepFailed(f"no integration test ran - the safety gate in conftest.py skipped them. "
                         f"Full output: {path}")
    note = describe_counts(counts)
    if counts.get("skipped"):
        note += " - the log says why some were skipped"
    return note


def integration_steps() -> list:
    venv = f"backend/venv-{INTEGRATION_PYTHON}"
    return [
        Step("integration", "database", "A MongoDB of its own", integration_database),
        Step("integration", "certificate", "A throwaway certificate for HTTPS", integration_certificate),
        Step("integration", "server", f"The FastAPI server over HTTPS on Python {INTEGRATION_PYTHON}",
             integration_server, ("database", "certificate", venv, "backend/snapshot")),
        Step("integration", "suites", "The API and regression tests the CI never runs",
             integration_tests, ("server",)),
    ]


# ------------------------------------------------------------------ frontend

def frontend_install(context: Context) -> str:
    node = node_of(context, NODE_MAJOR)
    completed = run_yarn(context, FRONTEND, "install", "--frozen-lockfile", "--non-interactive",
                         "--production=false", node=node, check=False, timeout=3600)
    path = context.log("frontend-install", completed.stdout + completed.stderr)
    if completed.returncode != 0:
        raise StepFailed(f"the frozen install failed. Full output: {path}\n" + tail(completed))
    return "frozen install on Node " + context.run(node, "--version", timeout=60).stdout.strip()


def frontend_build(context: Context) -> str:
    """The production build, with CI=true as on GitHub.

    Create React App turns every lint warning into an error when CI is set, so
    a build that passes in a developer's shell can still fail in the pipeline.
    """
    completed = run_yarn(context, FRONTEND, "build", node=node_of(context, NODE_MAJOR),
                         check=False, timeout=3600)
    path = context.log("frontend-build", completed.stdout + completed.stderr)
    if completed.returncode != 0:
        raise StepFailed(f"the production build failed. Full output: {path}\n" + tail(completed, 30))
    out = FRONTEND / "build"
    if not (out / "index.html").is_file():
        raise StepFailed("the build produced no build/index.html")
    bundles = list((out / "static" / "js").glob("*.js")) if (out / "static" / "js").is_dir() else []
    if not bundles:
        raise StepFailed("the build produced no JavaScript under build/static/js")
    size = sum(item.stat().st_size for item in out.rglob("*") if item.is_file())
    return f"{len(bundles)} bundles, {size // 1024} KiB"


def frontend_steps() -> list:
    return [
        Step("frontend", "install", "A frozen install from yarn.lock", frontend_install),
        Step("frontend", "build", "The production build with CI=true", frontend_build, ("install",)),
    ]


# --------------------------------------------------------------------- extra

def test_inventory(context: Context) -> str:
    """Every test file is run by some gate.

    The CI runs two of the four test files. A fifth one added tomorrow would be
    run by nothing, and nothing would say so.
    """
    present = {name for name in tracked(context, "backend/tests")
               if Path(name).name.startswith("test_") and name.endswith(".py")}
    covered = {f"backend/{name}" for name in CI_TEST_FILES + INTEGRATION_TEST_FILES}
    missing = sorted(present - covered)
    if missing:
        raise StepFailed("these test files are run by no gate:\n  " + "\n  ".join(missing))
    gone = sorted(covered - present)
    if gone:
        raise StepFailed("the gates name test files that no longer exist:\n  " + "\n  ".join(gone))
    return (f"all {len(present)} test files run: {len(CI_TEST_FILES)} in the CI, "
            f"{len(INTEGRATION_TEST_FILES)} only here")


def extra_steps() -> list:
    return [
        Step("extra", "test-inventory", "Every test file is run by some gate", test_inventory),
        Step("extra", "osv", "Known vulnerabilities in the lockfiles", osv_scan),
        Step("extra", "shellcheck", "ShellCheck over the deployment scripts", shellcheck),
    ]


# -------------------------------------------------------------------- wiring

def plan(groups: set) -> list:
    builders = {"repository": repository_steps, "backend": backend_steps,
                "integration": integration_steps, "frontend": frontend_steps, "extra": extra_steps}
    steps: list = []
    if "integration" in groups and "backend" not in groups:
        # The server runs from the snapshot in the newest environment.
        steps += [step for step in backend_steps()
                  if step.name in ("snapshot", f"venv-{INTEGRATION_PYTHON}")]
    for group in GROUPS:
        if group in groups:
            steps += builders[group]()
    return steps


def build_context(record: bool = False) -> Context:
    return base_context(record)


def tear_down(context: Context) -> None:
    stop_everything(context)


if __name__ == "__main__":
    sys.exit(main())
