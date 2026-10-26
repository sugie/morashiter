# 公式発表URLの手動追加：利用・反映手順

2026-10-26。実装済み、本番反映は未実施。サーバー操作は管理者が行う。

## できること

管理画面の「公式発表URLを手動追加」から、登録済み公式ドメインの個別発表HTML/PDFを入力する。

1. URLと企業・組織を選び、公式資料を確認したチェックを入れて追加する。
2. 保存完了後、既存の `msnc` キューへ取得ジョブを投入する。
3. 取得した資料を既存のAI解析に渡し、審査キューに下書きを作る。
4. 管理者が公式資料と照合して承認し、既存の「公開」画面で公開する。

追加画面は `/admin/official-announcements`。ダッシュボードと公式発表リンク画面から移動できる。取得・解析・審査の状態、取得エラー、再取得ボタンを表示する。画面を再読み込みして進捗を確認する。

ニュース記事の登録は不要。手動登録者・再取得者を記録し、ニュース由来の候補と区別して保存する。同じ正規化URLの二重追加は同じ登録を返す。取得済みの資料があるURLでは既存資料・審査状態を表示し、重複取得しない。同一URLの更新版を強制取得する機能ではない。

HTTPS、登録済みホストとの完全一致、公開IPへの名前解決を確認する。取得時にも既存のURL・リダイレクト検証を通す。未登録ドメインは既存の管理者確認を経て登録する。トップページのURLは入力対象外。

## 本番反映

### 1. 開発PC：変更をコミットしてイメージを作成

Git Bashでリポジトリを開く。以下は今回の変更だけをステージする。

```bash
cd /c/Users/ringo/opt/morashiter
git add services/mrst/app/Models/OfficialLinkCandidate.php \
  services/mrst/app/Http/Controllers/Admin/ManualOfficialAnnouncementController.php \
  services/mrst/app/Services/Crawling/ManualOfficialAnnouncement.php \
  services/mrst/database/migrations/2026_10_26_015939_add_manual_submission_to_official_link_candidates.php \
  services/mrst/resources/views/admin/dashboard.blade.php \
  services/mrst/resources/views/admin/link-candidates/index.blade.php \
  services/mrst/resources/views/admin/link-candidates/manual.blade.php \
  services/mrst/routes/web.php \
  services/mrst/tests/Feature/Admin/ManualOfficialAnnouncementTest.php \
  services/mrst/tests/fixtures/manual-official-notice.html \
  docs/manual-official-announcements.md \
  docs/official-announcement-candidates-2026-10-26.md
git diff --cached --stat
git commit -m "Add manual official announcement intake in admin"
bash bin/mrst-release-images.bash --push
```

Docker Desktopが起動し、既存のGHCRへのpush権限でログインしている必要がある。スクリプトは未コミットの追跡ファイルがあると停止する。他の変更を勝手に取り消さず、状態を確認する。

最後に出力される **`MRST_WEB_IMAGE=...@sha256:...` と `MRST_WORKER_IMAGE=...@sha256:...` の2行**を控える。どちらも今回の同一コミットで作る。ローカルでコミットしただけではサーバーには反映されない。

### 2. api1：Composeの場所と現在のイメージを確認

`ssh api1` 後のBashで行う。サーバー上のリポジトリ配置場所は決め打ちしない。

```bash
sudo docker inspect mrst-prod-app-1 --format '{{index .Config.Labels "com.docker.compose.project.config_files"}}'
sudo docker inspect mrst-prod-app-1 mrst-prod-msnc-1 mrst-prod-scheduler-1 --format '{{.Name}} {{.Config.Image}}'
```

最初の出力が現在使っている `docker-compose.mrst.prod.yml` の絶対パス。次の値をその実際のパスに置き換える。複数ファイルがカンマ区切りで表示された場合は、各ファイルをそれぞれ `-f` に指定して既存構成を維持する。

```bash
MRST_COMPOSE_FILE='/実際の絶対パス/docker-compose.mrst.prod.yml'
sudo test -f "$MRST_COMPOSE_FILE"
mrst_compose() {
  sudo docker compose --env-file /etc/mrst/compose.env \
    -f "$MRST_COMPOSE_FILE" --profile crawler --profile release "$@"
}
MRST_ENV_BACKUP="/etc/mrst/compose.env.before-manual-$(date +%Y%m%d-%H%M%S)"
sudo cp -p /etc/mrst/compose.env "$MRST_ENV_BACKUP"
printf '%s\n' "$MRST_ENV_BACKUP"
```

既存のDBバックアップ運用で、復元可能なバックアップも確保する。今回のマイグレーションは既存データを消さず、手動追加のためのNULL許容・監査列・一意インデックスを追加する。

### 3. api1：イメージ指定を更新し、DB→アプリの順に反映

```bash
sudoedit /etc/mrst/compose.env
```

手順1で得た2行で `MRST_WEB_IMAGE` と `MRST_WORKER_IMAGE` の値だけを置き換える。DB接続情報、`MRST_ENV_FILE`、`MRST_MIGRATION_ENV_FILE`、公開先設定は既存値を使う。

以下を**1コマンドずつ実行し、失敗したら次へ進まない**。

```bash
mrst_compose config --quiet
mrst_compose pull app msnc scheduler migrate
mrst_compose run --rm migrate
mrst_compose up -d --no-deps app msnc scheduler
mrst_compose ps
sudo docker exec mrst-prod-app-1 php artisan migrate:status
sudo docker exec mrst-prod-app-1 php artisan route:list --path=admin/official-announcements
sudo docker logs --since 10m --tail 100 mrst-prod-msnc-1
```

