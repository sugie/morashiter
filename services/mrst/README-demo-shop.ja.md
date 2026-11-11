# Aozora Goods：ローカル環境でのSandboxデモ

[English](README-demo-shop.md)

この架空店舗では、既存のMorashiter Chrome拡張を変更せずに、資料に基づくAI購入前メモと、購入者自身が承認するPayPal Sandbox決済を組み合わせます。商品は架空のキャンバストートバッグ1点、価格は24.00米ドルです。商品の発送は行いません。AIが注文や支払承認を行うこともありません。

2026年11月11日（日本時間）、Chromeでローカルデモの動作を確認しました。さくらのAI Engineの `llm-jp-3.1-8x13b-instruct4` を実際に呼び出し、検証を通った事実2件のメモが出典リンク2件とともに表示されました。また、`.localhost` 上の公式PayPal v6 SDKを使ったSandbox決済では、購入者の承認と24.00米ドルの決済確定（capture）が完了し、ローカル注文IDとPayPal capture IDを含むレシートが表示されました。Sandboxでの取引であり、実際のお金は動いていません。これらの手動確認と、外部サービスを模擬応答に置き換える自動テストは区別しています。それ以前のEdgeでの試行では承認画面から先へ進まない挙動があり、未解決です。すべてのブラウザでの動作確認が済んだという意味ではありません。

## 独立したローカルデモを起動する

必要なものはDocker Compose、Git、Chromeです。実装ブランチを別の作業ディレクトリにチェックアウトし、そのリポジトリのルートで以下のコマンドを実行してください。この構成は専用のデータベースを持ち、ローカルPCからのみ接続できるようループバックアドレスにバインドします。クローラーやスケジューラーは起動しません。

1. `.env.demo-shop.example` を `.env.demo-shop` にコピーします。
2. `DEMO_APP_KEY` には、ランダムな32バイトをBase64でエンコードした値に `base64:` を付けて設定します。`DEMO_DB_PASSWORD` には別のランダムなパスワードを設定します。Git Bashでは、それぞれ `openssl rand -base64 32` と `openssl rand -hex 24` で生成できます。設定したファイルをGitにコミットしないでください。
3. 最初はAIを無効、PayPalの認証情報を空欄にしたまま、店舗と資料ページを確認できます。実際のデモでは、Sandboxアプリのclient ID・secretと、そのSandboxビジネスアカウントのmerchant IDを設定します。購入者には別のSandbox個人アカウントを使います。AIにはデモ専用のキー・モデルを用意し、`/chat/completions`、`response_format: json_object`、`temperature`、`max_tokens` に対応するOpenAI互換APIのHTTPSベースURLを設定します。

乱数生成のコマンドは、次の枠内の行だけをGit Bashで実行します。本文でコードを囲むバッククォート（`）は入力しないでください。Bashではバッククォートに囲んだ部分がコマンド置換になり、生成した文字列を別のコマンドとして実行しようとしてしまいます。

```bash
openssl rand -base64 32
openssl rand -hex 24
```

1行目の出力には `base64:` を付けて `DEMO_APP_KEY` へ、2行目の出力はそのまま `DEMO_DB_PASSWORD` へ設定します。すでに設定して起動できている場合、生成をやり直す必要はありません。

```bash
docker compose --env-file .env.demo-shop -f docker-compose.demo-shop.yml build app
docker compose --env-file .env.demo-shop -f docker-compose.demo-shop.yml up -d --wait db
docker compose --env-file .env.demo-shop -f docker-compose.demo-shop.yml run --rm app composer install --no-interaction --prefer-dist
docker compose --env-file .env.demo-shop -f docker-compose.demo-shop.yml run --rm app php artisan migrate --force
docker compose --env-file .env.demo-shop -f docker-compose.demo-shop.yml up -d app
```

Chromeで `http://aozora-zakka.localhost:8765/demo-shop` を開きます。このルートは、ほかのホスト名でのアクセスを意図的に拒否します。既存の拡張0.2.0は、これまで使っていたチェックアウトから、編集せずに読み込んでください。拡張に組み込まれた `aozora-zakka.localhost` の固定レコードは、この店舗の架空資料と対応しています。このドラフトの基点を新規にチェックアウトしただけでは、別途開発した拡張が含まれていない場合があります。その場合は、すでに動作確認した拡張のチェックアウトを使ってください。

設定を変更したら、上記と同じ `up -d app` コマンドでデモアプリのコンテナを再作成します。本番データベースや本番用の認証情報には接続しないでください。停止するには次を実行します。

```bash
docker compose --env-file .env.demo-shop -f docker-compose.demo-shop.yml down
```

この停止方法では、デモ用データベースのボリュームを保持します。決済結果が不明な間は、保存済みの注文を削除しないでください。CSSとJavaScriptは静的ファイルとして直接配信するため、このデモにはViteのビルド手順や追加のパッケージ依存はありません。

## レビュー・実演の手順

1. 架空店舗を開き、拡張のバッジと2件の架空資料を確認します。両方の資料ページを開きます。
2. **Create AI purchase memo**（AI購入前メモを作成）を押します。公表された事実、日付付きの出典リンク、不明点、一般的な注意事項を確認します。この操作をした後に初めて、サーバーが設定済みのAIサービスを呼び出します。
3. **Save for later**（判断を後回しにする）を選びます。ページに見送りのメッセージが表示され、決済リクエストは送信されません。ウィッシュリストを永続保存する機能ではありません。
4. PayPalを選びます。サーバーは固定価格と保存済みの操作キーを使って注文を作成します。PayPal SDKが購入者に内容の確認と承認を求めます。サーバー側でのcaptureを要求するのは、承認後のコールバックだけです。
5. 完了画面には、`USD 24.00`、ローカル注文ID、Sandbox管理画面と一致するPayPal capture IDが表示されることを確認します。`PENDING`（保留）や結果不明の場合は、成功として表示してはいけません。
6. キャンセルも試します。決済が途中で中断された場合は、同じページの **Check payment status**（決済状況を確認）を使います。結果不明の状態を回避するために、ブラウザのストレージやCookieを削除したり、別の注文を始めたりしないでください。

