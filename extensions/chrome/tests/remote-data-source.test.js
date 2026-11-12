import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { createRemoteDataSource } from "../src/remote-data-source.js";
import { registrableDomain, PUBLIC_SUFFIXES } from "../src/public-suffixes.js";
import { createDataSource } from "../src/data-source.js";
import { createMonitor } from "../src/monitor.js";
import { createFakeChrome } from "./fake-chrome.js";
import { apiFixture, memoryStorage } from "./remote-fixtures.js";

function setup() {
  const fixture = apiFixture();
  const storage = memoryStorage();
  let time = Date.parse("2026-11-10T00:00:00Z");
  const options = { fetcher: fixture.fetcher, storage, now: () => time };
  return { ...fixture, storage, options, source: createRemoteDataSource(options), advance: (ms) => { time += ms; } };
}

test("suffix rules are identical to the server's curated rules", async () => {
  // Resolve from tests/ to the repository root without depending on the current directory.
  const rules = await readFile(new URL("../../../services/mrst/resources/data/public_suffixes.txt", import.meta.url), "utf8");
  assert.deepEqual(PUBLIC_SUFFIXES, rules.split(/\r?\n/).map(s => s.trim()).filter(s => s && !s.startsWith("#")));
});

test("registrable domains match server semantics including wildcard and IDN", () => {
  for (const [host, expected] of [
    ["WWW.Example.COM.", "example.com"], ["ir.alpha.co.jp", "alpha.co.jp"],
    ["www.pref.fukuoka.lg.jp", "pref.fukuoka.lg.jp"], ["www.city.kamogawa.lg.jp", "city.kamogawa.lg.jp"],
    ["www.shop.co.uk", "shop.co.uk"], ["www.例え.jp", "xn--r8jz45g.jp"],
    ["co.jp", null], ["localhost", null], ["192.168.1.1", null], ["[::1]", null],
    ["a.localhost", null], ["printer.local", null], ["https://example.com/path", null],
  ]) assert.equal(registrableDomain(host), expected, host);
});

test("fetches only metadata and a SHA-256 prefix, then matches the exact host", async () => {
  const s = setup();
  const result = await s.source.lookupHost("www.example.com");
  assert.equal(result.status, "found");
  assert.equal(result.kind, "published");
  assert.equal(result.shopName, "テスト商店（架空）");
  assert.equal(result.incidents[0].publishedOn, "2026-11-09");
  assert.equal(result.incidents[0].response, "調査を継続");
  assert.equal(result.incidents[0].sourceUrl, "https://notice.example/notice");
  assert.equal(result.incidents[0].responseCheckedOn, "公表資料を参照");
  assert.deepEqual(s.calls.map(c => c.url), [
    "https://www.morashiter.com/api/v1/index-meta.json",
    "https://www.morashiter.com/api/v1/buckets/a3.json",
  ]);
  for (const { options } of s.calls) {
    assert.equal(options.credentials, "omit");
    assert.equal(options.referrerPolicy, "no-referrer");
    assert.equal(options.redirect, "error");
    assert.equal(options.cache, "no-store");
    assert.equal(options.body, undefined);
  }
});

test("unlisted sibling and parent hosts are not found; empty buckets are valid", async () => {
  const s = setup();
  for (const host of ["example.com", "shop.example.com"]) assert.equal((await s.source.lookupHost(host)).status, "not_found");
  s.state.bucket.domains = {};
  s.state.bucket.incidents = {};
  assert.equal((await s.source.lookupHost("www.example.com", { force: true })).status, "not_found");
});

test("invalid and local hosts make no public requests", async () => {
  const s = setup();
  for (const host of ["127.0.0.1", "[::1]", "printer", "printer.local", "test.localhost", "co.jp", "x.com/secret"]) {
    assert.equal((await s.source.lookupHost(host)).status, "not_found");
  }
  assert.equal(s.calls.length, 0);
});

test("cached buckets survive worker restart and concurrent tabs share fetches", async () => {
  const s = setup();
  await Promise.all([s.source.lookupHost("www.example.com"), s.source.lookupHost("example.com")]);
  assert.equal(s.calls.length, 2);
  await createRemoteDataSource(s.options).lookupHost("www.example.com");
  assert.equal(s.calls.length, 2);
});

test("manual recheck refreshes meta and data even if server version is unchanged", async () => {
  const s = setup();
  await s.source.lookupHost("www.example.com");
  s.state.bucket.incidents["INC-TEST"].status = "追加報を公表";
  const result = await s.source.lookupHost("www.example.com", { force: true });
  assert.equal(result.incidents[0].response, "追加報を公表");
  assert.equal(s.calls.length, 4);
});

test("bucket expires at ten minutes; metadata expires at six hours", async () => {
  const s = setup();
  await s.source.lookupHost("www.example.com");
  s.advance(600_000);
  s.state.bucket.incidents["INC-TEST"].status = "更新";
  assert.equal((await s.source.lookupHost("www.example.com")).incidents[0].response, "更新");
  assert.equal(s.calls.length, 3);
  s.advance(21_600_000);
  await s.source.lookupHost("www.example.com");
  assert.equal(s.calls.length, 5);
});

