export const SHARE_PACKET_SCHEMA = "rightsrelay.support-review.v1" as const;
export const INCIDENT_ID = "times-car-2026-09" as const;
export const RULE_VERSION = "2026-09-30-v1" as const;

export const membershipValues = ["current", "former", "applied", "never", "unknown"] as const;
export const noticeValues = ["yes", "no", "unknown"] as const;
export const assessmentValues = ["possible", "unclear", "unlikely"] as const;
export const evidenceValues = ["notice", "relationship", "suspicious", "expense"] as const;

export type ShareMembership = (typeof membershipValues)[number];
export type ShareNotice = (typeof noticeValues)[number];
export type ShareAssessment = (typeof assessmentValues)[number];
export type EvidenceKind = (typeof evidenceValues)[number];

export type SharePacket = {
  schema: typeof SHARE_PACKET_SCHEMA;
  packetId: string;
  incidentId: typeof INCIDENT_ID;
  ruleVersion: typeof RULE_VERSION;
  assessment: ShareAssessment;
  answers: {
    membership: ShareMembership;
    notice: ShareNotice;
  };
  evidenceHeld: EvidenceKind[];
  intent: {
    updates: boolean;
    supportReview: true;
    research: boolean;
  };
  consent: {
    purpose: "support-body-feasibility-review";
    recipient: "user-selected-support-body";
    legalEngagementCreated: false;
  };
};

export type PacketValidation =
  | { ok: true; packet: SharePacket }
  | { ok: false; reason: string };

