// Fixed test data. Every company, shop and host here is fictional; nothing refers to a real business.

export const records = [
  {
    id: "DEMO-T-001",
    shopName: "テスト雑貨店（架空）",
    hosts: ["zakka.test.localhost", "www.zakka.test.localhost"],
    incidents: [
      {
        summary: "テスト用の会員情報流出（架空）",
        publishedOn: "2026-09-01",
        response: "対策を公表",
        responseCheckedOn: "2026-10-01",
        source: "デモ資料（架空）",
      },
      {
        summary: "テスト用の不正ログイン（架空）",
        publishedOn: "2026-10-01",
        response: "調査中",
        responseCheckedOn: "2026-10-15",
        source: "デモ資料（架空）",
      },
    ],
  },
  {
    id: "DEMO-T-002",
    shopName: "テスト書店（架空）",
    hosts: ["books.test.localhost"],
    incidents: [
      {
        summary: "テスト用のカード情報流出（架空）",
        publishedOn: "2026-08-20",
        response: "追加報告を確認できず",
        responseCheckedOn: "2026-10-20",
        source: "デモ資料（架空）",
      },
    ],
  },
];

// A host whose lookup fails on purpose, to exercise the error path.
export const failingHosts = ["broken.test.localhost"];
