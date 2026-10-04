"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import {
  createSharePacket,
  evidenceValues,
  type ShareAssessment,
  type ShareMembership,
  type ShareNotice,
} from "../lib/share-packet";

type Membership = "" | "current" | "former" | "applied" | "never" | "unknown";
type Notice = "" | "yes" | "no" | "unknown";
type Assessment = "possible" | "unclear" | "unlikely" | "incomplete";
type ScaleAnswer = "" | "1" | "2" | "3" | "4" | "5";
type ExpectationAnswer = "" | "device" | "project" | "support" | "unknown";
type ConcernAnswer = "" | "facts" | "assessment" | "evidence" | "intent" | "export" | "none";

type Incident = {
  id: string;
  name: string;
  category: string;
  summary: string;
  affectedLabel: string;
  affectedValue: string;
  updatedAt: string;
  status: "available" | "reviewing" | "watching";
  statusLabel: string;
  sourceLabel: string;
  sourceUrl: string;
  action: "assessment" | "source";
};

type SavedState = {
  membership: Membership;
  notice: Notice;
  evidence: Record<string, boolean>;
  note: string;
  intent: Record<string, boolean>;
};

type ResearchState = {
  startedAt: string;
  clarity: ScaleAnswer;
  trust: ScaleAnswer;
  expectedDestination: ExpectationAnswer;
  concern: ConcernAnswer;
};

const STORAGE_KEY = "rightsrelay-prototype-v1";
const RESEARCH_STORAGE_KEY = "rightsrelay-research-v1";

const defaultEvidence = {
  notice: false,
  relationship: false,
  suspicious: false,
  expense: false,
};

const defaultIntent = {
  updates: false,
  sharing: false,
  research: false,
};

const defaultResearch: ResearchState = {
  startedAt: "",
  clarity: "",
  trust: "",
  expectedDestination: "",
  concern: "",
};

const incidents: Incident[] = [
  {
    id: "times-car-2026-09",
    name: "タイムズカー",
    category: "カーシェア",
    summary: "Webサイトへの不正アクセス。本人確認書類画像を含む情報の漏えいが公表されています。",
    affectedLabel: "漏えいアカウント",
    affectedValue: "約660万件",
    updatedAt: "2026.09.30",
    status: "available",
    statusLabel: "対象確認を提供中",
    sourceLabel: "公式 第3報",
    sourceUrl: "https://share.timescar.jp/news/2026/0929/1816.html",
    action: "assessment",
  },
  {
    id: "eplus-2026-09",
    name: "イープラス",
    category: "チケット",
    summary: "スマチケの払戻し情報を管理するシステムへの不正アクセス。口座情報を含む場合があります。",
    affectedLabel: "公表件数",
    affectedValue: "1,463件",
    updatedAt: "2026.09.29",
    status: "reviewing",
    statusLabel: "公表内容を確認中",
    sourceLabel: "公式発表",
    sourceUrl: "https://support-qa.eplus.jp/hc/ja/articles/62711500727961",
    action: "source",
  },
  {
    id: "gyazo-2026-09",
    name: "Gyazo",
    category: "画像共有",
    summary: "ユーザー情報と画像メタデータの一部が流出。対象画像が閲覧されるおそれも公表されています。",
    affectedLabel: "ユーザーデータ",
    affectedValue: "約2,362万件",
    updatedAt: "2026.09.25",
    status: "reviewing",
    statusLabel: "対象条件を整理中",
    sourceLabel: "公式 第二報",
    sourceUrl: "https://help-ja.gyazo.com/Gyazo%E3%81%B8%E3%81%AE%E4%B8%8D%E6%AD%A3%E3%82%A2%E3%82%AF%E3%82%BB%E3%82%B9%E3%81%AB%E9%96%A2%E3%81%99%E3%82%8B%E3%81%94%E6%A1%88%E5%86%85%202026%E5%B9%B49%E6%9C%8816%E6%97%A5-6aaa3c946af600ac645528ae",
    action: "source",
  },
  {
    id: "murauchi-2026-09",
    name: "ムラウチドットコム",
    category: "EC",
    summary: "不正アクセスによる顧客情報漏えいについて公式案内が公開されています。",
    affectedLabel: "対象規模",
    affectedValue: "確認中",
    updatedAt: "2026.09",
    status: "watching",
    statusLabel: "一次情報を確認中",
    sourceLabel: "公式窓口",
    sourceUrl: "https://murauchi.com/inquiry",
    action: "source",
  },
];

