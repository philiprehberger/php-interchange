<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Tests;

use PhilipRehberger\Interchange\Tracing\TraceClass;
use PhilipRehberger\Interchange\Tracing\TraceContext;
use PhilipRehberger\Interchange\Tracing\TraceScope;
use PhilipRehberger\Interchange\Tracing\TraceState;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §3.4 / conformance key `trace.class.failclosed`.
 *
 * Every one of these is the same assertion from a different angle: anything
 * that is not exactly `scenario` is `production`. They are written out
 * individually because the failure mode is a *specific* input slipping
 * through, and a loop over a fixture array tends to grow only the cases
 * someone already thought of.
 */
class TraceClassTest extends TestCase
{
    protected function tearDown(): void
    {
        TraceScope::clear();
        parent::tearDown();
    }

    public function test_scenario_is_the_only_permissive_value(): void
    {
        $this->assertSame(TraceClass::SCENARIO, TraceClass::fromState($this->state('mnl_class=scenario')));
    }

    public function test_an_explicit_production_value_is_production(): void
    {
        $this->assertSame(TraceClass::PRODUCTION, TraceClass::fromState($this->state('mnl_class=production')));
    }

    public function test_a_null_state_fails_closed(): void
    {
        $this->assertSame(TraceClass::PRODUCTION, TraceClass::fromState(null));
    }

    public function test_an_empty_state_fails_closed(): void
    {
        $this->assertSame(TraceClass::PRODUCTION, TraceClass::fromState(TraceState::empty()));
    }

    public function test_a_state_without_the_member_fails_closed(): void
    {
        $this->assertSame(TraceClass::PRODUCTION, TraceClass::fromState($this->state('vendor=something')));
    }

    public function test_an_unrecognised_value_fails_closed(): void
    {
        // The case a plain `?? 'production'` lets through: present, non-null,
        // and meaningless. This is the whole reason the class exists.
        $this->assertSame(TraceClass::PRODUCTION, TraceClass::fromState($this->state('mnl_class=admin')));
    }

    public function test_a_wrong_case_value_fails_closed(): void
    {
        // Not treated as a sloppy spelling of the permissive class — being
        // lenient would invent a route to `scenario` the contract never defines.
        $this->assertSame(TraceClass::PRODUCTION, TraceClass::fromState($this->state('mnl_class=Scenario')));
    }

    public function test_an_empty_value_fails_closed(): void
    {
        $this->assertSame(TraceClass::PRODUCTION, TraceClass::fromState($this->state('mnl_class=')));
    }

    public function test_a_value_that_merely_contains_scenario_fails_closed(): void
    {
        $this->assertSame(TraceClass::PRODUCTION, TraceClass::fromState($this->state('mnl_class=notascenario')));
    }

    public function test_non_string_values_fail_closed(): void
    {
        foreach ([null, true, false, 0, 1, 1.5, [], new \stdClass] as $value) {
            $this->assertSame(TraceClass::PRODUCTION, TraceClass::normalise($value));
        }
    }

    public function test_body_snapshots_are_permitted_only_for_scenarios(): void
    {
        $this->assertTrue(TraceClass::permitsBodySnapshot($this->state('mnl_class=scenario')));
        $this->assertFalse(TraceClass::permitsBodySnapshot($this->state('mnl_class=production')));
        $this->assertFalse(TraceClass::permitsBodySnapshot($this->state('mnl_class=anything-else')));
        $this->assertFalse(TraceClass::permitsBodySnapshot(null));
    }

    public function test_current_reads_the_ambient_context(): void
    {
        TraceScope::set(new TraceContext(
            str_repeat('a', 32),
            str_repeat('b', 16),
            true,
            $this->state('mnl_class=scenario'),
        ));

        $this->assertSame(TraceClass::SCENARIO, TraceClass::current());
    }

    public function test_current_fails_closed_with_no_ambient_context(): void
    {
        TraceScope::clear();

        $this->assertSame(TraceClass::PRODUCTION, TraceClass::current());
    }

    private function state(string $header): TraceState
    {
        return TraceState::parse($header);
    }
}
