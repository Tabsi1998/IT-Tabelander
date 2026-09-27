import react from "@vitejs/plugin-react";
import { defineConfig } from "vite";

// The new website (#44). In development the API comes from the local backend;
// in production FastAPI serves the built files and the API from one origin.
export default defineConfig({
  plugins: [react()],
  server: {
    host: "127.0.0.1",
    port: 3010,
    strictPort: true,
    proxy: {
      "/api": { target: process.env.VITE_DEV_BACKEND_URL || "http://127.0.0.1:8001", changeOrigin: true },
    },
  },
  build: {
    target: "es2022",
    sourcemap: false,
  },
  test: {
    environment: "jsdom",
    globals: true,
    setupFiles: ["./src/test-setup.js"],
    include: ["src/**/*.test.{js,jsx}"],
  },
});
