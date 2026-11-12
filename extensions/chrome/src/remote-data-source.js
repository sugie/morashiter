import { normalizeHost, registrableDomain } from "./public-suffixes.js";

const BASE = "https://www.morashiter.com/api/v1/";
const META_TTL = 6 * 60 * 60 * 1000;
const BUCKET_TTL = 10 * 60 * 1000;
const CACHE_KEY = "merchant-cache:v1";
const MAX_TEXT = 1024 * 1024;
const MAX_BUCKETS = 16;
const object = value => value !== null && typeof value === "object" && !Array.isArray(value);
const own = (value, key) => Object.hasOwn(value, key);
const text = value => typeof value === "string" && value.trim().length > 0 && value.length <= 20_000;
const validVersion = value => typeof value === "string" && /^[0-9a-f]{12}$/.test(value);
const validDate = value => typeof value === "string" && /^\d{4}-\d{2}-\d{2}$/.test(value) &&
  Number.isFinite(Date.parse(value)) && new Date(value).toISOString().slice(0, 10) === value;

function validMeta(meta) {
  return object(meta) && meta.schema_version === "merchant-meta.v1" && validVersion(meta.version) &&
    [2, 3, 4].includes(meta.prefix_length) && meta.hash === "sha256" && meta.key === "registrable-domain" &&
    (meta.data_as_of === null || validDate(meta.data_as_of));
}

function sourceUrl(value) {
  if (!text(value)) throw new Error("invalid_source");
  const url = new URL(value);
  if (url.protocol !== "https:" || url.username || url.password) throw new Error("invalid_source");
  return url.href;
}

async function prefixOf(domain, length) {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(domain));
  return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, "0")).join("").slice(0, length);
}

async function validateBucket(bucket, meta, prefix) {
  if (object(bucket) && validVersion(bucket.version) && bucket.version !== meta.version) throw new Error("index_changed");
  if (!object(bucket) || bucket.schema_version !== "merchant-bucket.v1" || bucket.version !== meta.version ||
      bucket.prefix !== prefix || !object(bucket.domains) || !object(bucket.incidents)) throw new Error("invalid_bucket");
  const used = new Set();
  for (const [domain, entry] of Object.entries(bucket.domains)) {
    if (registrableDomain(domain) !== domain || await prefixOf(domain, meta.prefix_length) !== prefix ||
        !object(entry) || !text(entry.company) || !Array.isArray(entry.hosts) || !entry.hosts.length ||
        !entry.hosts.every(host => normalizeHost(host) === host && registrableDomain(host) === domain) ||
        !Array.isArray(entry.incident_ids) || !entry.incident_ids.length ||
        new Set(entry.incident_ids).size !== entry.incident_ids.length) throw new Error("invalid_domain");
    for (const id of entry.incident_ids) {
      if (!text(id) || !own(bucket.incidents, id)) throw new Error("missing_incident");
      used.add(id);
    }
  }
  for (const [id, incident] of Object.entries(bucket.incidents)) {
    if (!used.has(id) || !object(incident) || incident.id !== id ||
        !["name", "company", "type", "status"].every(key => text(incident[key])) ||
        !validDate(incident.date?.replaceAll("/", "-"))) throw new Error("invalid_incident");
    sourceUrl(incident.source);
  }
  return bucket;
}

