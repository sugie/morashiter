"use client";
/* Static S3 entry also imports this component: use a native home link. */
/* eslint-disable @next/next/no-html-link-for-pages */
import { useEffect, useRef, useState, useSyncExternalStore } from "react";
import { incidents, type Incident } from "./morashiter-data";
import "./morashiter.css";


let currentTheme: "light"|"dark"|null = null;
function readTheme(): "light"|"dark" {
  try {const saved = localStorage.getItem("morashiter-theme"); if(saved === "light" || saved === "dark") return saved;} catch { /* Storage may be disabled. */ }
  return currentTheme ?? (window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");
}
function subscribeTheme(notify: () => void) {
  const media = window.matchMedia("(prefers-color-scheme: dark)");
  window.addEventListener("storage",notify); window.addEventListener("morashiter-theme-change",notify); media.addEventListener("change",notify);
  return () => {window.removeEventListener("storage",notify); window.removeEventListener("morashiter-theme-change",notify); media.removeEventListener("change",notify);};
}

const dateLabel = (i: Incident) => `${i.date}${i.report ? ` ${i.report}` : ""}`;
const hash = (i: Incident) => `#${i.id.toLowerCase()}`;
function Badge({ i }: { i: Incident }) { return <span className={`badge ${i.disclosure === "漏えいの可能性" ? "possible" : i.disclosure === "漏えい確認" ? "" : "unknown"}`}>{i.disclosure}</span>; }

function Detail({ i, close }: { i: Incident; close: () => void }) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null;
    const y = window.scrollY;
    const b = document.body;
    const original = { position:b.style.position, top:b.style.top, width:b.style.width, overflow:b.style.overflow };
    ref.current?.showModal();
    Object.assign(b.style, {position:"fixed",top:`-${y}px`,width:"100%",overflow:"hidden"});
    return () => { Object.assign(b.style,original); window.scrollTo(0,y); previous?.focus({preventScroll:true}); };
  }, []);
  return <dialog ref={ref} className="m-dialog" aria-labelledby="detail-title" onCancel={e => {e.preventDefault();close();}}>
    <div className="detail-top"><span>公表情報の詳細</span><button type="button" aria-label="詳細を閉じる" onClick={close}>×</button></div>
    <div className="detail-body"><h2 id="detail-title">{i.name}</h2><p className="company">{i.company}</p><p className="date">公表資料：{dateLabel(i)}</p>
      <div className="sources"><a href={i.source} target="_blank" rel="noopener noreferrer">公式発表を読む（別タブ）</a></div>
      <p className="detail-lead">{i.summary}</p>
      <dl className="facts"><div><dt>影響規模</dt><dd>{i.quantity}</dd></div><div><dt>対象情報</dt><dd>{i.dataType}</dd></div><div><dt>漏えい区分</dt><dd><Badge i={i}/></dd></div></dl>
      <div className="report-body"><p>{i.target}</p><p>{i.response}</p></div>
      <a className="contact-link" href={i.contact} target="_blank" rel="noopener noreferrer">{i.contact === i.source ? "対象範囲・案内を公式資料で確認 ↗" : "問い合わせ先・対象者への案内 ↗"}</a>

    </div>
  </dialog>;
}

