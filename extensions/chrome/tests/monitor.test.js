import { test } from "node:test";
import assert from "node:assert/strict";
import { createMonitor } from "../src/monitor.js";
import { createLocalDataSource } from "../src/data-source.js";
import { records, failingHosts } from "./fixtures.js";
import { createFakeChrome, countingSource } from "./fake-chrome.js";

const scenarios = [
  { id: "published", shopName: "デモ雑貨店（架空）", status: "found", incidents: [{ summary: "デモ事故A", publishedOn: "2026-09-01", response: "対策を公表", responseCheckedOn: "2026-10-01", source: "デモ資料（架空）" }] },
  { id: "unknown", shopName: "デモ書店（架空）", status: "not_found", incidents: [] },
  { id: "failure", shopName: "デモ家電店（架空）", status: "error", incidents: [] },
];

function setup() {
  const chrome = createFakeChrome();
  const source = countingSource(createLocalDataSource({ records, failingHosts }));
  const monitor = createMonitor({ api: chrome, dataSource: source, scenarios, now: () => new Date("2026-11-02T00:00:00Z") });
  return { chrome, source, monitor };
}

test("a registered host shows 'found' with the incident count on that tab's badge", async () => {
  const { chrome, monitor } = setup();
  const result = await monitor.handleUrl(1, "https://zakka.test.localhost/item");
  assert.equal(result.status, "found");
  assert.equal(result.host, "zakka.test.localhost");
  assert.equal(result.incidents.length, 2);
  assert.equal(chrome.badges.get(1), "2");
  assert.equal(result.checkedAt, "2026-11-02T00:00:00.000Z");
});

test("an unregistered host shows 'not_found' and no badge", async () => {
  const { chrome, monitor } = setup();
  const result = await monitor.handleUrl(1, "https://other.test.localhost/");
  assert.equal(result.status, "not_found");
  assert.equal(chrome.badges.get(1), "");
});

test("a lookup failure is 'error', never 'not_found', and shows no count", async () => {
  const { chrome, monitor } = setup();
  const result = await monitor.handleUrl(1, "https://broken.test.localhost/");
  assert.equal(result.status, "error");
  assert.equal(chrome.badges.get(1), "");
});

test("non-web pages are 'unsupported' and clear an old badge", async () => {
  const { chrome, monitor } = setup();
  await monitor.handleUrl(1, "https://zakka.test.localhost/");
  assert.equal(chrome.badges.get(1), "2");
  const result = await monitor.handleUrl(1, "chrome://extensions/");
  assert.equal(result.status, "unsupported");
  assert.equal(result.host, null);
  assert.equal(chrome.badges.get(1), "");
});

test("moving to another site replaces the previous result and badge", async () => {
  const { chrome, monitor } = setup();
  await monitor.handleUrl(1, "https://zakka.test.localhost/");
  const result = await monitor.handleUrl(1, "https://books.test.localhost/");
  assert.equal(result.host, "books.test.localhost");
  assert.equal(result.incidents.length, 1);
  assert.equal(chrome.badges.get(1), "1");
  const stored = await monitor.getResult(1);
  assert.equal(stored.host, "books.test.localhost");
});

test("results are kept per tab", async () => {
  const { chrome, monitor } = setup();
  await monitor.handleUrl(1, "https://zakka.test.localhost/");
  await monitor.handleUrl(2, "https://other.test.localhost/");
  assert.equal(chrome.badges.get(1), "2");
  assert.equal(chrome.badges.get(2), "");
  assert.equal((await monitor.getResult(1)).host, "zakka.test.localhost");
  assert.equal((await monitor.getResult(2)).status, "not_found");
});

test("repeated events for the same host do not look it up again", async () => {
  const { source, monitor } = setup();
  await monitor.handleUrl(1, "https://zakka.test.localhost/a");
  await monitor.handleUrl(1, "https://zakka.test.localhost/b");
  await monitor.handleUrl(1, "https://zakka.test.localhost/c#x");
  assert.deepEqual(source.calls, ["zakka.test.localhost"]);
});

