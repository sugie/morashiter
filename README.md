# RightsRelay

> 小さな被害を、動かせる力に。

RightsRelay は、個人情報漏えいの被害者が「自分は対象か」「何を残すべきか」「どこへ意思表示すべきか」を理解し、本人の明確な同意の下で、弁護士または特定適格消費者団体へ安全につながるためのプロジェクトです。

現在は Discovery / Validation 段階です。法律相談、弁護士紹介、訴訟代理、勝訴可能性の判定は行いません。

## North Star

漏えい通知を受け取った人が、10分以内に次の三つを完了できること。

1. 自分が公表上の対象者に当たる可能性を確認する
2. 自分の端末に証拠保全チェックリストを作る
3. 個人を直接特定する情報を含めず参加意向を記録し、希望時だけ支援主体へ情報を渡す

## 現在の判断

- 作業名は **RightsRelay / ライツリレー（仮称）** とする。同名ソフトウェアと登録済み `.com` があるため、公開ブランドの確定は保留し、**KOETABA / 声束** を第一バックアップにする。
- 「集団訴訟プラットフォーム」ではなく「集団的被害回復の受付・証拠整理基盤」と位置付ける。
- 国内類似調査では、enjin・MatoMaという近い先例はいずれも終了しており、現行手段は団体窓口、事件別弁護団、訴訟支援、弁護士検索、漏えい監視に分断されている。日本初とは称さず、この分断を安全な共通入口と引渡し形式で埋められるか検証する。
- 救済参加に必要な基本機能は無料とする。
- 初期版では免許証画像、通知メール本文、会員番号などの原本をサーバーへ送信しない。
- 法的判断と受任は弁護士または法定の団体が担い、RightsRelay は法律事件を扱わない。
- 2027年 Shipaton を仮の公開目標とする。2027年版の正式要項が出た時点で計画を再確認する。

## 2027年までの工程

| 期間 | 目的 | 完了条件 |
|---|---|---|
| 2026-10〜11 | 成立性検証 | 法律家3人以上、団体2組織以上、被害経験者10人以上にヒアリング |
| 2026-12〜2027-01 | 非機密プロトタイプ | 事件確認、対象可能性判定、ローカル証拠チェック、最小共有パケットが通る |
| 2027-02〜03 | 利用者検証 | 20人以上のテスト、主要タスク完了率80%以上 |
| 2027-04〜05 | 小規模実証 | 支援主体がケース概要を評価し、受付時間の削減を確認 |
| 2027-06〜07 | 公開準備 | 法務・セキュリティレビュー、ストア素材、英語ピッチを完了 |
| 2027-08〜09（仮） | Shipaton | 正式要項に適合する新規モバイルアプリを期間内に初公開・応募 |

## 最初の30日

- [x] プロジェクト名の商標・アプリストア・ドメイン簡易調査（正式決定は保留）
- [x] 特定適格消費者団体4団体の連絡候補と依頼文作成
- [ ] 被害経験者向けヒアリングを5件実施
- [ ] 法律家・団体向けヒアリングを3件実施
- [x] タイムズカー事件を題材に、個人情報を送信しないWebプロトタイプを作成
- [x] 被害経験者向けユーザビリティテスト手順と識別子を含まない検証データ出力を作成
- [x] 支援主体レビュー用の最小共有パケットと端末内集計を作成
- [ ] ヒアリング結果を仮説台帳へ記録し、継続・変更・中止を判断

## ドキュメント

- [業務フロー図](docs/business-flow.html)
- [プロジェクト憲章](docs/charter.md)
- [名称候補と選定基準](docs/naming.md)
- [名称クリアランス一次調査](docs/name-clearance.md)
- [国内類似サービス・代替手段調査](docs/competitive-landscape.md)
- [検証計画](docs/validation-plan.md)
- [被害経験者向けユーザビリティテスト手順](docs/usability-test-protocol.md)
- [Gate B 被害経験者テスト判定キット](docs/gate-b-scorecard.md)
- [被害経験者テストの募集・スクリーニング](docs/participant-recruitment.md)
- [調査データ取扱手順](docs/research-data-protocol.md)
- [最小共有パケット仕様](docs/share-packet-spec.md)
- [事件横断データモデルの一次検証](docs/cross-incident-model.md)
- [法的境界メモ](docs/legal-boundary-memo.md)
- [脅威モデル](docs/threat-model.md)
- [支援主体向け評価セッション手順](docs/support-evaluation-protocol.md)
- [Gate A 判定キット](docs/gate-a-scorecard.md)
- [ヒアリングガイド](docs/interview-guides.md)
- [ヒアリング候補と依頼文](docs/outreach.md)
- [仮説・ヒアリング記録](docs/hypothesis-log.md)
- [MVP仕様とデータ境界](docs/mvp-scope.md)
- [リスク台帳](docs/risk-register.md)
- [一次情報](docs/sources.md)
- [Docker開発環境・クローラー／AI構成](docs/architecture/crawler-and-ai.md)
- [Webプロトタイプ](prototype/)

## Docker開発環境

新しい実装基盤は、静的ビルド可能なReact/Viteフロントと、FrankenPHP上のLaravel APIを分離しています。既存の`prototype/`は画面検証用として残しています。

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec api php artisan migrate
```

起動後のURL:

- React: <http://localhost:6544/>
- Laravel API: <http://localhost:6546/api/health>
- PostgreSQL: `localhost:6542`

主な操作:

```bash
docker compose logs -f api worker web
docker compose exec api composer ci
docker compose exec web npm run build
docker compose down
```

ローカルではLaravelからPostgreSQLへ直接接続します。本番では既存IaaSのPgBouncer（transaction pooling）を使い、接続先を環境変数で6432番へ切り替えます。

## 重要な表現

利用者向けには次の区別を崩さない。

- 「公表された条件に照らすと対象の**可能性があります**」
- 「参加意向を記録します」
- 「法律相談・受任ではありません」
- 「情報提供後に支援主体が扱うかどうかを独立して判断します」

「請求できます」「勝てます」「必ず補償されます」「弁護士を紹介します」とは表示しない。
