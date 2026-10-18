import { build } from "vite";
import { readFile, writeFile, rm } from "node:fs/promises";
import { pathToFileURL, fileURLToPath } from "node:url";
import path from "node:path";
import { computeReleaseId, robotsText } from "./verify-static.mjs";

const root = fileURLToPath(new URL("../", import.meta.url));
const configFile = path.join(root, "vite.static.config.ts");
let ssrCounter = 0;

/**
 * Build the public static site: client bundle, prerendered HTML, robots.txt and release.json.
 * Preview builds (the default) stay noindex; pass indexable only for the general public release.
 * @param {{ outDir?: string, indexable?: boolean }} [options]
 */
export async function buildStatic({ outDir = path.join(root, "dist-static"), indexable = false } = {}) {
  outDir = path.resolve(outDir);
  const quiet = { logLevel: "warn" };
  await build({ configFile, ...quiet, root: path.join(root, "static"), build: { outDir, emptyOutDir: true } });

  const ssrDir = path.join(root, `.static-ssr-${process.pid}-${ssrCounter++}`);
  try {
    await build({ configFile, ...quiet, root: path.join(root, "static"), build: { ssr: path.join(root, "static/render.tsx"), outDir: ssrDir } });
    const { render, renderNoscript, incidents } = await import(pathToFileURL(path.join(ssrDir, "render.js")).href);

    const target = path.join(outDir, "index.html");
    let html = await readFile(target, "utf8");
    for (const placeholder of ["<!--prerender-->", "<!--noscript-->", "<!--robots-->"]) {
      if (!html.includes(placeholder)) throw new Error(`Placeholder ${placeholder} missing in static/index.html`);
    }
    html = html
      .replace("<!--prerender-->", render())
      .replace("<!--noscript-->", renderNoscript())
      .replace("<!--robots-->", indexable ? "" : '<meta name="robots" content="noindex,nofollow" />');
    await writeFile(target, html);
    await writeFile(path.join(outDir, "robots.txt"), robotsText(indexable));

    const dataAsOf = incidents.map(i => i.date.replaceAll("/", "-")).sort().at(-1);
    const release = { releaseId: await computeReleaseId(outDir), incidentIds: incidents.map(i => i.id), dataAsOf, indexable };
    await writeFile(path.join(outDir, "release.json"), `${JSON.stringify(release, null, 2)}\n`);
    return release;
  } finally {
    await rm(ssrDir, { recursive: true, force: true });
  }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const indexable = process.argv.includes("--indexable");
  const release = await buildStatic({ indexable });
  console.log(`Static site ready in dist-static/ (release ${release.releaseId}, ${indexable ? "indexable" : "noindex preview"})`);
}
