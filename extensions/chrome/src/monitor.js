// Per-tab checking: works out the host of each tab, looks it up, stores the result in
// session storage (so it survives the service worker stopping) and sets that tab's badge.
// It knows nothing about chrome.tabs events or the popup; background.js wires those in.
//
// Result stored under "tab:<id>":
//   { tabId, host, status, kind, shopName, incidents, checkedAt, demo }
//   kind:   "demo" (fictional) or "published" (a real company's own notice), when found.
//   status: "checking" | "found" | "not_found" | "error" | "unsupported"
//   demo:   null, or { scenarioId, shopName } when a demo scenario is shown instead.
// Only the host is stored, never the full URL.

import { classifyUrl } from "./host.js";

const BADGE_COLOR = "#b3261e";

export function createMonitor({ api, dataSource, scenarios, now = () => new Date() }) {
  const pending = new Map(); // tabId -> { host, promise }
  const latestHost = new Map(); // tabId -> host the tab most recently asked for
  const generations = new Map();
  const saves = new Map();

  const resultKey = (tabId) => `tab:${tabId}`;
  const demoKey = (tabId) => `demo:${tabId}`;

  async function read(key) {
    const items = await api.storage.session.get(key);
    return items[key] ?? null;
  }

  async function save(result, generation) {
    const previous = saves.get(result.tabId) ?? Promise.resolve();
    const task = previous.catch(() => {}).then(async () => {
      if (generations.get(result.tabId) !== generation) return (await read(resultKey(result.tabId))) ?? result;
      await applyBadge(result);
      if (generations.get(result.tabId) !== generation) return (await read(resultKey(result.tabId))) ?? result;
      await api.storage.session.set({ [resultKey(result.tabId)]: result });
      return result;
    });
    saves.set(result.tabId, task);
    try { return await task; } finally { if (saves.get(result.tabId) === task) saves.delete(result.tabId); }
  }

  async function applyBadge(result) {
    const count = result.status === "found" ? result.incidents.length : 0;
    const text = count > 0 ? String(count) : "";
    await api.action.setBadgeText({ tabId: result.tabId, text });
    if (text !== "") {
      await api.action.setBadgeBackgroundColor({ tabId: result.tabId, color: BADGE_COLOR });
    }
  }

  function base(tabId, host) {
    return { tabId, host, status: "checking", kind: null, shopName: null, incidents: [], checkedAt: null, demo: null };
  }

  function fromScenario(tabId, host, scenario) {
    return {
      ...base(tabId, host),
      status: scenario.status,
      shopName: scenario.shopName,
      incidents: structuredClone(scenario.incidents),
      checkedAt: now().toISOString(),
      demo: { scenarioId: scenario.id, shopName: scenario.shopName },
    };
  }

  async function lookup(tabId, host, force) {
    try {
      const found = await dataSource.lookupHost(host, { force });
      return {
        ...base(tabId, host),
        status: found.status === "found" ? "found" : "not_found",
        kind: found.status === "found" ? found.kind ?? "demo" : null,
        shopName: found.shopName ?? null,
        incidents: found.status === "found" ? found.incidents : [],
        checkedAt: now().toISOString(),
      };
    } catch {
      return { ...base(tabId, host), status: "error", checkedAt: now().toISOString() };
    }
  }

  async function handleUrl(tabId, url, { force = false } = {}) {
    const target = classifyUrl(url);
    if (!target.supported) {
      const generation = Symbol();
      generations.set(tabId, generation);
      latestHost.set(tabId, null);
      pending.delete(tabId);
      return save({ ...base(tabId, null), status: "unsupported", checkedAt: now().toISOString() }, generation);
    }
    const host = target.host;
    latestHost.set(tabId, host);

    // Register the work before any await, so overlapping events for the same host share it.
    const inFlight = pending.get(tabId);
    if (!force && inFlight && inFlight.host === host) {
      return inFlight.promise;
    }
    const generation = Symbol();
    generations.set(tabId, generation);
    const promise = check(tabId, host, force, generation);
    pending.set(tabId, { host, promise });
    try {
      return await promise;
    } finally {
      if (pending.get(tabId)?.promise === promise) {
        pending.delete(tabId);
      }
    }
  }

  async function check(tabId, host, force, generation) {
    const demoId = await read(demoKey(tabId));
    if (demoId) {
      const scenario = scenarios.find((s) => s.id === demoId);
      if (scenario) {
        return save(fromScenario(tabId, host, scenario), generation);
      }
    }

    if (!force) {
      const stored = await read(resultKey(tabId));
      // Reuse a finished, non-demo result for the same host. A failure, or a "checking" left
      // behind by a stopped worker, is looked up again.
      const age = now().getTime() - Date.parse(stored?.checkedAt);
      const fresh = age >= 0 && age < (dataSource.resultTtlMs ?? Infinity);
      if (fresh && stored && stored.host === host && stored.demo === null && (stored.status === "found" || stored.status === "not_found")) {
        return save(stored, generation);
      }
    }

    await save(base(tabId, host), generation);
    if (generations.get(tabId) !== generation) return read(resultKey(tabId));
    const result = await lookup(tabId, host, force);
    // The tab may have moved on, or a demo may have been switched on, while we waited:
    // never let an old answer overwrite a newer one.
    if (generations.get(tabId) !== generation || latestHost.get(tabId) !== host || (await read(demoKey(tabId)))) {
      return (await read(resultKey(tabId))) ?? result;
    }
    return save(result, generation);
  }

  async function setDemo(tabId, scenarioId, url) {
    if (!scenarios.some((s) => s.id === scenarioId)) {
      throw new Error(`unknown scenario: ${scenarioId}`);
    }
    await api.storage.session.set({ [demoKey(tabId)]: scenarioId });
    pending.delete(tabId);
    return handleUrl(tabId, url, { force: true });
  }

  async function clearDemo(tabId, url) {
    await api.storage.session.remove(demoKey(tabId));
    pending.delete(tabId);
    return handleUrl(tabId, url, { force: true });
  }

  async function getResult(tabId) {
    return read(resultKey(tabId));
  }

  async function forgetTab(tabId) {
    pending.delete(tabId);
    latestHost.delete(tabId);
    generations.delete(tabId);
    await saves.get(tabId)?.catch(() => {});
    await api.storage.session.remove([resultKey(tabId), demoKey(tabId)]);
  }

  return { handleUrl, setDemo, clearDemo, getResult, forgetTab };
}
