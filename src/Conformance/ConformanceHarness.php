<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Conformance;

/**
 * SPEC §8 / plan D-4 — the suite ships assertions; each service ships an adapter.
 *
 * A generic package cannot know `IngestLeadJob` from `DeliverEventToSubscription`.
 * Rather than guess at internals — which would make the suite either
 * service-specific (defeating the point) or reduced to shallow assertions on
 * helper classes — each service declares how to trigger the three paths the
 * contract constrains.
 *
 * The assertions themselves land with the conformance package (plan 6.8);
 * this interface exists now so the first adopting service can implement it
 * against a stable shape.
 */
interface ConformanceHarness
{
    /** Slug this service is known by on the conformance dashboard. */
    public function serviceSlug(): string;

    /**
     * An inbound HTTP path that accepts trace context. Used to assert
     * `trace.http` and `trace.http.echo`.
     */
    public function inboundTracePath(): string;

    /**
     * Trigger an outbound signed request and return exactly what was
     * transmitted. Used to assert the `sig.*` requirements.
     *
     * @param  string  $scheme  scheme name to exercise
     * @return array{headers: array<string, string>, body: string, secret: string}
     */
    public function triggerSignedDelivery(string $scheme): array;

    /**
     * Dispatch a job that makes an outbound HTTP call, so the suite can assert
     * `trace.queue` — context surviving dispatch → worker → outbound request.
     *
     * MUST actually enqueue: running inline would pass vacuously.
     */
    public function dispatchTracedJob(): void;

    /**
     * Scheme names this service can currently sign with.
     *
     * @return array<int, string>
     */
    public function supportedSchemes(): array;
}
