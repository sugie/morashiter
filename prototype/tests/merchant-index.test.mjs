import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { cp, mkdtemp, readFile, readdir, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import { after, before, describe, test } from "node:test";
import { fileURLToPath } from "node:url";
import { buildStatic } from "../scripts/build-static.mjs";
import { verifyStaticSite } from "../scripts/verify-static.mjs";

// The extension hashes a host's registrable domain with SHA-256 and fetches only the bucket named by the
// first prefix_length hex characters, so the server never learns which site is being viewed.

const launchJson = fileURLToPath(new URL("../../services/mrst/database/seeders/data/launch-incidents.json", import.meta.url));
const launch = JSON.parse(await readFile(launchJson, "utf8"));
const work = await mkdtemp(path.join(tmpdir(), "morashiter-merchants-"));
const prefixOf = (domain, length) => createHash("sha256").update(domain).digest("hex").slice(0, length);

const merchants = {
  prefix_length: 2,
  domains: [
    { registrable_domain: "alpha-books.jp", host: "outlet.alpha-books.jp", company: "アルファ通販株式会社", incident_ids: ["INC-001", "INC-003"] },
    { registrable_domain: "alpha-shop.co.jp", host: "alpha-shop.co.jp", company: "アルファ通販株式会社", incident_ids: ["INC-001", "INC-003"] },
    { registrable_domain: "alpha-shop.co.jp", host: "www.alpha-shop.co.jp", company: "アルファ通販株式会社", incident_ids: ["INC-001", "INC-003"] },
  ],
};

async function writeSnapshot(name, extra = {}) {
  const file = path.join(work, `${name}.json`);
  const dates = launch.map(i => i.date.replaceAll("/", "-")).sort();
  await writeFile(file, JSON.stringify({ schema_version: "public-snapshot.v1", data_as_of: dates.at(-1), incidents: launch, withdrawals: [], ...extra }, null, 2));
  return file;
}

async function listFiles(dir, prefix = "") {
  const out = [];
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const rel = prefix ? `${prefix}/${entry.name}` : entry.name;
    if (entry.isDirectory()) out.push(...await listFiles(path.join(dir, entry.name), rel));
    else out.push(rel);
  }
  return out.sort();
}

const readJson = async (dir, rel) => JSON.parse(await readFile(path.join(dir, rel), "utf8"));
const withMerchants = path.join(work, "with-merchants");
const again = path.join(work, "again");
const without = path.join(work, "without");

before(async () => {
  const snapshot = await writeSnapshot("with-merchants", { merchants });
  await buildStatic({ outDir: withMerchants, snapshotPath: snapshot });
  await buildStatic({ outDir: again, snapshotPath: snapshot });
  await buildStatic({ outDir: without, snapshotPath: await writeSnapshot("without") });
});
after(() => rm(work, { recursive: true, force: true }));

