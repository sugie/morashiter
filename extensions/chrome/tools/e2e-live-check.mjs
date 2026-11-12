// Real published JSON and a real merchant page; no mocked HTTP responses.
import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { mkdir, mkdtemp, rm, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";
import { chromium } from "playwright";

const ext = fileURLToPath(new URL("../", import.meta.url));
const output = path.join(ext, "outputs", "live-publication");
await mkdir(output, { recursive: true });
const origin = "https://www.morashiter.com";
const checked = [];
async function jsonAt(relative) {
  const response = await fetch(`${origin}/${relative}`);
  assert.equal(response.status, 200, relative);
  assert.match(response.headers.get("content-type"), /application\/json/i);
  checked.push({ path: relative, status: response.status, cacheControl: response.headers.get("cache-control") });
  return response.json();
}
const meta = await jsonAt("api/v1/index-meta.json");
const release = await jsonAt("release.json");
assert.equal(meta.schema_version, "merchant-meta.v1");
assert.equal(meta.prefix_length, 2);
const buckets = {};
const prefixes = Array.from({ length: 256 }, (_, i) => i.toString(16).padStart(2, "0"));
for (let offset = 0; offset < prefixes.length; offset += 8) {
  await Promise.all(prefixes.slice(offset, offset + 8).map(async prefix => {
    const bucket = await jsonAt(`api/v1/buckets/${prefix}.json`);
    assert.equal(bucket.schema_version, "merchant-bucket.v1");
    assert.equal(bucket.prefix, prefix);
    assert.equal(bucket.version, meta.version);
    const used = new Set();
    for (const [domain, entry] of Object.entries(bucket.domains)) {
      assert.equal(createHash("sha256").update(domain).digest("hex").slice(0, 2), prefix);
      for (const host of entry.hosts) assert.ok(host === domain || host.endsWith(`.${domain}`));
      for (const id of entry.incident_ids) {
        assert.ok(release.incidentIds.includes(id));
        assert.equal(bucket.incidents[id].id, id);
        used.add(id);
      }
    }
    assert.deepEqual(Object.keys(bucket.incidents).sort(), [...used].sort());
    buckets[prefix] = bucket;
  }));
}
const host = "www.ginsara.jp";
const prefix = createHash("sha256").update("ginsara.jp").digest("hex").slice(0, 2);
assert.deepEqual(buckets[prefix].domains["ginsara.jp"].incident_ids, ["INC-007", "INC-016"]);
assert.ok(buckets[prefix].domains["ginsara.jp"].hosts.includes(host));
const profile = await mkdtemp(path.join(output, "profile-"));
const context = await chromium.launchPersistentContext(profile, {
  channel: "chromium", headless: true,
  args: [`--disable-extensions-except=${ext}`, `--load-extension=${ext}`],
});
const apiCalls = [];
context.on("request", request => {
  if (request.url().startsWith(`${origin}/api/`)) apiCalls.push({ url: request.url(), headers: request.headers() });
});
try {
  let [worker] = context.serviceWorkers();
  if (!worker) worker = await context.waitForEvent("serviceworker");
  const extensionId = new URL(worker.url()).host;
  const shop = await context.newPage();
  await shop.goto(`https://${host}/`, { waitUntil: "domcontentloaded", timeout: 60_000 });
  assert.equal(new URL(shop.url()).hostname, host);
  const tabId = await worker.evaluate(async url => (await chrome.tabs.query({})).find(tab => tab.url === url)?.id, shop.url());
  let state;
  for (let i = 0; i < 100; i++) {
    state = await worker.evaluate(async tabId => ({
      result: (await chrome.storage.session.get(`tab:${tabId}`))[`tab:${tabId}`],
      badge: await chrome.action.getBadgeText({ tabId }),
    }), tabId);
    if (state.result?.status === "found" && state.badge === "2") break;
    await new Promise(resolve => setTimeout(resolve, 200));
  }
  assert.equal(state.result?.status, "found", JSON.stringify(state));
  assert.equal(state.badge, "2");
  assert.equal(state.result.demo, null);
  const popup = await context.newPage();
  await popup.setViewportSize({ width: 400, height: 900 });
  await popup.goto(`chrome-extension://${extensionId}/popup/popup.html?tabId=${tabId}`);
  await popup.waitForFunction(() => document.getElementById("status").textContent === "情報あり");
  assert.equal(await popup.locator("#data-tag").textContent(), "公表情報");
  assert.deepEqual((await popup.locator(".source a").evaluateAll(links => links.map(link => link.href))).sort(), ["INC-007", "INC-016"].map(id => buckets[prefix].incidents[id].source).sort());
  await popup.screenshot({ path: path.join(output, "ginsara-popup.png"), fullPage: true });
  await shop.screenshot({ path: path.join(output, "ginsara-site.png"), fullPage: false });
  await popup.click("#recheck");
  await popup.waitForFunction(() => document.getElementById("status").textContent === "情報あり");
  assert.ok(apiCalls.length >= 2);
  assert.ok(apiCalls.every(call => /^https:\/\/www\.morashiter\.com\/api\/v1\/(index-meta\.json|buckets\/[0-9a-f]{2}\.json)$/.test(call.url)));
  assert.ok(apiCalls.every(call => !call.headers.cookie && !call.headers.referer));
  const report = { observedAt: new Date().toISOString(), browser: context.browser()?.version(), extensionVersion: "0.2.0", meta, release, checked, host, state, apiCalls };
  await writeFile(path.join(output, "verification.json"), JSON.stringify(report, null, 2));
  console.log(JSON.stringify({ checked: checked.length, version: meta.version, releaseId: release.releaseId, host, badge: state.badge, status: state.result.status, output }));
} finally {
  await context.close();
  await rm(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
}
