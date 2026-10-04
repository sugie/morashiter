# Gate B 被害経験者テスト判定キット

## 目的

被害経験者向けタスクテストを、好意的な感想ではなく、タスク完了、所要時間、重大な誤認、高機密情報入力の誘発、理解度、信頼度で判定する。

試作から本人が書き出す識別子なしフィードバックと、進行役が閉じた選択肢だけで作る観察マニフェストを分離する。氏名、連絡先、企業名、被害内容、自由記述はどちらにも含めない。

## 3段階・合計20人

| 段階 | 人数 | 目的 | 合格人数 |
|---|---:|---|---:|
| `round-1` | 5人 | 最初の重大な問題を発見 | 4人以上 |
| `round-2` | 別の5人 | 修正版で問題が減ったか確認 | 4人以上 |
| `gate-b` | 別の10人 | UIを固定し、次段階へ進める証拠を得る | 8人以上 |

UIを変更したラウンドの数値を、同一バージョンの20人として合算しない。

## ファイル構成

```text
round-1/
  manifest.json
  V-001.json
  V-002.json
  V-003.json
  V-004.json
  V-005.json
```

`V-001.json` 等は試作の「識別子なしの検証結果を書き出す」で得たファイルである。元のダウンロード名は匿名IDへ変更する。

## manifest.json

```json
{
  "schema": "rightsrelay.usability-manifest.v1",
  "roundId": "round-1-2026-10",
  "stage": "round-1",
  "sessions": [
    {
      "sessionId": "V-001",
      "feedbackFile": "V-001.json",
      "taskCompletion": {
        "t1": true,
        "t2": true,
        "t3": true,
        "t4": true
      },
      "majorAssistance": false,
      "misconceptions": [],
      "sensitiveInputAttempted": false,
      "coreTaskDurationSeconds": 480,
      "stopReason": "none"
    }
  ]
}
```

### 記録ルール

- `taskCompletion`: T1〜T4をそれぞれ完了したか
- `majorAssistance`: ボタン位置の案内を超える支援が一度でも必要だったか
- `misconceptions`: `assessment-is-legal`、`local-is-external`、`mandate-or-litigation-complete` のみ
- `sensitiveInputAttempted`: 会員番号、通知原文、本人確認書類等を入力・提示しようとしたか。内容は記録しない
- `coreTaskDurationSeconds`: T1開始からT3完了まで。10分以内のNorth Starを測る
- `stopReason`: `none`、`distress`、`real-data-offered`、`legal-advice-requested`、`privacy-risk`、`other` のみ

自由記述の行動メモはmanifestへ入れない。必要な観察メモは別ファイルで管理し、評価JSONと結合しない。

## 判定方法

```bash
node tools/gate-b.mjs /path/to/round-1/manifest.json
```

判定は次の三つ。

- `pass`: 段階ごとの全基準を通過
- `revise-and-retest`: 安全中止条件ではないが、タスク、時間、誤認、理解度または信頼度が未達
- `safety-redesign-required`: 高機密情報の入力を誘発した、または記録方法にプライバシー上の危険が生じた

## 自動判定する基準

- T1〜T4を重大な支援なしで完了: 5人段階は4人以上、Gate Bは8人以上
- T1〜T3を10分以内に完了: 同上
- 対象可能性を法的確定と誤認: 1人以下
- 端末内記録を外部送信と誤認: 1人以下
- 委任・授権・訴訟参加が成立したと誤認: 1人以下
- 高機密情報を入力・提示しようとした: 0人
- 意思記録の保存先を「この端末だけ」と回答: 5人段階は4人以上、Gate Bは8人以上
- 理解度・信頼度の中央値: それぞれ4以上

画面状態から得たタスクシグナルと観察記録が一致しない場合、判定器は匿名IDだけを警告する。削除タスクの実行など合理的な理由を確認し、都合よく記録を書き換えない。
