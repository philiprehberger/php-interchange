<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Tests;

use PhilipRehberger\Interchange\Signing\StandardWebhooksScheme;
use PHPUnit\Framework\TestCase;

class StandardWebhooksSchemeTest extends TestCase
{
    private StandardWebhooksScheme $scheme;

    private string $secret;

    protected function setUp(): void
    {
        $this->scheme = new StandardWebhooksScheme;
        $this->secret = StandardWebhooksScheme::generateSecret();
    }

    public function test_it_emits_the_three_spec_headers(): void
    {
        $h = $this->scheme->sign('msg_1', '{"a":1}', $this->secret);

        $this->assertSame(['webhook-id', 'webhook-timestamp', 'webhook-signature'], array_keys($h));
        $this->assertSame('msg_1', $h['webhook-id']);
        $this->assertStringStartsWith('v1,', $h['webhook-signature']);
    }

    public function test_the_signature_is_base64_not_hex(): void
    {
        // G-0 correction 2: base64 of raw bytes, not hex.
        $sig = substr($this->scheme->sign('msg_1', '{}', $this->secret)['webhook-signature'], 3);

        $this->assertNotSame(1, preg_match('/^[0-9a-f]{64}$/', $sig), 'must not be hex');
        $this->assertSame(32, strlen(base64_decode($sig, true)), 'must decode to 32 raw sha256 bytes');
    }

    public function test_the_hmac_key_is_the_decoded_bytes_after_stripping_whsec(): void
    {
        // G-0 correction 3. Signing with the literal prefixed string produces
        // signatures no compliant verifier accepts — assert we do not do that.
        $secret = 'whsec_'.base64_encode('super-secret-key');
        $payload = '{"a":1}';
        $ts = 1700000000;

        $produced = substr($this->scheme->sign('msg_1', $payload, $secret, $ts)['webhook-signature'], 3);

        $correct = base64_encode(hash_hmac('sha256', "msg_1.{$ts}.{$payload}", 'super-secret-key', true));
        $naive = base64_encode(hash_hmac('sha256', "msg_1.{$ts}.{$payload}", $secret, true));

        $this->assertSame($correct, $produced);
        $this->assertNotSame($naive, $produced);
    }

    public function test_signed_content_has_three_components_not_two(): void
    {
        // G-0 correction 1: Stripe-style is {ts}.{payload}; this spec is
        // {id}.{ts}.{payload}. Two different ids must not collide.
        $a = $this->scheme->sign('msg_a', '{}', $this->secret, 1700000000);
        $b = $this->scheme->sign('msg_b', '{}', $this->secret, 1700000000);

        $this->assertNotSame($a['webhook-signature'], $b['webhook-signature']);
    }

    public function test_round_trip_verification(): void
    {
        $payload = '{"type":"contact.created"}';
        $h = $this->scheme->sign('msg_1', $payload, $this->secret);

        $this->assertTrue($this->scheme->verify($h, $payload, $this->secret));
    }

    public function test_it_rejects_a_tampered_payload(): void
    {
        $h = $this->scheme->sign('msg_1', '{"amount":1}', $this->secret);

        $this->assertFalse($this->scheme->verify($h, '{"amount":9999}', $this->secret));
    }

    public function test_it_rejects_a_wrong_secret_and_a_tampered_id(): void
    {
        $payload = '{}';
        $h = $this->scheme->sign('msg_1', $payload, $this->secret);

        $this->assertFalse($this->scheme->verify($h, $payload, StandardWebhooksScheme::generateSecret()));

        $h['webhook-id'] = 'msg_other';
        $this->assertFalse($this->scheme->verify($h, $payload, $this->secret));
    }

    public function test_it_rejects_an_expired_timestamp_in_either_direction(): void
    {
        $payload = '{}';

        $old = $this->scheme->sign('msg_1', $payload, $this->secret, time() - 3600);
        $this->assertFalse($this->scheme->verify($old, $payload, $this->secret));

        $future = $this->scheme->sign('msg_1', $payload, $this->secret, time() + 3600);
        $this->assertFalse($this->scheme->verify($future, $payload, $this->secret));
    }

    public function test_rotation_is_a_space_delimited_list_in_one_header(): void
    {
        // G-0 correction 4: one header with several signatures, not a second
        // header as the native schemes in this fleet use.
        $payload = '{}';
        $old = StandardWebhooksScheme::generateSecret();
        $new = StandardWebhooksScheme::generateSecret();

        $h = $this->scheme->signWithRotation('msg_1', $payload, [$new, $old]);

        $this->assertCount(2, explode(' ', $h['webhook-signature']));
        $this->assertArrayNotHasKey('webhook-signature-old', $h);

        // A consumer holding either secret accepts the message.
        $this->assertTrue($this->scheme->verify($h, $payload, $new));
        $this->assertTrue($this->scheme->verify($h, $payload, $old));
        $this->assertFalse($this->scheme->verify($h, $payload, StandardWebhooksScheme::generateSecret()));
    }

    public function test_headers_are_matched_case_insensitively(): void
    {
        $payload = '{}';
        $h = $this->scheme->sign('msg_1', $payload, $this->secret);
        $upper = array_change_key_case($h, CASE_UPPER);

        $this->assertTrue($this->scheme->verify($upper, $payload, $this->secret));
    }

    public function test_missing_or_garbage_headers_fail_closed(): void
    {
        $this->assertFalse($this->scheme->verify([], '{}', $this->secret));
        $this->assertFalse($this->scheme->verify([
            'webhook-id' => 'msg_1',
            'webhook-timestamp' => 'not-a-number',
            'webhook-signature' => 'v1,zzzz',
        ], '{}', $this->secret));
    }

    public function test_generated_secrets_carry_the_prefix(): void
    {
        $this->assertStringStartsWith('whsec_', StandardWebhooksScheme::generateSecret());
    }
}
