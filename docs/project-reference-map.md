# プロジェクト参照先・リポジトリ台帳

確認日：2026年11月12日 JST。Morashiterの作業を別PC・クラウドへ引き継ぐ際の入口。
ローカルのディレクトリ名とGitHubのリポジトリ名は一致するとは限らない。
この台帳は参照先を記録するもので、非公開資料を公開資料へ転記する許可ではない。

## 参照する6ディレクトリ

ローカルの基準ディレクトリはWindowsでは `C:/Users/ringo/opt`。
macOS・Linuxでは任意の作業用ディレクトリに置ける。`/Users/ringo/opt` の存在を前提にしない。

| ローカル名 | 役割・参照する場面 | 確認したGitリモート | GitHubでの状態 |
|---|---|---|---|
| `morashiter` | 現行アプリ、DDD、計画、テスト。通常の開発の起点 | `https://github.com/sugie/morashiter.git` | public、非アーカイブ、既定ブランチ `main` |
| `morashiter-ext` | `chrome/` に旧デモ拡張0.1.0。通信しない固定架空データ版の参照 | Gitリポジトリではない。リモートなし | GitHub上の同名リポジトリの有無は未確認。名前からURLを作らない |
| `morashiter-ops` | 本番デプロイ、DB接続、AI運用、公開パイプラインの記録 | `https://github.com/sugie/morashiter-ops.git` | private、非アーカイブ、既定ブランチ `main` |
| `OLD_rights_replay` | Morashiterへ移行する前のコード・設計・運用履歴 | `git@github.com:sugie/rights_replay.git` | private、アーカイブ済み、既定ブランチ `feature/mrst-api1-production` |
| `OLD_rights_replay_public` | 旧公開用コピー。移行・匿名化後の履歴を確認するときだけ参照 | `https://github.com/sugie/morashiter.git` | 現行 `morashiter` と同じリモート。独立したリポジトリではない |
| `OLD_rights_replay_publish_work` | 公開化計画、除外パス、置換ルール、検査スクリプト | Gitリポジトリではない。リモートなし | Gitから復元できないローカル作業資料 |

URL・公開状態・アーカイブ状態・既定ブランチは、ローカルの `git remote -v` とGitHub APIのリポジトリ情報を照合した。
「公開用コピー」という名前やREADMEの説明は、GitHubの現在の公開設定を意味しない。
2026年11月12日に `morashiter` を公開し、未ログインでの閲覧を確認した。審査用拡張0.2.1は `extensions/chrome` に同梱。以下のローカル限定資料の記載は11月11日時点の調査記録であり、現在の審査手順は [judge-guide.md](judge-guide.md) を参照する。

## 参照の優先順位

1. 動作の説明は、作業対象の `morashiter` のコミット・コード・テストで確認する。
2. 本番の構成・運用実績は、権限があれば `morashiter-ops` の日付付き記録を確認する。過去の記録を現在の稼働確認と同一視しない。
3. 移行の経緯は `OLD_rights_replay`、`OLD_rights_replay_public`、`OLD_rights_replay_publish_work` で調べる。旧仕様を現在の実装として扱わない。
4. Chrome拡張は `manifest.json` の版と取得元を確認する。別置きの0.1.0を現在の0.2.0と混同しない。

主な資料の入口：

- 現行コード：`services/mrst/app/Jobs/`、`services/mrst/app/Services/`、`services/mrst/routes/console.php`、`workers/msnc-python/`。
- 本番配置：`morashiter-ops/docs/runbooks/api1-deploy-mrst.md`。
- 本番公開の履歴：`morashiter-ops/docs/runbooks/msgn-site-publish.md`。
- AI運用：`morashiter-ops/docs/runbooks/sakura-ai-engine.md`。初期状態と後日の追記が混在するため、現行コードと照合する。
- 公開化の経緯：`OLD_rights_replay_publish_work/public-repo-release-plan.md`。置換ルールなどの非公開情報を提出物へコピーしない。
- [審査員向けREADME・動画の制作計画](plans/hackathon-judge-readme-video-plan.html)。

## 別PC・クラウドでの取得

