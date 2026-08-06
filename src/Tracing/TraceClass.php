<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Tracing;

/**
 * The `mnl_class` tracestate member — SPEC §3.4, conformance key
 * `trace.class.failclosed`.
 *
 * Real production traffic is traced; real production payloads are never
 * exposed. This member is how a downstream service knows which it is holding,
 * and therefore how it decides whether a body may be snapshotted.
 *
 * THE RULE IS FAIL-CLOSED, AND THAT IS THE WHOLE POINT.
 *
 * Absent, unrecognised, or malformed values MUST read as `production`. Getting
 * this wrong is not a cosmetic bug: `scenario` is the permissive class, so any
 * value that is not exactly `scenario` must fall to the restrictive one. An
 * inline `$state->get('mnl_class') ?? 'production'` — the shape this class
 * replaces — handles *absent* but silently passes an unrecognised value
 * through, so a forged or typo'd `mnl_class` would be stored verbatim and any
 * later `=== 'scenario'` check becomes the only thing standing between a real
 * lead body and a public trace view.
 *
 * Centralised here because it was independently reimplemented per service, and
 * a fail-closed rule reimplemented per service is a fail-closed rule with
 * per-service exceptions.
 */
final class TraceClass
{
    public const KEY = 'mnl_class';

    /** Real traffic. Restrictive: bodies MUST NOT be exposed. */
    public const PRODUCTION = 'production';

    /** Synthetic traffic from a scenario run. Permissive: bodies may be snapshotted. */
    public const SCENARIO = 'scenario';

    /** The only values that are recognised at all. */
    public const PERMITTED = [self::PRODUCTION, self::SCENARIO];

    /**
     * Resolve the class from a tracestate, failing closed.
     *
     * Anything that is not exactly `scenario` — absent state, absent member,
     * empty string, wrong case, unknown word, forged value — is `production`.
     */
    public static function fromState(?TraceState $state): string
    {
        return self::normalise($state?->get(self::KEY));
    }

    /** Resolve from the ambient trace context, failing closed. */
    public static function current(): string
    {
        return self::fromState(TraceScope::current()?->state);
    }

    /**
     * Normalise an arbitrary value. Note this does NOT lowercase or trim before
     * comparing: `Scenario` and ` scenario ` are *unrecognised*, not sloppy
     * spellings of the permissive class, and the spec's rule sends anything
     * unrecognised to `production`. Being lenient here would mean inventing
     * ways to reach the permissive class that the contract does not define.
     */
    public static function normalise(mixed $value): string
    {
        return $value === self::SCENARIO ? self::SCENARIO : self::PRODUCTION;
    }

    /** True only for traffic that may have its bodies snapshotted. */
    public static function permitsBodySnapshot(?TraceState $state): bool
    {
        return self::fromState($state) === self::SCENARIO;
    }
}
