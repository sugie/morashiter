// A small stand-in for the chrome.* APIs the monitor uses: session storage and per-tab badges.

export function createFakeChrome() {
  const store = new Map();
  const badges = new Map();
  const colors = new Map();
  return {
    store,
    badges,
    colors,
    storage: {
      session: {
        async get(keys) {
          const list = keys === null || keys === undefined ? [...store.keys()] : Array.isArray(keys) ? keys : [keys];
          const out = {};
          for (const k of list) if (store.has(k)) out[k] = structuredClone(store.get(k));
          return out;
        },
        async set(items) {
          for (const [k, v] of Object.entries(items)) store.set(k, structuredClone(v));
        },
        async remove(keys) {
          for (const k of Array.isArray(keys) ? keys : [keys]) store.delete(k);
        },
      },
    },
    action: {
      async setBadgeText({ tabId, text }) {
        badges.set(tabId, text);
      },
      async setBadgeBackgroundColor({ tabId, color }) {
        colors.set(tabId, color);
      },
    },
  };
}

// A data source that counts calls and can be paused to test overlapping events.
export function countingSource(inner) {
  const calls = [];
  let gate = null;
  return {
    calls,
    hold() {
      let release;
      gate = new Promise((r) => (release = r));
      return () => {
        gate = null;
        release();
      };
    },
    async lookupHost(host) {
      calls.push(host);
      if (gate) await gate;
      return inner.lookupHost(host);
    },
  };
}