test("overlapping events for the same host share one lookup", async () => {
  const { source, monitor } = setup();
  const release = source.hold();
  const a = monitor.handleUrl(1, "https://zakka.test.localhost/a");
  const b = monitor.handleUrl(1, "https://zakka.test.localhost/b");
  release();
  await Promise.all([a, b]);
  assert.deepEqual(source.calls, ["zakka.test.localhost"]);
});

test("a manual recheck looks the host up again", async () => {
  const { source, monitor } = setup();
  await monitor.handleUrl(1, "https://zakka.test.localhost/");
  await monitor.handleUrl(1, "https://zakka.test.localhost/", { force: true });
  assert.equal(source.calls.length, 2);
});

test("a failed lookup is retried on the next event instead of being cached", async () => {
  const { source, monitor } = setup();
  await monitor.handleUrl(1, "https://broken.test.localhost/");
  await monitor.handleUrl(1, "https://broken.test.localhost/");
  assert.equal(source.calls.length, 2);
});

test("a slow lookup for a page the tab has left does not overwrite the newer result", async () => {
  const { chrome, source, monitor } = setup();
  const release = source.hold();
  const slow = monitor.handleUrl(1, "https://zakka.test.localhost/");
  await new Promise((r) => setImmediate(r));
  release();
  const fast = monitor.handleUrl(1, "https://other.test.localhost/");
  await Promise.all([slow, fast]);
  assert.equal((await monitor.getResult(1)).host, "other.test.localhost");
  assert.equal(chrome.badges.get(1), "");
});

test("while a lookup is running the tab shows 'checking' without a stale badge", async () => {
  const { chrome, source, monitor } = setup();
  await monitor.handleUrl(1, "https://zakka.test.localhost/");
  const release = source.hold();
  const pending = monitor.handleUrl(1, "https://books.test.localhost/");
  await new Promise((r) => setImmediate(r));
  assert.equal((await monitor.getResult(1)).status, "checking");
  assert.equal(chrome.badges.get(1), "");
  release();
  await pending;
});

test("after a service worker restart the stored result is reused without a new lookup", async () => {
  const { chrome, source, monitor } = setup();
  await monitor.handleUrl(1, "https://zakka.test.localhost/");
  chrome.badges.clear();
  const restarted = createMonitor({ api: chrome, dataSource: source, scenarios, now: () => new Date("2026-11-02T01:00:00Z") });
  const result = await restarted.handleUrl(1, "https://zakka.test.localhost/");
  assert.equal(result.status, "found");
  assert.equal(source.calls.length, 1);
  assert.equal(chrome.badges.get(1), "2", "the badge is restored");
});

test("a result left in 'checking' by a stopped worker is looked up again", async () => {
  const { chrome, source } = setup();
  await chrome.storage.session.set({ "tab:1": { tabId: 1, host: "zakka.test.localhost", status: "checking", incidents: [], demo: null } });
  const restarted = createMonitor({ api: chrome, dataSource: source, scenarios, now: () => new Date() });
  const result = await restarted.handleUrl(1, "https://zakka.test.localhost/");
  assert.equal(result.status, "found");
  assert.equal(source.calls.length, 1);
});

test("a demo scenario applies to that tab only, survives navigation, and can be cleared", async () => {
  const { chrome, source, monitor } = setup();
  await monitor.handleUrl(1, "https://other.test.localhost/");
  await monitor.handleUrl(2, "https://other.test.localhost/");

  const demo = await monitor.setDemo(1, "published", "https://other.test.localhost/");
  assert.equal(demo.status, "found");
  assert.deepEqual(demo.demo, { scenarioId: "published", shopName: "デモ雑貨店（架空）" });
  assert.equal(demo.host, "other.test.localhost", "the browsing domain is still shown");
  assert.equal(chrome.badges.get(1), "1");
  assert.equal(chrome.badges.get(2), "", "another tab is unaffected");

  const moved = await monitor.handleUrl(1, "https://books.test.localhost/");
  assert.equal(moved.demo.scenarioId, "published", "the demo stays on while navigating in this tab");
  assert.equal(moved.host, "books.test.localhost");
  assert.equal(source.calls.includes("books.test.localhost"), false, "demo results never call the data source");

  const cleared = await monitor.clearDemo(1, "https://books.test.localhost/");
  assert.equal(cleared.demo, null);
  assert.equal(cleared.status, "found");
  assert.equal(chrome.badges.get(1), "1");
});

