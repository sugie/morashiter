// Route fictional localhost shops to bundled data and public hosts to the remote index.
//
// Result: { status: "found", recordId, kind ("demo" | "published"), shopName, incidents: [...] } or { status: "not_found" }.
// A failed lookup rejects; callers must show that as a failure, never as "not found".

import { demoRecords, demoFailingHosts } from "./demo-data.js";

export function createDataSource({ remote }) {
  const local = createLocalDataSource({ records: demoRecords, failingHosts: demoFailingHosts });
  return {
    resultTtlMs: remote.resultTtlMs ?? 600_000,
    lookupHost(host, options) {
      // Only this dedicated judge-demo host aliases the fictional local shop.
      if (host === "demo.morashiter.com") {
        return local.lookupHost("aozora-zakka.localhost");
      }
      return host === "localhost" || host.endsWith(".localhost")
        ? local.lookupHost(host) : remote.lookupHost(host, options);
    },
  };
}

export function createLocalDataSource({ records, failingHosts = [] }) {
  const byHost = new Map();
  for (const record of records) {
    for (const host of record.hosts) {
      byHost.set(host.toLowerCase(), record);
    }
  }
  const failing = new Set(failingHosts.map((h) => h.toLowerCase()));

  return {
    async lookupHost(host) {
      const key = String(host ?? "").toLowerCase();
      if (failing.has(key)) {
        throw new Error("lookup_failed");
      }
      // Exact host or listed alias only. No suffix or substring matching, so a fictional
      // record can never attach itself to some other site.
      const record = byHost.get(key);
      if (!record) {
        return { status: "not_found" };
      }
      return {
        status: "found",
        recordId: record.id,
        kind: record.kind ?? "demo",
        shopName: record.shopName,
        incidents: structuredClone(record.incidents),
      };
    },
  };
}
