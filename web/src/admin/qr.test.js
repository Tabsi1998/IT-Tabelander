import jsQR from "jsqr";
import { describe, expect, test } from "vitest";
import { qrModules, qrPath } from "./qr.js";

const URL = "https://it.tabelander.co.at/status/view.php?track_id=IT7K3M9Q2XABCD12";

/** The code drawn as pixels, the way a phone camera would see it. */
function pixels(modules, scale = 4, quiet = 4) {
  const size = (modules.length + quiet * 2) * scale;
  const data = new Uint8ClampedArray(size * size * 4).fill(255);
  modules.forEach((row, y) => row.forEach((dark, x) => {
    if (!dark) return;
    for (let dy = 0; dy < scale; dy += 1) {
      for (let dx = 0; dx < scale; dx += 1) {
        const index = (((y + quiet) * scale + dy) * size + (x + quiet) * scale + dx) * 4;
        data[index] = 0;
        data[index + 1] = 0;
        data[index + 2] = 0;
      }
    }
  }));
  return { data, size };
}

/** The modules back from the SVG path, to see it draws exactly the code. */
function fromPath(path, count, quiet = 4) {
  const modules = Array.from({ length: count }, () => Array(count).fill(false));
  for (const [, x, y, run] of path.matchAll(/M(\d+) (\d+)h(\d+)v1h-\d+z/g)) {
    for (let step = 0; step < Number(run); step += 1) modules[Number(y) - quiet][Number(x) - quiet + step] = true;
  }
  return modules;
}

describe("the QR code on the device label (#72)", () => {
  test("reads back as the link to the status page", () => {
    const { data, size } = pixels(qrModules(URL));
    expect(jsQR(data, size, size)?.data).toBe(URL);
  });

  test("the SVG path draws exactly the dark modules", () => {
    const modules = qrModules(URL);
    expect(fromPath(qrPath(modules), modules.length)).toEqual(modules);
  });
});
