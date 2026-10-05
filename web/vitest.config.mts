import path from "node:path";

import react from "@vitejs/plugin-react";
import { defineConfig } from "vitest/config";

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      "@": path.resolve(import.meta.dirname, "src"),
      // Guards against importing server code into the browser bundle; meaningless under test.
      "server-only": path.resolve(import.meta.dirname, "src/test/empty.ts"),
    },
  },
  test: {
    environment: "jsdom",
    setupFiles: ["src/test/setup.ts"],
    // Behind UTC, so a calendar date parsed as UTC midnight shows up as the day before.
    env: { TZ: "Pacific/Honolulu" },
  },
});
