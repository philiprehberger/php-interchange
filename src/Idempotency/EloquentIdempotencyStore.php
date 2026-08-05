<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Idempotency;

use Illuminate\Support\Facades\DB;

/**
 * Eloquent-backed store. Table shape is deliberately minimal so a service can
 * point it at an existing table rather than being forced into a new one —
 * both Webhook Relay and Switchyard already have their own.
 */
final class EloquentIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private readonly string $table = 'interchange_idempotency',
        private readonly int $windowHours = 24,
    ) {}

    public function begin(string $scope, string $key, string $requestBody): IdempotencyResult
    {
        $fingerprint = $this->fingerprint($requestBody);

        $existing = DB::table($this->table)
            ->where('scope', $scope)
            ->where('key', $key)
            ->first();

        if ($existing === null) {
            DB::table($this->table)->insert([
                'scope' => $scope,
                'key' => $key,
                'fingerprint' => $fingerprint,
                'response_status' => null,
                'response_body' => null,
                'expires_at' => now()->addHours($this->windowHours),
                'created_at' => now(),
            ]);

            return IdempotencyResult::fresh();
        }

        if (now()->greaterThan($existing->expires_at)) {
            DB::table($this->table)->where('scope', $scope)->where('key', $key)->update([
                'fingerprint' => $fingerprint,
                'response_status' => null,
                'response_body' => null,
                'expires_at' => now()->addHours($this->windowHours),
                'created_at' => now(),
            ]);

            return IdempotencyResult::fresh();
        }

        if (! hash_equals((string) $existing->fingerprint, $fingerprint)) {
            return IdempotencyResult::conflict();
        }

        if ($existing->response_status === null) {
            // In flight: the first request has not finished. Treat as a replay
            // of an unfinished operation rather than running it twice.
            return IdempotencyResult::replay(409, '{"title":"Request in progress"}');
        }

        return IdempotencyResult::replay((int) $existing->response_status, (string) $existing->response_body);
    }

    public function complete(string $scope, string $key, int $status, string $responseBody): void
    {
        DB::table($this->table)
            ->where('scope', $scope)
            ->where('key', $key)
            ->update(['response_status' => $status, 'response_body' => $responseBody]);
    }

    public function prune(): int
    {
        return DB::table($this->table)->where('expires_at', '<', now())->delete();
    }

    private function fingerprint(string $body): string
    {
        return hash('sha256', $body);
    }
}
