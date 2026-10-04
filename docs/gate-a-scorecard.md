# Gate A 判定キット

## 目的

支援主体向け評価5件を、都合のよい回答だけ選ばず、[評価セッション手順](support-evaluation-protocol.md)に定めた同一基準で判定する。入力は試作から書き出した識別子なしの評価JSONだけとし、氏名、連絡先、組織名、自由記述、実在案件データを混ぜない。

## ファイルの置き方

面談ラウンドごとに、暗号化された調査用フォルダへ次のように置く。

```text
gate-a-round-1/
  manifest.json
  L-001.json
  L-002.json
  O-001.json
  O-002.json
  C-001.json
```

`L-001.json` 等は支援主体レビュー画面から本人が書き出したファイルである。ファイル名は調査用IDに変更し、元のダウンロード名や組織名を残さない。

## manifest.json

```json
{
  "schema": "rightsrelay.gate-a-manifest.v1",
  "roundId": "gate-a-round-1",
  "manualChecks": {
    "coreLegalRoleRequired": null,
    "unavoidableLegalRisk": null
  },
  "sessions": [
    {
      "sessionId": "L-001",
      "phase": "initial",
      "evaluationFile": "L-001.json",
      "organizationCode": null,
      "retestsSessionId": null
    },
    {
      "sessionId": "O-001",
      "phase": "initial",
      "evaluationFile": "O-001.json",
      "organizationCode": "ORG-001",
      "retestsSessionId": null
    }
  ]
}
```

- `sessionId`: 調査用ID。実名との対応表をこのフォルダへ置かない
- `organizationCode`: 消費者団体だけに付ける仮コード。同一団体の複数人を別組織と数えない
- `phase`: 最初の5件は `initial`。重大な境界表示を修正後に再評価した場合だけ `retest`
- `retestsSessionId`: 再評価がどの初回評価に対応するかを指定
- `manualChecks.coreLegalRoleRequired`: 法律判断・周旋をRightsRelayが担わないと価値が成立しないなら `true`
- `manualChecks.unavoidableLegalRisk`: 表示修正では避けられない重大な法的リスクがあると弁護士が判断した場合 `true`

手動確認が終わるまでは `null` にする。期待する結論に合わせて埋めない。

## 判定

プロジェクトルートで実行する。

```bash
node tools/gate-a.mjs /path/to/gate-a-round-1/manifest.json
```

判定結果は次のいずれか。

- `continue`: 6条件と手動確認がすべて通過
- `conditional`: 価値の兆候はあるが、サンプル構成、境界表示、時間削減、再評価または手動確認が不足
- `pivot`: 4人以上が価値なし、時間短縮根拠が0件、原本の事前集中保管が過半数、またはRightsRelayが法律判断を担うことを中核価値が要求
- `stop`: 弁護士が、現行中核フローの重大な法的リスクは表示修正では避けられないと判断

判定器は、未知フィールド、自由記述を足した評価ファイル、旧スキーマ、パストラバーサルを含むファイル名、同一団体を水増しする不整合を拒否する。

## 記録上の注意

- 判定JSONは仮説台帳へ数値だけ転記する
- 発言メモと評価JSONを結合しない
- `ORG-001` と実在団体名の対応表を作る場合は、面談調整用の連絡先保管場所へ分離する
- 評価JSONは法的意見・提携合意・実証参加の確約ではない
- `continue` でも実データ実証の開始条件を満たしたことにはならない。法務・プライバシー・セキュリティの各リリース阻止条件を別途確認する
