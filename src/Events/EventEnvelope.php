<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Events;

/**
 * SPEC §7 — the event envelope.
 *
 * Field names are RECOMMENDED, not normative. Webhook Relay's shipped API takes
 * `{type, payload}` with four generated SDKs compiled against it; mandating
 * `data` would have renamed a field on a public API to satisfy a naming
 * preference. What the contract requires is that correlation and causation are
 * CARRIED — what they are called is the service's business.
 *
 * `causation_id` names the IMMEDIATE predecessor, not the root:
 *   - a dead-letter replay is caused by the original event
 *   - a retry attempt is caused by the failed attempt
 * That is what lets a consumer render a chain rather than a flat list.
 */
final readonly class EventEnvelope
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $id,
        public string $type,
        public string $time,
        public array $data,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}

    /**
     * @param  array<string, string>  $fieldMap  override names a service already uses,
     *                                           e.g. ['data' => 'payload'] for Webhook Relay
     * @return array<string, mixed>
     */
    public function toArray(array $fieldMap = []): array
    {
        $name = fn (string $key): string => $fieldMap[$key] ?? $key;

        $out = [
            $name('id') => $this->id,
            $name('type') => $this->type,
            $name('time') => $this->time,
            $name('data') => $this->data,
        ];

        if ($this->correlationId !== null) {
            $out[$name('correlation_id')] = $this->correlationId;
        }

        if ($this->causationId !== null) {
            $out[$name('causation_id')] = $this->causationId;
        }

        return $out;
    }

    /**
     * An event caused by this one — a retry, a replay, a downstream effect.
     *
     * @param  array<string, mixed>  $data
     */
    public function causing(string $id, string $type, array $data, ?string $time = null): self
    {
        return new self(
            id: $id,
            type: $type,
            time: $time ?? $this->time,
            data: $data,
            correlationId: $this->correlationId ?? $this->id,
            causationId: $this->id,
        );
    }
}