注文は現在のサーバーセッションに紐づきます。ブラウザの `sessionStorage` は、再読み込み後も元の注文作成キーとローカル注文を保持します。セッションを失うと注文へアクセスできなくなる場合があり、その場合はSandbox管理画面で人間が確認する必要があります。このドラフトにはWebhook、返金、商品発送・注文履行、公開ホストへのデプロイは含みません。失敗または完了した注文は、そのデモタブでは終了したままとなります。別の実演セッションを始めるのは、前の決済の最終結果を確認した後にしてください。

## AI出力の契約

初版では、モデルの出力を選択式に制限しています。モデルは、2件の架空資料について許可した英語の事実文と、不明点・注意事項の文を、そのまま選択して並べます。サーバーは各文と出典IDを検証します。自由記述の助言、安全性の保証、HTML、URL、決済指示は受け付けません。当初の自由要約の計画から意図的に範囲を狭めています。上記の実サービスでの手動確認は現在の架空資料を対象としたものであり、モデル・プロンプト・資料を変更した場合は再確認します。

プロンプトには、現在の許可済み資料だけから作ったJSONの出力例を示し、その後に明示的な生成指示と必要なオブジェクト構造を渡します。実接続で発生した入力データのオウム返しに対応し、無関係な例文がメモに混ざることを避けるためです。厳格な出力検証は変更しておらず、入力のオウム返しや許可されていない文は引き続き拒否します。修正版ではキャッシュを識別するプロンプトの版も更新しています。

実行時の商品・資料データは `resources/data/demo-shop/catalog.json`、固定テストデータは `tests/fixtures/demo-shop/scenario.json` にあります。架空レコードの内容が一致するように保ってください。生成に成功したメモは、入力・プロンプトの版・モデルをキーとして24時間キャッシュします。既定の上限は、失敗した試行も含めてAIサービスの呼び出しが1日50回（日本時間基準）、リクエストが1セッションにつき毎分3回です。永続化でき、ロックに対応するキャッシュを使ってください。ローカルのアプリ1台なら `file`、将来複数台で動かす場合は共有ストアを使います。この機能では、テスト以外で `array` キャッシュを使わないでください。秘密情報や外部サービスの生の応答は、ブラウザに返しません。

## 検証方法

リポジトリのルートで実行します。

```bash
# 既存の使い捨てPHP 8.5/PostgreSQLテスト環境を使用。デモDBや本番DBでは実行しない。
bash bin/mrst-ci-test.bash
node --test services/mrst/tests/Browser/demo-checkout.test.mjs
```

`bash bin/mrst-ci-test.bash` が成功すれば、`DemoShopPageTest`・`PurchaseMemoTest`・`DemoOrderTest` も検証済みです。追加でホストのPHPから実行する必要はありません。スクリプトは専用の `mrst_test` データベースを持つ隔離環境を作り、終了時に片付けます。リポジトリ直下の `php vendor/bin/phpunit ...` は、この環境での実行にはなりません。デモ用コンテナもテスト用データベースとは別なので、代わりにそこで実行しないでください。

任意で実行するブラウザテストには、インストール済みのPlaywrightが必要です。`PLAYWRIGHT_MODULE` にPlaywrightの `index.mjs` の絶対パスを設定します。インストール済みのChromeを使う場合は、必要に応じて `PLAYWRIGHT_CHANNEL=chrome` も設定します。公開client IDに空欄ではない仮の値を設定してローカルデモを起動し、`services/mrst` で次を実行します。

```bash
cd services/mrst
node tests/Browser/demo-shop.e2e.mjs
```

上の `cd` はリポジトリのルートから実行する場合です。すでに `services/mrst` にいる場合は不要です。`Cannot find module .../tests/Browser/demo-shop.e2e.mjs` は実行場所が違う場合、`Cannot find package 'playwright'` はPlaywrightの参照先が設定されていない場合に確認するエラーです。通常のChromeで店舗画面を手動確認するだけなら、この自動ブラウザテストの準備は不要です。

ブラウザテストはSDK・AI・注文APIへの通信を模擬応答に置き換え、それ以外の外部通信を遮断します。PC／スマホの表示、メモと出典、見送り、完了、再読み込み、AI障害、保留状態と照会による復旧、キャンセルを確認します。スクリーンショットはGit管理対象外の `storage/app/demo-browser-check` に保存します。これらのテストは、実際の外部サービスと接続できることを証明するものではありません。テスト用の模擬PayPalボタンや応答を、提出動画に使わないでください。

提出までに残っているのは、通常のChromeでの拡張・AI・決済を通した動画の収録、モデル・プロンプト・資料の変更に応じたAI出力の品質確認、実際の並行ワーカーと外部サービス成功後のDB保存失敗を再現する障害注入テスト、審査員のアクセス方法、英語動画、リポジトリの公開設定・ライセンス、提出物の準備です。Edgeで承認画面から先へ進まない問題も未解決です。マージと公開は人間が判断します。

参照：[PayPal SDK v6のセットアップ](https://developer.paypal.com/sdk/js/set-up)、[SDKのコールバック](https://developer.paypal.com/sdk/js/reference)、[Orders API](https://developer.paypal.com/api/orders/v2)、[実装計画](../../docs/plans/aozora-ai-paypal-demo-plan.html)。
