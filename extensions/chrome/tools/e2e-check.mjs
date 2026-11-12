// Optional developer check (not part of the extension): loads this unpacked extension into
// Playwright Chromium and checks automatic lookup, per-tab badges, demo scenarios and a service
// worker restart against test-site/. Needs Playwright (npm i -D playwright) and Python 3.
// Usage: node tools/e2e-check.mjs <path-to-extensions/chrome> <screenshot-dir>
import { chromium } from "playwright";
import { spawn } from "node:child_process";
import { mkdtemp, mkdir } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";

const ext = process.argv[2];
const shots = process.argv[3];
await mkdir(shots, { recursive: true });
const port = 8765;
const server = spawn(process.platform === "win32" ? "py" : "python3", ["-I", "-m", "http.server", String(port), "--bind", "127.0.0.1", "--directory", path.join(ext, "test-site")], { stdio: "ignore" });
await new Promise((r) => setTimeout(r, 800));

const results = [];
const check = (name, ok, detail = "") => { results.push({ name, ok, detail }); console.log(`${ok ? "PASS" : "FAIL"} ${name}${detail ? " — " + detail : ""}`); };
const external = [];

const userDir = await mkdtemp(path.join(tmpdir(), "mrst-ext-"));
const context = await chromium.launchPersistentContext(userDir, {
  channel: "chromium",
  headless: true,
  args: [`--disable-extensions-except=${ext}`, `--load-extension=${ext}`],
});
context.on("request", (req) => {
  const u = new URL(req.url());
  if (!(u.hostname.endsWith(".localhost") || u.hostname === "localhost" || u.protocol === "chrome-extension:" || u.protocol === "chrome:" || u.protocol === "data:")) external.push(req.url());
});