マイグレーション `2026_10_26_015939_add_manual_submission_to_official_link_candidates` が `Ran`、新しい3ルートが表示され、appがhealthy、msncとschedulerが稼働していることを確認する。今回Composeファイルの変更はない。

現在の運用と同じく、`QUEUE_CONNECTION=database`、`MSIC_FETCH_ENABLED=true`、実際の連絡先を含む `MSNC_USER_AGENT`、`AI_PROVIDER=sakura` と既存のSakura設定を使用する。秘密情報を含む設定全体を画面やチャットに貼らない。キー名・動作は既存設定のままで、新しいAPIキーは不要。

### 4. 画面で1件確認

1. [管理画面](https://api.morashiter.com/admin)を再読み込みし、「公式発表URLを手動追加」を開く。
2. [候補一覧](official-announcement-candidates-2026-10-26.md)の公式資料を開いて内容を確認する。
3. 例として、アエナのドメインが登録済みなら `https://www.aena.co.jp/news/detail/7826/` を追加する。
4. 一覧が「取得待ち」→「解析待ち」→「審査待ち」へ進むことを確認する。短時間で完了した段階は画面に現れないこともある。
5. 審査キューで内容・日付・対象情報・影響規模・重複を確認する。承認後の公開は既存の公開操作で行う。

新規登録は即時にキューへ投入するので、通常は毎時のクローラー実行まで待つ必要はない。ワーカーの混雑やAIの日次上限で待つことはある。

## 進まない場合

| 表示・状況 | 確認・対応 |
| --- | --- |
| 公式発表の取得が停止中 | `MSIC_FETCH_ENABLED` または本番User-Agentの設定。保存された取得待ちは有効化後の毎時20分の `msic:collect` 対象になる。 |
| 取得待ちが続く | `mrst-prod-msnc-1` が稼働し、database接続のmsncキューを処理しているかログを確認する。 |
| 取得失敗 / `http_non_200` | URLをブラウザで確認。404・403などが解消してから「再取得」。誤ったURLなら正しい別URLを追加する。 |
| `url_policy_violation` | 登録ホスト、DNS、転送先を確認する。別ホストの転送先を無条件に許可しない。 |
| 解析待ち | ワーカーとAI日次上限を確認する。未解析資料は毎時40分の `msic:analyze-pending` でも処理される。 |
| mock | 実AIが未設定。既存Sakura設定を有効にしてコンテナを再作成する。mock下書きは定期解析で作り直される。 |
| 解析失敗 / `ai_failed` | 既存の自動再試行と最大試行回数の対象。AI設定・エラーを確認する。 |
| 解析失敗 / `no_text` | 本文を抽出できない資料。本文のある別の公式発表URLを追加する。この機能はOCRやPDF抽出器の修正を含まない。 |
| 承認済みだが公開サイトに出ない | 既存の「公開」画面で公開実行・結果を確認する。URL追加や審査承認だけでは公開されない。 |

解析予定だけを見る既存コマンド（読み取りのみ）：

```bash
sudo docker exec mrst-prod-msnc-1 php artisan msic:analyze-pending --dry-run
```

## 戻す場合

新しい処理の投入をやめ、上で保存した `compose.env` の旧イメージ2行へ戻して `mrst_compose up -d --no-deps app msnc scheduler` を実行する。今回の追加列を残したまま旧コードを動かせる。キューには既存ジョブ型しか投入していない。

**手動追加後は `migrate:rollback` を実行しない。** ニュース記事を持たない手動登録が残るため、旧来のNOT NULL制約へ戻すことはできない。マイグレーションのdownにもデータ保護の停止処理を入れている。コードを戻した後も、既に投入した取得・解析ジョブは旧ワーカーで実行され得る。

## テスト

先に固定fixtureと期待動作のFeatureテストを作り、未実装ルートの404で失敗することを確認してから実装した。

| 範囲 | データ・期待結果 |
| --- | --- |
| 正常系HTML/PDF | `tests/fixtures/manual-official-notice.html` と `Tests\Support\PdfFixture`。取得・AI解析・審査キューまで到達し、インシデントの自動公開はしない。 |
| 正規化・重複 | 大文字ホスト、443、追跡パラメーター、フラグメント。同一候補を返し、重複ジョブ・監査の上書きをしない。 |
| 入力異常・境界 | 未登録ホスト、別ホスト・サブドメイン、private IP/DNS、HTTP、認証情報付きURL、非443、トップページ、制御文字、2048文字超を拒否。 |
| 運用 | 取得停止時の待機、404後の再取得、処理中の二重再取得拒否、既存資料の再利用、未発見候補の表示。 |
| 管理画面 | 未認証・無効管理者・許可外IPの拒否、HTMLエスケープ、ナビゲーション。 |

検証には専用のPostgreSQL 17テストDBとローカルワーカーを使用。本番DB・本番AIを呼ばず、HTTP/DNSは固定応答に置換した。下記の関連テストを最初に **83件・298 assertions成功** で確認した。その後、ページ切り替えのテストを追加し、最終版の手動追加テスト **24件・131 assertions成功** を確認した。重複を除く検証ケースは、手動追加24件と既存連携60件。Pintも成功した。

```bash
php artisan test --compact \
  tests/Feature/Admin/ManualOfficialAnnouncementTest.php \
  tests/Feature/Admin/OfficialLinkReviewTest.php \
  tests/Feature/Msic \
  tests/Feature/Admin/IncidentReviewTest.php
```

既存の `Tests\TestCase` が要求する専用 `mrst_test` 接続で実行する。本番デプロイ・実Sakura呼出し・本番サイトからの取得成功は未検証であり、手順4で管理者が確認する。
