import assert from "node:assert/strict";
import { test } from "node:test";
import { merchantIndexFiles, META_PATH } from "../scripts/merchant-index.mjs";

const snapshot = {
  incidents: [{ id: "INC-TEST", date: "2026/11/01", status: "調査中", source: "https://shop.example.jp/notice", summary: "初回公表" }],
  merchants: { prefix_length: 2, domains: [{ host: "shop.example.jp", registrable_domain: "example.jp", company: "架空テスト株式会社", incident_ids: ["INC-TEST"] }] },
};
const version = data => JSON.parse(merchantIndexFiles(data)[META_PATH]).version;

test("unchanged public data preserves the merchant index version", () => {
  assert.equal(version(snapshot), version(structuredClone(snapshot)));
});

for (const [field, value] of Object.entries({ status: "対応完了", source: "https://shop.example.jp/final-notice", summary: "調査結果を追記" })) {
  test(`updating incident ${field} changes the version even with unchanged merchant mappings`, () => {
    const changed = structuredClone(snapshot);
    changed.incidents[0][field] = value;
    assert.notEqual(version(snapshot), version(changed));
    const files = merchantIndexFiles(changed);
    assert.ok(Object.values(files).every(text => JSON.parse(text).version === version(changed)));
  });
}