function assess(membership: Membership, notice: Notice): Assessment {
  if (!membership || !notice) return "incomplete";
  if (notice === "yes" || ["current", "former", "applied"].includes(membership)) {
    return "possible";
  }
  if (membership === "never" && notice === "no") return "unlikely";
  return "unclear";
}

const resultCopy: Record<Exclude<Assessment, "incomplete">, {
  label: string;
  title: string;
  body: string;
}> = {
  possible: {
    label: "対象の可能性があります",
    title: "公式の個別案内を確認してください",
    body: "公表された対象には、現会員・退会済み会員・入会未完了の方などが含まれます。あなた個人について漏えいした項目を、この画面だけで確定することはできません。",
  },
  unclear: {
    label: "現時点では判断できません",
    title: "公式情報の更新を待ちながら記録を残してください",
    body: "分からないことは、無理に「はい」「いいえ」へ寄せる必要はありません。通知の有無と、企業の続報を確認できるようにしておきましょう。",
  },
  unlikely: {
    label: "公表条件上は対象外の可能性が高いです",
    title: "不審な連絡には引き続き注意してください",
    body: "現在の公表条件だけに基づく結果です。新しい事実が公表された場合は判定が変わる可能性があります。",
  },
};

function Option({
  name,
  value,
  current,
  onChange,
  children,
}: {
  name: string;
  value: string;
  current: string;
  onChange: (value: string) => void;
  children: React.ReactNode;
}) {
  return (
    <label className={`choice ${current === value ? "selected" : ""}`}>
      <input
        type="radio"
        name={name}
        value={value}
        checked={current === value}
        onChange={() => onChange(value)}
      />
      <span className="radio-dot" aria-hidden="true" />
      <span>{children}</span>
    </label>
  );
}

