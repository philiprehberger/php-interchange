<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Tracing;

/**
 * W3C `tracestate` list, with the spec's mutation rules enforced.
 *
 * Verified against the specification 2026-08-05:
 *
 *   list        = list-member 0*31( OWS "," OWS list-member )  → max 32 members
 *   key         = lcalpha *( lcalpha / DIGIT / "_" / "-" / "*" / "/" )
 *   value       = up to 256 printable ASCII (0x20–0x7E) except "," and "="
 *
 *   - Vendors SHOULD propagate AT LEAST 512 characters. This is a minimum
 *     propagation guarantee, NOT a cap. An earlier draft of our contract had
 *     this backwards.
 *   - A modified or newly added entry SHOULD be moved to the BEGINNING.
 *   - A vendor MUST NOT delete keys it did not generate.
 *   - If truncation is unavoidable, drop members >128 chars first, from the END.
 *
 * @see https://www.w3.org/TR/trace-context/
 */
final class TraceState
{
    public const MAX_MEMBERS = 32;

    public const LARGE_MEMBER_CHARS = 128;

    /** @param array<int, array{0: string, 1: string}> $members ordered key/value pairs */
    private function __construct(private readonly array $members = []) {}

    public static function empty(): self
    {
        return new self;
    }

    public static function parse(?string $header): self
    {
        if ($header === null || trim($header) === '') {
            return new self;
        }

        $members = [];

        foreach (explode(',', $header) as $raw) {
            $raw = trim($raw);

            // "Empty and whitespace-only list members are allowed" — skip them.
            if ($raw === '') {
                continue;
            }

            $eq = strpos($raw, '=');

            if ($eq === false) {
                continue;
            }

            $key = substr($raw, 0, $eq);
            $value = substr($raw, $eq + 1);

            if (! self::isValidKey($key) || ! self::isValidValue($value)) {
                continue;
            }

            $members[] = [$key, $value];
        }

        return new self($members);
    }

    /**
     * Set our own key. Per spec the modified entry moves to the beginning, and
     * every foreign member is preserved in its existing relative order.
     */
    public function with(string $key, string $value): self
    {
        if (! self::isValidKey($key) || ! self::isValidValue($value)) {
            return $this;
        }

        $kept = array_values(array_filter($this->members, fn (array $m) => $m[0] !== $key));

        return new self(self::enforceLimit([[$key, $value], ...$kept]));
    }

    public function get(string $key): ?string
    {
        foreach ($this->members as [$k, $v]) {
            if ($k === $key) {
                return $v;
            }
        }

        return null;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function count(): int
    {
        return count($this->members);
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_map(fn (array $m) => $m[0], $this->members);
    }

    public function toHeader(): string
    {
        return implode(',', array_map(fn (array $m) => $m[0].'='.$m[1], $this->members));
    }

    /**
     * Trim to 32 members. Oversized members go first, then from the end —
     * never from the front, where our own freshly written entry lives.
     *
     * @param  array<int, array{0: string, 1: string}>  $members
     * @return array<int, array{0: string, 1: string}>
     */
    private static function enforceLimit(array $members): array
    {
        if (count($members) <= self::MAX_MEMBERS) {
            return $members;
        }

        // Drop large members from the end first.
        for ($i = count($members) - 1; $i > 0 && count($members) > self::MAX_MEMBERS; $i--) {
            if (strlen($members[$i][0].'='.$members[$i][1]) > self::LARGE_MEMBER_CHARS) {
                unset($members[$i]);
                $members = array_values($members);
            }
        }

        // Still over: drop from the end.
        return array_slice($members, 0, self::MAX_MEMBERS);
    }

    public static function isValidKey(string $key): bool
    {
        // simple-key; multi-tenant keys (tenant@system) are also permitted.
        return preg_match('#^[a-z][a-z0-9_\-*/]{0,255}$#', $key) === 1
            || preg_match('#^[a-z0-9][a-z0-9_\-*/]{0,240}@[a-z][a-z0-9_\-*/]{0,13}$#', $key) === 1;
    }

    public static function isValidValue(string $value): bool
    {
        if ($value === '' || strlen($value) > 256) {
            return false;
        }

        // Printable ASCII 0x20–0x7E excluding comma and equals; no trailing space.
        return preg_match('/^[\x20-\x2B\x2D-\x3C\x3E-\x7E]*[\x21-\x2B\x2D-\x3C\x3E-\x7E]$/', $value) === 1;
    }
}
