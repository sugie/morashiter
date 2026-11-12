// Keep this curated subset identical to services/mrst/resources/data/public_suffixes.txt.
// This is not the full PSL. Exact-host matching remains mandatory after bucketing.
export const PUBLIC_SUFFIXES = [
  "com", "net", "org", "info", "biz", "io", "co", "jp", "co.jp", "ne.jp", "or.jp",
  "ac.jp", "ad.jp", "ed.jp", "go.jp", "gr.jp", "lg.jp", "*.lg.jp", "uk", "co.uk",
  "org.uk", "ac.uk", "gov.uk", "au", "com.au", "net.au", "org.au",
];

export function normalizeHost(input) {
  if (typeof input !== "string" || !input || /[\s/:@?#\\\[\]%]/u.test(input)) return null;
  let host;
  try { host = new URL(`https://${input}`).hostname.replace(/\.$/, ""); } catch { return null; }
  if (host.length > 253 || !host.includes(".") || /^[\d.]+$/.test(host)) return null;
  if (!host.split(".").every(label => /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(label))) return null;
  if (/\.(localhost|local|internal|test|invalid)$/.test(host)) return null;
  return host;
}

export function registrableDomain(input) {
  const host = normalizeHost(input);
  if (!host) return null;
  const labels = host.split(".");
  let suffixLength = 1;
  for (let length = 1; length <= labels.length; length++) {
    if (PUBLIC_SUFFIXES.includes(labels.slice(-length).join(".")) ||
        (length > 1 && PUBLIC_SUFFIXES.includes(`*.${labels.slice(-(length - 1)).join(".")}`))) suffixLength = length;
  }
  return labels.length > suffixLength ? labels.slice(-(suffixLength + 1)).join(".") : null;
}
