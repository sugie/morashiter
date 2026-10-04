import assert from "node:assert/strict";
import test from "node:test";
import {
  aggregatePackets,
  samplePackets,
  validateSharePacket,
} from "../lib/share-packet.ts";

test("accepts the exact minimal packet schema", () => {
  const result = validateSharePacket(samplePackets[0]);
  assert.equal(result.ok, true);
});

test("rejects unknown fields instead of silently retaining sensitive data", () => {
  const packet = structuredClone(samplePackets[0]);
  packet.localNote = "do not accept this";
  const result = validateSharePacket(packet);

  assert.equal(result.ok, false);
  assert.match(result.reason, /許可されていない項目/);
});

test("rejects altered consent boundaries", () => {
  const packet = structuredClone(samplePackets[0]);
  packet.consent.legalEngagementCreated = true;
  const result = validateSharePacket(packet);

  assert.equal(result.ok, false);
  assert.match(result.reason, /同意/);
});

test("deduplicates packet IDs and exports counts only", () => {
  const aggregate = aggregatePackets([...samplePackets, samplePackets[0]]);

  assert.equal(aggregate.packetCount, 5);
  assert.equal(aggregate.duplicateCount, 1);
  assert.equal(aggregate.assessment.possible, 4);
  assert.equal(aggregate.evidence.notice, 4);
  assert.equal("packetId" in aggregate, false);
});
