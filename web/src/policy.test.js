import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

// vite preview gives the browser tests the backend's security policy
// (server.py, PUBLIC_CSP); both must stay the same (#54).
describe("security policy", () => {
  it("is the same for the browser tests and the live server", () => {
    // Windows checkouts may carry CRLF line endings.
    const read = (relative) => readFileSync(fileURLToPath(new URL(relative, import.meta.url)), "utf8").replaceAll("\r\n", "\n");
    const server = read("../../backend/server.py");
    const block = server.slice(server.indexOf("PUBLIC_CSP = "), server.indexOf("))", server.indexOf("PUBLIC_CSP = ")));
    const live = [...block.matchAll(/b"([^"]*)"/g)].map((match) => match[1]).slice(1).join("");
    const config = read("../vite.config.mjs");
    const start = config.indexOf("const CSP = ");
    const preview = [...config.slice(start, config.indexOf(";\n", start)).matchAll(/"([^"]*)"/g)].map((match) => match[1]).join("");
    expect(live).toContain("script-src 'self';");
    expect(preview).toBe(live);
  });
});
