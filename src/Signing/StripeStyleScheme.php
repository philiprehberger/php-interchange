<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Signing;

/**
 * The Stripe-style `t={ts},v1={hex}` scheme, which three services in this fleet
 * already speak under their own header names.
 *
 * Plan item 4.0 established that `philiprehberger/php-webhook-signature`
 * implements exactly this construction — signed content `{timestamp}.{payload}`,
 * hex encoding, raw secret — and therefore could NOT be the base for Standard
 * Webhooks, which differs on all four of content, encoding, secret handling and
 * rotation. It is the right basis for this one.
 *
 * Instances are named after the service whose header they carry, so a verifier
 * can be pointed at `inkwell-v0` or `switchyard-v0` without either service
 * needing to know about the other.
 */
final class StripeStyleScheme implements SignatureScheme
{
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /** @var callable(): int */
    private $clock;

    /**
     * @param  string  $name  scheme name, e.g. `inkwell-v0`
     * @param  string  $header  header this service signs into
     * @param  string|null  $rotationHeader  second header used during rotation,
     *   if the native scheme rotates that way. Standard Webhooks uses one
     *   multi-value header instead; these older schemes predate that.
     * @param  (callable(): int)|null  $clock
     */
    public function __construct(
        private readonly string $name,
        private readonly string $header,
        private readonly ?string $rotationHeader = null,
        private readonly int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public static function inkwell(?callable $clock = null): self
    {
        return new self('inkwell-v0', 'X-Inkwell-Signature', 'X-Inkwell-Signature-Old', clock: $clock);
    }

    public static function switchyard(?callable $clock = null): self
    {
        return new self('switchyard-v0', 'X-Switchyard-Signature', clock: $clock);
    }

    public static function webhookRelay(?callable $clock = null): self
    {
        return new self('webhook-relay-v0', 'X-Webhook-Signature', clock: $clock);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function header(): string
    {
        return $this->header;
    }

    /** @return array<string, string> */
    public function sign(string $messageId, string $payload, string $secret, ?int $timestamp = null): array
    {
        $timestamp ??= ($this->clock)();

        // Note the two-component content: this scheme does NOT include the
        // message id, which is the substantive difference from §4.2.
        return [$this->header => 't='.$timestamp.',v1='.$this->hmac($timestamp, $payload, $secret)];
    }

    /** @param array<int, string> $secrets current first, then outgoing */
    public function signWithRotation(string $messageId, string $payload, array $secrets, ?int $timestamp = null): array
    {
        $timestamp ??= ($this->clock)();
        $headers = $this->sign($messageId, $payload, $secrets[0], $timestamp);

        if ($this->rotationHeader !== null && isset($secrets[1])) {
            $headers[$this->rotationHeader] = 't='.$timestamp.',v1='.$this->hmac($timestamp, $payload, $secrets[1]);
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<int, string>|string  $secrets
     */
    public function verify(array $headers, string $payload, array|string $secrets): bool
    {
        $headers = array_change_key_case($headers);
        $candidates = [];

        foreach ([$this->header, $this->rotationHeader] as $name) {
            if ($name !== null && isset($headers[strtolower($name)])) {
                $candidates[] = (string) $headers[strtolower($name)];
            }
        }

        $secrets = is_string($secrets) ? [$secrets] : $secrets;

        foreach ($candidates as $candidate) {
            if (preg_match('/^t=(\d+),v1=([0-9a-f]+)$/', trim($candidate), $m) !== 1) {
                continue;
            }

            [$timestamp, $provided] = [(int) $m[1], $m[2]];

            if (abs(($this->clock)() - $timestamp) > $this->toleranceSeconds) {
                continue;
            }

            foreach ($secrets as $secret) {
                if (hash_equals($this->hmac($timestamp, $payload, $secret), $provided)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hmac(int $timestamp, string $payload, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}
