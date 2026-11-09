import { createHash } from "node:crypto";
import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";

// The extension's lookup data. It hashes the registrable domain of the tab's host with SHA-256 and fetches
// only api/v1/buckets/<first prefix_length hex chars>.json, then matches the exact host inside it. Every
// bucket is written, even empty ones, so a request never reveals whether a site is listed.

export const META_PATH = "api/v1/index-meta.json";
export const BUCKET_PATTERN = /^api\/v1\/buckets\/([0-9a-f]{2,4})\.json$/;
export const HOST_PATTERN = /^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/;

export const bucketOf = (domain, prefixLength) => createHash("sha256").update(domain).digest("hex").slice(0, prefixLength);
export const allPrefixes = prefixLength => Array.from({ length: 16 ** prefixLength }, (_, i) => i.toString(16).padStart(prefixLength, "0"));

/**
 * Check the snapshot's "merchants" block against its incidents. Missing means no shopping hosts yet.
 * @returns {{prefix_length: number, domains: {registrable_domain: string, host: string, company: string, incident_ids: string[]}[]}}
 */
export function validateMerchants(merchants, incidentIds) {
  if (merchants === undefined || merchants === null) return { prefix_length: 2, domains: [] };
  const length = merchants.prefix_length;
  if (!Number.isInteger(length) || length < 2 || length > 4) throw new Error("Snapshot merchants: prefix_length must be 2, 3 or 4");
  if (!Array.isArray(merchants.domains)) throw new Error("Snapshot merchants: domains must be a list");
  const known = new Set(incidentIds);
  const hosts = new Set();
  const domains = merchants.domains.map(row => {
    const host = String(row?.host ?? "");
    const domain = String(row?.registrable_domain ?? "");
    if (!HOST_PATTERN.test(host) || !HOST_PATTERN.test(domain)) throw new Error(`Snapshot merchant ${host || "?"}: host and registrable_domain must be plain host names`);
    if (host !== domain && !host.endsWith(`.${domain}`)) throw new Error(`Snapshot merchant ${host}: not under ${domain}`);
    if (hosts.has(host)) throw new Error(`Snapshot merchant ${host}: listed twice`);
    hosts.add(host);
    if (typeof row.company !== "string" || row.company.trim() === "") throw new Error(`Snapshot merchant ${host}: company is missing`);
    if (!Array.isArray(row.incident_ids) || row.incident_ids.length === 0) throw new Error(`Snapshot merchant ${host}: incident_ids is empty`);
    for (const id of row.incident_ids) {
      if (!known.has(id)) throw new Error(`Snapshot merchant ${host}: unknown incident ${id}`);
    }
    return { registrable_domain: domain, host, company: row.company, incident_ids: [...row.incident_ids] };
  });
  return { prefix_length: length, domains };
}

const sortedObject = (entries) => Object.fromEntries([...entries].sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)));

/**
 * Files for the merchant index, as site-relative path -> JSON text. Deterministic for the same data.
 * @param {{incidents: object[], merchants?: object}} data incidents already reduced to the public fields
 */