// Only a truncated domain hash leaves the browser. No URL, hostname, cookie or user ID
// is sent. Prefixes still disclose partial information; this is not anonymity.
export function createRemoteDataSource({ storage, fetcher = globalThis.fetch, now = Date.now, timeoutMs = 10_000 } = {}) {
  let cache;
  let loading;
  let writing = Promise.resolve();
  let metadataPending;
  const pending = new Map();
  const fresh = (entry, ttl) => object(entry) && Number.isFinite(entry.at) && now() >= entry.at && now() - entry.at < ttl;

  async function load() {
    if (!loading) loading = (async () => {
      try { cache = (await storage?.get(CACHE_KEY))?.[CACHE_KEY]; } catch { /* Storage is an optional cache. */ }
      if (!object(cache) || !object(cache.buckets)) cache = { meta: null, buckets: {} };
    })();
    await loading;
  }

  async function persist() {
    // Bound the persistent cache (Chrome's default quota is 10 MB); keep only recent data.
    const entries = Object.entries(cache.buckets).sort((a, b) => b[1].at - a[1].at);
    let size = 0;
    cache.buckets = Object.fromEntries(entries.filter(([, entry], index) => {
      size += JSON.stringify(entry).length * 2;
      return index < MAX_BUCKETS && size <= 4 * MAX_TEXT;
    }));
    const snapshot = structuredClone(cache);
    writing = writing.catch(() => {}).then(() => storage?.set({ [CACHE_KEY]: snapshot }));
    await writing.catch(() => {});
  }

  async function request(path) {
    const controller = new AbortController();
    let timer;
    try {
      return await Promise.race([
        (async () => {
          const response = await fetcher(BASE + path, {
            credentials: "omit", referrerPolicy: "no-referrer", redirect: "error", cache: "no-store", signal: controller.signal,
          });
          if (!response.ok || response.redirected || !/application\/json/i.test(response.headers.get("content-type") ?? "")) {
            throw new Error("fetch_failed");
          }
          const body = await response.text();
          if (body.length > MAX_TEXT) throw new Error("response_too_large");
          return JSON.parse(body);
        })(),
        new Promise((_, reject) => { timer = setTimeout(() => { controller.abort(); reject(new Error("lookup_timeout")); }, timeoutMs); }),
      ]);
    } finally { clearTimeout(timer); }
  }

  async function metadata(force) {
    if (metadataPending) {
      if (!force) return metadataPending;
      await metadataPending.catch(() => {});
    }
    if (!force && fresh(cache.meta, META_TTL) && validMeta(cache.meta.value)) return cache.meta.value;
    const task = (async () => {
      const value = await request("index-meta.json");
      if (!validMeta(value)) throw new Error("invalid_meta");
      if (cache.meta?.value?.version !== value.version || cache.meta?.value?.prefix_length !== value.prefix_length) cache.buckets = {};
      cache.meta = { at: now(), value };
      await persist();
      return value;
    })();
    metadataPending = task;
    try { return await task; } finally { if (metadataPending === task) metadataPending = null; }
  }

  async function getBucket(meta, prefix, force) {
    const key = `${meta.version}:${prefix}`;
    if (pending.has(key)) {
      if (!force) return pending.get(key);
      await pending.get(key).catch(() => {});
    }
    const task = (async () => {
      const saved = cache.buckets[key];
      if (!force && fresh(saved, BUCKET_TTL)) {
        try { return await validateBucket(saved.value, meta, prefix); } catch { delete cache.buckets[key]; }
      }
      const value = await validateBucket(await request(`buckets/${prefix}.json`), meta, prefix);
      cache.buckets[key] = { at: now(), value };
      await persist();
      return value;
    })();
    pending.set(key, task);
    try { return await task; } finally { if (pending.get(key) === task) pending.delete(key); }
  }

  return {
    resultTtlMs: BUCKET_TTL,
    async lookupHost(input, { force = false } = {}) {
      const host = normalizeHost(input);
      const domain = registrableDomain(host);
      if (!domain) return { status: "not_found" };
      await load();
      let meta = await metadata(force);
      let bucket;
      try {
        bucket = await getBucket(meta, await prefixOf(domain, meta.prefix_length), force);
      } catch (error) {
        if (error.message !== "index_changed") throw error;
        // Publishing may replace buckets while the previous metadata is cached.
        // Retry once; persistent inconsistencies must remain a visible error.
        meta = await metadata(true);
        bucket = await getBucket(meta, await prefixOf(domain, meta.prefix_length), true);
      }
      const entry = own(bucket.domains, domain) ? bucket.domains[domain] : null;
      if (!entry?.hosts.includes(host)) return { status: "not_found" };
      return {
        status: "found", recordId: domain, kind: "published", shopName: entry.company,
        incidents: entry.incident_ids.map(id => {
          const incident = bucket.incidents[id];
          return {
            summary: `${incident.name}：${incident.type}${incident.dataType ? `（${incident.dataType}）` : ""}`,
            publishedOn: incident.date.replaceAll("/", "-"), response: incident.status,
            // The API has no per-incident verification date. Never substitute today's date.
            responseCheckedOn: "公表資料を参照", source: incident.source, sourceUrl: sourceUrl(incident.source),
          };
        }),
      };
    },
  };
}
