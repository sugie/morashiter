# モラシッター 採用プロトタイプ

2026年10月3日採用。参考サイトの行型一覧を取り入れ、ライト／ダーク切り替え、表示設定の保存、種別フィルター、検索、詳細モーダルを実装。

このコミットは静的配信用のプロトタイプを記録する。既存の別用途のアプリやバックエンドは対象外。

## ローカルで確認

Node.js 22.13以上を使用。

```sh
cd prototype
npm ci
npm run build:static
npm run preview:static
```

http://127.0.0.1:4173/ で確認できる。

## 配信

`dist-static/` はビルド生成物のためGitに含めない。

```sh
npm run test:static              # ビルド・検査・アップロード手順のテスト
npm run build:static             # noindexのプレビュー版。一般公開時は -- --indexable
npm run verify:static            # 許可ファイル・CSP・robots・release.jsonを検査
npm run deploy:sakura            # dry run。送信順を表示するだけ
npm run deploy:sakura -- --upload
```

`--upload` には `MORASHITER_BUCKET`、`MORASHITER_S3_ENDPOINT`、`MORASHITER_AWS_PROFILE`、`MORASHITER_S3_REGION` が必要。
検査に通らない成果物は送信しない。アセットを先に、`index.html` を最後に、すべてPrivateで送る。`--delete` と `public-read` は使わない。
送信後はWebアクセラレータのキャッシュを削除し、`https://www.morashiter.com/release.json` のreleaseIdが一致することを確認する。
AWS CLIのプロファイルは端末側で設定し、認証情報をGitに保存しない。

## データ

掲載データは一次資料のスナップショット。最新情報を保証するものではない。
順位付けや単位の異なる件数の合算は行わない。
