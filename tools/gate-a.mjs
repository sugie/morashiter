import { readFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const ROLE_VALUES = ["lawyer", "consumer-body", "counselor", "security", "other"];
const TIME_VALUES = ["under-15", "15-30", "31-60", "over-60", "unknown"];
const UTILITY_VALUES = ["yes", "partial", "no"];
const RISK_VALUES = ["legal", "privacy", "quality", "operations", "trust", "none"];
const FORMAT_VALUES = ["csv", "pdf", "json", "web-form", "api"];
const BOUNDARY_VALUES = ["clear", "needs-review", "unsafe"];
const NEXT_STEP_VALUES = ["second-review", "pilot", "no"];
const ORIGINAL_VALUES = ["not-needed-initial", "after-triage", "before-triage", "unknown"];
const MISSING_FIELD_VALUES = [
  "timeline",
  "actualHarm",
  "amount",
  "authenticity",
  "evidenceProof",
  "consentAudit",
  "contactRoute",
  "none",
];
const EXPECTED_EXCLUDED = [
  "氏名・連絡先",
  "所属組織名",
  "自由記述",
  "実在する被害者・事件データ",
  "読み込んだ共有パケット",
];
const EXPECTED_DISCLAIMER = "法律意見、提携合意、実証参加の確約ではありません。";

const RESPONSE_KEYS = [
  "role",
  "currentIntakeTimeBand",
  "initialTriageValue",
  "expectedTimeWithPrototype",
  "minimumMissingFields",
  "primaryRisk",
  "preferredOutput",
  "legalBoundaryAssessment",
  "nextStepWillingness",
  "originalEvidenceTiming",
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

export function validateEvaluation(value) {
  assertExactKeys(
    value,
    ["schema", "prototypeVersion", "context", "durationSeconds", "responses", "excluded", "disclaimer"],
    "evaluation",
  );
  if (value.schema !== "rightsrelay.support-evaluation.v2") {
    throw new Error("evaluation.schema must be rightsrelay.support-evaluation.v2");
  }
  if (typeof value.prototypeVersion !== "string" || !/^2026-09-30-v\d+$/.test(value.prototypeVersion)) {
    throw new Error("evaluation.prototypeVersion is invalid");
  }
  if (value.context !== "fictional-five-packet-sample") {
    throw new Error("evaluation.context must use the fictional sample");
  }
  if (
    value.durationSeconds !== null &&
    (!Number.isInteger(value.durationSeconds) || value.durationSeconds < 0 || value.durationSeconds > 14_400)
  ) {
    throw new Error("evaluation.durationSeconds is invalid");
  }
  if (
    !Array.isArray(value.excluded) ||
    value.excluded.length !== EXPECTED_EXCLUDED.length ||
    value.excluded.some((item, index) => item !== EXPECTED_EXCLUDED[index])
  ) {
    throw new Error("evaluation.excluded does not match the approved fixed list");
  }
  if (value.disclaimer !== EXPECTED_DISCLAIMER) {
    throw new Error("evaluation.disclaimer does not match the approved fixed text");
  }

  assertExactKeys(value.responses, RESPONSE_KEYS, "evaluation.responses");
  const responses = value.responses;
  assertEnum(responses.role, ROLE_VALUES, "responses.role");
  assertEnum(responses.currentIntakeTimeBand, TIME_VALUES, "responses.currentIntakeTimeBand");
  assertEnum(responses.initialTriageValue, UTILITY_VALUES, "responses.initialTriageValue");
  assertEnum(responses.expectedTimeWithPrototype, TIME_VALUES, "responses.expectedTimeWithPrototype");
  assertEnum(responses.primaryRisk, RISK_VALUES, "responses.primaryRisk");
  assertEnum(responses.preferredOutput, FORMAT_VALUES, "responses.preferredOutput");
  assertEnum(responses.legalBoundaryAssessment, BOUNDARY_VALUES, "responses.legalBoundaryAssessment");
  assertEnum(responses.nextStepWillingness, NEXT_STEP_VALUES, "responses.nextStepWillingness");
  assertEnum(responses.originalEvidenceTiming, ORIGINAL_VALUES, "responses.originalEvidenceTiming");

  if (
    !Array.isArray(responses.minimumMissingFields) ||
    responses.minimumMissingFields.length === 0 ||
    new Set(responses.minimumMissingFields).size !== responses.minimumMissingFields.length ||
    responses.minimumMissingFields.some((item) => !MISSING_FIELD_VALUES.includes(item)) ||
    (responses.minimumMissingFields.includes("none") && responses.minimumMissingFields.length !== 1)
  ) {
    throw new Error("responses.minimumMissingFields is invalid");
  }
  return value;
}

export function validateManifest(value) {
  assertExactKeys(value, ["schema", "roundId", "manualChecks", "sessions"], "manifest");
  if (value.schema !== "rightsrelay.gate-a-manifest.v1") {
    throw new Error("manifest.schema must be rightsrelay.gate-a-manifest.v1");
  }
  if (typeof value.roundId !== "string" || !/^[a-z0-9][a-z0-9-]{0,39}$/.test(value.roundId)) {
    throw new Error("manifest.roundId is invalid");
  }
  assertExactKeys(value.manualChecks, ["coreLegalRoleRequired", "unavoidableLegalRisk"], "manifest.manualChecks");
  for (const key of ["coreLegalRoleRequired", "unavoidableLegalRisk"]) {
    if (![true, false, null].includes(value.manualChecks[key])) {
      throw new Error(`manifest.manualChecks.${key} must be true, false, or null`);
    }
  }
  if (!Array.isArray(value.sessions)) throw new Error("manifest.sessions must be an array");

  const ids = new Set();
  const files = new Set();
  for (const [index, session] of value.sessions.entries()) {
    const label = `manifest.sessions[${index}]`;
    assertExactKeys(
      session,
      ["sessionId", "phase", "evaluationFile", "organizationCode", "retestsSessionId"],
      label,
    );
    if (typeof session.sessionId !== "string" || !/^[A-Z]{1,3}-\d{3}$/.test(session.sessionId)) {
      throw new Error(`${label}.sessionId is invalid`);
    }
    if (ids.has(session.sessionId)) throw new Error(`${label}.sessionId is duplicated`);
    ids.add(session.sessionId);
    assertEnum(session.phase, ["initial", "retest"], `${label}.phase`);
    if (
      typeof session.evaluationFile !== "string" ||
      !/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}\.json$/.test(session.evaluationFile) ||
      path.basename(session.evaluationFile) !== session.evaluationFile
    ) {
      throw new Error(`${label}.evaluationFile must be a JSON basename`);
    }
    if (files.has(session.evaluationFile)) throw new Error(`${label}.evaluationFile is duplicated`);
    files.add(session.evaluationFile);
    if (session.organizationCode !== null && !/^ORG-\d{3}$/.test(session.organizationCode)) {
      throw new Error(`${label}.organizationCode is invalid`);
    }
    if (session.phase === "initial" && session.retestsSessionId !== null) {
      throw new Error(`${label}.retestsSessionId must be null for an initial session`);
    }
    if (
      session.phase === "retest" &&
      (typeof session.retestsSessionId !== "string" || !/^[A-Z]{1,3}-\d{3}$/.test(session.retestsSessionId))
    ) {
      throw new Error(`${label}.retestsSessionId is required for a retest`);
    }
  }

  const initialIds = new Set(value.sessions.filter((item) => item.phase === "initial").map((item) => item.sessionId));
  for (const session of value.sessions.filter((item) => item.phase === "retest")) {
    if (!initialIds.has(session.retestsSessionId)) {
      throw new Error(`${session.sessionId} retests an unknown initial session`);
    }
  }
  return value;
}

const TIME_RANK = new Map([
  ["under-15", 0],
  ["15-30", 1],
  ["31-60", 2],
  ["over-60", 3],
]);

function hasTimeReduction(responses) {
  if (!TIME_RANK.has(responses.currentIntakeTimeBand) || !TIME_RANK.has(responses.expectedTimeWithPrototype)) {
    return false;
  }
  return TIME_RANK.get(responses.expectedTimeWithPrototype) < TIME_RANK.get(responses.currentIntakeTimeBand);
}

export function evaluateGateA(manifest, evaluationsByFile) {
  validateManifest(manifest);
  const records = manifest.sessions.map((session) => {
    const evaluation = evaluationsByFile.get(session.evaluationFile);
    if (!evaluation) throw new Error(`missing evaluation file: ${session.evaluationFile}`);
    validateEvaluation(evaluation);
    if (session.organizationCode && evaluation.responses.role !== "consumer-body") {
      throw new Error(`${session.sessionId} has an organizationCode but is not a consumer-body evaluation`);
    }
    if (!session.organizationCode && evaluation.responses.role === "consumer-body") {
      throw new Error(`${session.sessionId} is a consumer-body evaluation without organizationCode`);
    }
    return { ...session, responses: evaluation.responses };
  });

  const initial = records.filter((record) => record.phase === "initial");
  if (initial.length !== 5) throw new Error("Gate A round 1 requires exactly five initial sessions");

  const retests = records.filter((record) => record.phase === "retest");
  const lawyerCount = initial.filter((record) => record.responses.role === "lawyer").length;
  const consumerOrganizations = new Set(
    initial.filter((record) => record.responses.role === "consumer-body").map((record) => record.organizationCode),
  );
  const valueCount = initial.filter((record) => ["yes", "partial"].includes(record.responses.initialTriageValue)).length;
  const followupCount = initial.filter((record) => ["second-review", "pilot"].includes(record.responses.nextStepWillingness)).length;
  const timeReductionCount = initial.filter((record) => hasTimeReduction(record.responses)).length;
  const noValueCount = initial.filter((record) => record.responses.initialTriageValue === "no").length;
  const originalRequiredCount = initial.filter(
    (record) => record.responses.originalEvidenceTiming === "before-triage",
  ).length;
  const unknownOriginalCount = initial.filter((record) => record.responses.originalEvidenceTiming === "unknown").length;

  const unsafeInitial = initial.filter((record) => record.responses.legalBoundaryAssessment === "unsafe");
  const unresolvedUnsafeIds = unsafeInitial
    .filter(
      (record) =>
        !retests.some(
          (retest) =>
            retest.retestsSessionId === record.sessionId && retest.responses.legalBoundaryAssessment !== "unsafe",
        ),
    )
    .map((record) => record.sessionId);

  const conditions = {
    participantMix: lawyerCount >= 2 && consumerOrganizations.size >= 2,
    valueSignal: valueCount >= 2,
    followupSignal: followupCount >= 1,
    timeReductionEvidence: timeReductionCount >= 2,
    legalBoundarySafeOrRetested: unresolvedUnsafeIds.length === 0,
    originalsNotRequiredByMajority: originalRequiredCount <= initial.length / 2,
  };

  let decision;
  const manualComplete = Object.values(manifest.manualChecks).every((value) => value !== null);
  if (manifest.manualChecks.unavoidableLegalRisk === true) {
    decision = "stop";
  } else if (
    noValueCount >= 4 ||
    timeReductionCount === 0 ||
    originalRequiredCount > initial.length / 2 ||
    manifest.manualChecks.coreLegalRoleRequired === true
  ) {
    decision = "pivot";
  } else if (Object.values(conditions).every(Boolean) && manualComplete) {
    decision = "continue";
  } else {
    decision = "conditional";
  }

  return {
    schema: "rightsrelay.gate-a-report.v1",
    roundId: manifest.roundId,
    decision,
    conditions,
    counts: {
      initialSessions: initial.length,
      retestSessions: retests.length,
      lawyers: lawyerCount,
      consumerBodyOrganizations: consumerOrganizations.size,
      valueResponses: valueCount,
      followupResponses: followupCount,
      timeReductionResponses: timeReductionCount,
      noValueResponses: noValueCount,
      originalsRequiredBeforeTriage: originalRequiredCount,
      originalsTimingUnknown: unknownOriginalCount,
    },
    unresolvedUnsafeSessionIds: unresolvedUnsafeIds,
    manualChecks: manifest.manualChecks,
    warnings: [
      ...(unknownOriginalCount > 0 ? ["証拠原本の必要時期を判断できない回答があります。"] : []),
      ...(!manualComplete ? ["手動の法的境界確認が未完了です。"] : []),
    ],
    generatedFrom: "de-identified closed-choice evaluations; no names, contacts, free text, or case data",
  };
}

export async function loadAndEvaluate(manifestPath) {
  const absoluteManifestPath = path.resolve(manifestPath);
  const manifest = JSON.parse(await readFile(absoluteManifestPath, "utf8"));
  validateManifest(manifest);
  const directory = path.dirname(absoluteManifestPath);
  const evaluations = new Map();
  for (const session of manifest.sessions) {
    const filePath = path.join(directory, session.evaluationFile);
    evaluations.set(session.evaluationFile, JSON.parse(await readFile(filePath, "utf8")));
  }
  return evaluateGateA(manifest, evaluations);
}

const invokedPath = process.argv[1] ? pathToFileURL(path.resolve(process.argv[1])).href : "";
if (import.meta.url === invokedPath) {
  const manifestPath = process.argv[2];
  if (!manifestPath) {
    console.error("Usage: node tools/gate-a.mjs /path/to/manifest.json");
    process.exitCode = 2;
  } else {
    try {
      console.log(JSON.stringify(await loadAndEvaluate(manifestPath), null, 2));
    } catch (error) {
      console.error(`Gate A evaluation failed: ${error instanceof Error ? error.message : String(error)}`);
      process.exitCode = 1;
    }
  }
}
