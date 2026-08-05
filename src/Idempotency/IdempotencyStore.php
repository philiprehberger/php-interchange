<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Idempotency;

/**
 * SPEC §6 — idempotency semantics.
 *
 * THIS IS A BEHAVIOUR CHANGE, DECIDED DELIBERATELY. Measured 2026-08-05 across
 * the fleet:
 *
 *   Webhook Relay  24h window, stores a request fingerprint, returns 409 on
 *                  same-key/different-body
 *   Switchyard     24h window, NO fingerprint column, no conflict detection
 *
 * The windows already agree, so only conflict semantics differ. This contract
 * adopts Webhook Relay's, which means **Switchyard gains a 409 path it does not
 * have today**. That belongs in its migration note and its API docs. Calling
 * this a refactor would be wrong.
 */
interface IdempotencyStore
{
    /**
     * @return IdempotencyResult one of: fresh (proceed), replay (return the
     *   cached response), or conflict (409 — same key, different body)
     */
    public function begin(string $scope, string $key, string $requestBody): IdempotencyResult;

    /** Record the response for a key so a replay can return it. */
    public function complete(string $scope, string $key, int $status, string $responseBody): void;

    /** Drop records past the retention window. */
    public function prune(): int;
}
