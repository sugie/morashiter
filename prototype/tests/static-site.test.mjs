import assert from "node:assert/strict";
import { cp, mkdtemp, readFile, readdir, rm, unlink, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import { after, before, describe, test } from "node:test";
import { fileURLToPath } from "node:url";
import { buildStatic } from "../scripts/build-static.mjs";
import { verifyStaticSite } from "../scripts/verify-static.mjs";

const work = await mkdtemp(path.join(tmpdir(), "morashiter-static-"));
const previewDir = path.join(work, "preview");
const indexableDir = path.join(work, "indexable");
const incidentIds = ["INC-001", "INC-002", "INC-003", "INC-004", "INC-005"];
const incidentNames = ["タイムズカー", "イープラス", "ASKUL・LOHACO", "KADOKAWAグループ", "G-BOOK・G-Link等"];

before(async () => {
  await buildStatic({ outDir: previewDir });
  await buildStatic({ outDir: indexableDir, indexable: true });
});
after(() => rm(work, { recursive: true, force: true }));

const read = (dir, file) => readFile(path.join(dir, file), "utf8");

async function listFiles(dir, prefix = "") {
  const out = [];
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const rel = prefix ? `${prefix}/${entry.name}` : entry.name;
    if (entry.isDirectory()) out.push(...await listFiles(path.join(dir, entry.name), rel));
    else out.push(rel);
  }
  return out.sort();
}

async function copyOfPreview(name) {
  const dir = path.join(work, name);
  await cp(previewDir, dir, { recursive: true });
  return dir;
}

describe("static build output", () => {
  test("passes the publish verification as built", async () => {
    assert.deepEqual(await verifyStaticSite(previewDir), []);
    assert.deepEqual(await verifyStaticSite(indexableDir, { indexable: true }), []);
  });

  test("ships only the allowlisted publish files", async () => {
    const files = await listFiles(previewDir);
    assert.deepEqual(files.filter(f => !f.startsWith("assets/")), ["favicon.svg", "index.html", "release.json", "robots.txt"]);
    for (const asset of files.filter(f => f.startsWith("assets/"))) assert.match(asset, /^assets\/[\w-]+\.(js|css)$/);
  });

  test("prerenders all five incidents so the list is readable before JavaScript runs", async () => {
    const html = await read(previewDir, "index.html");
    const root = html.slice(html.indexOf('<div id="root">')).replaceAll("<!-- -->", "");
    for (const name of incidentNames) assert.ok(root.includes(name), name);
    assert.match(root, /5件/);
  });

  test("offers official source links without JavaScript", async () => {
    const html = await read(previewDir, "index.html");
    const noscript = html.match(/<noscript>([\s\S]*?)<\/noscript>/)?.[1] ?? "";
    assert.match(noscript, /https:\/\/www\.park24\.co\.jp\/news\/2026\/09\/20260928-1\.html/);
    assert.match(noscript, /https:\/\/global\.toyota\/jp\/newsroom\/corporate\/39241571\.html/);
    assert.equal((noscript.match(/<a /g) ?? []).length, 5);
    assert.equal((noscript.match(/rel="noopener noreferrer"/g) ?? []).length, 5);
  });

  test("declares a no-exfiltration CSP and canonical URL in the HTML", async () => {
    const html = await read(previewDir, "index.html");
    const csp = html.match(/<meta http-equiv="Content-Security-Policy" content="([^"]+)"/)?.[1] ?? "";
    for (const directive of ["default-src 'none'", "script-src 'self'", "style-src 'self'", "img-src 'self'", "connect-src 'none'", "form-action 'none'", "base-uri 'none'", "object-src 'none'"]) {
      assert.ok(csp.includes(directive), directive);
    }
    assert.doesNotMatch(csp, /unsafe-inline|unsafe-eval/);
    assert.match(html, /<link rel="canonical" href="https:\/\/www\.morashiter\.com\/"/);
    assert.match(html, /<meta name="referrer" content="no-referrer"/);
    assert.match(html, /<link rel="icon" href="\/favicon\.svg" type="image\/svg\+xml"/);
    assert.doesNotMatch(html, /画面確認用/);
  });

  test("keeps preview builds out of search engines in both meta and robots.txt", async () => {
    assert.match(await read(previewDir, "index.html"), /<meta name="robots" content="noindex,nofollow"/);
    assert.equal(await read(previewDir, "robots.txt"), "User-agent: *\nDisallow: /\n");
  });

  test("allows indexing only when explicitly requested", async () => {
    assert.doesNotMatch(await read(indexableDir, "index.html"), /noindex/);
    assert.equal(await read(indexableDir, "robots.txt"), "User-agent: *\nAllow: /\n");
  });

  test("writes a non-secret release manifest tied to the snapshot", async () => {
    const release = JSON.parse(await read(previewDir, "release.json"));
    assert.deepEqual(Object.keys(release).sort(), ["dataAsOf", "incidentIds", "indexable", "releaseId"]);
    assert.match(release.releaseId, /^[0-9a-f]{12}$/);
    assert.deepEqual(release.incidentIds, incidentIds);
    assert.equal(release.dataAsOf, "2026-09-29");
    assert.equal(release.indexable, false);
  });

  test("derives the release id from content so identical inputs give identical releases", async () => {
    const again = path.join(work, "again");
    await buildStatic({ outDir: again });
    assert.equal(JSON.parse(await read(again, "release.json")).releaseId, JSON.parse(await read(previewDir, "release.json")).releaseId);
    assert.notEqual(JSON.parse(await read(indexableDir, "release.json")).releaseId, JSON.parse(await read(previewDir, "release.json")).releaseId);
  });
});

