import assert from "node:assert/strict";
import { mkdtemp, readFile, readdir, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import { after, before, describe, test } from "node:test";
import { fileURLToPath } from "node:url";
import { spawnSync } from "node:child_process";
import { buildStatic } from "../scripts/build-static.mjs";
import { verifyStaticSite } from "../scripts/verify-static.mjs";

const launchJson = fileURLToPath(new URL("../../services/mrst/database/seeders/data/launch-incidents.json", import.meta.url));
const work = await mkdtemp(path.join(tmpdir(), "morashiter-snapshot-"));
const launch = JSON.parse(await readFile(launchJson, "utf8"));

async function writeSnapshot(name, { incidents = launch, withdrawals = [], schema = "public-snapshot.v1" } = {}) {
  const file = path.join(work, `${name}.json`);
  const dates = incidents.map(i => i.date.replaceAll("/", "-")).sort();
  await writeFile(file, JSON.stringify({ schema_version: schema, data_as_of: dates.at(-1) ?? null, incidents, withdrawals }, null, 2));
  return file;
}

async function listFiles(dir, prefix = "") {
  const out = [];
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const rel = prefix ? `${prefix}/${entry.name}` : entry.name;
    if (entry.isDirectory()) out.push(...await listFiles(path.join(dir, entry.name), rel));
    else out.push(rel);
  }
  return out.sort();
}

const defaultDir = path.join(work, "default");
const snapshotDir = path.join(work, "from-snapshot");

before(async () => {
  await buildStatic({ outDir: defaultDir });
  await buildStatic({ outDir: snapshotDir, snapshotPath: await writeSnapshot("launch") });
});
after(() => rm(work, { recursive: true, force: true }));

describe("building from a public snapshot", () => {
  test("the launch snapshot produces a site byte-identical to the current build", async () => {
    const files = await listFiles(defaultDir);
    assert.deepEqual(await listFiles(snapshotDir), files);
    for (const file of files) {
      assert.ok((await readFile(path.join(defaultDir, file))).equals(await readFile(path.join(snapshotDir, file))), file);
    }
  });

  test("a newly approved incident appears on the page and in the release", async () => {
    const extra = { ...launch[1], id: "INC-006", name: "テスト会員サイト", company: "テスト株式会社", date: "2026/10/19", report: null };
    const out = path.join(work, "with-new");
    await buildStatic({ outDir: out, snapshotPath: await writeSnapshot("with-new", { incidents: [...launch, extra] }) });

    const html = await readFile(path.join(out, "index.html"), "utf8");
    const release = JSON.parse(await readFile(path.join(out, "release.json"), "utf8"));
    assert.ok(html.includes("テスト会員サイト"));
    assert.deepEqual(release.incidentIds, ["INC-001", "INC-002", "INC-003", "INC-004", "INC-005", "INC-006"]);
    assert.equal(release.dataAsOf, "2026-10-19");
    assert.deepEqual(await verifyStaticSite(out, { expectedIncidentIds: release.incidentIds }), []);
  });

  test("a withdrawn incident leaves the list and is shown as a correction notice", async () => {
    const out = path.join(work, "withdrawn");
    const remaining = launch.filter(i => i.id !== "INC-003");
    const withdrawals = [{ id: "INC-003", name: "ASKUL・LOHACO", company: "アスクル株式会社", date: "2026/10/20", reason: "公表内容の訂正のため" }];
    await buildStatic({ outDir: out, snapshotPath: await writeSnapshot("withdrawn", { incidents: remaining, withdrawals }) });

    const html = await readFile(path.join(out, "index.html"), "utf8");
    const root = html.slice(html.indexOf('<div id="root">'), html.indexOf("<noscript>"));
    assert.ok(root.includes("訂正・撤回のお知らせ"));
    assert.ok(root.includes("公表内容の訂正のため"));
    assert.ok(!root.includes("ランサムウェア攻撃で情報流出、サービス一時停止"), "the withdrawn record row is gone");
    assert.deepEqual(JSON.parse(await readFile(path.join(out, "release.json"), "utf8")).incidentIds, remaining.map(i => i.id));
  });
});

