// Synthetic public API data matching PR #13 (aa68c09). No real incident data.
export const meta = {
  schema_version: "merchant-meta.v1", version: "0123456789ab",
  data_as_of: "2026-11-09", prefix_length: 2, hash: "sha256", key: "registrable-domain",
};
export const bucket = {
  schema_version: "merchant-bucket.v1", version: meta.version, prefix: "a3",
  domains: { "example.com": { hosts: ["www.example.com"], company: "テスト商店（架空）", incident_ids: ["INC-TEST"] } },
  incidents: { "INC-TEST": {
    id: "INC-TEST", name: "テスト商店（架空）", company: "テスト株式会社（架空）", type: "不正アクセス",
    date: "2026/11/09", disclosure: "調査中", quantity: "確認中", dataType: "会員情報",
    status: "調査を継続", source: "https://notice.example/notice", contact: "https://notice.example/contact",
  } },
};

export function memoryStorage() {
  const items = {};
  return {
    items,
    async get(key) { return structuredClone(key == null ? items : { [key]: items[key] }); },
    async set(values) { Object.assign(items, structuredClone(values)); },
    async remove(key) { delete items[key]; },
  };
}

export function apiFixture() {
  const calls = [];
  const state = { meta: structuredClone(meta), bucket: structuredClone(bucket), status: 200 };
  return {
    calls, state,
    async fetcher(url, options) {
      calls.push({ url, options });
      const data = url.endsWith("index-meta.json") ? state.meta : state.bucket;
      return new Response(JSON.stringify(data), { status: state.status, headers: { "Content-Type": "application/json" } });
    },
  };
}