const exactKeys = (value: Record<string, unknown>, keys: string[]) => {
  const actual = Object.keys(value).sort();
  const expected = [...keys].sort();
  return actual.length === expected.length && actual.every((key, index) => key === expected[index]);
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === "object" && value !== null && !Array.isArray(value);

const isOneOf = <T extends readonly string[]>(value: unknown, allowed: T): value is T[number] =>
  typeof value === "string" && allowed.includes(value);

export function createSharePacket(input: {
  membership: ShareMembership;
  notice: ShareNotice;
  assessment: ShareAssessment;
  evidenceHeld: EvidenceKind[];
  updates: boolean;
  research: boolean;
}): SharePacket {
  return {
    schema: SHARE_PACKET_SCHEMA,
    packetId: crypto.randomUUID(),
    incidentId: INCIDENT_ID,
    ruleVersion: RULE_VERSION,
    assessment: input.assessment,
    answers: {
      membership: input.membership,
      notice: input.notice,
    },
    evidenceHeld: evidenceValues.filter((value) => input.evidenceHeld.includes(value)),
    intent: {
      updates: input.updates,
      supportReview: true,
      research: input.research,
    },
    consent: {
      purpose: "support-body-feasibility-review",
      recipient: "user-selected-support-body",
      legalEngagementCreated: false,
    },
  };
}

export function validateSharePacket(value: unknown): PacketValidation {
  if (!isRecord(value)) return { ok: false, reason: "JSONオブジェクトではありません" };
  if (!exactKeys(value, ["schema", "packetId", "incidentId", "ruleVersion", "assessment", "answers", "evidenceHeld", "intent", "consent"])) {
    return { ok: false, reason: "許可されていない項目、または不足している項目があります" };
  }
  if (value.schema !== SHARE_PACKET_SCHEMA) return { ok: false, reason: "未対応の共有形式です" };
  if (value.incidentId !== INCIDENT_ID || value.ruleVersion !== RULE_VERSION) {
    return { ok: false, reason: "事案または判定ルールの版が一致しません" };
  }
  if (typeof value.packetId !== "string" || !/^[0-9a-f-]{36}$/i.test(value.packetId)) {
    return { ok: false, reason: "パケットIDが不正です" };
  }
  if (!isOneOf(value.assessment, assessmentValues)) return { ok: false, reason: "対象判定が不正です" };

  if (!isRecord(value.answers) || !exactKeys(value.answers, ["membership", "notice"])) {
    return { ok: false, reason: "対象確認の項目が不正です" };
  }
  if (!isOneOf(value.answers.membership, membershipValues) || !isOneOf(value.answers.notice, noticeValues)) {
    return { ok: false, reason: "対象確認の値が不正です" };
  }

  if (!Array.isArray(value.evidenceHeld) || !value.evidenceHeld.every((item) => isOneOf(item, evidenceValues))) {
    return { ok: false, reason: "証拠保有区分が不正です" };
  }
  if (new Set(value.evidenceHeld).size !== value.evidenceHeld.length) {
    return { ok: false, reason: "証拠保有区分が重複しています" };
  }

  if (!isRecord(value.intent) || !exactKeys(value.intent, ["updates", "supportReview", "research"])) {
    return { ok: false, reason: "意思表示の項目が不正です" };
  }
  if (typeof value.intent.updates !== "boolean" || value.intent.supportReview !== true || typeof value.intent.research !== "boolean") {
    return { ok: false, reason: "意思表示の値が不正です" };
  }

  if (!isRecord(value.consent) || !exactKeys(value.consent, ["purpose", "recipient", "legalEngagementCreated"])) {
    return { ok: false, reason: "同意記録の項目が不正です" };
  }
  if (
    value.consent.purpose !== "support-body-feasibility-review" ||
    value.consent.recipient !== "user-selected-support-body" ||
    value.consent.legalEngagementCreated !== false
  ) {
    return { ok: false, reason: "同意の目的または範囲が一致しません" };
  }

  return { ok: true, packet: value as SharePacket };
}

export function aggregatePackets(packets: SharePacket[]) {
  const unique = [...new Map(packets.map((packet) => [packet.packetId, packet])).values()];
  const countBy = <T extends string>(values: readonly T[], getValue: (packet: SharePacket) => T) =>
    Object.fromEntries(values.map((value) => [value, unique.filter((packet) => getValue(packet) === value).length])) as Record<T, number>;

  return {
    packetCount: unique.length,
    duplicateCount: packets.length - unique.length,
    assessment: countBy(assessmentValues, (packet) => packet.assessment),
    membership: countBy(membershipValues, (packet) => packet.answers.membership),
    notice: countBy(noticeValues, (packet) => packet.answers.notice),
    evidence: Object.fromEntries(
      evidenceValues.map((value) => [value, unique.filter((packet) => packet.evidenceHeld.includes(value)).length]),
    ) as Record<EvidenceKind, number>,
    intent: {
      updates: unique.filter((packet) => packet.intent.updates).length,
      supportReview: unique.length,
      research: unique.filter((packet) => packet.intent.research).length,
    },
  };
}

export const samplePackets: SharePacket[] = [
  ["11111111-1111-4111-8111-111111111111", "former", "yes", "possible", ["notice", "relationship"]],
  ["22222222-2222-4222-8222-222222222222", "current", "yes", "possible", ["notice", "relationship", "suspicious"]],
  ["33333333-3333-4333-8333-333333333333", "applied", "no", "possible", ["relationship"]],
  ["44444444-4444-4444-8444-444444444444", "unknown", "unknown", "unclear", ["notice"]],
  ["55555555-5555-4555-8555-555555555555", "former", "yes", "possible", ["notice", "expense"]],
].map(([packetId, membership, notice, assessment, evidenceHeld], index) => ({
  schema: SHARE_PACKET_SCHEMA,
  packetId: packetId as string,
  incidentId: INCIDENT_ID,
  ruleVersion: RULE_VERSION,
  assessment: assessment as ShareAssessment,
  answers: { membership: membership as ShareMembership, notice: notice as ShareNotice },
  evidenceHeld: evidenceHeld as EvidenceKind[],
  intent: { updates: index !== 2, supportReview: true, research: index < 3 },
  consent: {
    purpose: "support-body-feasibility-review",
    recipient: "user-selected-support-body",
    legalEngagementCreated: false,
  },
}));