GitHubで必要なリポジトリの読み取り権限を持つアカウントを使用する。
トークンをclone URLへ埋め込まない。GitHub CLIの認証または認証情報マネージャーを使う。
クラウド環境で `morashiter` だけが割り当てられている場合、兄弟ディレクトリや非公開リポジトリが利用できるとは限らない。

空の作業用親ディレクトリで、通常必要な2リポジトリを取得する：

```bash
git clone https://github.com/sugie/morashiter.git morashiter
git clone https://github.com/sugie/morashiter-ops.git morashiter-ops
```

旧履歴が必要な場合だけ追加する：

```bash
git clone https://github.com/sugie/rights_replay.git OLD_rights_replay
```

`rights_replay` のローカルoriginはSSH形式だが、上記は同一リポジトリのHTTPS形式。
アーカイブを解除したり、旧リポジトリへpushしたりする必要はない。
取得先の同名ディレクトリが既にある場合は、上書きせず `git remote -v` と作業状態を確認する。

取得後は各リポジトリで確認する：

```bash
git remote -v
git status --short --branch
git rev-parse HEAD
git worktree list
```

複数worktreeを使う場合、`git rev-parse --show-toplevel` はそのworktreeのルートを返す。
`work/<名前>` から単純に `../morashiter-ops` を探さず、`git worktree list` とこの台帳から実際の兄弟リポジトリを探す。
見つからなければ不在と記録し、リモートの読み取り権限・ワークスペースへの追加方法を確認する。
資料参照の指示を、そのリポジトリや本番環境を変更する許可とは解釈しない。

## 旧コピー・ローカル限定資料の復元範囲

| 対象 | 調査時の記録 | 復元するときの扱い |
|---|---|---|
| `morashiter-ops` | HEAD `305ce37cc8b592b55631185efab5bf89e3f0e374`、ローカルブランチ `main` | 通常は最新の運用資料を取得。過去時点の比較にだけこのSHAを使う |
| `OLD_rights_replay` | HEAD `1646797bf44e422f9827ce396fff1f234ccb8acd`、ローカルブランチ `codex/phase1-ci` | clone直後の既定ブランチとは異なる。SHAが取得できる場合だけ別worktree等で参照 |
| `OLD_rights_replay_public` | HEAD `7724c43b9e32c65df629b80bfd48e680ca83e7c1`、ローカルブランチ `main` | 最新の `morashiter/main` と同じ内容ではない。必要な場合だけ同一リモートから旧SHAの別コピーを用意 |
| 現在使っている拡張0.2.0 | `morashiter` のローカルブランチ `codex/chrome-bucket-integration`、HEAD `9759f758ea9fc9f38f1bac7b933f92867e4f8682` | 調査時点で当該コミットを含む `origin` の追跡ブランチは見つからない。最新mainをcloneしても取得できるとは限らない |
| `morashiter-ext` | Git情報なし、`chrome/manifest.json` は0.1.0 | 必要なら別途保管した配布物／バックアップから復元。バックアップ先は未記録 |
| `OLD_rights_replay_publish_work` | Git情報なし | 非公開バックアップ／別途承認された保管先が必要。現時点では復元元URLなし |

SHAは取得可能性を保証するものではない。例えば旧コピーを再現したいときは、新しいcloneで対象SHAが存在することを確認してからdetached worktreeを作る。

```bash
git -C morashiter cat-file -t 7724c43b9e32c65df629b80bfd48e680ca83e7c1
# 上の結果がcommitで、作成先が存在しない場合だけ実行する
git -C morashiter worktree add --detach ../OLD_rights_replay_public 7724c43b9e32c65df629b80bfd48e680ca83e7c1
```

拡張ブランチのpushやローカル作業資料の新規リポジトリ化は、この台帳作成には含めない。
移行前に復元元を確保する課題として残す。Gitで復元できるコードと、環境変数・DB・配布物・ローカル未コミット変更は別に管理する。

## 更新時のルール

ディレクトリを追加・改名・廃止したとき、またはリモート・公開状態を変えたときに、この台帳の確認日と該当行を更新する。
URLは実際のGit設定とリモートで確認し、推定したURLは登録しない。
非公開運用資料の内部IP・ホスト名・アカウント識別子・資格情報はこのファイルへ転記しない。
公開資料には技術と役割だけをまとめ、非公開の参照資料は権限のある環境で読む。
