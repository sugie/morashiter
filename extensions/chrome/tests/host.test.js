import { test } from "node:test";
import assert from "node:assert/strict";
import { classifyUrl } from "../src/host.js";

test("http and https pages give a lower-case host without port or trailing dot", () => {
  assert.deepEqual(classifyUrl("https://Zakka.Test.Localhost./items/1?q=1#x"), { supported: true, host: "zakka.test.localhost" });
  assert.deepEqual(classifyUrl("http://zakka.test.localhost:8765/"), { supported: true, host: "zakka.test.localhost" });
});

test("internationalised hosts are compared in punycode form", () => {
  assert.deepEqual(classifyUrl("https://例え.テスト/"), { supported: true, host: "xn--r8jz45g.xn--zckzah" });
});

test("non-web pages are unsupported", () => {
  for (const url of [
    "chrome://extensions/",
    "chrome-extension://abcdefghijklmnop/popup.html",
    "edge://settings",
    "about:blank",
    "file:///C:/Users/test/page.html",
    "view-source:https://zakka.test.localhost/",
    "data:text/html,hello",
    "ftp://zakka.test.localhost/",
    "javascript:void(0)",
  ]) {
    assert.equal(classifyUrl(url).supported, false, url);
  }
});

test("missing or malformed urls are unsupported, not an error", () => {
  for (const url of [undefined, null, "", "not a url", "https://"]) {
    assert.equal(classifyUrl(url).supported, false, String(url));
  }
});
