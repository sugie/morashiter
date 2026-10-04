# インシデント収集・AI解析の初期構成

## 採用方針

初期版はLaravelのキューとArtisanコマンドで実装する。PythonとBeautifulSoupは最初から常駐させない。

理由は次のとおり。

- 収集対象、実行履歴、再試行、公開前レビューを同じPostgreSQLトランザクションと運用監視に置ける
- FrankenPHPコンテナと同じコードをキューワーカーとして動かせる
- DOMDocumentで公式発表のHTML本文抽出には十分対応できる
- PHPとPythonの二つのデプロイ、依存更新、ログ、障害対応を初期段階から抱えずに済む

BeautifulSoupを使うPythonワーカーは、次の要件が実測で必要になった場合に追加する。

- JavaScriptで描画されたページをPlaywrightで取得する
- PDF、画像、OCRなどHTML以外の解析を行う
- 日本語本文抽出の精度がDOMDocumentでは足りず、Python固有の解析ライブラリが必要になる

## 処理の境界

```text
登録済みの公式URL
        │
        ▼
Laravelキューワーカー
  URL検証・DNS検証・取得上限・リダイレクト再検証
        │
        ▼
DOMDocumentによる決定論的な本文抽出
        │
        ├─ 本文ハッシュが同一 → 更新なし
        │
        ▼
さくらのAIエンジン
  件数・情報種別・公表日・対応状況の候補をJSON化
        │
        ▼
レビュー待ちレコード
        │
        ▼
人が一次情報と差分を確認して公開
```

AIにWebページの取得を任せない。取得元、HTTPステータス、最終URL、本文ハッシュはアプリケーション側で確定させる。AIは取得済み本文の構造化だけを担当する。

## セキュリティ条件

- 任意URLを一般利用者から直接受け付けない
- HTTP/HTTPSと80/443番だけを許可する
- DNS解決後の全IPを検証し、プライベート、ループバック、リンクローカル、クラウドメタデータ宛てを拒否する
- 接続先IPを固定し、DNS検証後のリバインドを防ぐ
- リダイレクトごとにURLとDNSを再検証する
- 応答サイズ、接続時間、全体時間、リダイレクト回数を制限する
- HTMLのscript、style、form、hidden要素をAIへ渡さない
- 取得本文、AI入力、APIキーをログへ書かない
- AI出力は公開データではなく、常にレビュー待ち候補として保存する

`other-service`の安全なクローラー実装を移植する際も、上記条件を削らない。

## さくらAIエンジン

既定値は`AI_PROVIDER=mock`で、外部通信も課金も発生しない。実接続時だけ次を秘密情報として設定する。

```dotenv
AI_PROVIDER=sakura
SAKURA_AI_BASE_URL=
SAKURA_AI_API_KEY=
SAKURA_AI_MODEL=
```

疎通とJSON形式の確認には、コンテナ内のローカルfixtureを使う。

```bash
docker compose exec api php artisan incidents:analyze-fixture storage/app/private/notice.txt \
  --url=https://example.jp/official-notice \
  --title="公式発表"
```

`AI_PROVIDER=sakura`では実リクエストとなり、課金が発生する。APIキーと入力本文はコマンド出力に含めない。
