<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Tests;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PhilipRehberger\Interchange\Http\PropagatingHttpClient;
use PhilipRehberger\Interchange\Queue\QueueTracePropagator;
use PhilipRehberger\Interchange\Tracing\TraceContext;
use PhilipRehberger\Interchange\Tracing\TraceScope;
use PhilipRehberger\Interchange\Tracing\TraceState;

/**
 * Plan gate G-3 — the load-bearing assumption of the entire contract.
 *
 * Every hop in the fleet crosses a queue. If trace context cannot survive
 * dispatch → worker → outbound HTTP, the design produces five disconnected
 * root spans and everything downstream of it is invalid.
 *
 * These tests use the DATABASE queue driver and explicitly clear the ambient
 * scope between dispatch and execution, because a worker is a different
 * process with no shared memory. Running this on the `sync` driver would pass
 * vacuously — the context would still be in scope from the dispatching request.
 */
class QueueBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TraceScope::clear();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
    }

    protected function tearDown(): void
    {
        TraceScope::clear();
        parent::tearDown();
    }

    public function test_trace_context_survives_dispatch_worker_and_outbound_http(): void
    {
        $origin = TraceContext::start(TraceState::empty()->with('mnl_class', 'scenario'));
        TraceScope::set($origin);

        TracedJob::dispatch();

        // The worker is a different process. Nothing is in scope for it.
        TraceScope::clear();
        $this->assertNull(TraceScope::current());

        $this->workOneJob();

        Http::assertSent(function ($request) use ($origin) {
            $traceparent = $request->header('traceparent')[0] ?? '';
            $tracestate = $request->header('tracestate')[0] ?? '';

            // Same trace...
            $this->assertStringContainsString($origin->traceId, $traceparent);
            // ...but a new span, not a replay of the dispatching one.
            $this->assertStringNotContainsString($origin->parentId, $traceparent);
            // ...and tracestate rode along.
            $this->assertStringContainsString('mnl_class=scenario', $tracestate);

            return true;
        });
    }

    public function test_the_job_span_is_a_child_of_the_dispatching_span(): void
    {
        $origin = TraceContext::start();
        TraceScope::set($origin);

        TracedJob::dispatch();
        TraceScope::clear();

        $this->workOneJob();

        $this->assertSame($origin->traceId, TracedJob::$seenTraceId);
        $this->assertNotSame($origin->parentId, TracedJob::$seenSpanId);
        $this->assertTrue(TraceContext::isValidParentId((string) TracedJob::$seenSpanId));
    }

    public function test_a_job_dispatched_without_a_trace_does_not_invent_one(): void
    {
        TraceScope::clear();

        TracedJob::dispatch();
        $this->workOneJob();

        // No context stamped means none restored — the job is untraced rather
        // than being given a bogus root that would fragment the view.
        $this->assertNull(TracedJob::$seenTraceId);
    }

    public function test_the_worker_does_not_leak_one_jobs_trace_into_the_next(): void
    {
        // A long-lived worker processes many jobs. Leaking context between them
        // would silently merge unrelated traces.
        $first = TraceContext::start();
        TraceScope::set($first);
        TracedJob::dispatch();
        TraceScope::clear();
        $this->workOneJob();
        $this->assertSame($first->traceId, TracedJob::$seenTraceId);

        TracedJob::$seenTraceId = null;

        TraceScope::clear();
        TracedJob::dispatch();
        $this->workOneJob();

        $this->assertNull(TracedJob::$seenTraceId, 'the previous job\'s trace must not persist');
    }

    public function test_the_payload_carries_the_context_on_the_wire(): void
    {
        $origin = TraceContext::start(TraceState::empty()->with('mnl_class', 'scenario'));
        TraceScope::set($origin);

        TracedJob::dispatch();

        // Read what actually landed in the queue table, not an internal method:
        // this is the bytes a separate worker process will read back.
        $stored = json_decode((string) DB::table('jobs')->value('payload'), true);

        $this->assertArrayHasKey(QueueTracePropagator::PAYLOAD_KEY, $stored);
        $carrier = $stored[QueueTracePropagator::PAYLOAD_KEY];

        $this->assertStringContainsString($origin->traceId, $carrier['traceparent']);
        $this->assertStringContainsString('mnl_class=scenario', $carrier['tracestate']);
    }

    private function workOneJob(): void
    {
        $this->artisan('queue:work', [
            '--once' => true,
            '--queue' => 'default',
            '--stop-when-empty' => true,
        ]);
    }
}

class TracedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public static ?string $seenTraceId = null;

    public static ?string $seenSpanId = null;

    public function handle(): void
    {
        $context = TraceScope::current();

        self::$seenTraceId = $context?->traceId;
        self::$seenSpanId = $context?->parentId;

        PropagatingHttpClient::make()->post('https://downstream.test/v1/events', ['a' => 1]);
    }
}
