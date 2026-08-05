<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Tests;

use PhilipRehberger\Interchange\Errors\ProblemDetails;
use PhilipRehberger\Interchange\Events\EventEnvelope;
use PhilipRehberger\Interchange\Signing\NullScheme;
use PhilipRehberger\Interchange\Signing\StripeStyleScheme;
use PHPUnit\Framework\TestCase;

class V02ComponentsTest extends TestCase
{
    // ------------------------------------------------------------ signing

    public function test_the_stripe_style_scheme_signs_two_components_not_three(): void
    {
        // The substantive difference from Standard Webhooks: no message id in
        // the signed content, so two different ids produce the SAME signature.
        $scheme = StripeStyleScheme::inkwell(clock: fn () => 1700000000);

        $a = $scheme->sign('msg_a', '{}', 'secret');
        $b = $scheme->sign('msg_b', '{}', 'secret');

        $this->assertSame($a, $b);
    }

    public function test_it_produces_the_native_header_shape(): void
    {
        $scheme = StripeStyleScheme::inkwell(clock: fn () => 1700000000);
        $headers = $scheme->sign('msg_1', '{"a":1}', 'secret');

        $this->assertArrayHasKey('X-Inkwell-Signature', $headers);
        $this->assertMatchesRegularExpression('/^t=1700000000,v1=[0-9a-f]{64}$/', $headers['X-Inkwell-Signature']);
    }

    public function test_it_matches_a_hand_computed_hmac(): void
    {
        // Pins the construction so a refactor cannot silently change it for
        // the three services that already speak this format.
        $scheme = StripeStyleScheme::inkwell(clock: fn () => 1700000000);
        $expected = hash_hmac('sha256', '1700000000.{"a":1}', 'secret');

        $this->assertSame("t=1700000000,v1={$expected}", $scheme->sign('m', '{"a":1}', 'secret')['X-Inkwell-Signature']);
    }

    public function test_round_trip_and_tamper_rejection(): void
    {
        $scheme = StripeStyleScheme::inkwell(clock: fn () => 1700000000);
        $headers = $scheme->sign('m', '{"a":1}', 'secret');

        $this->assertTrue($scheme->verify($headers, '{"a":1}', 'secret'));
        $this->assertFalse($scheme->verify($headers, '{"a":2}', 'secret'));
        $this->assertFalse($scheme->verify($headers, '{"a":1}', 'wrong'));
    }

    public function test_expired_signatures_are_rejected(): void
    {
        $signer = StripeStyleScheme::inkwell(clock: fn () => 1700000000);
        $headers = $signer->sign('m', '{}', 'secret');

        $later = StripeStyleScheme::inkwell(clock: fn () => 1700000000 + 3600);
        $this->assertFalse($later->verify($headers, '{}', 'secret'));
    }

    public function test_rotation_uses_a_second_header_unlike_standard_webhooks(): void
    {
        // These older schemes predate the space-delimited-list convention.
        $scheme = StripeStyleScheme::inkwell(clock: fn () => 1700000000);
        $headers = $scheme->signWithRotation('m', '{}', ['new-secret', 'old-secret']);

        $this->assertArrayHasKey('X-Inkwell-Signature', $headers);
        $this->assertArrayHasKey('X-Inkwell-Signature-Old', $headers);
        $this->assertTrue($scheme->verify($headers, '{}', 'new-secret'));
        $this->assertTrue($scheme->verify($headers, '{}', 'old-secret'));
    }

    public function test_a_scheme_without_a_rotation_header_omits_it(): void
    {
        $scheme = StripeStyleScheme::switchyard(clock: fn () => 1700000000);
        $headers = $scheme->signWithRotation('m', '{}', ['new', 'old']);

        $this->assertSame(['X-Switchyard-Signature'], array_keys($headers));
    }

    public function test_the_null_scheme_never_authenticates(): void
    {
        // "No signature required" must not become "any signature accepted".
        $scheme = new NullScheme;

        $this->assertSame([], $scheme->sign('m', '{}', 'x'));
        $this->assertFalse($scheme->verify(['anything' => 'goes'], '{}', 'x'));
    }

    // ------------------------------------------------------------ envelope

    public function test_the_envelope_allows_a_service_to_keep_its_field_names(): void
    {
        // Webhook Relay's shipped API takes {type, payload}; renaming it would
        // break four generated SDKs.
        $envelope = new EventEnvelope('evt_1', 'lead.captured', '2026-08-05T12:00:00Z', ['email' => 'a@b.test'], 'corr_1');

        $native = $envelope->toArray();
        $this->assertArrayHasKey('data', $native);

        $mapped = $envelope->toArray(['data' => 'payload']);
        $this->assertArrayHasKey('payload', $mapped);
        $this->assertArrayNotHasKey('data', $mapped);
        $this->assertSame('corr_1', $mapped['correlation_id']);
    }

    public function test_causation_names_the_immediate_predecessor(): void
    {
        $original = new EventEnvelope('evt_1', 'delivery.failed', '2026-08-05T12:00:00Z', [], 'corr_1');
        $replay = $original->causing('evt_2', 'delivery.replayed', []);

        $this->assertSame('evt_1', $replay->causationId, 'caused by the immediate predecessor');
        $this->assertSame('corr_1', $replay->correlationId, 'correlation follows the whole workflow');

        $third = $replay->causing('evt_3', 'delivery.succeeded', []);
        $this->assertSame('evt_2', $third->causationId, 'a chain, not a flat list pointing at the root');
    }

    // ------------------------------------------------------------- problem

    public function test_problem_details_serialise_per_rfc_9457(): void
    {
        $problem = new ProblemDetails(422, 'Invalid request', 'The body failed validation.', extensions: ['errors' => ['a' => ['required']]]);
        $out = $problem->toArray();

        $this->assertSame('about:blank', $out['type']);
        $this->assertSame(422, $out['status']);
        $this->assertSame('Invalid request', $out['title']);
        $this->assertSame(['a' => ['required']], $out['errors']);
    }

    public function test_problem_detection_accepts_shape_or_content_type(): void
    {
        $this->assertTrue(ProblemDetails::looksLikeProblem(['title' => 'x', 'status' => 400]));
        $this->assertTrue(ProblemDetails::looksLikeProblem(null, 'application/problem+json'));
        $this->assertFalse(ProblemDetails::looksLikeProblem(['message' => 'nope'], 'application/json'));
    }
}
