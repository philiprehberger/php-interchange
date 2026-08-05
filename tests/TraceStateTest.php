<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Tests;

use PhilipRehberger\Interchange\Tracing\TraceState;
use PHPUnit\Framework\TestCase;

class TraceStateTest extends TestCase
{
    public function test_the_spec_example_parses(): void
    {
        $s = TraceState::parse('rojo=00f067aa0ba902b7,congo=t61rcWkgMzE');

        $this->assertSame('00f067aa0ba902b7', $s->get('rojo'));
        $this->assertSame('t61rcWkgMzE', $s->get('congo'));
        $this->assertSame(2, $s->count());
    }

    public function test_our_entry_is_prepended_and_foreign_entries_are_preserved(): void
    {
        // Spec: modified/added keys SHOULD move to the beginning; a vendor
        // MUST NOT delete keys it did not generate.
        $s = TraceState::parse('rojo=abc,congo=def')->with('mnl_class', 'scenario');

        $this->assertSame('mnl_class=scenario,rojo=abc,congo=def', $s->toHeader());
        $this->assertSame(['mnl_class', 'rojo', 'congo'], $s->keys());
    }

    public function test_updating_our_own_key_moves_it_to_the_front_without_duplicating(): void
    {
        $s = TraceState::parse('mnl_class=production,rojo=abc')->with('mnl_class', 'scenario');

        $this->assertSame('mnl_class=scenario,rojo=abc', $s->toHeader());
        $this->assertSame(1, count(array_filter($s->keys(), fn ($k) => $k === 'mnl_class')));
    }

    public function test_the_list_is_capped_at_32_members(): void
    {
        $members = [];
        for ($i = 0; $i < 40; $i++) {
            $members[] = "k{$i}=v{$i}";
        }

        $s = TraceState::parse(implode(',', $members));

        $this->assertSame(40, $s->count(), 'parsing preserves what was sent');
        $this->assertSame(32, $s->with('mnl_class', 'scenario')->count(), 'writing enforces the cap');
    }

    public function test_truncation_keeps_our_freshly_written_entry(): void
    {
        // Dropping happens from the END; our own entry sits at the front.
        $members = [];
        for ($i = 0; $i < 40; $i++) {
            $members[] = "k{$i}=v{$i}";
        }

        $s = TraceState::parse(implode(',', $members))->with('mnl_class', 'scenario');

        $this->assertSame('mnl_class', $s->keys()[0]);
        $this->assertSame('scenario', $s->get('mnl_class'));
    }

    public function test_invalid_members_are_discarded_not_fatal(): void
    {
        $s = TraceState::parse('GOOD=x,valid=y,noequalsign,=novalue');

        $this->assertNull($s->get('GOOD'), 'uppercase keys are invalid');
        $this->assertSame('y', $s->get('valid'));
        $this->assertSame(1, $s->count());
    }

    public function test_empty_and_whitespace_members_are_tolerated(): void
    {
        $s = TraceState::parse('a=1, ,  , b=2');

        $this->assertSame(2, $s->count());
    }

    public function test_key_and_value_grammar(): void
    {
        $this->assertTrue(TraceState::isValidKey('mnl_class'));
        $this->assertTrue(TraceState::isValidKey('a-b*c/d'));
        $this->assertTrue(TraceState::isValidKey('tenant@system'));
        $this->assertFalse(TraceState::isValidKey('Upper'));
        $this->assertFalse(TraceState::isValidKey('9leading'));

        $this->assertTrue(TraceState::isValidValue('scenario'));
        $this->assertFalse(TraceState::isValidValue('has,comma'));
        $this->assertFalse(TraceState::isValidValue('has=equals'));
        $this->assertFalse(TraceState::isValidValue(''));
        $this->assertFalse(TraceState::isValidValue(str_repeat('x', 257)));
    }
}
