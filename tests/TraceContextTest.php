<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Tests;

use PhilipRehberger\Interchange\Tracing\TraceContext;
use PhilipRehberger\Interchange\Tracing\TraceState;
use PHPUnit\Framework\TestCase;

class TraceContextTest extends TestCase
{
    public function test_generated_ids_match_the_spec_shapes(): void
    {
        $c = TraceContext::start();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $c->traceId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $c->parentId);
        $this->assertMatchesRegularExpression(
            '/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/',
            $c->toTraceparent(),
        );
    }

    public function test_all_zero_ids_are_invalid(): void
    {
        $this->assertFalse(TraceContext::isValidTraceId(TraceContext::INVALID_TRACE_ID));
        $this->assertFalse(TraceContext::isValidParentId(TraceContext::INVALID_PARENT_ID));
        $this->assertNull(TraceContext::parse('00-'.TraceContext::INVALID_TRACE_ID.'-00f067aa0ba902b7-01'));
        $this->assertNull(TraceContext::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-'.TraceContext::INVALID_PARENT_ID.'-01'));
    }

    public function test_version_ff_is_rejected(): void
    {
        $this->assertNull(TraceContext::parse('ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'));
    }

    public function test_uppercase_hex_is_rejected(): void
    {
        // The spec's HEXDIGLC is lowercase-only.
        $this->assertNull(TraceContext::parse('00-4BF92F3577B34DA6A3CE929D0E0E4736-00f067aa0ba902b7-01'));
    }

    public function test_the_spec_example_round_trips(): void
    {
        $c = TraceContext::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

        $this->assertNotNull($c);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $c->traceId);
        $this->assertSame('00f067aa0ba902b7', $c->parentId);
        $this->assertTrue($c->sampled);
        $this->assertSame('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', $c->toTraceparent());
    }

    public function test_unsampled_flag_round_trips(): void
    {
        $c = TraceContext::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00');

        $this->assertNotNull($c);
        $this->assertFalse($c->sampled);
        $this->assertStringEndsWith('-00', $c->toTraceparent());
    }

    public function test_undefined_flag_bits_are_not_echoed_back(): void
    {
        // "Vendors MUST set those to zero" — we must not reflect bits we set.
        $c = TraceContext::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-ff');

        $this->assertNotNull($c);
        $this->assertTrue($c->sampled);
        $this->assertStringEndsWith('-01', $c->toTraceparent());
    }

    public function test_a_child_keeps_the_trace_and_mints_a_new_span(): void
    {
        $parent = TraceContext::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
        $child = $parent->child();

        $this->assertSame($parent->traceId, $child->traceId);
        $this->assertNotSame($parent->parentId, $child->parentId);
        $this->assertTrue(TraceContext::isValidParentId($child->parentId));
    }

    public function test_absent_or_malformed_headers_yield_null(): void
    {
        $this->assertNull(TraceContext::parse(null));
        $this->assertNull(TraceContext::parse(''));
        $this->assertNull(TraceContext::parse('garbage'));
        $this->assertNull(TraceContext::parse('00-tooshort-00f067aa0ba902b7-01'));
    }

    public function test_headers_include_tracestate_only_when_present(): void
    {
        $bare = TraceContext::start(TraceState::empty());
        $this->assertArrayNotHasKey('tracestate', $bare->toHeaders());

        $with = $bare->withState(TraceState::empty()->with('mnl_class', 'scenario'));
        $this->assertSame('mnl_class=scenario', $with->toHeaders()['tracestate']);
    }
}
