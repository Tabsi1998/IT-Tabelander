// Design A (Werkbank). The colours live as CSS variables in src/styles.css so
// the dark variant follows the system setting without a second class set.
const token = (name) => `var(--c-${name})`;

export default {
  content: ["./index.html", "./src/**/*.{js,jsx}"],
  theme: {
    extend: {
      colors: {
        page: token("page"),
        surface: token("surface"),
        subtle: token("subtle"),
        ink: token("ink"),
        body: token("body"),
        muted: token("muted"),
        line: token("line"),
        "line-soft": token("line-soft"),
        field: token("field"),
        accent: token("accent"),
        "on-accent": token("on-accent"),
        brand: token("brand"),
        "link-hover": token("link-hover"),
        navy: token("navy"),
        "on-navy": token("on-navy"),
        "on-navy-muted": token("on-navy-muted"),
        "navy-line": token("navy-line"),
        chip: token("chip"),
        badge: token("badge"),
        "badge-ink": token("badge-ink"),
      },
      fontFamily: {
        sans: ['"Source Sans 3"', '"Segoe UI"', "system-ui", "sans-serif"],
        display: ["Sora", '"Segoe UI"', "system-ui", "sans-serif"],
        label: ["Michroma", '"Segoe UI"', "system-ui", "sans-serif"],
      },
      maxWidth: {
        page: "1200px",
      },
    },
  },
  plugins: [],
};
