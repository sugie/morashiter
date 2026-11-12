// Built-in data for the demo version. Every shop, incident and host here is fictional.
// Hosts end in ".localhost", which browsers send to this computer only, so the records can
// never be attached to a real website. Use the local test pages (test-site/) to visit them.

// Words used for "what happened after the incident": facts we checked, never a judgement.
export const RESPONSE_LABELS = ["対策を公表", "調査中", "追加報告を確認できず"];

export const demoRecords = [
  {
    id: "DEMO-001",
    shopName: "あおぞら雑貨店（架空）",
    hosts: ["aozora-zakka.localhost", "www.aozora-zakka.localhost"],
    incidents: [
      {
        summary: "会員情報の一部が外部から閲覧できる状態だった（架空）",
        publishedOn: "2026-08-05",
        response: "対策を公表",
        responseCheckedOn: "2026-10-20",
        source: "デモ資料（架空）",
      },
      {
        summary: "会員アカウントへの不正ログイン（架空）",
        publishedOn: "2026-10-10",
        response: "調査中",
        responseCheckedOn: "2026-10-28",
        source: "デモ資料（架空）",
      },
    ],
  },
  {
    id: "DEMO-002",
    shopName: "くも書房（架空）",
    hosts: ["kumo-books.localhost"],
    incidents: [
      {
        summary: "決済画面の改ざんによるカード情報流出の可能性（架空）",
        publishedOn: "2026-07-01",
        response: "追加報告を確認できず",
        responseCheckedOn: "2026-10-25",
        source: "デモ資料（架空）",
      },
    ],
  },
];

// Looking these up fails on purpose, to show the "取得失敗" state.
export const demoFailingHosts = ["fail-check.localhost"];

// Scenarios the popup can show on any page. They are not about the site being viewed.
export const demoScenarios = [
  {
    id: "published",
    label: "事故あり・対策を公表",
    shopName: "ひかり家電（架空）",
    status: "found",
    incidents: [
      {
        summary: "通販サイトの顧客情報への不正アクセス（架空）",
        publishedOn: "2026-06-12",
        response: "対策を公表",
        responseCheckedOn: "2026-10-30",
        source: "デモ資料（架空）",
      },
    ],
  },
  {
    id: "investigating",
    label: "事故あり・調査中",
    shopName: "みどり食品（架空）",
    status: "found",
    incidents: [
      {
        summary: "注文確認メールの誤送信による氏名・住所の流出（架空）",
        publishedOn: "2026-10-22",
        response: "調査中",
        responseCheckedOn: "2026-10-31",
        source: "デモ資料（架空）",
      },
    ],
  },
  {
    id: "none",
    label: "登録情報なし",
    shopName: "ほし文具（架空）",
    status: "not_found",
    incidents: [],
  },
  {
    id: "failure",
    label: "取得失敗",
    shopName: "うみ堂（架空）",
    status: "error",
    incidents: [],
  },
];
