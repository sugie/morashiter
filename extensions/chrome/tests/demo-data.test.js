import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { demoRecords, demoFailingHosts, demoScenarios, RESPONSE_LABELS } from "../src/demo-data.js";

const allHosts = [...demoRecords.flatMap((r) => r.hosts), ...demoFailingHosts];

test("every built-in host is fictional (*.localhost, which only ever points at this computer)", () => {
  for (const host of allHosts) {
    assert.match(host, /^[a-z0-9-]+(\.[a-z0-9-]+)*\.localhost$/, host);
  }
  assert.equal(new Set(allHosts).size, allHosts.length, "no host is listed twice");
});

test("there are the four demo scenarios the popup offers", () => {
  assert.deepEqual(demoScenarios.map((s) => s.status).sort(), ["error", "found", "found", "not_found"]);
  const found = demoScenarios.filter((s) => s.status === "found").map((s) => s.incidents[0].response).sort();
  assert.deepEqual(found, ["対策を公表", "調査中"]);
  for (const s of demoScenarios) {
    assert.match(s.shopName, /（架空）$/, s.id);
    assert.ok(s.label, s.id);
  }
});

test("every incident uses a factual response label, a fictional source and valid dates", () => {
  const incidents = [...demoRecords.flatMap((r) => r.incidents), ...demoScenarios.flatMap((s) => s.incidents)];
  assert.ok(incidents.length >= 3);
  for (const i of incidents) {
    assert.ok(RESPONSE_LABELS.includes(i.response), i.response);
    assert.equal(i.source, "デモ資料（架空）");
    assert.match(i.publishedOn, /^\d{4}-\d{2}-\d{2}$/);
    assert.match(i.responseCheckedOn, /^\d{4}-\d{2}-\d{2}$/);
    assert.ok(i.responseCheckedOn >= i.publishedOn, i.summary);
    assert.match(i.summary, /架空/);
  }
});

test("no reassurance wording anywhere in the data or the popup", async () => {
  const files = ["src/demo-data.js", "popup/popup.html", "popup/popup.js"];
  for (const file of files) {
    const text = await readFile(new URL(`../${file}`, import.meta.url), "utf8");
    for (const word of ["安全です", "安心", "問題ありません", "対策済み"]) {
      assert.equal(text.includes(word), false, `${file} contains ${word}`);
    }
  }
});

test("the manifest permits only the public data host and no remote scripts", async () => {
  const manifest = JSON.parse(await readFile(new URL("../manifest.json", import.meta.url), "utf8"));
  assert.equal(manifest.manifest_version, 3);
  assert.deepEqual([...manifest.permissions].sort(), ["storage", "tabs"]);
  assert.deepEqual(manifest.host_permissions, ["https://www.morashiter.com/*"]);
  assert.match(manifest.content_security_policy.extension_pages, /script-src 'self'/);
  assert.equal(manifest.content_scripts, undefined);
  assert.equal(manifest.background.service_worker, "src/background.js");
  assert.equal(manifest.background.type, "module");
  assert.equal(manifest.action.default_popup, "popup/popup.html");
  for (const size of ["16", "32", "48", "128"]) {
    await readFile(new URL(`../${manifest.icons[size]}`, import.meta.url));
  }
});

test("monitor and fictional data do not perform network requests", async () => {
  for (const file of ["src/background.js", "src/monitor.js", "src/data-source.js", "src/demo-data.js", "popup/popup.html", "popup/popup.js"]) {
    const text = await readFile(new URL(`../${file}`, import.meta.url), "utf8");
    assert.equal(/\bfetch\(|XMLHttpRequest|WebSocket|https?:\/\/(?!\S*\.localhost)/.test(text), false, file);
  }
});
