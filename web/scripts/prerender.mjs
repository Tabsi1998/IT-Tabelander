// Writes every page of the site as finished HTML into dist/ (#44). The data
// from the API (services, reviews, company) follows in the browser; texts,
// headings and links are in the file from the start.
import { mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const dist = path.join(root, "dist");
const { render } = await import(pathToFileURL(path.join(root, "dist-ssr", "entry-server.js")).href);
const template = readFileSync(path.join(dist, "index.html"), "utf8");

const PAGES = [
  { url: "/", file: "index.html" },
  { url: "/rechtliches/impressum", file: "rechtliches/impressum/index.html",
    title: "Impressum – IT-Tabelander" },
  { url: "/rechtliches/datenschutz", file: "rechtliches/datenschutz/index.html",
    title: "Datenschutzerklärung – IT-Tabelander" },
  { url: "/rechtliches/nutzungsbedingungen", file: "rechtliches/nutzungsbedingungen/index.html",
    title: "Nutzungsbedingungen – IT-Tabelander" },
  // Behind the personal link after a closed ticket (#71); never in search results.
  { url: "/bewertung", file: "bewertung/index.html", title: "Bewertung – IT-Tabelander", robots: "noindex" },
  { url: "/seite-nicht-gefunden", file: "404.html", title: "Seite nicht gefunden – IT-Tabelander",
    robots: "noindex" },
];

for (const page of PAGES) {
  let html = template.replace('<div id="root"><!--app-html--></div>',
    `<div id="root" data-path="${page.url}">${render(page.url)}</div>`);
  if (page.title) {
    html = html.replace(/<title>[^<]*<\/title>/, `<title>${page.title}</title>`)
      .replace(/(<meta property="og:title" content=")[^"]*(")/, `$1${page.title}$2`);
  }
  if (page.robots) {
    html = html.replace("<!--app-head-->", `<meta name="robots" content="${page.robots}" />\n    <!--app-head-->`);
  }
  const target = path.join(dist, page.file);
  mkdirSync(path.dirname(target), { recursive: true });
  writeFileSync(target, html);
  console.log(`prerendered ${page.url} -> dist/${page.file}`);
}
// The admin opens in the browser only; its page carries no prerendered text.
writeFileSync(path.join(dist, "admin.html"), template
  .replace(/<title>[^<]*<\/title>/, "<title>Verwaltung – IT-Tabelander</title>")
  .replace("<!--app-head-->", '<meta name="robots" content="noindex" />\n    <!--app-head-->'));
console.log("wrote the empty admin page -> dist/admin.html");
// So does the customer area (#63): it needs a sign-in, search engines stay out.
writeFileSync(path.join(dist, "kundenbereich.html"), template
  .replace(/<title>[^<]*<\/title>/, "<title>Kundenbereich – IT-Tabelander</title>")
  .replace("<!--app-head-->", '<meta name="robots" content="noindex" />\n    <!--app-head-->'));
console.log("wrote the empty customer area page -> dist/kundenbereich.html");
rmSync(path.join(root, "dist-ssr"), { recursive: true, force: true });
