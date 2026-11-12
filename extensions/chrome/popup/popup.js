// Popup: shows the stored result for the active tab and sends user actions to the service
// worker. Everything is written with textContent; no HTML from data is ever inserted.

import { demoScenarios } from "../src/demo-data.js";

const STATUS_LABELS = {
  checking: "確認中",
  found: "情報あり",
  not_found: "登録情報なし",
  error: "取得失敗",
  unsupported: "対象外",
};

const STATUS_NOTES = {
  checking: "照合しています。",
  found: "公表された事故の情報があります。内容は公表時点と、記載の確認日時点の情報です。",
  not_found: "登録された事故情報は見つかりませんでした。これは事故がなかったことや、サイトの安全性を示すものではありません。",
  error: "情報を取得できませんでした。登録情報の有無は分かりません。「再確認」を試してください。",
  unsupported: "このページは照合の対象外です（http / https のページだけを確認します）。",
};

const $ = (id) => document.getElementById(id);
let tabId = null;

async function send(message) {
  const response = await chrome.runtime.sendMessage({ ...message, tabId });
  if (!response?.ok) {
    throw new Error(response?.error ?? "no response");
  }
  return response.result;
}

function formatDateTime(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? "—" : d.toLocaleString("ja-JP");
}

function render(result) {
  const status = result?.status ?? "error";
  $("host").textContent = result?.host ?? "—";
  const statusEl = $("status");
  statusEl.textContent = STATUS_LABELS[status] ?? STATUS_LABELS.error;
  statusEl.className = `status ${status}`;
  $("checked-at").textContent = formatDateTime(result?.checkedAt);
  $("status-note").textContent = STATUS_NOTES[status] ?? STATUS_NOTES.error;

  const demo = result?.demo ?? null;
  $("demo-banner").hidden = demo === null;
  $("demo-shop").textContent = demo ? demo.shopName : "";
  $("clear-demo").hidden = demo === null;
  if (demo) $("scenario").value = demo.scenarioId;

  const published = status === "found" && result?.kind === "published" && demo === null;
  $("data-tag").hidden = status !== "found" && demo === null;
  $("data-tag").textContent = published ? "公表情報" : "デモ版・架空データ";
  $("kind-note").hidden = !published;
  $("kind-note").textContent = published
    ? "架空データではありません。会社自身が公表した資料に基づく情報です。"
    : "";

  const list = $("incident-list");
  list.replaceChildren();
  const incidents = status === "found" ? result.incidents ?? [] : [];
  $("incidents").hidden = incidents.length === 0;
  $("shop-name").textContent = result?.shopName ?? "";
  const template = $("incident-template");
  for (const incident of incidents) {
    const item = template.content.firstElementChild.cloneNode(true);
    item.querySelector(".summary").textContent = incident.summary;
    item.querySelector(".published-on").textContent = incident.publishedOn;
    item.querySelector(".response").textContent = incident.response;
    item.querySelector(".checked-on").textContent = incident.responseCheckedOn;
    item.querySelector(".source").textContent = incident.source;
    if (incident.sourceUrl) {
      try {
        const url = new URL(incident.sourceUrl);
        if (url.protocol === "https:" && !url.username && !url.password) {
          const link = document.createElement("a");
          link.href = url.href;
          link.target = "_blank";
          link.rel = "noopener noreferrer";
          link.textContent = "公式発表を開く";
          item.querySelector(".source").replaceChildren(link);
        }
      } catch { /* Keep the source as plain text if the URL is invalid. */ }
    }
    list.append(item);
  }
}

function renderFailure(error) {
  render({ status: "error", host: $("host").textContent === "—" ? null : $("host").textContent });
  $("status-note").textContent = `拡張内部の通信に失敗しました（${error.message}）。`;
}

async function run(action) {
  try {
    render(await action());
  } catch (error) {
    renderFailure(error);
  }
}

async function init() {
  for (const scenario of demoScenarios) {
    const option = document.createElement("option");
    option.value = scenario.id;
    option.textContent = scenario.label;
    $("scenario").append(option);
  }

  // Normally the active tab. "popup.html?tabId=N" (opened as a page for debugging or tests)
  // shows tab N instead.
  const requested = Number(new URLSearchParams(location.search).get("tabId"));
  const [tab] = Number.isInteger(requested) && requested > 0
    ? [await chrome.tabs.get(requested)]
    : await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab) {
    render({ status: "unsupported", host: null });
    return;
  }
  tabId = tab.id;

  $("recheck").addEventListener("click", () => run(() => send({ type: "recheck" })));
  $("apply-demo").addEventListener("click", () => run(() => send({ type: "setDemo", scenarioId: $("scenario").value })));
  $("clear-demo").addEventListener("click", () => run(() => send({ type: "clearDemo" })));

  // Keep the popup in step while a check finishes or the page changes.
  chrome.storage.onChanged.addListener((changes, area) => {
    const change = changes[`tab:${tabId}`];
    if (area === "session" && change?.newValue) {
      render(change.newValue);
    }
  });

  await run(() => send({ type: "get" }));
}

init();
