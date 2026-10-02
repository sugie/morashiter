import { build } from "vite";
import { readFile, writeFile, rm } from "node:fs/promises";
import { pathToFileURL, fileURLToPath } from "node:url";
import path from "node:path";

const root = fileURLToPath(new URL("../", import.meta.url));
process.chdir(root);
const configFile = path.join(root, "vite.static.config.ts");
await build({ configFile });
const ssrDir = path.join(root, ".static-ssr");
try {
  await build({ configFile, build: { ssr: path.join(root, "static/render.tsx"), outDir: ssrDir } });
  const { render } = await import(pathToFileURL(path.join(ssrDir, "render.js")));
  const target = path.join(root, "dist-static/index.html");
  const html = await readFile(target, "utf8");
  if (!html.includes("<!--prerender-->")) throw new Error("Prerender placeholder missing");
  await writeFile(target, html.replace("<!--prerender-->", render()));
  console.log("Static HTML and assets ready in dist-static/");
} finally {
  await rm(ssrDir, { recursive: true, force: true });
}
