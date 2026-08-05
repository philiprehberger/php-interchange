<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Signing;

/**
 * Standard Webhooks signing and verification.
 *
 * Verified against the published specification 2026-08-05. Four details here
 * were wrong in the first draft of our contract and are worth stating plainly,
 * because each produces signatures a compliant verifier rejects:
 *
 *   1. Signed content is THREE components: "{id}.{timestamp}.{payload}".
 *      Stripe-style schemes use two ("{timestamp}.{payload}").
 *   2. The signature is BASE64 of the raw HMAC bytes, not hex.
 *   3. The secret is base64-encoded with a `whsec_` prefix. The HMAC key is the
 *      DECODED BYTES after stripping the prefix — not the literal string.
 *   4. Rotation is a SPACE-DELIMITED LIST in the one `webhook-signature`
 *      header, not a second header.
 *
 * @see https://github.com/standard-webhooks/standard-webhooks
 */
final class StandardWebhooksScheme implements SignatureScheme
{
    public const SECRET_PREFIX = 'whsec_';

    public const VERSION = 'v1';

    /** The spec recommends a tolerance without fixing one; 5 minutes is our default. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /** @var callable(): int */
    private $clock;

    /**
     * @param  (callable(): int)|null  $clock  injectable so a consumer can test
     *   verification under a frozen clock. Found during the first real
     *   adoption: a caller that signs at a frozen timestamp cannot verify its
     *   own output if this class reads the wall clock directly.
     */
    public function __construct(
        private readonly int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    public function name(): string
    {
        return 'standard-webhooks';
    }

    /**
     * @param  string  $messageId  stable across retries of the same message
     * @return array<string, string> the three headers
     */
    public function sign(string $messageId, string $payload, string $secret, ?int $timestamp = null): array
    {
        $timestamp ??= $this->now();

        return [
            'webhook-id' => $messageId,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => self::VERSION.','.$this->signature($messageId, $timestamp, $payload, $secret),
        ];
    }

    /**
     * Sign with several secrets at once for zero-downtime rotation. The spec
     * represents this as a space-delimited list inside the single header.
     *
     * @param  array<int, string>  $secrets  current first, then outgoing
     * @return array<string, string>
     */
    public function signWithRotation(string $messageId, string $payload, array $secrets, ?int $timestamp = null): array
    {
        $timestamp ??= $this->now();

        $signatures = array_map(
            fn (string $secret) => self::VERSION.','.$this->signature($messageId, $timestamp, $payload, $secret),
            $secrets,
        );

        return [
            'webhook-id' => $messageId,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => implode(' ', $signatures),
        ];
    }

    /**
     * @param  array<string, string>  $headers  case-insensitive
     * @param  array<int, string>|string  $secrets  one or more acceptable secrets
     */
    public function verify(array $headers, string $payload, array|string $secrets): bool
    {
        $headers = array_change_key_case($headers);

        $messageId = $headers['webhook-id'] ?? null;
        $timestamp = $headers['webhook-timestamp'] ?? null;
        $header = $headers['webhook-signature'] ?? null;

        if (! is_string($messageId) || ! is_string($timestamp) || ! is_string($header)) {
            return false;
        }

        if (! ctype_digit(ltrim($timestamp, '-')) || ! $this->withinTolerance((int) $timestamp)) {
            return false;
        }

        $secrets = is_string($secrets) ? [$secrets] : $secrets;

        // Any signature in the space-delimited list matching any acceptable
        // secret is a pass — that is what makes rotation zero-downtime.
        foreach (preg_split('/\s+/', trim($header)) ?: [] as $candidate) {
            if (! str_starts_with($candidate, self::VERSION.',')) {
                continue;
            }

            $provided = substr($candidate, strlen(self::VERSION) + 1);

            foreach ($secrets as $secret) {
                $expected = $this->signature($messageId, (int) $timestamp, $payload, $secret);

                if (hash_equals($expected, $provided)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function withinTolerance(int $timestamp): bool
    {
        return abs($this->now() - $timestamp) <= $this->toleranceSeconds;
    }

    private function signature(string $messageId, int $timestamp, string $payload, string $secret): string
    {
        return base64_encode(
            hash_hmac('sha256', "{$messageId}.{$timestamp}.{$payload}", self::key($secret), binary: true)
        );
    }

    /**
     * The HMAC key: base64-decoded bytes after stripping `whsec_`.
     *
     * A secret without the prefix is used as raw bytes rather than silently
     * decoded — decoding an arbitrary string as base64 succeeds far too often
     * and would produce a wrong key with no error.
     */
    public static function key(string $secret): string
    {
        if (! str_starts_with($secret, self::SECRET_PREFIX)) {
            return $secret;
        }

        $decoded = base64_decode(substr($secret, strlen(self::SECRET_PREFIX)), strict: true);

        return $decoded === false ? $secret : $decoded;
    }

    /** Generate a correctly formatted secret. */
    public static function generateSecret(int $bytes = 32): string
    {
        return self::SECRET_PREFIX.base64_encode(random_bytes($bytes));
    }
}