describe("publish verification rejects unsafe output", () => {
  test("a source map", async () => {
    const dir = await copyOfPreview("with-map");
    await writeFile(path.join(dir, "assets", "index.js.map"), "{}");
    assert.ok((await verifyStaticSite(dir)).some(e => e.includes("assets/index.js.map")));
  });

  test("an env file", async () => {
    const dir = await copyOfPreview("with-env");
    await writeFile(path.join(dir, ".env"), "SECRET=x");
    assert.ok((await verifyStaticSite(dir)).some(e => e.includes(".env")));
  });

  test("a localhost reference", async () => {
    const dir = await copyOfPreview("with-localhost");
    const file = path.join(dir, "index.html");
    await writeFile(file, (await readFile(file, "utf8")).replace("</body>", "<a href=\"http://localhost:3000/\">x</a></body>"));
    assert.ok((await verifyStaticSite(dir)).some(e => e.includes("localhost")));
  });

  test("a referenced asset that is missing", async () => {
    const dir = await copyOfPreview("missing-asset");
    const js = (await readdir(path.join(dir, "assets"))).find(f => f.endsWith(".js"));
    await unlink(path.join(dir, "assets", js));
    assert.ok((await verifyStaticSite(dir)).some(e => e.includes(js)));
  });

  test("robots.txt disagreeing with the meta robots tag", async () => {
    const dir = await copyOfPreview("robots-mismatch");
    await writeFile(path.join(dir, "robots.txt"), "User-agent: *\nAllow: /\n");
    assert.ok((await verifyStaticSite(dir)).some(e => e.includes("robots")));
  });

  test("a release manifest that does not match the page", async () => {
    const dir = await copyOfPreview("release-mismatch");
    await writeFile(path.join(dir, "release.json"), JSON.stringify({ releaseId: "000000000000", incidentIds: ["INC-001"], dataAsOf: "2026-09-29", indexable: false }));
    assert.ok((await verifyStaticSite(dir)).some(e => e.includes("release.json")));
  });

  test("an empty or missing directory", async () => {
    const errors = await verifyStaticSite(path.join(work, "does-not-exist"));
    assert.ok(errors.some(e => e.includes("index.html")));
  });
});

test("verification CLI is importable without side effects", () => {
  assert.equal(typeof verifyStaticSite, "function");
  assert.ok(fileURLToPath(import.meta.url));
});