test("prefix lengths 3 and 4 and a new version invalidate old buckets", async () => {
  for (const [length, prefix] of [[3, "a37"], [4, "a379"]]) {
    const s = setup();
    await s.source.lookupHost("www.example.com");
    Object.assign(s.state.meta, { prefix_length: length, version: "bbbbbbbbbbbb" });
    Object.assign(s.state.bucket, { prefix, version: "bbbbbbbbbbbb" });
    assert.equal((await s.source.lookupHost("www.example.com", { force: true })).status, "found");
    assert.ok(s.calls.at(-1).url.endsWith(`/${prefix}.json`));
  }
});

test("a deployment after metadata was cached recovers by refreshing metadata once", async () => {
  const s = setup();
  await s.source.lookupHost("www.example.com");
  s.advance(600_000);
  s.state.meta.version = "cccccccccccc";
  s.state.bucket.version = "cccccccccccc";
  s.state.bucket.domains = {};
  s.state.bucket.incidents = {};
  assert.equal((await s.source.lookupHost("www.example.com")).status, "not_found");
  assert.equal(s.calls.length, 5);
});

test("a broken persistent cache does not stop a fresh public lookup", async () => {
  const s = setup();
  const source = createRemoteDataSource({ fetcher: s.fetcher, storage: {
    async get() { throw new Error("storage unavailable"); },
    async set() { throw new Error("quota exceeded"); },
  } });
  assert.equal((await source.lookupHost("www.example.com")).status, "found");
});

test("oversized responses and redirects are errors", async () => {
  for (const fetcher of [
    async () => new Response(JSON.stringify({ padding: "x".repeat(1_100_000) }), { headers: { "content-type": "application/json" } }),
    async () => ({ ok: true, redirected: true, headers: new Headers({ "content-type": "application/json" }), text: async () => "{}" }),
  ]) await assert.rejects(createRemoteDataSource({ fetcher }).lookupHost("www.example.com"));
});

for (const [name, mutate] of [
  ["unknown metadata schema", s => { s.meta.schema_version = "bad"; }],
  ["invalid prefix length", s => { s.meta.prefix_length = 1; }],
  ["unsupported hash", s => { s.meta.hash = "sha1"; }],
  ["bucket version mismatch", s => { s.bucket.version = "bbbbbbbbbbbb"; }],
  ["wrong bucket prefix", s => { s.bucket.prefix = "ff"; }],
  ["missing domains", s => { delete s.bucket.domains; }],
  ["missing referenced incident", s => { s.bucket.incidents = {}; }],
  ["unsafe source URL", s => { s.bucket.incidents["INC-TEST"].source = "javascript:alert(1)"; }],
  ["invalid incident date", s => { s.bucket.incidents["INC-TEST"].date = "2026/99/99"; }],
  ["cross-domain host", s => { s.bucket.domains["example.com"].hosts.push("other.example"); }],
  ["HTTP failure", s => { s.status = 503; }],
]) test(`${name} is an error, never not_found`, async () => {
  const s = setup(); mutate(s.state);
  const chrome = createFakeChrome();
  const monitor = createMonitor({ api: chrome, dataSource: s.source, scenarios: [] });
  assert.equal((await monitor.handleUrl(1, "https://www.example.com/private?token=secret")).status, "error");
  assert.equal(chrome.badges.get(1), "");
  assert.equal(JSON.stringify(s.calls).includes("secret"), false);
});

test("invalid JSON and timeouts reject including a stalled response body", async () => {
  const invalid = createRemoteDataSource({ storage: memoryStorage(), fetcher: async () => new Response("<html>") });
  await assert.rejects(invalid.lookupHost("www.example.com"));
  for (const fetcher of [() => new Promise(() => {}), async () => ({ ok: true, text: () => new Promise(() => {}), headers: new Headers({ "content-type": "application/json" }) })]) {
    const source = createRemoteDataSource({ storage: memoryStorage(), fetcher, timeoutMs: 10 });
    await assert.rejects(source.lookupHost("www.example.com"), /timeout/);
  }
});

test("demo routing stays local and real hosts cannot fall back to hardcoded incidents", async () => {
  const calls = [];
  const remote = { async lookupHost(host, options) { calls.push({ host, options }); throw new Error("offline"); } };
  const source = createDataSource({ remote });
  assert.equal((await source.lookupHost("aozora-zakka.localhost")).incidents.length, 2);
  assert.equal((await source.lookupHost("new.localhost")).status, "not_found");
  assert.equal(calls.length, 0);
  await assert.rejects(source.lookupHost("www.club-t.com", { force: true }));
  assert.deepEqual(calls, [{ host: "www.club-t.com", options: { force: true } }]);
});

test("the hosted judge demo gets exactly the fictional Aozora notices without a remote lookup", async () => {
  const calls = [];
  const source = createDataSource({ remote: { async lookupHost(host) {
    calls.push(host); return { status: "not_found" };
  } } });
  const result = await source.lookupHost("demo.morashiter.com");
  assert.equal(result.status, "found");
  assert.equal(result.kind, "demo");
  assert.equal(result.recordId, "DEMO-001");
  assert.equal(result.incidents.length, 2);
  assert.deepEqual(calls, []);
  for (const host of ["www.morashiter.com", "shop.demo.morashiter.com", "demo.morashiter.com.evil.test"]) {
    assert.equal((await source.lookupHost(host)).status, "not_found");
  }
  assert.deepEqual(calls, ["www.morashiter.com", "shop.demo.morashiter.com", "demo.morashiter.com.evil.test"]);
});
