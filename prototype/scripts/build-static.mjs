import { build } from "vite";
import { readFile, writeFile, rm } from "node:fs/promises";
import { pathToFileURL, fileURLToPath } from "node:url";
import path from "node:path";
import { computeReleaseId, robotsText } from "./verify-static.mjs";
import { dataModulePlugin, guardAgainstDrops, loadDefaultData, loadSnapshot } from "./snapshot.mjs";
import { writeMerchantIndex } from "./merchant-index.mjs";

const root = fileURLToPath(new URL("../", import.meta.url));
const configFile = path.join(root, "vite.static.config.ts");
let ssrCounter = 0;

/**
 * Build the public static site: client bundle, prerendered HTML, robots.txt and release.json.
 * Preview builds (the default) stay noindex; pass indexable only for the general public release.
 * snapshotPath: msgn's public snapshot (approved revisions); without it the prototype's hand-checked data is used.
 * previousRelease: the live release.json; an incident that vanished without a withdrawal stops the build.
 * @param {{ outDir?: string, indexable?: boolean, snapshotPath?: string, previousRelease?: {incidentIds: string[]} }} [options]
 */
export async function buildStatic({ outDir = path.join(root, "dist-static"), indexable = false, snapshotPath, previousRelease } = {}) {
  outDir = path.resolve(outDir);
  const data = snapshotPath ? await loadSnapshot(snapshotPath) : await loadDefaultData();
  guardAgainstDrops(previousRelease, data);
  const shared = { configFile, logLevel: "warn", root: path.join(root, "static"), plugins: [dataModulePlugin(data)] };
  await build({ ...shared, build: { outDir, emptyOutDir: true } });

  const ssrDir = path.join(root, `.static-ssr-${process.pid}-${ssrCounter++}`);
  try {
    await build({ ...shared, build: { ssr: path.join(root, "static/render.tsx"), outDir: ssrDir } });
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
    // The extension's bucketed lookup data (api/v1/...). Part of the release id like every other file.
    await writeMerchantIndex(outDir, data);

    const dataAsOf = incidents.map(i => i.date.replaceAll("/", "-")).sort().at(-1);
    const release = { releaseId: await computeReleaseId(outDir), incidentIds: incidents.map(i => i.id), dataAsOf, indexable };
    await writeFile(path.join(outDir, "release.json"), `${JSON.stringify(release, null, 2)}\n`);
    return release;
  } finally {
    await rm(ssrDir, { recursive: true, force: true });
  }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const option = name => process.argv.find(arg => arg.startsWith(`--${name}=`))?.slice(name.length + 3);
  const indexable = process.argv.includes("--indexable");
  const outDir = option("out") ?? path.join(root, "dist-static");
  const previousReleasePath = option("previous-release");
  const previousRelease = previousReleasePath ? JSON.parse(await readFile(previousReleasePath, "utf8")) : undefined;
  try {
    const release = await buildStatic({ outDir, indexable, snapshotPath: option("snapshot"), previousRelease });
    console.log(JSON.stringify({ ok: true, outDir, ...release }));
  } catch (error) {
    console.error(`build refused: ${error.message}`);
    process.exit(1);
  }
}
