import { readFile } from "node:fs/promises";
import path from "node:path";
import { pathToFileURL } from "node:url";

const STUDY_STAGES = {
  "round-1": { sampleSize: 5, passCount: 4 },
  "round-2": { sampleSize: 5, passCount: 4 },
  "gate-b": { sampleSize: 10, passCount: 8 },
};
const MISCONCEPTIONS = ["assessment-is-legal", "local-is-external", "mandate-or-litigation-complete"];
const STOP_REASONS = ["none", "distress", "real-data-offered", "legal-advice-requested", "privacy-risk", "other"];
const EXPECTED_DESTINATIONS = ["device", "project", "support", "unknown"];
const CONCERNS = ["facts", "assessment", "evidence", "intent", "export", "none"];
const EXPECTED_EXCLUDED = [
  "氏名・連絡先",
  "会員情報",
  "対象確認の回答内容",
  "証拠チェックの内訳",
  "端末内メモ",
  "意思表示の内訳",
];

function isRecord(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

function assertRecord(value, label) {
  if (!isRecord(value)) throw new Error(`${label} must be an object`);
}

function assertExactKeys(value, keys, label) {
  assertRecord(value, label);
  const actual = Object.keys(value).sort();
  const expected = [...keys].sort();
  if (actual.length !== expected.length || actual.some((key, index) => key !== expected[index])) {
    throw new Error(`${label} has unexpected or missing fields: ${actual.join(", ")}`);
  }
}

function assertEnum(value, allowed, label) {
  if (!allowed.includes(value)) throw new Error(`${label} has an invalid value`);
}

export function validateUsabilityFeedback(value) {
  assertExactKeys(
    value,
    ["schemaVersion", "study", "prototypeVersion", "durationSeconds", "taskSignals", "feedback", "excluded"],
    "feedback",
  );
  if (value.schemaVersion !== 1 || value.study !== "rightsrelay-usability-v1") {
    throw new Error("feedback schema is not supported");
  }
  if (typeof value.prototypeVersion !== "string" || !/^2026-09-30-v\d+$/.test(value.prototypeVersion)) {
    throw new Error("feedback.prototypeVersion is invalid");
  }
  if (!Number.isInteger(value.durationSeconds) || value.durationSeconds < 0 || value.durationSeconds > 7_200) {
    throw new Error("feedback.durationSeconds is invalid");
  }
  assertExactKeys(
    value.taskSignals,
    ["assessmentCompleted", "evidenceChecklistUsed", "intentChoiceUsed"],
    "feedback.taskSignals",
  );
  for (const [key, signal] of Object.entries(value.taskSignals)) {
    if (typeof signal !== "boolean") throw new Error(`feedback.taskSignals.${key} must be boolean`);
  }
  assertExactKeys(value.feedback, ["clarity", "trust", "expectedDestination", "primaryConcern"], "feedback.feedback");
  for (const key of ["clarity", "trust"]) {
    if (!Number.isInteger(value.feedback[key]) || value.feedback[key] < 1 || value.feedback[key] > 5) {
      throw new Error(`feedback.feedback.${key} must be an integer from 1 to 5`);
    }
  }
  assertEnum(value.feedback.expectedDestination, EXPECTED_DESTINATIONS, "feedback.feedback.expectedDestination");
  assertEnum(value.feedback.primaryConcern, CONCERNS, "feedback.feedback.primaryConcern");
  if (
    !Array.isArray(value.excluded) ||
    value.excluded.length !== EXPECTED_EXCLUDED.length ||
    value.excluded.some((item, index) => item !== EXPECTED_EXCLUDED[index])
  ) {
    throw new Error("feedback.excluded does not match the approved fixed list");
  }
  return value;
}

export function validateUsabilityManifest(value) {
  assertExactKeys(value, ["schema", "roundId", "stage", "sessions"], "manifest");
  if (value.schema !== "rightsrelay.usability-manifest.v1") {
    throw new Error("manifest.schema must be rightsrelay.usability-manifest.v1");
  }
  if (typeof value.roundId !== "string" || !/^[a-z0-9][a-z0-9-]{0,39}$/.test(value.roundId)) {
    throw new Error("manifest.roundId is invalid");
  }
  assertEnum(value.stage, Object.keys(STUDY_STAGES), "manifest.stage");
  if (!Array.isArray(value.sessions)) throw new Error("manifest.sessions must be an array");
  if (value.sessions.length !== STUDY_STAGES[value.stage].sampleSize) {
    throw new Error(`${value.stage} requires exactly ${STUDY_STAGES[value.stage].sampleSize} sessions`);
  }

  const ids = new Set();
  const files = new Set();
  for (const [index, session] of value.sessions.entries()) {
    const label = `manifest.sessions[${index}]`;
    assertExactKeys(
      session,
      [
        "sessionId",
        "feedbackFile",
        "taskCompletion",
        "majorAssistance",
        "misconceptions",
        "sensitiveInputAttempted",
        "coreTaskDurationSeconds",
        "stopReason",
      ],
      label,
    );
    if (typeof session.sessionId !== "string" || !/^V-\d{3}$/.test(session.sessionId)) {
      throw new Error(`${label}.sessionId is invalid`);
    }
    if (ids.has(session.sessionId)) throw new Error(`${label}.sessionId is duplicated`);
    ids.add(session.sessionId);
    if (
      typeof session.feedbackFile !== "string" ||
      !/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}\.json$/.test(session.feedbackFile) ||
      path.basename(session.feedbackFile) !== session.feedbackFile
    ) {
      throw new Error(`${label}.feedbackFile must be a JSON basename`);
    }
    if (files.has(session.feedbackFile)) throw new Error(`${label}.feedbackFile is duplicated`);
    files.add(session.feedbackFile);

    assertExactKeys(session.taskCompletion, ["t1", "t2", "t3", "t4"], `${label}.taskCompletion`);
    for (const [task, completed] of Object.entries(session.taskCompletion)) {
      if (typeof completed !== "boolean") throw new Error(`${label}.taskCompletion.${task} must be boolean`);
    }
    if (typeof session.majorAssistance !== "boolean") throw new Error(`${label}.majorAssistance must be boolean`);
    if (
      !Array.isArray(session.misconceptions) ||
      new Set(session.misconceptions).size !== session.misconceptions.length ||
      session.misconceptions.some((item) => !MISCONCEPTIONS.includes(item))
    ) {
      throw new Error(`${label}.misconceptions is invalid`);
    }
    if (typeof session.sensitiveInputAttempted !== "boolean") {
      throw new Error(`${label}.sensitiveInputAttempted must be boolean`);
    }
    if (
      session.coreTaskDurationSeconds !== null &&
      (!Number.isInteger(session.coreTaskDurationSeconds) ||
        session.coreTaskDurationSeconds < 0 ||
        session.coreTaskDurationSeconds > 3_600)
    ) {
      throw new Error(`${label}.coreTaskDurationSeconds is invalid`);
    }
    assertEnum(session.stopReason, STOP_REASONS, `${label}.stopReason`);
    if (session.stopReason === "none" && session.coreTaskDurationSeconds === null) {
      throw new Error(`${label}.coreTaskDurationSeconds is required unless the session stopped early`);
    }
  }
  return value;
}

