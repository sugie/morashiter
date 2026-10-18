import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { chmod, cp, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import { after, before, test } from "node:test";
import { fileURLToPath } from "node:url";
import { buildStatic } from "../scripts/build-static.mjs";

const script = fileURLToPath(new URL("../scripts/upload-sakura.sh", import.meta.url));
const work = await mkdtemp(path.join(tmpdir(), "morashiter-upload-"));
const site = path.join(work, "site");
const fakeBin = path.join(work, "bin");
const log = path.join(work, "aws.log");
const env = {
  MORASHITER_SITE_DIR: site,
  MORASHITER_BUCKET: "test-bucket",
  MORASHITER_S3_ENDPOINT: "https://s3.example.invalid",
  MORASHITER_AWS_PROFILE: "test-profile",
  MORASHITER_S3_REGION: "jp-north-1",
};

before(async () => {
  await buildStatic({ outDir: site });
  await import("node:fs/promises").then(fs => fs.mkdir(fakeBin));
  await writeFile(path.join(fakeBin, "aws"), `#!/usr/bin/env bash\nprintf '%s\\n' "$*" >> "${log.replaceAll("\\", "/")}"\n`);
  await chmod(path.join(fakeBin, "aws"), 0o755);
});
after(() => rm(work, { recursive: true, force: true }));

function run(args, extraEnv = {}) {
  return spawnSync("bash", [script, ...args], {
    encoding: "utf8",
    env: { ...process.env, ...env, ...extraEnv, PATH: `${fakeBin}${path.delimiter}${process.env.PATH}` },
  });
}

async function awsCalls() {
  try {
    return (await readFile(log, "utf8")).trim().split("\n").filter(Boolean);
  } catch {
    return [];
  }
}

test("without --upload it only verifies and prints the plan", async () => {
  await rm(log, { force: true });
  const result = run([]);
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stdout, /dry run/i);
  assert.match(result.stdout, /index\.html/);
  assert.deepEqual(await awsCalls(), []);
});

test("refuses to upload when bucket settings are missing", async () => {
  await rm(log, { force: true });
  const result = run(["--upload"], { MORASHITER_BUCKET: "" });
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /MORASHITER_BUCKET/);
  assert.deepEqual(await awsCalls(), []);
});

test("refuses to upload output that fails verification", async () => {
  await rm(log, { force: true });
  const broken = path.join(work, "broken");
  await cp(site, broken, { recursive: true });
  await writeFile(path.join(broken, ".env"), "SECRET=x");
  const result = run(["--upload"], { MORASHITER_SITE_DIR: broken });
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /\.env/);
  assert.deepEqual(await awsCalls(), []);
});

test("uploads assets first, HTML last, private, with the planned cache headers", async () => {
  await rm(log, { force: true });
  const result = run(["--upload"]);
  assert.equal(result.status, 0, result.stderr);
  const calls = await awsCalls();
  const release = JSON.parse(await readFile(path.join(site, "release.json"), "utf8"));

  assert.ok(calls.length >= 6, calls.join("\n"));
  for (const call of calls) {
    assert.match(call, /--profile test-profile/);
    assert.match(call, /--endpoint-url https:\/\/s3\.example\.invalid/);
    assert.match(call, /--acl private/);
    assert.doesNotMatch(call, /public-read|--delete|\bsync\b/);
  }
  assert.match(calls.at(-1), /index\.html s3:\/\/test-bucket\/index\.html .*--content-type text\/html; charset=utf-8 .*--cache-control public, max-age=300/);

  const assetCalls = calls.filter(c => c.includes("/assets/"));
  assert.ok(assetCalls.length >= 2);
  for (const call of assetCalls) assert.match(call, /--cache-control public, max-age=31536000, immutable/);
  assert.ok(calls.findLastIndex(c => c.includes("/assets/")) < calls.length - 1);
  assert.ok(assetCalls.some(c => /\.js s3:.* --content-type text\/javascript; charset=utf-8/.test(c)));
  assert.ok(assetCalls.some(c => /\.css s3:.* --content-type text\/css; charset=utf-8/.test(c)));

  assert.ok(calls.some(c => /release\.json s3:\/\/test-bucket\/release\.json .*--content-type application\/json; charset=utf-8 .*--cache-control no-store/.test(c)));
  assert.ok(calls.some(c => /robots\.txt s3:\/\/test-bucket\/robots\.txt .*--content-type text\/plain; charset=utf-8/.test(c)));
  assert.ok(calls.some(c => /favicon\.svg s3:\/\/test-bucket\/favicon\.svg .*--content-type image\/svg\+xml/.test(c)));
  assert.match(result.stdout, new RegExp(`release ${release.releaseId}`));
  assert.match(result.stdout, /purge/i);
});
