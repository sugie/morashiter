# ドメインモデル・集約・ライフサイクル

2026-10-03 時点の設計アンカー。以下は業務モデルであり、現行 `services/mrst` にクローラー、Incident、公開ジョブが実装済みという意味ではない。[18テーブル案](../design/mrst-table-proposal.html)を起点とし、今回の指示に従ってマルチAI合議承認と人手承認の両経路を認める。

## Incident 集約

`Incident` を集約ルートとし、同じ事象の安定した `id`、公開URL用 `public_id`、表示用 `display_code`、現在公開中の版への参照を管理する。記事ごと、社名ごとに Incident を自動作成しない。同一事象の判断が不確かな候補は人手レビューに残す。内部ID、公開ID、表示コードはそれぞれ役割が異なり、記事URLをIDに流用しない。

| 要素 | 役割 |
| --- | --- |
| `IncidentRevision` | Incidentの版。見出し、種別、日付、漏えいの確実性、対象範囲、対応状況、審査状態を保持する。続報・訂正は新しい版を作り、承認済み版を上書きしない。 |
| `IncidentImpact` | 版に属する影響規模。数値・単位・対象範囲・概数表現・原表記・根拠資料を一組で保持する。 |
| `IncidentCompany` | Incidentと企業・団体の関係。運営、親会社、委託先などの役割と根拠を保持し、企業名だけで同一性を決めない。 |
| `IncidentSource` | 版の主張と保存済み資料を結び、`primary` / `supporting` の役割、項目別の根拠位置を保持する。 |
| `SourceDocument` | 収集側で所有する取得スナップショット。Incident集約からはIDで参照する。本文や原文を集約の公開フィールドとして持たない。 |

値オブジェクトとして `DisclosureStatus`（`confirmed` / `possible` / `under_investigation` / `unknown`）、`ImpactQuantity`（数値・単位・確定性・範囲・原表記）、`SourceReference`（資料ID・資料版・根拠位置）、`PublicationDate`（日付種別と出典）、`RevisionNumber` を扱う。これらは概念上の境界であり、PHPクラス名やテーブル構造は実装時に既存規約へ合わせる。

### 不変条件

1. `IncidentRevision` の版番号は Incident 内で一意。`current_published_revision_id` は同じ Incident に属する版だけを指す。未公開はNULL。
2. 承認済み・公開済みの版の内容を直接編集しない。訂正・続報は新しい版と理由を作り、旧版を監査可能に残す。
3. 公開対象版には、一次資料と重要項目を照合した**2つ以上の独立したAIによる完全合意ログ**（`ai_consensus`）、または**管理者の人手承認ログ**（`manual`）のいずれかが必要。`approval_method` と対応する合議IDまたは管理者ID、承認日時、理由・根拠を追跡できること。単一AI、ニュース記事だけ、合意したスコアだけでは自動承認できない。
4. 漏えい `possible` を根拠なく `confirmed` に昇格させない。日付不明を取得日で埋めない。数値0と未判明NULLを区別し、単位や対象範囲をまたぐ合算をしない。
5. 非公開・審査中・撤回済みの版を新しい公開成果物へ含めない。公開失敗中に公開版ポインターを先行更新しない。
6. 公開版の切替、承認・訂正・撤回操作、配信要求の関係を監査できるようにする。外部CDN操作はDBトランザクションに含められないため、`publication_jobs` と冪等な再試行で整合させる。

## 処理のライフサイクル

収集処理、候補版の審査、実際の配信は**別の状態機械**として扱う。一つの `status` に押し込めない。

| 段階 | 所有者 | 記録・次の条件 |
| --- | --- | --- |
| 収集 raw | msnc / msic | 登録済み出典を安全に取得し、原文・ハッシュ・日時を非公開で保存。失敗は成功扱いしない。 |
| 抽出完了 | 収集・抽出 | PythonのJSONをLaravelが検証し、抽出結果と抽出版を保存。根拠不明、短文・文字化け等は抽出失敗または要確認。 |
| 合議評価中 | msjd | `evaluating`。同一資料版を2つ以上の独立AIが検証し、結果・根拠・不確実性を比較する。 |
| 自動承認 / 公開対象 | msjd | `evaluating → approved`。抽出内容、企業同定、漏えい確実性、数値・単位、一次資料の根拠位置が完全一致し、重大な不確実性がなく、要レビュー条件にも該当しない場合のみ `approval_method=ai_consensus` と合議ログを確定する。配信要求を作成するが、まだ公開済みではない。 |
| 人手レビュー待ち | msjd → admin | `evaluating → review_required`。AI間の不一致、一次資料欠落、評価失敗、訂正・撤回・重大な削除、設定された重大インシデント条件などを理由付きで渡す。 |
| 人手承認 / 公開対象 | admin | `review_required → approved`。管理者が資料と公開表現を確認し、`approval_method=manual` と承認ログを記録する。まだ公開済みではない。 |
| 公開済み | msgn | 承認済みスナップショットから静的成果物を生成し、公開バケットへ反映、CDN更新を確認した後、公開版を切り替える。 |
| 非公開 / 却下 | admin | `rejected` 等の理由を残す。公開対象に入れない。既公開版がある場合は旧版の扱いを別に判断する。 |
| 訂正 / 撤回 | admin → msgn | 根拠・理由を残す。訂正は新しい版で再承認・再配信。撤回は公開対象から除去した成果物の再配信とCDN残存確認を要する。 |

`incident_revisions.status` の候補値は `draft` / `evaluating` / `review_required` / `approved` / `rejected` / `withdrawn`。`AiJudgmentRun` の合議成功だけでは Incident を承認しない。上記の不変条件を検証し、版の `approval_method=ai_consensus` と合議IDを同一の承認操作に結び付けたときに限り `approved` とする。[テーブル案](../design/mrst-table-proposal.html)の人手側の値 `human` は、新方針の `manual` に実装前に整合させる。`publication_jobs.status` の `queued` / `building` / `uploading` / `invalidating` / `published` / `failed` は別系列である。

## 集約間の境界

- `CrawlSource` / `CrawlRun` / `SourceDocument` は収集側のライフサイクル。Incident の編集トランザクションで原文を更新しない。
- `AiJudgmentRun` / `AiEvaluationLog` は評価履歴。参加した各AIのモデル名・版、実行時のプロンプト、入力資料ハッシュ、判定理由、検証結果、不一致、合意時刻を非公開で完全記録する。再評価で既存の承認済み版を暗黙に上書きしない。
- `PublicationJob` は配信要求。版固定のマニフェストと重複排除キーを持ち、失敗後に同じ要求を安全に再試行できるようにする。
- 将来の `VictimSubscription` / `LawsuitDemand` はIncidentを参照するが、Incidentの公開可否や影響人数を変更しない。

## まだ決めない事項

重大度・ランキングの計算式、AIモデル・スコア閾値、重大インシデントの要レビュー閾値と有効化設定、対象とする不祥事の範囲、媒体コードの具体値、資料保持期限、通知・希望集計の公開基準は未決。未決の判定条件に依存する候補を便宜的に自動承認せず、レビュー待ちとして扱う。表示値は「未算定」「未集計」を使う。関連資料: [msnc実装計画](../plans/msnc-crawler-implementation-plan.html)。