function median(values) {
  const sorted = [...values].sort((a, b) => a - b);
  const middle = Math.floor(sorted.length / 2);
  return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
}

export function evaluateGateB(manifest, feedbackByFile) {
  validateUsabilityManifest(manifest);
  const records = manifest.sessions.map((session) => {
    const feedback = feedbackByFile.get(session.feedbackFile);
    if (!feedback) throw new Error(`missing feedback file: ${session.feedbackFile}`);
    validateUsabilityFeedback(feedback);
    return { ...session, feedback };
  });
  const required = STUDY_STAGES[manifest.stage].passCount;
  const allTasksWithoutMajorHelp = records.filter(
    (record) =>
      record.stopReason === "none" &&
      !record.majorAssistance &&
      Object.values(record.taskCompletion).every(Boolean),
  ).length;
  const coreUnderTenMinutes = records.filter(
    (record) => record.coreTaskDurationSeconds !== null && record.coreTaskDurationSeconds <= 600,
  ).length;
  const assessmentMisconceptions = records.filter((record) =>
    record.misconceptions.includes("assessment-is-legal"),
  ).length;
  const externalSendMisconceptions = records.filter((record) =>
    record.misconceptions.includes("local-is-external"),
  ).length;
  const mandateMisconceptions = records.filter((record) =>
    record.misconceptions.includes("mandate-or-litigation-complete"),
  ).length;
  const sensitiveInputAttempts = records.filter((record) => record.sensitiveInputAttempted).length;
  const correctDestination = records.filter(
    (record) => record.feedback.feedback.expectedDestination === "device",
  ).length;
  const clarityMedian = median(records.map((record) => record.feedback.feedback.clarity));
  const trustMedian = median(records.map((record) => record.feedback.feedback.trust));
  const stoppedEarly = records.filter((record) => record.stopReason !== "none").length;

  const conditions = {
    taskCompletion: allTasksWithoutMajorHelp >= required,
    coreFlowUnderTenMinutes: coreUnderTenMinutes >= required,
    assessmentMisconceptionsAcceptable: assessmentMisconceptions <= 1,
    externalSendMisconceptionsAcceptable: externalSendMisconceptions <= 1,
    mandateMisconceptionsAcceptable: mandateMisconceptions <= 1,
    noSensitiveInputAttempts: sensitiveInputAttempts === 0,
    deviceDestinationUnderstood: correctDestination >= required,
    clarityMedianAtLeastFour: clarityMedian >= 4,
    trustMedianAtLeastFour: trustMedian >= 4,
  };
  const safetyIssue = sensitiveInputAttempts > 0 || records.some((record) => record.stopReason === "privacy-risk");
  const decision = safetyIssue
    ? "safety-redesign-required"
    : Object.values(conditions).every(Boolean)
      ? "pass"
      : "revise-and-retest";

  const signalMismatches = records
    .filter(
      (record) =>
        (record.taskCompletion.t1 && !record.feedback.taskSignals.assessmentCompleted) ||
        (record.taskCompletion.t2 && !record.feedback.taskSignals.evidenceChecklistUsed) ||
        (record.taskCompletion.t3 && !record.feedback.taskSignals.intentChoiceUsed),
    )
    .map((record) => record.sessionId);

  return {
    schema: "rightsrelay.gate-b-report.v1",
    roundId: manifest.roundId,
    stage: manifest.stage,
    decision,
    requiredPassCount: required,
    conditions,
    counts: {
      sessions: records.length,
      allTasksWithoutMajorHelp,
      coreUnderTenMinutes,
      assessmentMisconceptions,
      externalSendMisconceptions,
      mandateMisconceptions,
      sensitiveInputAttempts,
      correctDestination,
      stoppedEarly,
    },
    medians: { clarity: clarityMedian, trust: trustMedian },
    warnings: [
      ...(signalMismatches.length
        ? [`観察記録と画面状態シグナルが一致しないセッション: ${signalMismatches.join(", ")}`]
        : []),
      ...(stoppedEarly ? ["中止セッションがあります。理由カテゴリを個別に確認してください。"] : []),
    ],
    generatedFrom: "de-identified feedback plus closed-choice observation records; no names, contacts, free text, or case data",
  };
}

export async function loadAndEvaluateGateB(manifestPath) {
  const absoluteManifestPath = path.resolve(manifestPath);
  const manifest = JSON.parse(await readFile(absoluteManifestPath, "utf8"));
  validateUsabilityManifest(manifest);
  const directory = path.dirname(absoluteManifestPath);
  const feedbackByFile = new Map();
  for (const session of manifest.sessions) {
    feedbackByFile.set(
      session.feedbackFile,
      JSON.parse(await readFile(path.join(directory, session.feedbackFile), "utf8")),
    );
  }
  return evaluateGateB(manifest, feedbackByFile);
}

const invokedPath = process.argv[1] ? pathToFileURL(path.resolve(process.argv[1])).href : "";
if (import.meta.url === invokedPath) {
  const manifestPath = process.argv[2];
  if (!manifestPath) {
    console.error("Usage: node tools/gate-b.mjs /path/to/manifest.json");
    process.exitCode = 2;
  } else {
    try {
      console.log(JSON.stringify(await loadAndEvaluateGateB(manifestPath), null, 2));
    } catch (error) {
      console.error(`Gate B evaluation failed: ${error instanceof Error ? error.message : String(error)}`);
      process.exitCode = 1;
    }
  }
}