describe("bucketed merchant index", () => {
  test("every bucket exists, named by the hex prefix, and the meta file describes them", async () => {
    const files = (await listFiles(withMerchants)).filter(f => f.startsWith("api/"));
    const buckets = files.filter(f => f.startsWith("api/v1/buckets/"));
    assert.equal(buckets.length, 256);
    assert.ok(buckets.every(f => /^api\/v1\/buckets\/[0-9a-f]{2}\.json$/.test(f)));
    assert.deepEqual(files.filter(f => !f.startsWith("api/v1/buckets/")), ["api/v1/index-meta.json"]);

    const meta = await readJson(withMerchants, "api/v1/index-meta.json");
    assert.deepEqual(Object.keys(meta), ["schema_version", "version", "data_as_of", "prefix_length", "hash", "key"]);
    assert.equal(meta.schema_version, "merchant-meta.v1");
    assert.equal(meta.prefix_length, 2);
    assert.equal(meta.hash, "sha256");
    assert.equal(meta.key, "registrable-domain");
    assert.match(meta.version, /^[0-9a-f]{12}$/);
  });

  test("a domain sits in the bucket of its own hash prefix with its hosts and incidents", async () => {
    const meta = await readJson(withMerchants, "api/v1/index-meta.json");
    const bucket = await readJson(withMerchants, `api/v1/buckets/${prefixOf("alpha-shop.co.jp", 2)}.json`);

    assert.equal(bucket.schema_version, "merchant-bucket.v1");
    assert.equal(bucket.version, meta.version);
    assert.equal(bucket.prefix, prefixOf("alpha-shop.co.jp", 2));
    assert.deepEqual(bucket.domains["alpha-shop.co.jp"], {
      hosts: ["alpha-shop.co.jp", "www.alpha-shop.co.jp"],
      company: "アルファ通販株式会社",
      incident_ids: ["INC-001", "INC-003"],
    });
    assert.deepEqual(Object.keys(bucket.incidents).sort(), ["INC-001", "INC-003"]);
    assert.deepEqual(bucket.incidents["INC-001"], launch.find(i => i.id === "INC-001"));
  });

  test("buckets with no domain are written empty, so a request never reveals a match", async () => {
    const used = new Set([prefixOf("alpha-shop.co.jp", 2), prefixOf("alpha-books.jp", 2)]);
    const empty = Array.from({ length: 256 }, (_, i) => i.toString(16).padStart(2, "0")).find(p => !used.has(p));
    const bucket = await readJson(withMerchants, `api/v1/buckets/${empty}.json`);

    assert.deepEqual(bucket.domains, {});
    assert.deepEqual(bucket.incidents, {});
  });

  test("the output is deterministic and passes verification", async () => {
    const files = await listFiles(withMerchants);
    assert.deepEqual(await listFiles(again), files);
    for (const file of files) {
      assert.ok((await readFile(path.join(withMerchants, file))).equals(await readFile(path.join(again, file))), file);
    }
    const release = await readJson(withMerchants, "release.json");
    assert.deepEqual(await verifyStaticSite(withMerchants, { expectedIncidentIds: release.incidentIds }), []);
  });

  test("a snapshot without merchants still gets every bucket, all empty", async () => {
    const buckets = (await listFiles(without)).filter(f => f.startsWith("api/v1/buckets/"));
    assert.equal(buckets.length, 256);
    for (const file of buckets) assert.deepEqual((await readJson(without, file)).domains, {}, file);
    assert.deepEqual(await verifyStaticSite(without, {}), []);
  });

  test("merchants that point at unknown incidents or malformed hosts are refused", async () => {
    const cases = [
      { prefix_length: 2, domains: [{ ...merchants.domains[0], incident_ids: ["INC-999"] }] },
      { prefix_length: 2, domains: [{ ...merchants.domains[0], host: "outlet.other-books.jp" }] },
      { prefix_length: 2, domains: [{ ...merchants.domains[0], host: "http://outlet.alpha-books.jp" }] },
      { prefix_length: 5, domains: [] },
    ];
    for (const [index, bad] of cases.entries()) {
      await assert.rejects(buildStatic({ outDir: path.join(work, `bad-${index}`), snapshotPath: await writeSnapshot(`bad-${index}`, { merchants: bad }) }), /merchant/i, String(index));
    }
  });

  test("verification catches a missing bucket, a bucket in the wrong place and an unexpected api file", async () => {
    const meta = await readJson(withMerchants, "api/v1/index-meta.json");
    const target = prefixOf("alpha-shop.co.jp", 2);
    const release = await readJson(withMerchants, "release.json");

    const missing = path.join(work, "tamper-missing");
    await cp(withMerchants, missing, { recursive: true });
    await rm(path.join(missing, "api/v1/buckets/00.json"));
    assert.ok((await verifyStaticSite(missing, { expectedIncidentIds: release.incidentIds })).some(e => /bucket/.test(e)));

    const moved = path.join(work, "tamper-moved");
    await cp(withMerchants, moved, { recursive: true });
    const wrong = target === "00" ? "01" : "00";
    const bucket = await readJson(moved, `api/v1/buckets/${target}.json`);
    await writeFile(path.join(moved, `api/v1/buckets/${wrong}.json`), JSON.stringify({ ...bucket, prefix: wrong, version: meta.version }));
    assert.ok((await verifyStaticSite(moved, { expectedIncidentIds: release.incidentIds })).some(e => /alpha-shop\.co\.jp/.test(e)));

    const extra = path.join(work, "tamper-extra");
    await cp(withMerchants, extra, { recursive: true });
    await writeFile(path.join(extra, "api/v1/everything.json"), "{}");
    assert.ok((await verifyStaticSite(extra, { expectedIncidentIds: release.incidentIds })).some(e => /unexpected file: api\/v1\/everything\.json/.test(e)));
  });
});
