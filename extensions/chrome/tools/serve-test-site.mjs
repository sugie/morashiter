// Serves test-site/ on http://127.0.0.1:8765 for the local test pages, using only Node.js
// (no packages). Answers on this computer only. Usage: node tools/serve-test-site.mjs [port]
import { createServer } from "node:http";
import { readFile } from "node:fs/promises";

const port = Number(process.argv[2] ?? 8765);
const files = {
  "/": ["index.html", "text/html; charset=utf-8"],
  "/index.html": ["index.html", "text/html; charset=utf-8"],
  "/page.js": ["page.js", "text/javascript; charset=utf-8"],
};

createServer(async (req, res) => {
  const path = new URL(req.url ?? "/", "http://localhost").pathname;
  const file = files[path];
  if (req.method !== "GET" || !file) {
    res.writeHead(404, { "content-type": "text/plain; charset=utf-8" }).end("not found");
    return;
  }
  const body = await readFile(new URL(`../test-site/${file[0]}`, import.meta.url));
  res.writeHead(200, { "content-type": file[1], "cache-control": "no-store" }).end(body);
}).listen(port, "127.0.0.1", () => {
  console.log(`Test pages: http://aozora-zakka.localhost:${port}/  (Ctrl+C to stop)`);
});
