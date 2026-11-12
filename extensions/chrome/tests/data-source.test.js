import { test } from "node:test";
import assert from "node:assert/strict";
import { createLocalDataSource } from "../src/data-source.js";
import { records, failingHosts } from "./fixtures.js";

const source = createLocalDataSource({ records, failingHosts });

test("an exact host returns its fictional incidents", async () => {
  const result = await source.lookupHost("zakka.test.localhost");
  assert.equal(result.status, "found");
  assert.equal(result.shopName, "テスト雑貨店（架空）");
  assert.equal(result.incidents.length, 2);
  assert.equal(result.incidents[0].response, "対策を公表");
});

test("a listed alias matches the same record", async () => {
  const result = await source.lookupHost("www.zakka.test.localhost");
  assert.equal(result.status, "found");
  assert.equal(result.recordId, "DEMO-T-001");
});

test("no partial matching: similar, parent or unlisted subdomains are not found", async () => {
  for (const host of ["zakka.test.localhost.evil.example", "evil-zakka.test.localhost", "test.localhost", "shop.books.test.localhost", "books.test.localhos"]) {
    assert.equal((await source.lookupHost(host)).status, "not_found", host);
  }
});

test("a failing host rejects, so callers can show a fetch failure instead of 'not found'", async () => {
  await assert.rejects(source.lookupHost(failingHosts[0]));
});

test("the returned incidents are copies: callers cannot change the stored data", async () => {
  const first = await source.lookupHost("books.test.localhost");
  first.incidents[0].response = "changed";
  const second = await source.lookupHost("books.test.localhost");
  assert.equal(second.incidents[0].response, "追加報告を確認できず");
});

test("empty input is not found", async () => {
  assert.equal((await source.lookupHost("")).status, "not_found");
});
