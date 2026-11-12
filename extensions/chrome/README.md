# Hosted judge demo (0.2.1)

Load this folder as an unpacked extension in desktop Chrome, pin Morashiter, and open [Aozora Goods](https://demo.morashiter.com/demo-shop). The badge shows **2** fictional notices. This exact hosted hostname is an alias of the local fictional shop; real websites continue to use the published data index. No new host permission or build step is required. See the [English judge walkthrough](../../docs/judge-guide.md) for popup translations and the payment flow.

# モラシッター Chrome 拡張 0.2.1

閲覧中の店舗について、公開された事故情報を件数バッジとポップアップで表示します。ページ本文・Cookieを読まず、ページも書き換えません。「登録情報なし」は事故がなかったことやサイトの安全性を意味しません。

## Windows Chromeへの読み込み

1. `chrome://extensions` を開き、デベロッパーモードをオンにします。
2. 旧版のモラシッターがあれば無効にします。
3. 「パッケージ化されていない拡張機能を読み込む」で、`manifest.json` があるフォルダを選びます。取得したリポジトリ内の `extensions/chrome` です。ZIPの場合は先に展開してください。
4. バージョン **0.2.1** を確認し、ツールバーにピン留めします。
5. 変更後は拡張カードの更新ボタンを押し、閲覧ページを再読み込みします。

追加ビルドやnpm installは不要です。Chrome Web Storeには公開していません。

## 公開データで使う前提

サーバー側PR #13のAPI形式に対応します。管理画面での通販ドメイン・会社・公開インシデントの関連付けと、以下のファイルの配信が必要です。

- `https://www.morashiter.com/api/v1/index-meta.json`
- `https://www.morashiter.com/api/v1/buckets/<ハッシュ先頭>.json`

**2026-11-10の確認ではmetaが404でした。** API反映前は実サイトの照合が「取得失敗」になります。固定の実在企業データによる代用はしません。

API配信後、登録済み店舗でバッジ → ポップアップ →「公式発表を開く」を確認してください。「再確認」はmetaとバケツを両方取り直します。APIには事故ごとの確認日がないので、その欄は「公表資料を参照」と表示します。

## API反映前にデモを試す

普通のWebページで拡張アイコンを押し、デモシナリオを選んで「このタブで表示」を押します。閲覧中の店舗とは無関係な架空デモであることを表示します。「デモを解除」で通常照合へ戻ります。

自動バッジの確認は、このフォルダで `node tools/serve-test-site.mjs` を実行して次を開きます。

| ページ | 期待結果 |
|---|---|
| `http://aozora-zakka.localhost:8765/` | 架空の事故2件、バッジ2 |
| `http://kumo-books.localhost:8765/` | 架空の事故1件、バッジ1 |
| `http://hoshi-zakka.localhost:8765/` | 登録情報なし |
| `http://shop.aozora-zakka.localhost:8765/` | 未登録ホスト、登録情報なし |
| `http://fail-check.localhost:8765/` | 取得失敗 |
| `chrome://extensions` | 対象外 |

## 通信・権限・保存

| 項目 | 内容 |
|---|---|
| `tabs` | URL変更・タブ切り替え時の自動照合。URL全体は保存しません |
| `storage` | タブ結果はsession、公開JSONキャッシュはlocal |
| ホスト権限 | `https://www.morashiter.com/*` のみ |
| 通信 | metaと、登録ドメインのSHA-256先頭2〜4文字に対応するJSONのみ |
| 送らないもの | 閲覧URL、完全なホスト名、パス、クエリ、Cookie、個人識別子 |
| 更新 | metaは6時間、バケツ・タブ結果は10分で次の照合時に更新。再確認時は直ちに取得 |
| 容量 | バケツキャッシュは最大16個・約4MiB。保存失敗時も取得結果を表示 |

ハッシュの先頭は閲覧先について部分的な情報を伝えます。匿名性の保証ではなく、CDNには通常のアクセス情報（IPなど）が届きます。完全なホスト照合はブラウザ内で行い、未登録の同じドメイン配下の店舗には情報を表示しません。

公開サフィックスはサーバーと同じ限定リストで、完全なPublic Suffix Listではありません。取得先はHTTPS固定、リダイレクトは拒否、10秒でタイムアウト、形式を検証します。画面は `textContent` を使い、出典リンクはHTTPSのみ許可します。

## テスト

Node.js 22以降で、このフォルダから `npm test` を実行します。追加パッケージは不要です。サフィックス一致テストは親プロジェクトの `services/mrst/resources/data/public_suffixes.txt` を参照します。

隔離したChromiumでの確認：

```powershell
npm install --no-save --package-lock=false playwright
$env:PLAYWRIGHT_BROWSERS_PATH = "$PWD\node_modules\.cache\ms-playwright"
npx playwright install chromium
node tools/e2e-remote-check.mjs
```

HTTP通信を架空fixtureで差し替え、実在店舗・本番APIには接続しません。画面は `outputs/remote-e2e` に保存します。通常の利用者用Chromeプロファイルは使いません。

2026-11-10時点：単体テスト64件、Windows上のChromiumで16項目成功。`node tools/e2e-live-check.mjs` では通信を差し替えず、公開meta・全256バケツ・release.jsonの258ファイルと実店舗 `www.ginsara.jp` でのバッジ2・公表情報・公式出典を確認済みです。結果と画面は `outputs/live-publication` にあります。2レコードは同一事案の第2報・第3報です。利用者の通常Chromeへの読み込みは手動で行ってください。

## English: install and privacy

Open `chrome://extensions`, enable Developer mode, choose **Load unpacked**, and select the folder containing `manifest.json`. Disable the previous demo extension and pin Morashiter. No build is required.

Morashiter shows published incidents for explicitly registered shopping hosts. An empty result does not establish safety or the absence of incidents. The extension does not scan pages or assess vulnerabilities.

Public JSON is fetched only from `https://www.morashiter.com`. The extension hashes the registrable domain locally and requests a bucket selected by the first 2–4 SHA-256 hex characters. Exact host matching happens locally. Browsing URLs, full hostnames, paths, queries, cookies and user identifiers are not sent. A hash prefix still reveals partial information; the CDN sees ordinary connection metadata such as IP addresses. This is not an anonymity guarantee.

Unavailable or malformed data is shown as a lookup failure. Fictional localhost shops and explicitly selected demo scenarios work offline; banners distinguish them from the current website. The public API was deployed on 2026-11-10. An isolated Chromium profile verified live published data on www.ginsara.jp. Loading the extension into your everyday Chrome profile remains a manual step.

PayPal Sandbox checkout and AI purchase guidance are separate project work and are not included in this extension.
