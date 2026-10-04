import assert from "node:assert/strict";
import { access, readFile } from "node:fs/promises";
import test from "node:test";

const projectRoot = new URL("../", import.meta.url);

async function render(path = "/", origin = "http://localhost") {
  const workerUrl = new URL("../dist/server/index.js", import.meta.url);
  workerUrl.searchParams.set("test", `${process.pid}-${Date.now()}`);
  const { default: worker } = await import(workerUrl.href);

  return worker.fetch(
    new Request(`${origin}${path}`, {
      headers: { accept: "text/html", host: new URL(origin).host },
    }),
    {
      ASSETS: {
        fetch: async () => new Response("Not found", { status: 404 }),
      },
    },
    {
      waitUntil() {},
      passThroughOnException() {},
    },
  );
}

async function requestProduction(path = "/", init = {}) {
  const workerUrl = new URL("../dist/server/index.js", import.meta.url);
  workerUrl.searchParams.set("security", `${process.pid}-${Date.now()}-${Math.random()}`);
  const { default: worker } = await import(workerUrl.href);

  return worker.fetch(
    new Request(`https://rightsrelay.example${path}`, init),
    {
      ASSETS: { fetch: async () => new Response("Not found", { status: 404 }) },
    },
    { waitUntil() {}, passThroughOnException() {} },
  );
}

test("server-renders the retained RightsRelay prototype", async () => {
  const response = await render("/legacy");
  assert.equal(response.status, 200);
  assert.match(response.headers.get("content-type") ?? "", /^text\/html\b/i);

  const html = await response.text();
  assert.match(html, /<title>RightsRelay \| 小さな被害を、動かせる力に。<\/title>/i);
  assert.match(html, /起きた事件を/);
  assert.match(html, /インシデント一覧/);
  assert.match(html, /タイムズカー/);
  assert.match(html, /イープラス/);
  assert.match(html, /Gyazo/);
  assert.match(html, /ムラウチドットコム/);
  assert.match(html, /支援希望者数・正式参加者数ではありません/);
  assert.match(html, /対象の可能性を確認/);
  assert.match(html, /氏名・会員番号・免許証画像/);
  assert.match(html, /法律相談・受任・訴訟参加の受付は行っていません/);
  assert.match(html, /約660万件/);
  assert.match(html, /約160万件/);
  assert.match(html, /この試作を、/);
  assert.match(html, /識別子なしの検証結果を書き出す/);
  assert.doesNotMatch(html, /匿名集計|匿名の検証結果/);
  assert.match(html, /開始するまで計測せず/);
  assert.doesNotMatch(html, /codex-preview|Building your site|Starter Project/i);
});

test("server-renders the support-body local aggregation route", async () => {
  const response = await render("/support-review");
  assert.equal(response.status, 200);
  const html = await response.text();

  assert.match(html, /<title>支援主体向けレビュー \| RightsRelay<\/title>/i);
  assert.match(html, /原本を集める前に/);
  assert.match(html, /共有パケットを読み込む/);
  assert.match(html, /架空サンプル5件で試す/);
  assert.match(html, /支援主体評価を開始する/);
  assert.match(html, /識別子なしの評価結果を書き出す/);
  assert.match(html, /組織名、氏名、自由記述は取得しません/);
  assert.match(html, /正式受付ではない/);
  assert.match(html, /証拠原本をRightsRelayへ渡す必要がある段階/);
  assert.doesNotMatch(html, /property="og:image"/i);
  assert.doesNotMatch(html, /name="twitter:image"/i);
});

test("keeps usability research separate from incident answers", async () => {
  const source = await readFile(new URL("app/rights-relay-app.tsx", projectRoot), "utf8");

  assert.match(source, /RESEARCH_STORAGE_KEY = "rightsrelay-research-v1"/);
  assert.match(source, /excluded:\s*\[/);
  assert.match(source, /対象確認の回答内容/);
  assert.match(source, /端末内メモ/);
  assert.doesNotMatch(source, /payload\.answers\s*=|payload\.localNote\s*=/);
});

test("keeps support evaluation separate from organizations and case data", async () => {
  const source = await readFile(new URL("app/support-review/support-evaluation.tsx", projectRoot), "utf8");

  assert.match(source, /context: "fictional-five-packet-sample"/);
  assert.match(source, /schema: "rightsrelay\.support-evaluation\.v2"/);
  assert.match(source, /originalEvidenceTiming/);
  assert.match(source, /"所属組織名"/);
  assert.match(source, /"自由記述"/);
  assert.match(source, /"読み込んだ共有パケット"/);
  assert.doesNotMatch(source, /localStorage|sessionStorage|organizationName|contactEmail|freeText/);
});

test("ships product metadata and no starter preview assets", async () => {
  const response = await render();
  const html = await response.text();
  const packageJson = await readFile(new URL("package.json", projectRoot), "utf8");

  assert.match(html, /property="og:title" content="Morashiter \| 企業インシデント公表情報一覧"/i);
  assert.doesNotMatch(html, /property="og:image"/i);
  assert.match(html, /name="twitter:card" content="summary_large_image"/i);
  assert.doesNotMatch(packageJson, /react-loading-skeleton/);
  await access(new URL("public/og.png", projectRoot));
  await assert.rejects(access(new URL("app/_sites-preview/SkeletonPreview.tsx", projectRoot)));
});

test("enforces a no-exfiltration browser policy in production", async () => {
  const response = await requestProduction();
  const csp = response.headers.get("content-security-policy") ?? "";

  assert.equal(response.status, 200);
  assert.match(csp, /connect-src 'none'/);
  assert.match(csp, /form-action 'none'/);
  assert.match(csp, /frame-ancestors 'none'/);
  assert.match(csp, /object-src 'none'/);
  assert.match(csp, /upgrade-insecure-requests/);
  assert.equal(response.headers.get("referrer-policy"), "no-referrer");
  assert.equal(response.headers.get("x-content-type-options"), "nosniff");
  assert.equal(response.headers.get("x-frame-options"), "DENY");
  assert.equal(response.headers.get("cache-control"), "no-store");
});

test("rejects network submissions", async () => {
  const response = await requestProduction("/", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ personalData: "must not be accepted" }),
  });

  assert.equal(response.status, 405);
  assert.equal(response.headers.get("allow"), "GET, HEAD");
  assert.match(await response.text(), /does not accept network submissions/);
});

test("client routes contain no outbound network primitive", async () => {
  const clientSources = await Promise.all([
    readFile(new URL("app/rights-relay-app.tsx", projectRoot), "utf8"),
    readFile(new URL("app/morashiter-app.tsx", projectRoot), "utf8"),
    readFile(new URL("app/support-review/support-review-app.tsx", projectRoot), "utf8"),
    readFile(new URL("app/support-review/support-evaluation.tsx", projectRoot), "utf8"),
  ]);
  const combined = clientSources.join("\n");

  assert.doesNotMatch(combined, /\bfetch\s*\(/);
  assert.doesNotMatch(combined, /\bXMLHttpRequest\b/);
  assert.doesNotMatch(combined, /\bsendBeacon\b/);
  assert.doesNotMatch(combined, /\bWebSocket\b/);
});

 test("server-renders the Excel incident list", async () => {
 const response = await render();
 const html = await response.text();
 assert.equal(response.status, 200);
 for (const label of ["Morashiter", "インシデント一覧", "ASKUL・LOHACO", "KADOKAWAグループ", "G-BOOK・G-Link", "約660万アカウント", "資料時点の状況", "未算定"]) assert.ok(html.includes(label), label);
 assert.doesNotMatch(html, /Gyazo|ムラウチドットコム/);
 });