try {
  let [worker] = context.serviceWorkers();
  if (!worker) worker = await context.waitForEvent("serviceworker");
  const extId = new URL(worker.url()).host;

  const tabIdOf = async (url) => worker.evaluate(async (u) => (await chrome.tabs.query({})).find((t) => t.url === u)?.id, url);
  const state = async (tabId) => worker.evaluate(async (id) => ({ badge: await chrome.action.getBadgeText({ tabId: id }), result: (await chrome.storage.session.get(`tab:${id}`))[`tab:${id}`] ?? null }), tabId);
  const settle = async (tabId, want) => {
    let first = null;
    for (let i = 0; i < 40; i++) {
      const s = await state(tabId);
      const done = s.result && s.result.status !== "checking" && (!want || s.result.host === want);
      const badgeOk = !done || s.result.status !== "found" || s.badge === String(s.result.incidents.length);
      if (done && first === null) first = i;
      if (done && badgeOk) { if (i !== first) console.log(`BADGE LATE by ${(i - first) * 100}ms for ${want}`); return s; }
      await new Promise((r) => setTimeout(r, 100));
    }
    console.log(`BADGE NEVER for ${want}`);
    return state(tabId);
  };
  const base = (h) => `http://${h}:${port}/`;

  const page1 = await context.newPage();
  await page1.goto(base("aozora-zakka.localhost"));
  const t1 = await tabIdOf(base("aozora-zakka.localhost"));
  let s = await settle(t1, "aozora-zakka.localhost");
  check("registered host is found automatically with badge 2", s.result?.status === "found" && s.badge === "2", JSON.stringify({ status: s.result?.status, badge: s.badge }));

  await page1.goto(base("hoshi-zakka.localhost"));
  s = await settle(t1, "hoshi-zakka.localhost");
  check("navigating to an unregistered host clears the badge (not_found)", s.result?.status === "not_found" && s.badge === "", JSON.stringify({ status: s.result?.status, badge: s.badge }));

  await page1.goto(base("shop.aozora-zakka.localhost"));
  s = await settle(t1, "shop.aozora-zakka.localhost");
  check("no partial match for an unlisted subdomain", s.result?.status === "not_found" && s.badge === "", s.result?.status);

  await page1.goto(base("fail-check.localhost"));
  s = await settle(t1, "fail-check.localhost");
  check("a lookup failure is shown as error, not not_found", s.result?.status === "error" && s.badge === "", s.result?.status);

  await page1.goto(base("kumo-books.localhost"));
  s = await settle(t1, "kumo-books.localhost");
  check("moving to another registered host replaces the result and badge", s.result?.status === "found" && s.badge === "1" && s.result.incidents.length === 1, JSON.stringify({ host: s.result?.host, badge: s.badge }));

  const page2 = await context.newPage();
  await page2.goto(base("aozora-zakka.localhost"));
  const t2 = await tabIdOf(base("aozora-zakka.localhost"));
  const s2 = await settle(t2, "aozora-zakka.localhost");
  const s1again = await state(t1);
  check("results and badges are kept per tab", s2.badge === "2" && s1again.badge === "1" && s1again.result.host === "kumo-books.localhost", JSON.stringify({ tab1: s1again.badge, tab2: s2.badge }));

  await page1.bringToFront();
  await page1.goto("chrome://version/").catch(() => {});
  const s3 = await settle(t1);
  check("a chrome:// page is unsupported and its old badge is cleared", s3.result?.status === "unsupported" && s3.badge === "", JSON.stringify({ status: s3.result?.status, badge: s3.badge }));

  // Popup UI, rendered for tab 2 (found) and as a demo.
  const popup = await context.newPage();
  await popup.setViewportSize({ width: 380, height: 760 });
  await popup.goto(`chrome-extension://${extId}/popup/popup.html?tabId=${t2}`);
  await popup.waitForFunction(() => document.getElementById("status").textContent === "情報あり");
  const texts = await popup.evaluate(() => ({ host: document.getElementById("host").textContent, status: document.getElementById("status").textContent, items: document.querySelectorAll("#incident-list li").length, tag: document.querySelector(".demo-tag").textContent }));
  check("popup shows the domain, status and two incidents with the demo label", texts.host === "aozora-zakka.localhost" && texts.items === 2 && texts.tag === "デモ版・架空データ", JSON.stringify(texts));
  await popup.screenshot({ path: path.join(shots, "popup-found.png"), fullPage: true });

  await popup.selectOption("#scenario", "investigating");
  await popup.click("#apply-demo");
  await popup.waitForFunction(() => !document.getElementById("demo-banner").hidden);
  const demoState = await state(t2);
  check("a demo scenario is shown for that tab with a fictional shop and badge", demoState.result.demo?.scenarioId === "investigating" && demoState.badge === "1", JSON.stringify({ demo: demoState.result.demo, badge: demoState.badge }));
  await popup.screenshot({ path: path.join(shots, "popup-demo-investigating.png"), fullPage: true });

  await page2.goto(base("hoshi-zakka.localhost"));
  const sDemoNav = await settle(t2, "hoshi-zakka.localhost");
  check("the demo stays on in that tab after navigating", sDemoNav.result.demo?.scenarioId === "investigating", JSON.stringify(sDemoNav.result.demo));

  await popup.click("#clear-demo");
  await popup.waitForFunction(() => document.getElementById("demo-banner").hidden && document.getElementById("status").textContent === "登録情報なし");
  check("clearing the demo returns to the real lookup", (await state(t2)).result.demo === null);
  await popup.screenshot({ path: path.join(shots, "popup-not-found.png"), fullPage: true });

  // Service worker restart: stop it, then check that stored results and new events still work.
  const cdp = await context.newCDPSession(page2);
  await cdp.send("ServiceWorker.enable");
  await cdp.send("ServiceWorker.stopAllWorkers");
  await new Promise((r) => setTimeout(r, 500));
  await page2.goto(base("aozora-zakka.localhost"));
  worker = context.serviceWorkers().find((w) => w.url().includes(extId)) ?? (await context.waitForEvent("serviceworker"));
  for (let i = 0; i < 50 && !(await worker.evaluate(() => true).catch(() => false)); i++) await new Promise((r) => setTimeout(r, 100));
  const afterRestart = await settle(t2, "aozora-zakka.localhost");
  check("after a service worker stop, a new page is checked again", afterRestart.result?.status === "found" && afterRestart.badge === "2", JSON.stringify({ status: afterRestart.result?.status, badge: afterRestart.badge }));

  check("no request left this computer", external.length === 0, external.join(", "));
} catch (error) {
  check("e2e run", false, String(error?.stack ?? error));
} finally {
  await context.close();
  server.kill();
}
const failed = results.filter((r) => !r.ok).length;
console.log(`\n${results.length - failed}/${results.length} passed`);
process.exit(failed ? 1 : 0);
