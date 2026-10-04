"use client";

import { useState } from "react";

type Role = "" | "lawyer" | "consumer-body" | "counselor" | "security" | "other";
type TimeBand = "" | "under-15" | "15-30" | "31-60" | "over-60" | "unknown";
type Utility = "" | "yes" | "partial" | "no";
type Boundary = "" | "clear" | "needs-review" | "unsafe";
type OutputFormat = "" | "csv" | "pdf" | "json" | "web-form" | "api";
type NextStep = "" | "second-review" | "pilot" | "no";
type Risk = "" | "legal" | "privacy" | "quality" | "operations" | "trust" | "none";
type OriginalEvidenceTiming = "" | "not-needed-initial" | "after-triage" | "before-triage" | "unknown";

const missingFieldOptions = {
  timeline: "出来事の時系列",
  actualHarm: "二次被害・実損の有無",
  amount: "支出・損害額の区分",
  authenticity: "本人性・回答真正性",
  evidenceProof: "証拠原本の確認状態",
  consentAudit: "同意・撤回の履歴",
  contactRoute: "本人への連絡可否",
  none: "この段階では追加不要",
} as const;

type MissingField = keyof typeof missingFieldOptions;

function RadioChoices<T extends string>({
  name,
  value,
  options,
  onChange,
}: {
  name: string;
  value: T | "";
  options: readonly (readonly [T, string])[];
  onChange: (value: T) => void;
}) {
  return (
    <div className="review-choice-grid">
      {options.map(([optionValue, label]) => {
        const id = `${name}-${optionValue}`;
        return (
          <label htmlFor={id} aria-label={label} key={optionValue}>
            <input
              id={id}
              type="radio"
              name={name}
              value={optionValue}
              checked={value === optionValue}
              onChange={() => onChange(optionValue)}
            />
            <span>{label}</span>
          </label>
        );
      })}
    </div>
  );
}

