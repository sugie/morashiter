import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  root: "static",
  plugins: [react()],
  build: {
    outDir: "../dist-static",
    emptyOutDir: true,
    sourcemap: false,
  },
});
