<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhilipRehberger\Interchange\Tracing\TraceContext;
use PhilipRehberger\Interchange\Tracing\TraceScope;
use PhilipRehberger\Interchange\Tracing\TraceState;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accept inbound trace context, create a child span, bind it to the log
 * context, and echo it on the response (SPEC §3.1, conformance keys
 * `trace.http` and `trace.http.echo`).
 *
 * An absent or invalid `traceparent` mints a new trace rather than continuing
 * a broken one — a malformed header is not a trace.
 */
final class TraceMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $inbound = TraceContext::parse(
            $request->header('traceparent'),
            $request->header('tracestate'),
        );

        $context = $inbound !== null
            ? $inbound->child()
            : TraceContext::start(TraceState::parse($request->header('tracestate')));

        TraceScope::set($context);
        $request->attributes->set('trace_context', $context);

        Log::shareContext([
            'trace_id' => $context->traceId,
            'span_id' => $context->parentId,
        ]);

        $response = $next($request);

        foreach ($context->toHeaders() as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        TraceScope::clear();
    }
}