export function merchantIndexFiles(data) {
  const merchants = data.merchants ?? { prefix_length: 2, domains: [] };
  const length = merchants.prefix_length;
  const incidents = new Map(data.incidents.map(i => [i.id, i]));
  const version = createHash("sha256").update(JSON.stringify({ merchants, incidents: data.incidents })).digest("hex").slice(0, 12);
  const dataAsOf = data.incidents.map(i => i.date.replaceAll("/", "-")).sort().at(-1) ?? null;

  const byDomain = new Map();
  for (const row of merchants.domains) {
    const entry = byDomain.get(row.registrable_domain) ?? { hosts: new Set(), company: row.company, ids: new Set() };
    entry.hosts.add(row.host);
    for (const id of row.incident_ids) entry.ids.add(id);
    byDomain.set(row.registrable_domain, entry);
  }

  const buckets = new Map(allPrefixes(length).map(prefix => [prefix, new Map()]));
  for (const [domain, entry] of byDomain) buckets.get(bucketOf(domain, length)).set(domain, entry);

  const files = {};
  files[META_PATH] = `${JSON.stringify({ schema_version: "merchant-meta.v1", version, data_as_of: dataAsOf, prefix_length: length, hash: "sha256", key: "registrable-domain" })}\n`;
  for (const [prefix, domains] of buckets) {
    const used = new Set();
    const domainEntries = [...domains].map(([domain, entry]) => {
      const ids = [...entry.ids].sort();
      ids.forEach(id => used.add(id));
      return [domain, { hosts: [...entry.hosts].sort(), company: entry.company, incident_ids: ids }];
    });
    const bucket = {
      schema_version: "merchant-bucket.v1",
      version,
      prefix,
      domains: sortedObject(domainEntries),
      incidents: sortedObject([...used].map(id => [id, incidents.get(id)])),
    };
    files[`api/v1/buckets/${prefix}.json`] = `${JSON.stringify(bucket)}\n`;
  }
  return files;
}

export async function writeMerchantIndex(outDir, data) {
  await mkdir(path.join(outDir, "api/v1/buckets"), { recursive: true });
  for (const [rel, text] of Object.entries(merchantIndexFiles(data))) {
    await writeFile(path.join(outDir, rel), text);
  }
}

/**
 * Problems in a built merchant index; empty means publishable.
 * @param {string[]} files site-relative paths
 * @param {Record<string, string>} text contents of the JSON files
 */
export function verifyMerchantIndex(files, text, expectedIncidentIds) {
  const errors = [];
  let meta;
  try {
    meta = JSON.parse(text[META_PATH] ?? "");
  } catch {
    return [`${META_PATH}: missing or not JSON`];
  }
  const length = meta.prefix_length;
  if (meta.schema_version !== "merchant-meta.v1" || !Number.isInteger(length) || length < 2 || length > 4 || !/^[0-9a-f]{12}$/.test(meta.version ?? "")) {
    return [`${META_PATH}: unexpected format`];
  }
  const expected = new Set(allPrefixes(length));
  const present = new Set();
  for (const file of files) {
    const prefix = file.match(BUCKET_PATTERN)?.[1];
    if (prefix === undefined) continue;
    if (!expected.has(prefix)) {
      errors.push(`${file}: bucket does not match prefix_length ${length}`);
      continue;
    }
    present.add(prefix);
    let bucket;
    try {
      bucket = JSON.parse(text[file]);
    } catch {
      errors.push(`${file}: bucket is not JSON`);
      continue;
    }
    if (bucket.schema_version !== "merchant-bucket.v1" || bucket.version !== meta.version || bucket.prefix !== prefix) {
      errors.push(`${file}: bucket header does not match the meta file`);
    }
    const published = expectedIncidentIds ? new Set(expectedIncidentIds) : null;
    const referenced = new Set();
    for (const [domain, entry] of Object.entries(bucket.domains ?? {})) {
      if (bucketOf(domain, length) !== prefix) errors.push(`${file}: ${domain} belongs in bucket ${bucketOf(domain, length)}`);
      for (const host of entry.hosts ?? []) {
        if (host !== domain && !host.endsWith(`.${domain}`)) errors.push(`${file}: ${host} is not under ${domain}`);
      }
      for (const id of entry.incident_ids ?? []) {
        referenced.add(id);
        if (!(id in (bucket.incidents ?? {}))) errors.push(`${file}: ${domain} refers to missing incident ${id}`);
        if (published && !published.has(id)) errors.push(`${file}: ${id} is not a published incident`);
      }
    }
    for (const id of Object.keys(bucket.incidents ?? {})) {
      if (!referenced.has(id)) errors.push(`${file}: incident ${id} is not used by any domain`);
    }
  }
  for (const prefix of expected) {
    if (!present.has(prefix)) errors.push(`api/v1/buckets/${prefix}.json: bucket missing`);
  }
  return errors;
}
