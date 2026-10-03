# Morashiter の境界づけられたコンテキスト

2026-10-03 時点。APIと管理Webは同じ Laravel 13 プロジェクト `services/mrst`、本番はAPI1のアプリケーションとして配置する方針。コンテキストは**業務上の責務とデータ契約**の境界であり、最初から別マイクロサービス・別DBに分ける指示ではない。現時点でクローラーと業務テーブルは未実装である。

```text
ニュース媒体 → msnc ─┐
                  ├→ 非公開資料 → msjdの候補評価 → adminの人手審査
企業公式発表 → msic ─┘                            │承認された版
                                                ▼
                                       msgnの静的生成・配信
                                                ▼
                                非公開オリジンS3 → さくらCDN → www

将来: www → 別ホストの公開API → 希望集計・通知
```

## 収集・抽出コンテキスト（msnc / msic）

msncは登録済みニュース媒体から記事を発見し、LaravelがURL・DNS・リダイレクトを検証してHTTP取得する。Laravel Scheduler / database queue はスケジュール、再試行、冪等性、DB保存、非公開資料保管、msjdへの永続的な引き渡しを所有する。ニュース記事は補助資料であり、公式発表へのリンクを msic の追跡候補にできるが、それだけで一次資料確認済みにしない。msic は企業公式サイト等の原資料の取得を担当する。

Python / BeautifulSoup は msnc ワーカー内の**局所的な抽出コマンド**である。Laravelが取得済みHTML・媒体コード・抽出版を含むJSONを標準入力で渡し、Pythonは標準出力のJSONでタイトル・本文・日付候補等を返す。PythonはHTTP、DB、AIへ接続しない。Laravelは終了コード、サイズ、時間、JSONスキーマを検証してから保存する。媒体ごとの取得可否、RSS、保存・AI送信条件は有効化前に確認する。初期候補は Security NEXT、ScanNetSecurity、ITmedia NEWS のセキュリティ記事であり、登録済み配信元ではない。

所有する概念は `crawl_sources`、`crawl_runs`、`source_documents` と、[msnc実装計画](../plans/msnc-crawler-implementation-plan.html)で提案された `crawl_items` / `document_processing_requests` 等。後者は追加**案**であり、まだマイグレーションに存在しない。

## 管理・審査コンテキスト（msjd / admin・msad）

msjdは資料から事象・企業・影響規模・確実性の候補を作り、根拠位置、モデル/ルールの版、不一致・失敗を記録する。候補は管理画面に渡す。管理者は一次資料と照合し、表現を校正し、承認・却下・訂正・撤回を判断する。承認操作には管理者ID、日時、理由、根拠版を結び付け、監査ログを残す。

管理画面はAPIと同じLaravel内の `/admin` に置く。現在の認証基盤は専用 `admin_users` とログイン、`is_active`、IP許可リストの失敗時拒否までで、インシデント審査画面や公開承認機能は未実装。将来の公開承認は本人認証、権限、CSRF、信頼済みプロキシから得た利用者IPの検証を通す。固定IP制限だけを承認権限の代わりにしない。MFAや役割体系は未決。

所有する概念は `incidents`、`incident_revisions`、`incident_companies`、`incident_impacts`、`incident_sources`、評価記録、`admin_audit_logs`。公開可能な版の決定はこのコンテキストで行い、msnc・msic・AIに委ねない。

## 公開・配信コンテキスト（msgn / public）

入力は、人が承認した Incident の特定版と、その公開許可済みフィールドのみ。msgnは版を固定したスナップショットから静的HTML/CSS/JS・マニフェストを生成し、`morashiter-site-prod` にアップロードしてさくらのWebアクセラレータ/CDNへ反映する。配信失敗時は旧公開版を維持し、成功後に公開版ポインターを更新する。公開物の検証とCDNキャッシュ更新を完了条件に含める。

公開閲覧は `www.morashiter.com` の静的配信で完結し、動的DB・管理画面のテーブル・非公開資料への接続を要しない。公開用バケットはCDNのオリジンとして使い、購読者情報、IP由来データ、非公開候補、raw記事、AI生ログ、キーを入れない。`/api` と `/admin` は公開静的サイトと別の動的経路であり、静的ビルドへ埋め込まない。

所有する概念は公開スナップショット、配信マニフェスト、`publication_jobs`、CDN反映結果。DBでの承認と配信要求の記録は原子的に行う設計案だが、外部ストレージ/CDNとの処理は再試行可能な非同期工程とする。

## 通知・希望集計コンテキスト（将来の公開API）

メール購読・確認・解除、希望ボタンの重複抑制、集計値の提供は将来のAPIの責務。`victim_subscriptions`、`subscription_tokens`、`lawsuit_demands` は論理設計案であり、現在の静的公開版では通知・希望登録を「準備中」として扱う。架空の成功応答や件数は出さない。本人確認前の購読は通知対象にしない。希望ボタンの押下数を被害者数・訴訟参加者数へ変換しない。

利用者が端末内で行う「対象可能性」の整理は個人の漏えい確定ではない。将来APIが受け取る情報・保持期間・同意、メール送信基盤、公開集計の粒度は実装前に別途決定する。

## コンテキスト間の契約

- 収集 → 審査: 出典ID、URL、取得時刻、原文ハッシュ、抽出版、非公開保存先、候補の根拠を渡す。記事本文を公開データとして渡さない。
- 審査 → 公開: `incident_id`、承認済み `revision_id`、公開許可済み項目、承認者・承認日時を確認できる要求を渡す。AI評価結果は承認の代用にならない。
- 公開 → 将来API: 安定した公開IDと公開済み状態だけを照合に使う。APIの内部DB構造・個人データを静的生成へ流さない。

参照: [テーブル案](../design/mrst-table-proposal.html)、[msnc実装計画](../plans/msnc-crawler-implementation-plan.html)、[Docker/API1計画](../plans/mrst-docker-environment-plan.html)。