export default function SupportEvaluation({ sampleReady }: { sampleReady: boolean }) {
  const [startedAt, setStartedAt] = useState("");
  const [role, setRole] = useState<Role>("");
  const [currentTime, setCurrentTime] = useState<TimeBand>("");
  const [utility, setUtility] = useState<Utility>("");
  const [expectedTime, setExpectedTime] = useState<TimeBand>("");
  const [missingFields, setMissingFields] = useState<MissingField[]>([]);
  const [risk, setRisk] = useState<Risk>("");
  const [format, setFormat] = useState<OutputFormat>("");
  const [boundary, setBoundary] = useState<Boundary>("");
  const [nextStep, setNextStep] = useState<NextStep>("");
  const [originalEvidenceTiming, setOriginalEvidenceTiming] = useState<OriginalEvidenceTiming>("");
  const [exported, setExported] = useState(false);

  const complete = Boolean(
    startedAt &&
    role &&
    currentTime &&
    utility &&
    expectedTime &&
    missingFields.length &&
    risk &&
    format &&
    boundary &&
    nextStep &&
    originalEvidenceTiming,
  );

  function startEvaluation() {
    if (!sampleReady) return;
    setStartedAt(new Date().toISOString());
    setRole("");
    setCurrentTime("");
    setUtility("");
    setExpectedTime("");
    setMissingFields([]);
    setRisk("");
    setFormat("");
    setBoundary("");
    setNextStep("");
    setOriginalEvidenceTiming("");
    setExported(false);
  }

  function toggleMissingField(field: MissingField, checked: boolean) {
    setMissingFields((current) => {
      if (field === "none" && checked) return ["none"];
      const withoutNone = current.filter((value) => value !== "none" && value !== field);
      return checked ? [...withoutNone, field] : withoutNone;
    });
    setExported(false);
  }

  function exportEvaluation() {
    if (!complete || !sampleReady) return;
    const start = Date.parse(startedAt);
    const payload = {
      schema: "rightsrelay.support-evaluation.v2",
      prototypeVersion: "2026-09-30-v4",
      context: "fictional-five-packet-sample",
      durationSeconds: Number.isFinite(start) ? Math.max(0, Math.round((Date.now() - start) / 1000)) : null,
      responses: {
        role,
        currentIntakeTimeBand: currentTime,
        initialTriageValue: utility,
        expectedTimeWithPrototype: expectedTime,
        minimumMissingFields: missingFields,
        primaryRisk: risk,
        preferredOutput: format,
        legalBoundaryAssessment: boundary,
        nextStepWillingness: nextStep,
        originalEvidenceTiming,
      },
      excluded: [
        "氏名・連絡先",
        "所属組織名",
        "自由記述",
        "実在する被害者・事件データ",
        "読み込んだ共有パケット",
      ],
      disclaimer: "法律意見、提携合意、実証参加の確約ではありません。",
    };
    const blob = new Blob([JSON.stringify(payload, null, 2)], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = "rightsrelay-support-evaluation.json";
    anchor.click();
    URL.revokeObjectURL(url);
    setExported(true);
  }

  const formEnabled = Boolean(startedAt && sampleReady);

  return (
    <section className="evaluator-section" id="evaluation" aria-labelledby="evaluation-title">
      <div className="evaluator-intro">
        <p className="eyebrow">GATE A REVIEW</p>
        <h2 id="evaluation-title">実務価値と境界を、<br />同じ尺度で評価する。</h2>
        <p>
          弁護士・特定適格消費者団体・相談員向けの評価票です。組織名、氏名、自由記述は取得しません。
          必ず架空サンプル5件を表示してから開始します。
        </p>
        <button type="button" disabled={!sampleReady} onClick={startEvaluation}>
          {startedAt ? "評価を最初からやり直す" : "支援主体評価を開始する"}
        </button>
        {!sampleReady && <small>上の「架空サンプル5件で試す」を先に押してください。</small>}
        {startedAt && !sampleReady && <small>サンプルが消去されました。再読込みすると続けられます。</small>}
      </div>

      <form className={`evaluator-form ${formEnabled ? "is-active" : ""}`} onSubmit={(event) => event.preventDefault()}>
        <fieldset disabled={!formEnabled}>
          <legend>1. 主な立場</legend>
          <RadioChoices<Role>
            name="evaluator-role"
            value={role}
            onChange={setRole}
            options={[
              ["lawyer", "弁護士"],
              ["consumer-body", "消費者団体"],
              ["counselor", "消費生活相談員"],
              ["security", "情報管理・セキュリティ"],
              ["other", "その他の支援職"],
            ]}
          />
        </fieldset>

        <fieldset disabled={!formEnabled}>
          <legend>2. 同種相談1件の初期整理に現在かかる時間</legend>
          <RadioChoices<TimeBand>
            name="current-time"
            value={currentTime}
            onChange={setCurrentTime}
            options={[
              ["under-15", "15分未満"],
              ["15-30", "15〜30分"],
              ["31-60", "31〜60分"],
              ["over-60", "60分超"],
              ["unknown", "把握していない"],
            ]}
          />
        </fieldset>

        <fieldset disabled={!formEnabled}>
          <legend>3. この集計は初期トリアージに価値がありますか</legend>
          <RadioChoices<Utility>
            name="triage-value"
            value={utility}
            onChange={setUtility}
            options={[
              ["yes", "そのまま価値がある"],
              ["partial", "修正すれば価値がある"],
              ["no", "この形では価値がない"],
            ]}
          />
        </fieldset>

        <fieldset disabled={!formEnabled}>
          <legend>4. この形式を使った場合の初期整理時間の見込み</legend>
          <RadioChoices<TimeBand>
            name="expected-time"
            value={expectedTime}
            onChange={setExpectedTime}
            options={[
              ["under-15", "15分未満"],
              ["15-30", "15〜30分"],
              ["31-60", "31〜60分"],
              ["over-60", "60分超"],
              ["unknown", "評価できない"],
            ]}
          />
        </fieldset>

        <fieldset disabled={!formEnabled}>
          <legend>5. 初期トリアージに最低限追加したい項目（複数選択可）</legend>
          <div className="review-choice-grid multi-choice-grid">
            {Object.entries(missingFieldOptions).map(([field, label]) => {
              const key = field as MissingField;
              const id = `missing-${field}`;
              return (
                <label htmlFor={id} aria-label={label} key={field}>
                  <input
                    id={id}
                    type="checkbox"
                    checked={missingFields.includes(key)}
                    onChange={(event) => toggleMissingField(key, event.target.checked)}
                  />
                  <span>{label}</span>
                </label>
              );
            })}
          </div>
        </fieldset>

        <fieldset disabled={!formEnabled}>
          <legend>6. 最も大きい懸念</legend>
          <RadioChoices<Risk>
            name="primary-risk"
            value={risk}
            onChange={setRisk}
            options={[
              ["legal", "法的境界"],
              ["privacy", "個人情報保護"],
              ["quality", "データ品質"],
              ["operations", "運用負担"],
              ["trust", "利用者の信頼"],
              ["none", "重大な懸念なし"],
            ]}
          />
        </fieldset>

        <fieldset disabled={!formEnabled}>
          <legend>7. 最も受け取りやすい形式</legend>
          <RadioChoices<OutputFormat>
            name="preferred-output"
            value={format}
            onChange={setFormat}
            options={[
              ["csv", "CSV"],
              ["pdf", "PDF"],
              ["json", "JSON"],
              ["web-form", "Web画面"],
              ["api", "API"],
            ]}
          />
        </fieldset>

        <fieldset disabled={!formEnabled}>
          <legend>8. 法律相談・正式受付との境界表示</legend>
          <RadioChoices<Boundary>
            name="legal-boundary"
            value={boundary}
            onChange={setBoundary}
            options={[
              ["clear", "現状で区別できる"],
              ["needs-review", "修正・専門家確認が必要"],
              ["unsafe", "重大な誤認リスクがある"],
            ]}
          />
        </fieldset>

        <fieldset disabled={!formEnabled}>
          <legend>9. 次の協力可能性</legend>
          <RadioChoices<NextStep>
            name="next-step"
            value={nextStep}
            onChange={setNextStep}
            options={[
              ["second-review", "修正版をもう一度評価できる"],
              ["pilot", "小規模実証を検討できる"],
              ["no", "現時点では継続しない"],
            ]}
          />
        </fieldset>

        <fieldset disabled={!formEnabled}>
          <legend>10. 証拠原本をRightsRelayへ渡す必要がある段階</legend>
          <RadioChoices<OriginalEvidenceTiming>
            name="original-evidence-timing"
            value={originalEvidenceTiming}
            onChange={setOriginalEvidenceTiming}
            options={[
              ["not-needed-initial", "初期トリアージでは原本の集中保管は不要"],
              ["after-triage", "受任・正式受付後に支援主体が受領すればよい"],
              ["before-triage", "初期トリアージ前からRightsRelayでの集中保管が必須"],
              ["unknown", "この画面だけでは判断できない"],
            ]}
          />
        </fieldset>

        <div className="evaluator-export">
          <p>出力しない情報：氏名、連絡先、所属組織、自由記述、実在案件データ、共有パケット。</p>
          <button type="button" disabled={!complete || !sampleReady} onClick={exportEvaluation}>
            {exported ? "識別子なしの評価結果を書き出しました" : "識別子なしの評価結果を書き出す"}
          </button>
        </div>
      </form>
    </section>
  );
}
