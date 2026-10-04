import assert from "node:assert/strict";
import { mkdtemp, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";
import { evaluateGateA, loadAndEvaluate, validateEvaluation, validateManifest } from "./gate-a.mjs";

function evaluation(overrides = {}) {
  return {
    schema: "rightsrelay.support-evaluation.v2",
    prototypeVersion: "2026-09-30-v4",
    context: "fictional-five-packet-sample",
    durationSeconds: 420,
    responses: {
      role: "lawyer",
      currentIntakeTimeBand: "31-60",
      initialTriageValue: "partial",
      expectedTimeWithPrototype: "15-30",
      minimumMissingFields: ["timeline"],
      primaryRisk: "legal",
      preferredOutput: "csv",
      legalBoundaryAssessment: "clear",
      nextStepWillingness: "second-review",
      originalEvidenceTiming: "after-triage",
      ...overrides,
    },
    excluded: [
      "氏名・連絡先",
      "所属組織名",
      "自由記述",
      "実在する被害者・事件データ",
      "読み込んだ共有パケット",
    ],
    disclaimer: "法律意見、提携合意、実証参加の確約ではありません。",
  };
}

function manifest(sessionOverrides = [], manualChecks = { coreLegalRoleRequired: false, unavoidableLegalRisk: false }) {
  const defaults = [
    { sessionId: "L-001", role: "lawyer", organizationCode: null },
    { sessionId: "L-002", role: "lawyer", organizationCode: null },
    { sessionId: "O-001", role: "consumer-body", organizationCode: "ORG-001" },
    { sessionId: "O-002", role: "consumer-body", organizationCode: "ORG-002" },
    { sessionId: "C-001", role: "counselor", organizationCode: null },
  ];
  return {
    schema: "rightsrelay.gate-a-manifest.v1",
    roundId: "gate-a-round-1",
    manualChecks,
    sessions: defaults.map((item, index) => ({
      sessionId: item.sessionId,
      phase: "initial",
      evaluationFile: `${item.sessionId}.json`,
      organizationCode: item.organizationCode,
      retestsSessionId: null,
      ...sessionOverrides[index],
    })),
  };
}

function recordsForManifest(value, overrides = []) {
  return new Map(
    value.sessions.map((session, index) => [
      session.evaluationFile,
      evaluation({
        role: index === 0 || index === 1 ? "lawyer" : index === 2 || index === 3 ? "consumer-body" : "counselor",
        ...overrides[index],
      }),
    ]),
  );
}

test("returns continue only when all six Gate A conditions and manual checks pass", () => {
  const input = manifest();
  const report = evaluateGateA(input, recordsForManifest(input));
  assert.equal(report.decision, "continue");
  assert.deepEqual(report.conditions, {
    participantMix: true,
    valueSignal: true,
    followupSignal: true,
    timeReductionEvidence: true,
    legalBoundarySafeOrRetested: true,
    originalsNotRequiredByMajority: true,
  });
});

test("returns pivot when a majority requires centralized originals before triage", () => {
  const input = manifest();
  const records = recordsForManifest(input, [
    { originalEvidenceTiming: "before-triage" },
    { originalEvidenceTiming: "before-triage" },
    { originalEvidenceTiming: "before-triage" },
  ]);
  const report = evaluateGateA(input, records);
  assert.equal(report.decision, "pivot");
  assert.equal(report.conditions.originalsNotRequiredByMajority, false);
});

test("requires a non-unsafe retest to resolve an unsafe boundary response", () => {
  const input = manifest();
  const records = recordsForManifest(input, [{ legalBoundaryAssessment: "unsafe" }]);
  let report = evaluateGateA(input, records);
  assert.equal(report.decision, "conditional");
  assert.deepEqual(report.unresolvedUnsafeSessionIds, ["L-001"]);

  input.sessions.push({
    sessionId: "L-006",
    phase: "retest",
    evaluationFile: "L-006.json",
    organizationCode: null,
    retestsSessionId: "L-001",
  });
  records.set("L-006.json", evaluation({ legalBoundaryAssessment: "needs-review" }));
  report = evaluateGateA(input, records);
  assert.equal(report.decision, "continue");
  assert.deepEqual(report.unresolvedUnsafeSessionIds, []);
});

test("keeps a decision conditional while manual legal checks are unknown", () => {
  const input = manifest([], { coreLegalRoleRequired: null, unavoidableLegalRisk: null });
  const report = evaluateGateA(input, recordsForManifest(input));
  assert.equal(report.decision, "conditional");
  assert.match(report.warnings.join(" "), /手動の法的境界確認/);
});

test("rejects unknown evaluation fields and unsafe manifest file paths", () => {
  assert.throws(() => validateEvaluation({ ...evaluation(), freeText: "do not accept" }), /unexpected or missing/);
  assert.throws(() => validateEvaluation({ ...evaluation(), disclaimer: "氏名を書けます" }), /fixed text/);
  const input = manifest();
  input.sessions[0].evaluationFile = "../L-001.json";
  assert.throws(() => validateManifest(input), /JSON basename/);
});

test("loads a five-session fictional round from separate JSON files", async (t) => {
  const directory = await mkdtemp(join(tmpdir(), "rightsrelay-gate-a-"));
  t.after(() => rm(directory, { recursive: true, force: true }));
  const input = manifest();
  const records = recordsForManifest(input);
  await writeFile(join(directory, "manifest.json"), JSON.stringify(input));
  await Promise.all(
    [...records].map(([filename, value]) => writeFile(join(directory, filename), JSON.stringify(value))),
  );

  const report = await loadAndEvaluate(join(directory, "manifest.json"));
  assert.equal(report.counts.initialSessions, 5);
  assert.equal(report.decision, "continue");
});
