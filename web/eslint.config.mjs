import js from "@eslint/js";
import jsxA11y from "eslint-plugin-jsx-a11y";
import react from "eslint-plugin-react";
import reactHooks from "eslint-plugin-react-hooks";

const browser = {
  window: "readonly", document: "readonly", navigator: "readonly", location: "readonly",
  history: "readonly", sessionStorage: "readonly", localStorage: "readonly", fetch: "readonly",
  FormData: "readonly", File: "readonly", URL: "readonly", URLSearchParams: "readonly",
  AbortController: "readonly", crypto: "readonly", console: "readonly", setTimeout: "readonly",
  clearTimeout: "readonly", matchMedia: "readonly", HTMLElement: "readonly", Event: "readonly",
  requestAnimationFrame: "readonly", IntersectionObserver: "readonly",
};
const node = { process: "readonly", console: "readonly", URL: "readonly", Buffer: "readonly" };

export default [
  { ignores: ["dist/**", "dist-ssr/**", "node_modules/**", "playwright-report/**", "test-results/**"] },
  js.configs.recommended,
  {
    files: ["src/**/*.{js,jsx}"],
    plugins: { react, "react-hooks": reactHooks, "jsx-a11y": jsxA11y },
    languageOptions: {
      ecmaVersion: 2024,
      sourceType: "module",
      parserOptions: { ecmaFeatures: { jsx: true } },
      globals: browser,
    },
    settings: { react: { version: "19.3" } },
    rules: {
      ...react.configs.recommended.rules,
      ...react.configs["jsx-runtime"].rules,
      ...reactHooks.configs.recommended.rules,
      ...jsxA11y.configs.strict.rules,
      "react/prop-types": "off",
    },
  },
  {
    files: ["src/**/*.test.{js,jsx}", "src/test-setup.js"],
    languageOptions: {
      globals: { ...browser, describe: "readonly", it: "readonly", expect: "readonly", vi: "readonly",
                 beforeEach: "readonly", afterEach: "readonly", global: "readonly" },
    },
  },
  {
    files: ["*.{js,mjs}", "scripts/**/*.mjs", "e2e/**/*.js"],
    languageOptions: { ecmaVersion: 2024, sourceType: "module", globals: { ...node, ...browser } },
  },
];
