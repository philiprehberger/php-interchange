<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use PhilipRehberger\Interchange\Tracing\TraceScope;

/**
 * Outbound HTTP that carries the ambient trace context.
 *
 * The point is that propagation is automatic rather than per-callsite: a
 * contract requiring every outbound call to be traced cannot depend on every
 * developer remembering to attach two headers.
 */
final class PropagatingHttpClient
{
    /** A PendingRequest with the current trace context already attached. */
    public static function make(): PendingRequest
    {
        return Http::withHeaders(self::headers());
    }

    /** Attach the current context to a request you already built. */
    public static function attach(PendingRequest $request): PendingRequest
    {
        return $request->withHeaders(self::headers());
    }

    /**
     * The headers for the *next outbound call*: a fresh child span, so the
     * callee's parent-id is this call rather than our own inbound parent.
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        $context = TraceScope::current();

        if ($context === null) {
            return [];
        }

        return $context->child()->toHeaders();
    }
}
