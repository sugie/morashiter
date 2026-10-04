import assert from "node:assert/strict";
import { mkdtemp, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";
import {
  evaluateGateB,
  loadAndEvaluateGateB,
  validateUsabilityFeedback,
  validateUsabilityManifest,
} from "./gate-b.mjs";

function feedback(overrides = {}) {
  return {
    schemaVersion: 1,
    study: "rightsrelay-usability-v1",
    prototypeVersion: "2026-09-30-v2",
    durationSeconds: 720,
    taskSignals: {
      assessmentCompleted: true,
      evidenceChecklistUsed: true,
      intentChoiceUsed: true,
      ...overrides.taskSignals,
    },
    feedback: {
      clarity: 4,
      trust: 4,
      expectedDestination: "device",
      primaryConcern: "none",
      ...overrides.feedback,
    },
    excluded: [
      "氏名・連絡先",
      "会員情報",
      "対象確認の回答内容",
      "証拠チェックの内訳",
      "端末内メモ",
      "意思表示の内訳",
    ],
    ...Object.fromEntries(Object.entries(overrides).filter(([key]) => !["taskSignals", "feedback"].includes(key))),
  };
}

function manifest(stage = "round-1", sessionOverrides = []) {
  const size = stage === "gate-b" ? 10 : 5;
  return {
    schema: "rightsrelay.usability-manifest.v1",
    roundId: `${stage}-fictional`,
    stage,
    sessions: Array.from({ length: size }, (_, index) => ({
      sessionId: `V-${String(index + 1).padStart(3, "0")}`,
      feedbackFile: `V-${String(index + 1).padStart(3, "0")}.json`,
      taskCompletion: { t1: true, t2: true, t3: true, t4: true },
      majorAssistance: false,
      misconceptions: [],
      sensitiveInputAttempted: false,
      coreTaskDurationSeconds: 480,
      stopReason: "none",
      ...sessionOverrides[index],
    })),
  };
}

function feedbackForManifest(value, feedbackOverrides = []) {
  return new Map(
    value.sessions.map((session, index) => [session.feedbackFile, feedback(feedbackOverrides[index])]),
  );
}

test("passes a five-person round when all safety and usability thresholds pass", () => {
  const input = manifest();
  const report = evaluateGateB(input, feedbackForManifest(input));
  assert.equal(report.decision, "pass");
  assert.equal(report.requiredPassCount, 4);
  assert.ok(Object.values(report.conditions).every(Boolean));
});

test("requires eight of ten for the final Gate B cohort", () => {
  const input = manifest("gate-b", [
    { majorAssistance: true },
    { majorAssistance: true },
  ]);
  const report = evaluateGateB(input, feedbackForManifest(input));
  assert.equal(report.decision, "pass");
  assert.equal(report.counts.allTasksWithoutMajorHelp, 8);
  assert.equal(report.requiredPassCount, 8);
});

test("requires safety redesign after any attempted sensitive input", () => {
  const input = manifest("round-1", [{ sensitiveInputAttempted: true }]);
  const report = evaluateGateB(input, feedbackForManifest(input));
  assert.equal(report.decision, "safety-redesign-required");
  assert.equal(report.conditions.noSensitiveInputAttempts, false);
});

test("fails when legal certainty or external transmission is misunderstood too often", () => {
  const input = manifest("round-1", [
    { misconceptions: ["assessment-is-legal", "local-is-external"] },
    { misconceptions: ["assessment-is-legal", "local-is-external"] },
  ]);
  const report = evaluateGateB(input, feedbackForManifest(input));
  assert.equal(report.decision, "revise-and-retest");
  assert.equal(report.conditions.assessmentMisconceptionsAcceptable, false);
  assert.equal(report.conditions.externalSendMisconceptionsAcceptable, false);
});

test("rejects free text and unsafe feedback paths", () => {
  assert.throws(() => validateUsabilityFeedback({ ...feedback(), comment: "do not collect" }), /unexpected or missing/);
  const input = manifest();
  input.sessions[0].feedbackFile = "../V-001.json";
  assert.throws(() => validateUsabilityManifest(input), /JSON basename/);
});

test("loads ten separate fictional feedback files from disk", async (t) => {
  const directory = await mkdtemp(join(tmpdir(), "rightsrelay-gate-b-"));
  t.after(() => rm(directory, { recursive: true, force: true }));
  const input = manifest("gate-b");
  const records = feedbackForManifest(input);
  await writeFile(join(directory, "manifest.json"), JSON.stringify(input));
  await Promise.all(
    [...records].map(([filename, value]) => writeFile(join(directory, filename), JSON.stringify(value))),
  );

  const report = await loadAndEvaluateGateB(join(directory, "manifest.json"));
  assert.equal(report.counts.sessions, 10);
  assert.equal(report.decision, "pass");
});
