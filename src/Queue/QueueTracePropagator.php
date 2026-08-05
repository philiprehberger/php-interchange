<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Queue;

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use PhilipRehberger\Interchange\Tracing\TraceContext;
use PhilipRehberger\Interchange\Tracing\TraceScope;

/**
 * Carries trace context across the queue boundary.
 *
 * SPEC §3.3 — every hop in this fleet crosses a queue (Inkwell delivers from
 * DeliverToDestinationJob, Webhook Relay from DeliverEventToSubscription,
 * Switchyard from IngestLeadJob → RouteLeadToClickUpJob). A contract that only
 * propagates over HTTP yields five disconnected root spans and a trace view
 * that shows nothing useful.
 *
 * Deliberately NOT the opt-in trait model used by laravel-correlation-id: that
 * requires every job class to be annotated, which a future contributor will
 * silently forget. `Queue::createPayloadUsing` captures every dispatch with no
 * per-job change, which is what "MUST propagate across asynchronous
 * boundaries" actually requires.
 */
final class QueueTracePropagator
{
    public const PAYLOAD_KEY = 'interchange_trace';

    public static function register(): void
    {
        // Dispatch side: stamp the active context onto every job payload.
        Queue::createPayloadUsing(static function (): array {
            $context = TraceScope::current();

            if ($context === null) {
                return [];
            }

            return [
                self::PAYLOAD_KEY => [
                    'traceparent' => $context->toTraceparent(),
                    'tracestate' => $context->toTracestate(),
                ],
            ];
        });

        // Worker side: restore before handle(), clear after, so a long-lived
        // worker never leaks one job's trace into the next.
        app('events')->listen(JobProcessing::class, static function (JobProcessing $event): void {
            TraceScope::set(self::fromJob($event->job));
        });

        app('events')->listen(JobProcessed::class, static function (): void {
            TraceScope::clear();
        });
    }

    /** Rehydrate the context a dispatching process stamped onto this job. */
    public static function fromJob(JobContract $job): ?TraceContext
    {
        $payload = $job->payload();
        $carrier = $payload[self::PAYLOAD_KEY] ?? null;

        if (! is_array($carrier) || ! isset($carrier['traceparent'])) {
            return null;
        }

        $parent = TraceContext::parse(
            is_string($carrier['traceparent']) ? $carrier['traceparent'] : null,
            isset($carrier['tracestate']) && is_string($carrier['tracestate']) ? $carrier['tracestate'] : null,
        );

        // The job is a child of the dispatching span, not a continuation of it.
        return $parent?->child();
    }
}