describe("safety guards before building", () => {
  const previousRelease = { releaseId: "000000000000", incidentIds: launch.map(i => i.id), dataAsOf: "2026-09-29", indexable: false };

  test("an incident that disappears without a withdrawal stops the build", async () => {
    const file = await writeSnapshot("dropped", { incidents: launch.filter(i => i.id !== "INC-003") });

    await assert.rejects(buildStatic({ outDir: path.join(work, "dropped"), snapshotPath: file, previousRelease }), /INC-003.*without a withdrawal/);
  });

  test("the same drop is allowed when it is a withdrawal", async () => {
    const withdrawals = [{ id: "INC-003", name: "ASKUL・LOHACO", company: "アスクル株式会社", date: "2026/10/20", reason: "訂正" }];
    const file = await writeSnapshot("dropped-ok", { incidents: launch.filter(i => i.id !== "INC-003"), withdrawals });

    await buildStatic({ outDir: path.join(work, "dropped-ok"), snapshotPath: file, previousRelease });
  });

  test("an empty snapshot stops the build", async () => {
    await assert.rejects(buildStatic({ outDir: path.join(work, "empty"), snapshotPath: await writeSnapshot("empty", { incidents: [] }) }), /no incidents/);
  });

  test("an unknown schema version stops the build", async () => {
    await assert.rejects(buildStatic({ outDir: path.join(work, "schema"), snapshotPath: await writeSnapshot("schema", { schema: "v0" }) }), /schema_version/);
  });

  test("a record with a missing field or a non-https source stops the build", async () => {
    const { summary, ...noSummary } = launch[0];
    await assert.rejects(buildStatic({ outDir: path.join(work, "missing"), snapshotPath: await writeSnapshot("missing", { incidents: [noSummary] }) }), /INC-001.*summary/);
    await assert.rejects(buildStatic({ outDir: path.join(work, "http"), snapshotPath: await writeSnapshot("http", { incidents: [{ ...launch[0], source: "http://example.jp/" }] }) }), /INC-001.*source/);
    assert.ok(summary);
  });

  test("duplicate incident codes stop the build", async () => {
    await assert.rejects(buildStatic({ outDir: path.join(work, "dup"), snapshotPath: await writeSnapshot("dup", { incidents: [launch[0], launch[0]] }) }), /duplicate.*INC-001/);
  });
});

describe("publish verification of incident codes", () => {
  test("the published codes must match the snapshot", async () => {
    const errors = await verifyStaticSite(snapshotDir, { expectedIncidentIds: ["INC-001", "INC-002"] });
    assert.ok(errors.some(e => e.includes("do not match the snapshot")), errors.join("\n"));
  });

  test("an unexpected incident code in the HTML is rejected", async () => {
    const out = path.join(work, "stray-code");
    await buildStatic({ outDir: out });
    const file = path.join(out, "index.html");
    await writeFile(file, (await readFile(file, "utf8")).replace("</body>", "<p>INC-999</p></body>"));

    const errors = await verifyStaticSite(out, { expectedIncidentIds: launch.map(i => i.id) });
    assert.ok(errors.some(e => e.includes("INC-999")), errors.join("\n"));
  });

  test("the command line checks the codes against --snapshot", async () => {
    const script = fileURLToPath(new URL("../scripts/verify-static.mjs", import.meta.url));
    const ok = spawnSync(process.execPath, [script, snapshotDir, `--snapshot=${await writeSnapshot("cli-ok")}`], { encoding: "utf8" });
    assert.equal(ok.status, 0, ok.stderr);

    const fewer = await writeSnapshot("cli-fewer", { incidents: launch.slice(0, 2) });
    const ng = spawnSync(process.execPath, [script, snapshotDir, `--snapshot=${fewer}`], { encoding: "utf8" });
    assert.equal(ng.status, 1);
    assert.match(ng.stderr, /do not match the snapshot/);
  });
});
