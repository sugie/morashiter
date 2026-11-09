import { createHash } from "node:crypto";
import { readFile, readdir } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { loadSnapshot } from "./snapshot.mjs";
import { BUCKET_PATTERN, META_PATH, verifyMerchantIndex } from "./merchant-index.mjs";

const ALLOWED_TOP_LEVEL = new Set(["index.html", "robots.txt", "release.json", "favicon.svg"]);
const ASSET_PATTERN = /^assets\/[\w-]+\.(js|css)$/;
const FORBIDDEN_TEXT = [/localhost/i, /127\.0\.0\.1/, /sourceMappingURL/];
const REQUIRED_CSP = ["default-src 'none'", "connect-src 'none'", "form-action 'none'", "object-src 'none'", "base-uri 'none'"];

export const robotsText = (indexable) => `User-agent: *\n${indexable ? "Allow" : "Disallow"}: /\n`;

async function listFiles(dir, prefix = "") {
  const out = [];
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const rel = prefix ? `${prefix}/${entry.name}` : entry.name;
    if (entry.isDirectory()) out.push(...await listFiles(path.join(dir, entry.name), rel));
    else out.push(rel);
  }
  return out.sort();
}

/** Hash every published file except release.json, so the id changes whenever served content changes. */
export async function computeReleaseId(dir) {
  const hash = createHash("sha256");
  for (const file of await listFiles(dir)) {
    if (file === "release.json") continue;
    hash.update(file).update("\0").update(await readFile(path.join(dir, file))).update("\0");
  }
  return hash.digest("hex").slice(0, 12);
}

/**
 * Check a built site before upload. Returns a list of human-readable problems; empty means publishable.
 * @param {string} dir
 * expectedIncidentIds: the snapshot's incident codes; the page and release.json must list exactly these.
 * @param {{ indexable?: boolean, expectedIncidentIds?: string[] }} [options]
 * @returns {Promise<string[]>}
 */
export async function verifyStaticSite(dir, { indexable = false, expectedIncidentIds } = {}) {
  let files;
  try {
    files = await listFiles(dir);
  } catch {
    return [`index.html: output directory not found (${dir})`];
  }
  if (!files.includes("index.html")) return ["index.html: missing"];

  const errors = [];
  for (const file of files) {
    if (!ALLOWED_TOP_LEVEL.has(file) && !ASSET_PATTERN.test(file) && file !== META_PATH && !BUCKET_PATTERN.test(file)) errors.push(`unexpected file: ${file}`);
  }

  const text = {};
  for (const file of files.filter(f => /\.(html|js|css|txt|json|svg)$/.test(f))) {
    text[file] = await readFile(path.join(dir, file), "utf8");
    for (const pattern of FORBIDDEN_TEXT) {
      if (pattern.test(text[file])) errors.push(`${file}: contains forbidden reference ${pattern} (localhost / source map)`);
    }
  }

  const html = text["index.html"];
  for (const ref of html.matchAll(/(?:src|href)="\/(assets\/[^"]+)"/g)) {
    if (!files.includes(ref[1])) errors.push(`index.html: referenced asset missing: ${ref[1]}`);
  }

  const csp = html.match(/<meta http-equiv="Content-Security-Policy" content="([^"]+)"/)?.[1];
  if (!csp) errors.push("index.html: Content-Security-Policy meta missing");
  else {
    for (const directive of REQUIRED_CSP) if (!csp.includes(directive)) errors.push(`index.html: CSP lacks ${directive}`);
    if (/unsafe-inline|unsafe-eval/.test(csp)) errors.push("index.html: CSP allows unsafe sources");
  }

  if (!/<div id="root">\s*<div class="morashiter"/.test(html)) errors.push("index.html: incident list was not prerendered");

  const hasNoindex = /<meta name="robots" content="noindex,nofollow"/.test(html);
  if (hasNoindex === indexable) errors.push(`index.html: meta robots does not match indexable=${indexable}`);
  if (text["robots.txt"] !== robotsText(indexable)) errors.push(`robots.txt: does not match indexable=${indexable}`);

  const pageIds = [...html.matchAll(/data-incident-id="([^"]+)"/g)].map(m => m[1]);
  let release;
  try {
    release = JSON.parse(text["release.json"] ?? "");
  } catch {
    errors.push("release.json: missing or not JSON");
  }
  if (release) {
    if (JSON.stringify(release.incidentIds) !== JSON.stringify(pageIds)) errors.push("release.json: incidentIds do not match the page");
    if (release.indexable !== indexable) errors.push("release.json: indexable flag does not match");
    if (release.releaseId !== await computeReleaseId(dir)) errors.push("release.json: releaseId does not match the files");
  }

  errors.push(...verifyMerchantIndex(files, text, expectedIncidentIds));

  if (expectedIncidentIds) {
    if (JSON.stringify(pageIds) !== JSON.stringify(expectedIncidentIds)) errors.push("index.html: incident codes do not match the snapshot");
    const expected = new Set(expectedIncidentIds);
    for (const code of new Set(html.match(/INC-\d+/g) ?? [])) {
      if (!expected.has(code)) errors.push(`index.html: unexpected incident code ${code}`);
    }
  }

  return errors;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const args = process.argv.slice(2);
  const dir = path.resolve(args.find(a => !a.startsWith("--")) ?? fileURLToPath(new URL("../dist-static", import.meta.url)));
  const snapshotArg = args.find(a => a.startsWith("--snapshot="));
  const expectedIncidentIds = snapshotArg
    ? (await loadSnapshot(path.resolve(snapshotArg.slice("--snapshot=".length)))).incidents.map(i => i.id)
    : undefined;
  const errors = await verifyStaticSite(dir, { indexable: args.includes("--indexable"), expectedIncidentIds });
  if (errors.length > 0) {
    for (const error of errors) console.error(`NG ${error}`);
    process.exit(1);
  }
  console.log(`OK ${dir} is publishable`);
}
