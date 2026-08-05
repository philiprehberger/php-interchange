<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Tracing;

/**
 * The ambient trace context for the current process/request/job.
 *
 * Deliberately a simple static holder rather than a container binding: it must
 * be readable from a queue worker's job payload hook, from HTTP middleware, and
 * from an outbound HTTP client, none of which reliably share a container scope.
 */
final class TraceScope
{
    private static ?TraceContext $current = null;

    public static function current(): ?TraceContext
    {
        return self::$current;
    }

    public static function set(?TraceContext $context): void
    {
        self::$current = $context;
    }

    /** Start a trace if none is active; otherwise keep the existing one. */
    public static function ensure(): TraceContext
    {
        return self::$current ??= TraceContext::start(TraceState::empty());
    }

    public static function clear(): void
    {
        self::$current = null;
    }

    /** Run a callback with a given context active, restoring the previous one after. */
    public static function with(?TraceContext $context, callable $callback): mixed
    {
        $previous = self::$current;
        self::$current = $context;

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }
}