export default function MorashiterApp() {
  const theme = useSyncExternalStore(subscribeTheme, readTheme, () => "light");
  const [category,setCategory] = useState("すべて");
  function toggleTheme() { currentTheme = theme === "light" ? "dark" : "light"; try {localStorage.setItem("morashiter-theme",currentTheme);} catch { /* Switching works even when storage is unavailable. */ } window.dispatchEvent(new Event("morashiter-theme-change")); }
  const [query,setQuery] = useState("");
  const [selected,setSelected] = useState<Incident|null>(null);
  const pushed = useRef<string|null>(null);
  useEffect(() => {
    const sync = () => {setSelected(incidents.find(i => hash(i) === location.hash) ?? null);if(location.hash !== pushed.current) pushed.current=null;};
    sync(); window.addEventListener("hashchange",sync); window.addEventListener("popstate",sync);
    return () => {window.removeEventListener("hashchange",sync);window.removeEventListener("popstate",sync);};
  },[]);
  function open(i:Incident) { if(location.hash !== hash(i)) {history.pushState(null,"",hash(i));pushed.current=hash(i);}setSelected(i); }
  function close() { if(pushed.current === location.hash) history.back(); else {history.replaceState(null,"",location.pathname+location.search);setSelected(null);} }
  const filtered = [...incidents].sort((a,b) => b.date.localeCompare(a.date)).filter(i => category === "すべて" || i.type === category).filter(i => `${i.name} ${i.company} ${i.type} ${i.brief}`.toLowerCase().includes(query.trim().toLowerCase()));
  return <div className="morashiter" data-theme={theme}><header className="m-header"><div className="header-inner"><a href="/" className="brand"><span className="brand-mark" aria-hidden="true">M</span>Morashiter</a><span className="header-label">企業インシデント公表情報</span><button type="button" className="theme-toggle" onClick={toggleTheme} aria-label={theme === "light" ? "ダーク表示に切り替える" : "ライト表示に切り替える"}>{theme === "light" ? "☾ ダーク" : "☀ ライト"}</button></div></header><main className="m-main">
    <div className="breadcrumb">モラシッター <span>/ 公表情報</span></div>
    <div className="page-heading"><h1>企業インシデント一覧</h1><span className="trial">試験公開</span></div><p className="intro">企業・サービスの情報漏えいなどを、公表資料からたどる。</p>
    <div className="tools"><div className="category-tabs" role="group" aria-label="インシデント種別">{["すべて","不正アクセス","ランサムウェア攻撃","クラウド設定不備"].map(c => <button type="button" key={c} aria-pressed={category === c} onClick={() => setCategory(c)}>{c}</button>)}</div><label className="search"><span className="sr">企業・サービス・種別を検索</span><input type="search" value={query} onChange={e => setQuery(e.target.value)} placeholder="企業・サービスを検索…"/></label></div>
    <p className="results" aria-live="polite" aria-atomic="true">{filtered.length}件 <span>公表資料の日付が新しい順</span></p>
    <ul className="record-list">{filtered.map(i => <li key={i.id}><button className="record" onClick={() => open(i)} aria-haspopup="dialog" aria-label={`${i.name}の詳細を開く`}><span className="company-mark" aria-hidden="true">{i.name === "イープラス" ? "e+" : i.name === "タイムズカー" ? "T" : i.name === "ASKUL・LOHACO" ? "A" : i.name === "KADOKAWAグループ" ? "K" : "G"}</span><span className="record-copy"><span className="record-name">{i.name}</span><span className="record-summary">{i.brief}</span><span className="record-company">{i.company}</span></span><span className="record-metrics"><span className="metric scale"><strong>{i.quantity}</strong><span>影響規模</span></span><span className="metric publication"><strong>{i.date}</strong><span>{i.report || "公表日"}</span></span><span className="metric kind"><strong>{i.type}</strong><span>{i.disclosure}</span></span></span><span className="chevron" aria-hidden="true">›</span></button></li>)}</ul>
    {filtered.length === 0 && <p className="empty" role="status">該当する情報はありません。検索語を変えてください。</p>}
    <p className="explanation">掲載内容は各公表資料の日付時点の記録です。最新情報や対象範囲は詳細にある公式資料をご確認ください。件数の単位は資料に従い、合算・ランキングは行っていません。</p><footer className="m-footer"><span>モラシッター運営</span><a href="mailto:morashiter@sapp.sakura.ne.jp">訂正・問い合わせ</a></footer>
    </main>{selected && <Detail key={selected.id} i={selected} close={close}/>}</div>;
}
