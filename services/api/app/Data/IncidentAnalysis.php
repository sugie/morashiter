<?php

declare(strict_types=1);

namespace App\Data;

use InvalidArgumentException;
use JsonSerializable;

final readonly class IncidentAnalysis implements JsonSerializable
{
    /**
     * @param  list<string>  $dataTypes
     */
    public function __construct(
        public ?string $organization,
        public ?string $incidentTitle,
        public ?string $affectedCountText,
        public array $dataTypes,
        public ?string $disclosedAt,
        public ?string $responseStatus,
        public string $summary,
        public bool $needsHumanReview = true,
    ) {}

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array $value): self
    {
        $dataTypes = $value['data_types'] ?? [];

        if (! is_array($dataTypes) || ! array_is_list($dataTypes)) {
            throw new InvalidArgumentException('data_types must be a list.');
        }

        $summary = $value['summary'] ?? null;

        if (! is_string($summary) || trim($summary) === '') {
            throw new InvalidArgumentException('summary is required.');
        }

        return new self(
            self::nullableString($value['organization'] ?? null),
            self::nullableString($value['incident_title'] ?? null),
            self::nullableString($value['affected_count_text'] ?? null),
            array_values(array_filter($dataTypes, is_string(...))),
            self::nullableString($value['disclosed_at'] ?? null),
            self::nullableString($value['response_status'] ?? null),
            trim($summary),
            true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'organization' => $this->organization,
            'incident_title' => $this->incidentTitle,
            'affected_count_text' => $this->affectedCountText,
            'data_types' => $this->dataTypes,
            'disclosed_at' => $this->disclosedAt,
            'response_status' => $this->responseStatus,
            'summary' => $this->summary,
            'needs_human_review' => $this->needsHumanReview,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
