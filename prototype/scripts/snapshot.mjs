import { readFile } from "node:fs/promises";
import { pathToFileURL, fileURLToPath } from "node:url";
import { validateMerchants } from "./merchant-index.mjs";

/** The site's Incident fields, in the order msgn (PHP) writes them. */
export const INCIDENT_KEYS = ["id", "name", "company", "type", "scale", "brief", "date", "status", "disclosure", "report", "quantity", "dataType", "summary", "target", "action", "response", "source", "contact"];
const WITHDRAWAL_KEYS = ["id", "name", "company", "date", "reason"];
export const SCHEMA_VERSION = "public-snapshot.v1";
const VIRTUAL_ID = "\0morashiter-data";

const pick = (record, keys) => Object.fromEntries(keys.map(key => [key, record[key] ?? null]));

/** Data for a build without --snapshot: the prototype's hand-checked list, normalised like a snapshot. */
export async function loadDefaultData() {
  const source = await import(pathToFileURL(fileURLToPath(new URL("../app/morashiter-data.ts", import.meta.url))).href);
  return { incidents: source.incidents.map(i => pick(i, INCIDENT_KEYS)), withdrawals: (source.withdrawals ?? []).map(w => pick(w, WITHDRAWAL_KEYS)), merchants: validateMerchants(undefined, []) };
}

/**
 * Read and validate a public snapshot from msgn. Throws with the offending incident code.
 * @returns {Promise<{incidents: object[], withdrawals: object[], merchants: object}>}
 */
export async function loadSnapshot(file) {
  const snapshot = JSON.parse(await readFile(file, "utf8"));
  if (snapshot?.schema_version !== SCHEMA_VERSION) throw new Error(`Snapshot schema_version must be ${SCHEMA_VERSION}`);
  if (!Array.isArray(snapshot.incidents) || snapshot.incidents.length === 0) throw new Error("Snapshot has no incidents; refusing to publish an empty site");
  if (!Array.isArray(snapshot.withdrawals)) throw new Error("Snapshot withdrawals must be a list");

  const seen = new Set();
  for (const incident of snapshot.incidents) {
    const id = String(incident?.id ?? "?");
    if (!/^INC-\d{3,}$/.test(id)) throw new Error(`Snapshot incident ${id}: id must look like INC-001`);
    if (seen.has(id)) throw new Error(`Snapshot has a duplicate incident ${id}`);
    seen.add(id);
    for (const key of INCIDENT_KEYS) {
      const value = incident[key];
      const ok = key === "report" ? value === null || (typeof value === "string" && value !== "") : typeof value === "string" && value.trim() !== "";
      if (!ok) throw new Error(`Snapshot incident ${id}: ${key} is missing or empty`);
    }
    for (const key of ["source", "contact"]) {
      if (!/^https:\/\/[^\s"'<>]+$/.test(incident[key])) throw new Error(`Snapshot incident ${id}: ${key} must be an https URL`);
    }
    if (!/^\d{4}\/\d{2}\/\d{2}$/.test(incident.date)) throw new Error(`Snapshot incident ${id}: date must be YYYY/MM/DD`);
  }
  for (const withdrawal of snapshot.withdrawals) {
    for (const key of WITHDRAWAL_KEYS) {
      if (typeof withdrawal?.[key] !== "string" || withdrawal[key].trim() === "") throw new Error(`Snapshot withdrawal ${withdrawal?.id ?? "?"}: ${key} is missing`);
    }
  }

  const merchants = validateMerchants(snapshot.merchants, snapshot.incidents.map(i => i.id));
  return { incidents: snapshot.incidents.map(i => pick(i, INCIDENT_KEYS)), withdrawals: snapshot.withdrawals.map(w => pick(w, WITHDRAWAL_KEYS)), merchants };
}

/** Stop before building if a previously published incident vanished without being withdrawn. */
export function guardAgainstDrops(previousRelease, data) {
  if (!previousRelease) return;
  const current = new Set(data.incidents.map(i => i.id));
  const withdrawn = new Set(data.withdrawals.map(w => w.id));
  const vanished = (previousRelease.incidentIds ?? []).filter(id => !current.has(id) && !withdrawn.has(id));
  if (vanished.length > 0) throw new Error(`${vanished.join(", ")} would disappear without a withdrawal; refusing to build`);
}

/** Vite plugin: every import of app/morashiter-data resolves to the given data, so default and snapshot builds share one code path. */
export function dataModulePlugin(data) {
  return {
    name: "morashiter-data",
    enforce: "pre",
    resolveId(source) {
      return /(^|\/)morashiter-data(\.ts)?$/.test(source) ? VIRTUAL_ID : null;
    },
    load(id) {
      if (id !== VIRTUAL_ID) return null;
      return `export const incidents = ${JSON.stringify(data.incidents)};\nexport const withdrawals = ${JSON.stringify(data.withdrawals)};\n`;
    },
  };
}
