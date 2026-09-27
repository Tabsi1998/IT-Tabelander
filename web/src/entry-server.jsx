import { renderToString } from "react-dom/server";
import { StaticRouter } from "react-router";
import App from "./App.jsx";

/** One page as HTML, for scripts/prerender.mjs: text and headings are in the
 *  file itself, for search engines and link previews without JavaScript. */
export function render(url) {
  return renderToString(
    <StaticRouter location={url}>
      <App />
    </StaticRouter>,
  );
}