export default function RightsRelayApp() {
  const [membership, setMembership] = useState<Membership>("");
  const [notice, setNotice] = useState<Notice>("");
  const [evidence, setEvidence] = useState(defaultEvidence);
  const [note, setNote] = useState("");
  const [intent, setIntent] = useState(defaultIntent);
  const [hydrated, setHydrated] = useState(false);
  const [saved, setSaved] = useState(false);
  const [research, setResearch] = useState<ResearchState>(defaultResearch);
  const [researchExported, setResearchExported] = useState(false);
  const [sharePreviewAccepted, setSharePreviewAccepted] = useState(false);
  const [shareExported, setShareExported] = useState(false);

  /* eslint-disable react-hooks/set-state-in-effect -- Device-local state is hydrated after mount to keep server markup deterministic. */
  useEffect(() => {
    try {
      const raw = window.localStorage.getItem(STORAGE_KEY);
      if (raw) {
        const data = JSON.parse(raw) as Partial<SavedState>;
        if (data.membership) setMembership(data.membership);
        if (data.notice) setNotice(data.notice);
        if (data.evidence) setEvidence({ ...defaultEvidence, ...data.evidence });
        if (typeof data.note === "string") setNote(data.note);
        if (data.intent) setIntent({ ...defaultIntent, ...data.intent });
      }
      const researchRaw = window.localStorage.getItem(RESEARCH_STORAGE_KEY);
      if (researchRaw) {
        const researchData = JSON.parse(researchRaw) as Partial<ResearchState>;
        setResearch({ ...defaultResearch, ...researchData });
      }
    } catch {
      // A broken local entry should never block access to the guide.
    }
    setHydrated(true);
  }, []);
  /* eslint-enable react-hooks/set-state-in-effect */

  useEffect(() => {
    if (!hydrated) return;
    const state: SavedState = { membership, notice, evidence, note, intent };
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  }, [membership, notice, evidence, note, intent, hydrated]);

  useEffect(() => {
    if (!hydrated || !research.startedAt) return;
    window.localStorage.setItem(RESEARCH_STORAGE_KEY, JSON.stringify(research));
  }, [research, hydrated]);

  const assessment = useMemo(() => assess(membership, notice), [membership, notice]);
  const completedEvidence = Object.values(evidence).filter(Boolean).length;
  const researchComplete = Boolean(
    research.startedAt &&
    research.clarity &&
    research.trust &&
    research.expectedDestination &&
    research.concern,
  );
  const canExportSharePacket = Boolean(
    assessment !== "incomplete" &&
    membership &&
    notice &&
    intent.sharing &&
    sharePreviewAccepted,
  );

  function resetLocalData() {
    if (!window.confirm("この端末に保存した回答・チェック・メモをすべて削除しますか？")) return;
    window.localStorage.removeItem(STORAGE_KEY);
    setMembership("");
    setNotice("");
    setEvidence(defaultEvidence);
    setNote("");
    setIntent(defaultIntent);
    setSaved(false);
  }

  function exportLocalData() {
    const payload = {
      version: 1,
      incident: "times-car-2026-09",
      ruleVersion: "2026-09-30-v1",
      assessment,
      answers: { membership, notice },
      evidenceStatus: evidence,
      localNote: note,
      intent,
      exportedAt: new Date().toISOString(),
      disclaimer: "このファイルは法律相談、委任、授権または請求書ではありません。",
    };
    const blob = new Blob([JSON.stringify(payload, null, 2)], {
      type: "application/json",
    });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = "rightsrelay-times-car-summary.json";
    anchor.click();
    URL.revokeObjectURL(url);
  }

  function startResearchSession() {
    const next = { ...defaultResearch, startedAt: new Date().toISOString() };
    setResearch(next);
    setResearchExported(false);
  }

  function exportResearchData() {
    if (!researchComplete) return;
    const startedAt = Date.parse(research.startedAt);
    const durationSeconds = Number.isFinite(startedAt)
      ? Math.max(0, Math.round((Date.now() - startedAt) / 1000))
      : null;
    const payload = {
      schemaVersion: 1,
      study: "rightsrelay-usability-v1",
      prototypeVersion: "2026-09-30-v2",
      durationSeconds,
      taskSignals: {
        assessmentCompleted: assessment !== "incomplete",
        evidenceChecklistUsed: completedEvidence > 0,
        intentChoiceUsed: Object.values(intent).some(Boolean),
      },
      feedback: {
        clarity: Number(research.clarity),
        trust: Number(research.trust),
        expectedDestination: research.expectedDestination,
        primaryConcern: research.concern,
      },
      excluded: [
        "氏名・連絡先",
        "会員情報",
        "対象確認の回答内容",
        "証拠チェックの内訳",
        "端末内メモ",
        "意思表示の内訳",
      ],
    };
    const blob = new Blob([JSON.stringify(payload, null, 2)], {
      type: "application/json",
    });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = "rightsrelay-usability-feedback.json";
    anchor.click();
    URL.revokeObjectURL(url);
    setResearchExported(true);
  }

  function exportSharePacket() {
    if (!canExportSharePacket || assessment === "incomplete" || !membership || !notice) return;
    const packet = createSharePacket({
      membership: membership as ShareMembership,
      notice: notice as ShareNotice,
      assessment: assessment as ShareAssessment,
      evidenceHeld: evidenceValues.filter((kind) => evidence[kind]),
      updates: intent.updates,
      research: intent.research,
    });
    const blob = new Blob([JSON.stringify(packet, null, 2)], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = "rightsrelay-support-review-packet.json";
    anchor.click();
    URL.revokeObjectURL(url);
    setShareExported(true);
  }

  return (
    <main>
      <header className="site-header">
        <a className="wordmark" href="#top" aria-label="RightsRelay ホーム">
          <span className="mark" aria-hidden="true">R</span>
          <span>RightsRelay</span>
        </a>
        <nav className="main-nav" aria-label="ページ内メニュー">
          <a href="#incidents">インシデント一覧</a>
          <a href="#how-it-works">利用の流れ</a>
          <a href="#assessment">対象確認</a>
        </nav>
        <span className="prototype-badge">検証用</span>
      </header>

      <section className="hero" id="top">
        <div className="hero-copy">
          <p className="eyebrow">PERSONAL DATA INCIDENT DIRECTORY</p>
          <h1>起きた事件を、<br />見失わない。</h1>
          <p className="lead">
            個人情報漏えいの公式発表を一か所に整理し、対象の可能性と今できることを確認します。
            支援主体が決まる前に、原告や訴訟参加者を募集するサイトではありません。
          </p>
          <div className="trust-row" aria-label="この確認で守ること">
            <span><b aria-hidden="true">✓</b> 公式発表を出典付きで整理</span>
            <span><b aria-hidden="true">✓</b> 支援希望と正式参加を区別</span>
            <span><b aria-hidden="true">✓</b> 個人運営の検証プロジェクト</span>
          </div>
        </div>

        <aside className="directory-summary" aria-label="現在の掲載状況">
          <p className="summary-label">CURRENT DIRECTORY</p>
          <strong>{incidents.length}</strong>
          <span>件のインシデントを掲載</span>
          <dl>
            <div><dt>対象確認を提供中</dt><dd>1件</dd></div>
            <div><dt>内容を整理中</dt><dd>3件</dd></div>
            <div><dt>支援希望の受付</dt><dd>未開始</dd></div>
          </dl>
          <a href="#incidents">一覧を見る <span aria-hidden="true">↓</span></a>
        </aside>
      </section>

      <section className="incident-directory" id="incidents" aria-labelledby="incident-directory-title">
        <div className="directory-heading">
          <div>
            <p className="eyebrow">INCIDENTS</p>
            <h2 id="incident-directory-title">インシデント一覧</h2>
          </div>
          <p>
            件数は企業の公式発表上の件数です。支援希望者数・正式参加者数ではありません。
            現在、連絡先や支援希望の中央受付は行っていません。
          </p>
        </div>

        <div className="incident-grid">
          {incidents.map((incident) => (
            <article className="directory-card" key={incident.id}>
              <div className="directory-card-topline">
                <span className={`directory-status status-${incident.status}`}>{incident.statusLabel}</span>
                <time>{incident.updatedAt} 更新</time>
              </div>
              <p className="incident-category">{incident.category}</p>
              <h3>{incident.name}</h3>
              <p className="incident-summary">{incident.summary}</p>
              <dl className="directory-metrics">
                <div><dt>{incident.affectedLabel}</dt><dd>{incident.affectedValue}</dd></div>
                <div><dt>支援希望</dt><dd>— <small>受付前</small></dd></div>
              </dl>
              <div className="directory-actions">
                {incident.action === "assessment" ? (
                  <a className="directory-primary" href="#assessment">対象の可能性を確認 <span aria-hidden="true">→</span></a>
                ) : (
                  <span className="directory-pending">対象確認は準備中</span>
                )}
                <a href={incident.sourceUrl} target="_blank" rel="noreferrer">{incident.sourceLabel} ↗</a>
              </div>
            </article>
          ))}
        </div>
      </section>

      <section className="process-strip" id="how-it-works" aria-label="RightsRelay利用の流れ">
        <p className="section-label">RIGHTSRELAYの役割</p>
        <ol>
          <li><span>01</span><strong>事件を選ぶ</strong><small>公式発表と更新状況を見る</small></li>
          <li><span>02</span><strong>対象を確認</strong><small>公表条件だけで整理する</small></li>
          <li><span>03</span><strong>支援を希望</strong><small>受付開始後に用途別で選ぶ</small></li>
          <li><span>04</span><strong>支援主体が判断</strong><small>正式受付・授権は別手続</small></li>
        </ol>
      </section>

      <section className="content-section facts-section" id="facts">
        <div className="section-heading">
          <p className="eyebrow">SELECTED INCIDENT · TIMES CAR</p>
          <h2>タイムズカー事件を<br />詳しく確認する。</h2>
          <p>現在、対象確認を提供しているインシデントです。企業の発表内容と、こちらの推測を混ぜません。</p>
        </div>
        <div className="fact-grid">
          <article className="fact-card confirmed">
            <span className="card-kicker">確認されていること</span>
            <ul>
              <li>約660万アカウントの情報が第三者に取得された</li>
              <li>氏名、住所、生年月日、電話番号、メールアドレスなどが対象</li>
              <li>約160万件で運転免許証画像などの漏えいを確認</li>
              <li>クレジットカード情報の漏えいは確認されていない</li>
            </ul>
          </article>
          <article className="fact-card unknown">
            <span className="card-kicker">まだ分からないこと</span>
            <ul>
              <li>あなた個人について漏えいした全項目</li>
              <li>侵入に使われた具体的な脆弱性・原因</li>
              <li>今後の補償や被害回復の内容</li>
              <li>二次被害が将来発生するかどうか</li>
            </ul>
          </article>
        </div>
        <div className="source-links">
          <span>一次情報</span>
          <a href="https://share.timescar.jp/news/2026/0928/1815.html" target="_blank" rel="noreferrer">公式 第2報 ↗</a>
          <a href="https://share.timescar.jp/news/2026/0929/1816.html" target="_blank" rel="noreferrer">公式 第3報 ↗</a>
        </div>
      </section>

      <section className="privacy-promise" aria-label="データ保護方針">
        <div>
          <p className="eyebrow">COLLECT LESS</p>
          <h2>集めないことが、<br />最初のセキュリティです。</h2>
        </div>
        <div className="promise-list">
          <p><span>入力しない</span>氏名・会員番号・免許証画像</p>
          <p><span>送信しない</span>通知メール本文・端末内メモ</p>
          <p><span>分けて選ぶ</span>更新通知・集計利用・外部提供</p>
        </div>
      </section>

      <section className="content-section tool-section" id="assessment">
        <div className="section-heading compact">
          <p className="eyebrow">STEP 01</p>
          <h2>対象の可能性を確認</h2>
          <p>回答はこの端末にだけ保存され、サーバーへ送信されません。</p>
        </div>

        <div className="assessment-layout">
          <form className="question-panel" onSubmit={(event) => event.preventDefault()}>
            <fieldset>
              <legend><span>1</span>タイムズカーとの関係を選んでください</legend>
              <Option name="membership" value="current" current={membership} onChange={(v) => setMembership(v as Membership)}>現在会員である</Option>
              <Option name="membership" value="former" current={membership} onChange={(v) => setMembership(v as Membership)}>以前会員だった</Option>
              <Option name="membership" value="applied" current={membership} onChange={(v) => setMembership(v as Membership)}>入会を申し込んだが完了しなかった</Option>
              <Option name="membership" value="never" current={membership} onChange={(v) => setMembership(v as Membership)}>利用・申込みをしたことがない</Option>
              <Option name="membership" value="unknown" current={membership} onChange={(v) => setMembership(v as Membership)}>分からない／覚えていない</Option>
            </fieldset>
            <fieldset>
              <legend><span>2</span>今回の件について個別メールを受け取りましたか</legend>
              <div className="choice-row">
                <Option name="notice" value="yes" current={notice} onChange={(v) => setNotice(v as Notice)}>受け取った</Option>
                <Option name="notice" value="no" current={notice} onChange={(v) => setNotice(v as Notice)}>受け取っていない</Option>
                <Option name="notice" value="unknown" current={notice} onChange={(v) => setNotice(v as Notice)}>分からない</Option>
              </div>
            </fieldset>
          </form>

          <aside className={`result-panel result-${assessment}`} aria-live="polite">
            {assessment === "incomplete" ? (
              <>
                <span className="result-status">回答待ち</span>
                <h3>二つの質問に回答すると、ここに整理結果が表示されます。</h3>
                <p>氏名、会員番号、通知メールの内容は入力しないでください。</p>
              </>
            ) : (
              <>
                <span className="result-status">{resultCopy[assessment].label}</span>
                <h3>{resultCopy[assessment].title}</h3>
                <p>{resultCopy[assessment].body}</p>
                <div className="result-rule">
                  <span>判定ルール</span>
                  <code>2026-09-30-v1</code>
                </div>
              </>
            )}
          </aside>
        </div>
      </section>

      <section className="content-section evidence-section" id="evidence">
        <div className="section-heading compact">
          <p className="eyebrow">STEP 02</p>
          <h2>証拠を手元で整理</h2>
          <p>ファイルはアップロードしません。何を保存したかだけをチェックします。</p>
        </div>

        <div className="evidence-layout">
          <div className="checklist-panel">
            <div className="checklist-progress">
              <span>{completedEvidence} / 4 完了</span>
              <div aria-hidden="true"><i style={{ width: `${completedEvidence * 25}%` }} /></div>
            </div>
            {[
              ["notice", "企業からの通知メールを手元に保存した", "メールアプリやPDFなど、ご自身の端末で保存"],
              ["relationship", "会員だったことが分かる記録を確認した", "過去の請求・利用履歴など。ここには入力しません"],
              ["suspicious", "不審なメール・SMSがあれば記録した", "リンクを開かず、日時と送信元が分かる形で保存"],
              ["expense", "再発行費用などの支出があれば記録した", "領収書と、何のための支出かを手元で整理"],
            ].map(([key, title, detail]) => (
              <label className="evidence-item" key={key}>
                <input
                  type="checkbox"
                  checked={evidence[key as keyof typeof evidence]}
                  onChange={(event) =>
                    setEvidence((current) => ({ ...current, [key]: event.target.checked }))
                  }
                />
                <span className="check-box" aria-hidden="true">✓</span>
                <span><strong>{title}</strong><small>{detail}</small></span>
              </label>
            ))}
          </div>

          <div className="local-note">
            <div className="local-label"><span aria-hidden="true">●</span> この端末だけに保存</div>
            <label htmlFor="incident-note">あとで確認したいこと</label>
            <textarea
              id="incident-note"
              value={note}
              onChange={(event) => setNote(event.target.value)}
              placeholder="例：9月29日に公式メールを受信。不審なSMSは今のところなし。"
              maxLength={500}
            />
            <div className="note-footer">
              <span>会員番号・免許証番号は書かないでください</span>
              <span>{note.length}/500</span>
            </div>
          </div>
        </div>
      </section>

      <section className="content-section intent-section" id="intent">
        <div className="section-heading compact">
          <p className="eyebrow">STEP 03</p>
          <h2>意思を項目ごとに記録</h2>
          <p>一括同意にはしません。この試作では、選択内容を外部へ送信しません。</p>
        </div>

        <div className="intent-layout">
          <div className="intent-options">
            {[
              ["updates", "この事案の更新を知りたい", "通知先の登録は実証段階で別途確認します"],
              ["sharing", "支援団体への情報提供を検討したい", "提供先・項目・目的を確認してから改めて同意します"],
              ["research", "識別子を含めない利用者調査に協力してもよい", "調査の内容と所要時間を見て、その都度判断できます"],
            ].map(([key, title, detail]) => (
              <label className="intent-item" htmlFor={`intent-${key}`} aria-label={title} key={key}>
                <input
                  id={`intent-${key}`}
                  type="checkbox"
                  checked={intent[key as keyof typeof intent]}
                  onChange={(event) => {
                    setSaved(false);
                    setIntent((current) => ({ ...current, [key]: event.target.checked }));
                  }}
                />
                <span className="toggle" aria-hidden="true"><i /></span>
                <span><strong>{title}</strong><small>{detail}</small></span>
              </label>
            ))}
            <button
              className="save-button"
              type="button"
              onClick={() => {
                setSaved(true);
                window.setTimeout(() => setSaved(false), 2600);
              }}
            >
              {saved ? "この端末に記録しました" : "選択内容をこの端末に記録"}
            </button>
          </div>

          <aside className="handoff-preview">
            <span className="card-kicker">支援主体に見せる情報の例</span>
            <h3>原本ではなく、<br />整理状況から渡す。</h3>
            <dl>
              <div><dt>事案ID</dt><dd>times-car-2026-09</dd></div>
              <div><dt>対象判定</dt><dd>{assessment === "incomplete" ? "未確認" : resultCopy[assessment].label}</dd></div>
              <div><dt>証拠保有状況</dt><dd>{completedEvidence} / 4 項目</dd></div>
              <div><dt>外部提供同意</dt><dd>まだ行っていません</dd></div>
            </dl>
            <p>正式な提供前に、提供先・項目・目的をもう一度表示します。</p>
            <div className="share-packet-box">
              <h4>支援主体レビュー用パケット</h4>
              <p>
                含む：会員関係の区分、通知受領区分、対象判定、保有証拠の種類、選択した意向。
                氏名、連絡先、会員番号、証拠原本、端末内メモは含みません。
              </p>
              <label>
                <input
                  type="checkbox"
                  checked={sharePreviewAccepted}
                  onChange={(event) => {
                    setSharePreviewAccepted(event.target.checked);
                    setShareExported(false);
                  }}
                />
                <span aria-hidden="true">✓</span>
                <b>内容を確認しました。ファイルを自分で保存します</b>
              </label>
              <button type="button" disabled={!canExportSharePacket} onClick={exportSharePacket}>
                {shareExported ? "最小共有パケットを書き出しました" : "最小共有パケットを書き出す"}
              </button>
              {!intent.sharing && <small>先に「支援団体への情報提供を検討したい」を選択してください。</small>}
              <small>書き出しただけでは外部送信・委任・訴訟参加になりません。</small>
            </div>
          </aside>
        </div>
      </section>

      <section className="data-controls">
        <div>
          <p className="eyebrow">YOUR DATA</p>
          <h2>あなたの記録は、あなたが管理。</h2>
          <p>現在の試作はブラウザのローカル保存だけを使用します。</p>
        </div>
        <div className="control-actions">
          <button type="button" onClick={exportLocalData}>記録を書き出す</button>
          <button className="danger-button" type="button" onClick={resetLocalData}>この端末から削除</button>
        </div>
      </section>

      <section className="research-section" id="research" aria-labelledby="research-title">
        <div className="research-intro">
          <p className="eyebrow">VALIDATION MODE</p>
          <h2 id="research-title">この試作を、<br />安全に検証する。</h2>
          <p>
            モデレーテッド調査用の記録欄です。開始するまで計測せず、回答はこの端末だけに保存します。
            書き出すファイルには、事案への回答や端末内メモを含めません。
          </p>
          {!research.startedAt ? (
            <button className="research-start" type="button" onClick={startResearchSession}>
              検証を開始する
            </button>
          ) : (
            <div className="research-active"><span aria-hidden="true" /> 検証セッション中</div>
          )}
        </div>

        <form className={`research-form ${research.startedAt ? "is-active" : ""}`} onSubmit={(event) => event.preventDefault()}>
          <fieldset disabled={!research.startedAt}>
            <legend>1. 対象確認の結果は理解できましたか</legend>
            <div className="scale-row" aria-label="理解度。1は理解できない、5は理解できた">
              {["1", "2", "3", "4", "5"].map((value) => (
                <label key={value}>
                  <input
                    type="radio"
                    name="clarity"
                    value={value}
                    checked={research.clarity === value}
                    onChange={() => setResearch((current) => ({ ...current, clarity: value as ScaleAnswer }))}
                  />
                  <span>{value}</span>
                </label>
              ))}
            </div>
            <div className="scale-labels"><span>理解できない</span><span>理解できた</span></div>
          </fieldset>

          <fieldset disabled={!research.startedAt}>
            <legend>2. 個人情報の扱いを信頼できそうですか</legend>
            <div className="scale-row" aria-label="信頼度。1は信頼できない、5は信頼できる">
              {["1", "2", "3", "4", "5"].map((value) => (
                <label key={value}>
                  <input
                    type="radio"
                    name="trust"
                    value={value}
                    checked={research.trust === value}
                    onChange={() => setResearch((current) => ({ ...current, trust: value as ScaleAnswer }))}
                  />
                  <span>{value}</span>
                </label>
              ))}
            </div>
            <div className="scale-labels"><span>信頼できない</span><span>信頼できる</span></div>
          </fieldset>

          <fieldset disabled={!research.startedAt}>
            <legend>3. 「意思を記録」すると、どこへ送られると思いましたか</legend>
            <div className="research-choices">
              {[
                ["device", "この端末だけ"],
                ["project", "RightsRelay運営"],
                ["support", "弁護士・支援団体"],
                ["unknown", "分からない"],
              ].map(([value, label]) => (
                <label key={value}>
                  <input
                    type="radio"
                    name="expectedDestination"
                    value={value}
                    checked={research.expectedDestination === value}
                    onChange={() => setResearch((current) => ({ ...current, expectedDestination: value as ExpectationAnswer }))}
                  />
                  <span>{label}</span>
                </label>
              ))}
            </div>
          </fieldset>

          <fieldset disabled={!research.startedAt}>
            <legend>4. いちばん不安だった箇所はどこですか</legend>
            <div className="research-choices">
              {[
                ["facts", "事案情報"],
                ["assessment", "対象確認"],
                ["evidence", "証拠整理"],
                ["intent", "意思表示"],
                ["export", "データ書き出し"],
                ["none", "特になし"],
              ].map(([value, label]) => (
                <label key={value}>
                  <input
                    type="radio"
                    name="concern"
                    value={value}
                    checked={research.concern === value}
                    onChange={() => setResearch((current) => ({ ...current, concern: value as ConcernAnswer }))}
                  />
                  <span>{label}</span>
                </label>
              ))}
            </div>
          </fieldset>

          <div className="research-export">
            <p>
              <strong>書き出さない情報：</strong>
              対象確認の回答、チェック内容、メモ、意思表示の内訳
            </p>
            <button type="button" disabled={!researchComplete} onClick={exportResearchData}>
              {researchExported ? "識別子なしの検証結果を書き出しました" : "識別子なしの検証結果を書き出す"}
            </button>
            {research.startedAt && (
              <button className="text-button" type="button" onClick={startResearchSession}>
                検証回答だけをやり直す
              </button>
            )}
          </div>
        </form>
      </section>

      <footer>
        <a className="wordmark" href="#top"><span className="mark" aria-hidden="true">R</span><span>RightsRelay</span></a>
        <p>小さな被害を、動かせる力に。</p>
        <div className="footer-note">
          <Link href="/support-review">支援主体向けレビュー画面</Link>
          <p>検証用プロトタイプ — 法律相談・受任・訴訟参加の受付は行っていません。</p>
        </div>
      </footer>
    </main>
  );
}
