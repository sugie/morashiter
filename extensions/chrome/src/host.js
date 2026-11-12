// Turns a tab URL into the host to look up. Only ordinary web pages (http/https) are checked;
// browser pages, files, extensions and anything unparsable are "unsupported".

export function classifyUrl(url) {
  if (typeof url !== "string" || url === "") {
    return { supported: false, reason: "no_url" };
  }
  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    return { supported: false, reason: "invalid_url" };
  }
  if (parsed.protocol !== "http:" && parsed.protocol !== "https:") {
    return { supported: false, reason: "not_web" };
  }
  // URL already lower-cases the host and converts it to punycode; drop a trailing dot so
  // "shop.example." and "shop.example" are the same host.
  const host = parsed.hostname.replace(/\.$/, "");
  if (host === "") {
    return { supported: false, reason: "invalid_url" };
  }
  return { supported: true, host };
}
