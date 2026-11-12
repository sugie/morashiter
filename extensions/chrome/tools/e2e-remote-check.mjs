// Isolated Chromium profile; all HTTP requests are intercepted with synthetic fixtures.
// Run from extensions/chrome: node tools/e2e-remote-check.mjs
import { chromium } from "playwright";
import assert from "node:assert/strict";
import { mkdir, mkdtemp, rm } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";
import { meta, bucket } from "../tests/remote-fixtures.js";

const ext = fileURLToPath(new URL("../", import.meta.url));
const output = path.join(ext, "outputs", "remote-e2e");
await mkdir(output, { recursive: true });
const profile = await mkdtemp(path.join(output, "profile-"));
const context = await chromium.launchPersistentContext(profile, {
  channel: "chromium", headless: true,
  args: [`--disable-extensions-except=${ext}`, `--load-extension=${ext}`],
});
context.setDefaultTimeout(10_000);
const apiCalls = [];
const unexpected = [];
const pageErrors = [];
let currentBucket = structuredClone(bucket);
let apiStatus = 200;
let passed = 0;
const check = (label, actual, expected) => { assert.deepEqual(actual, expected, label); passed++; console.log(`PASS ${label}`); };

try {
  await context.route(/^https?:/, async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin === "https://www.morashiter.com") {
      apiCalls.push({ url: request.url(), headers: await request.allHeaders() });
      assert.ok(/^\/api\/v1\/(index-meta\.json|buckets\/a3\.json)$/.test(url.pathname));
      await route.fulfill({ status: apiStatus, contentType: "application/json", body: JSON.stringify(url.pathname.endsWith("index-meta.json") ? meta : currentBucket) });
    } else if (["www.example.com", "shop.example.com", "aozora-zakka.localhost", "hoshi-zakka.localhost"].includes(url.hostname)) {
      await route.fulfill({ contentType: "text/html", body: "<!doctype html><html><head><title>Fictional test shop</title></head><body><h1>Test fixture shop (fictional)</h1></body></html>" });
    } else {
      unexpected.push(request.url());
      await route.abort();
    }
  });
  context.on("page", page => page.on("pageerror", error => pageErrors.push(error.message)));
  let [worker] = context.serviceWorkers();
  if (!worker) worker = await context.waitForEvent("serviceworker");
  const id = new URL(worker.url()).host;
  const state = tabId => worker.evaluate(async tabId => ({
    result: (await chrome.storage.session.get(`tab:${tabId}`))[`tab:${tabId}`],
    badge: await chrome.action.getBadgeText({ tabId }),
  }), tabId);
  async function settle(tabId, status, host) {
    for (let i = 0; i < 100; i++) {
      const value = await state(tabId);
      if (value.result?.status === status && (!host || value.result.host === host) &&
          value.badge === (status === "found" ? String(value.result.incidents.length) : "")) return value;
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error(`Did not settle: ${JSON.stringify(await state(tabId))}`);
  }
  async function tabIdOf(page) {
    return worker.evaluate(async url => (await chrome.tabs.query({})).find(tab => tab.url === url)?.id, page.url());
  }
  const shop = await context.newPage();
  await shop.goto("https://www.example.com/products/mug?private=DO_NOT_SEND");
  const shopId = await tabIdOf(shop);
  const found = await settle(shopId, "found", "www.example.com");
  check("automatic remote lookup sets badge", found.badge, "1");
  check("remote lookup uses two prefix-only API requests", apiCalls.map(c => c.url), [
    "https://www.morashiter.com/api/v1/index-meta.json", "https://www.morashiter.com/api/v1/buckets/a3.json",
  ]);

  const popup = await context.newPage();
  await popup.setViewportSize({ width: 400, height: 850 });
  await popup.goto(`chrome-extension://${id}/popup/popup.html?tabId=${shopId}`);
  await popup.waitForFunction(() => document.getElementById("status").textContent === "情報あり");
  check("popup renders published facts", await popup.locator("#data-tag").textContent(), "公表情報");
  check("popup has a usable official source link", await popup.locator(".source a").getAttribute("href"), "https://notice.example/notice");
  await popup.screenshot({ path: path.join(output, "published.png"), fullPage: true });

  currentBucket.incidents["INC-TEST"].status = "追加報を公表";
  await popup.click("#recheck");
  await popup.waitForFunction(() => document.querySelector(".response")?.textContent === "追加報を公表");
  check("manual refresh reads updated content with the same version", apiCalls.length, 4);

  const local = await context.newPage();
  await local.goto("http://aozora-zakka.localhost/");
  const localId = await tabIdOf(local);
  check("local demos keep their own badge", (await settle(localId, "found", "aozora-zakka.localhost")).badge, "2");
  check("another tab keeps its remote badge", (await state(shopId)).badge, "1");

  await shop.goto("https://shop.example.com/");
  await settle(shopId, "not_found", "shop.example.com");
  await popup.waitForFunction(() => document.getElementById("status").textContent === "登録情報なし");
  check("unlisted subdomain has no fictional tag", await popup.locator("#data-tag").isVisible(), false);
  await popup.screenshot({ path: path.join(output, "not-found.png"), fullPage: true });

  apiStatus = 404;
  await popup.click("#recheck");
  await popup.waitForFunction(() => document.getElementById("status").textContent === "取得失敗");
  check("API failure is not presented as no incidents", (await state(shopId)).badge, "");
  await popup.screenshot({ path: path.join(output, "error.png"), fullPage: true });

  await popup.selectOption("#scenario", "investigating");
  await popup.click("#apply-demo");
  await popup.waitForFunction(() => !document.getElementById("demo-banner").hidden);
  check("offline demo is visibly fictional", await popup.locator("#data-tag").textContent(), "デモ版・架空データ");
  apiStatus = 200;
  await popup.click("#clear-demo");
  await popup.waitForFunction(() => document.getElementById("demo-banner").hidden && document.getElementById("status").textContent === "登録情報なし");
  check("clearing the demo restores real lookup", (await state(shopId)).result.demo, null);

  const session = await context.newCDPSession(shop);
  await session.send("ServiceWorker.enable");
  await session.send("ServiceWorker.stopAllWorkers");
  await shop.goto("https://www.example.com/after-restart");
  await settle(shopId, "found", "www.example.com");
  check("service worker restart restores results", (await state(shopId)).badge, "1");

  await shop.goto("chrome://version/");
  await settle(shopId, "unsupported");
  check("browser pages clear the badge", (await state(shopId)).badge, "");
  check("API requests contain no browsing path or cookie", apiCalls.every(c => !JSON.stringify(c).includes("DO_NOT_SEND") && !c.headers.cookie && !c.headers.referer), true);
  check("no unexpected HTTP destination", unexpected, []);
  check("no popup JavaScript error", pageErrors, []);
  console.log(`${passed} browser checks passed. Screenshots: ${output}`);
} finally {
  await context.close();
  await rm(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
}
