/// <reference types="vitest/config" />
import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";

// Dev: API calls go to the PHP dev server (composer serve, :8080) or to the
// prism mock (pnpm mock, :4010) with WV_API=http://127.0.0.1:4010.
const api = process.env.WV_API ?? "http://127.0.0.1:8080";
const mock = api.includes(":4010");

export default defineConfig({
  plugins: [react(), tailwindcss()],
  build: { outDir: "../public", emptyOutDir: false },
  test: { environment: "jsdom", restoreMocks: true, setupFiles: ["src/test/setup.ts"] },
  server: {
    proxy: {
      "/api/v1": {
        target: api,
        changeOrigin: true,
        rewrite: mock ? (p) => p.replace(/^\/api\/v1/, "") : undefined,
      },
    },
  },
});