test("demo scenarios cover not_found and error without a count", async () => {
  const { chrome, monitor } = setup();
  assert.equal((await monitor.setDemo(1, "unknown", "https://other.test.localhost/")).status, "not_found");
  assert.equal(chrome.badges.get(1), "");
  assert.equal((await monitor.setDemo(1, "failure", "https://other.test.localhost/")).status, "error");
  assert.equal(chrome.badges.get(1), "");
});

test("a demo is not applied to unsupported pages", async () => {
  const { chrome, monitor } = setup();
  await monitor.setDemo(1, "published", "https://other.test.localhost/");
  const result = await monitor.handleUrl(1, "chrome://extensions/");
  assert.equal(result.status, "unsupported");
  assert.equal(chrome.badges.get(1), "");
});

test("an unknown scenario id is rejected", async () => {
  const { monitor } = setup();
  await assert.rejects(monitor.setDemo(1, "nope", "https://other.test.localhost/"));
});

test("closing a tab forgets its result and demo", async () => {
  const { chrome, monitor } = setup();
  await monitor.setDemo(1, "published", "https://other.test.localhost/");
  await monitor.forgetTab(1);
  assert.equal(chrome.store.has("tab:1"), false);
  assert.equal(chrome.store.has("demo:1"), false);
});

test("stored results keep the host only, not the full URL", async () => {
  const { chrome, monitor } = setup();
  await monitor.handleUrl(1, "https://zakka.test.localhost/private/path?token=secret");
  assert.equal(JSON.stringify([...chrome.store.values()]).includes("secret"), false);
  assert.equal(JSON.stringify([...chrome.store.values()]).includes("/private/path"), false);
});

test("manual recheck forwards force to the remote cache", async () => {
  const calls = [];
  const monitor = createMonitor({ api: createFakeChrome(), scenarios: [], dataSource: {
    async lookupHost(host, options) { calls.push(options); return { status: "not_found" }; },
  } });
  await monitor.handleUrl(1, "https://example.com", { force: true });
  assert.equal(calls[0].force, true);
});

test("remote tab results expire so updates and withdrawals appear without restarting Chrome", async () => {
  let time = Date.parse("2026-11-10T00:00:00Z");
  let count = 0;
  const monitor = createMonitor({ api: createFakeChrome(), scenarios: [], now: () => new Date(time), dataSource: {
    resultTtlMs: 600_000,
    async lookupHost() { count++; return { status: "not_found" }; },
  } });
  await monitor.handleUrl(1, "https://example.com");
  time += 599_999;
  await monitor.handleUrl(1, "https://example.com");
  assert.equal(count, 1);
  time++;
  await monitor.handleUrl(1, "https://example.com");
  assert.equal(count, 2);
});

test("a slow previous lookup for the same host cannot overwrite a forced recheck", async () => {
  let release;
  let count = 0;
  const old = new Promise(resolve => { release = resolve; });
  const monitor = createMonitor({ api: createFakeChrome(), scenarios: [], dataSource: {
    async lookupHost() { return ++count === 1 ? old : { status: "not_found" }; },
  } });
  const pending = monitor.handleUrl(1, "https://example.com");
  await new Promise(resolve => setImmediate(resolve));
  await monitor.handleUrl(1, "https://example.com", { force: true });
  release({ status: "found", kind: "published", incidents: [{ summary: "old" }] });
  await pending;
  assert.equal((await monitor.getResult(1)).status, "not_found");
});
