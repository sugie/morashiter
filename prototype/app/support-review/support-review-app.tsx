"use client";

import { useMemo, useRef, useState } from "react";
import Link from "next/link";
import {
  aggregatePackets,
  samplePackets,
  validateSharePacket,
  type SharePacket,
} from "../../lib/share-packet";
import SupportEvaluation from "./support-evaluation";

const membershipLabels = {
  current: "現在会員",
  former: "退会済み",
  applied: "申込み未完了",
  never: "利用・申込みなし",
  unknown: "不明",
};

const evidenceLabels = {
  notice: "企業通知",
  relationship: "会員・申込記録",
  suspicious: "不審連絡の記録",
  expense: "支出記録",
};

export default function SupportReviewApp() {
  const [packets, setPackets] = useState<SharePacket[]>([]);
  const [rejections, setRejections] = useState<string[]>([]);
  const [usingSamples, setUsingSamples] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const aggregate = useMemo(() => aggregatePackets(packets), [packets]);

  async function importFiles(files: FileList | null) {
    if (!files) return;
    const selected = [...files].slice(0, 100);
    const accepted: SharePacket[] = [];
    const rejected: string[] = [];

    for (const [index, file] of selected.entries()) {
      if (file.size > 64 * 1024) {
        rejected.push(`${index + 1}件目：64KBを超えるため拒否`);
        continue;
      }
      try {
        const value = JSON.parse(await file.text());
        const result = validateSharePacket(value);
        if (result.ok) accepted.push(result.packet);
        else rejected.push(`${index + 1}件目：${result.reason}`);
      } catch {
        rejected.push(`${index + 1}件目：有効なJSONではありません`);
      }
    }

    if (files.length > 100) rejected.push("一度に読み込める上限100件を超えた分は拒否しました");
    setPackets((current) => [...current, ...accepted]);
    setRejections((current) => [...current, ...rejected]);
    setUsingSamples(false);
    if (inputRef.current) inputRef.current.value = "";
  }

  function loadSamples() {
    setPackets(samplePackets);
    setRejections([]);
    setUsingSamples(true);
  }

  function clearAll() {
    setPackets([]);
    setRejections([]);
    setUsingSamples(false);
    if (inputRef.current) inputRef.current.value = "";
  }

  function exportAggregate() {
    if (!aggregate.packetCount) return;
    const payload = {
      schema: "rightsrelay.support-aggregate.v1",
      incidentId: "times-car-2026-09",
      ruleVersion: "2026-09-30-v1",
      isSample: usingSamples,
      aggregate,
      excluded: ["packetId", "氏名・連絡先", "会員番号", "証拠原本", "端末内メモ"],
      disclaimer: "正式受付、法的評価、委任または訴訟参加を示すものではありません。",
    };
    const blob = new Blob([JSON.stringify(payload, null, 2)], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = "rightsrelay-support-aggregate.json";
    anchor.click();
    URL.revokeObjectURL(url);
  }

  const maxEvidence = Math.max(aggregate.packetCount, 1);

  return (
    <main className="support-main">
      <header className="support-header">
        <Link className="wordmark" href="/" aria-label="RightsRelay 利用者向け画面へ戻る">
          <span className="mark" aria-hidden="true">R</span>
          <span>RightsRelay</span>
        </Link>
        <span>支援主体向けレビュー</span>
        <Link href="/">利用者向け画面へ戻る</Link>
      </header>

      <section className="support-hero">
        <div>
          <p className="eyebrow">SUPPORT-BODY REVIEW</p>
          <h1>原本を集める前に、<br />案件の輪郭をつかむ。</h1>
        </div>
        <div className="support-hero-copy">
          <p>
            利用者が自分で書き出した最小共有パケットだけを、この端末内で検証・集計します。
            ファイルはアップロードされず、画面を閉じると消えます。
          </p>
          <div className="support-limits">
            <span>正式受付ではない</span>
            <span>法的評価をしない</span>
            <span>原本を保持しない</span>
          </div>
        </div>
      </section>

      <section className="import-panel" aria-labelledby="import-title">
        <div>
          <p className="eyebrow">LOCAL IMPORT</p>
          <h2 id="import-title">共有パケットを読み込む</h2>
          <p>対応形式以外の項目が一つでも含まれるファイルは拒否します。ファイル名は記録・表示しません。</p>
        </div>
        <div className="import-actions">
          <label className="file-button">
            JSONファイルを選ぶ
            <input
              ref={inputRef}
              type="file"
              accept="application/json,.json"
              multiple
              onChange={(event) => void importFiles(event.target.files)}
            />
          </label>
          <button type="button" onClick={loadSamples}>架空サンプル5件で試す</button>
          <button className="plain-button" type="button" onClick={clearAll}>すべて消去</button>
        </div>
      </section>

      {rejections.length > 0 && (
        <section className="rejection-panel" aria-live="polite">
          <strong>{rejections.length}件を安全上の理由で拒否しました</strong>
          <ul>{rejections.map((reason, index) => <li key={`${reason}-${index}`}>{reason}</li>)}</ul>
        </section>
      )}

      <section className="support-dashboard" aria-live="polite">
        <div className="dashboard-heading">
          <div>
            <p className="eyebrow">AGGREGATE ONLY</p>
            <h2>{usingSamples ? "架空データの集計" : "端末内の集計"}</h2>
          </div>
          <button type="button" disabled={!aggregate.packetCount} onClick={exportAggregate}>集計結果だけを書き出す</button>
        </div>

        <div className="metric-grid">
          <article className="metric-card primary-metric">
            <span>有効な共有パケット</span>
            <strong>{aggregate.packetCount}</strong>
            <small>重複除外 {aggregate.duplicateCount}件</small>
          </article>
          <article className="metric-card">
            <span>対象の可能性あり</span>
            <strong>{aggregate.assessment.possible}</strong>
            <small>公表条件による整理</small>
          </article>
          <article className="metric-card">
            <span>企業通知を保有</span>
            <strong>{aggregate.evidence.notice}</strong>
            <small>原本は未取得</small>
          </article>
          <article className="metric-card">
            <span>更新情報を希望</span>
            <strong>{aggregate.intent.updates}</strong>
            <small>連絡先は未取得</small>
          </article>
        </div>

        <div className="dashboard-grid">
          <article className="breakdown-card">
            <h3>会員関係の区分</h3>
            <dl>
              {Object.entries(membershipLabels).map(([key, label]) => (
                <div key={key}><dt>{label}</dt><dd>{aggregate.membership[key as keyof typeof aggregate.membership]}</dd></div>
              ))}
            </dl>
          </article>

          <article className="breakdown-card">
            <h3>保有している証拠の種類</h3>
            <ul className="bar-list">
              {Object.entries(evidenceLabels).map(([key, label]) => {
                const count = aggregate.evidence[key as keyof typeof aggregate.evidence];
                return (
                  <li key={key}>
                    <div><span>{label}</span><strong>{count}</strong></div>
                    <i aria-hidden="true"><b style={{ width: `${(count / maxEvidence) * 100}%` }} /></i>
                  </li>
                );
              })}
            </ul>
          </article>

          <article className="breakdown-card boundary-card">
            <h3>この集計だけでは判断できないこと</h3>
            <ul>
              <li>損害賠償請求が成立するか</li>
              <li>証拠原本が真正・十分であるか</li>
              <li>誰を正式な対象者として受け付けるか</li>
              <li>委任・授権・訴訟参加が成立したか</li>
            </ul>
          </article>
        </div>
      </section>

      <SupportEvaluation sampleReady={usingSamples && aggregate.packetCount === samplePackets.length} />

      <section className="support-handoff">
        <span>次の検証</span>
        <p>弁護士・特定適格消費者団体に、この項目だけで初期トリアージができるか、追加項目が必要かを確認します。</p>
      </section>

      <footer className="support-footer">
        <p>検証用プロトタイプ — 読み込んだファイルはサーバーへ送信・保存しません。</p>
      </footer>
    </main>
  );
}
