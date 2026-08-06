<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhilipRehberger\Interchange\Idempotency\EloquentIdempotencyStore;

/**
 * SPEC §6. The convergence is a BEHAVIOUR CHANGE, not a refactor: Switchyard
 * has no fingerprint column today and so no conflict detection. Adopting this
 * gives it a 409 path it does not currently have.
 */
class IdempotencyStoreTest extends TestCase
{
    use RefreshDatabase;

    private EloquentIdempotencyStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new EloquentIdempotencyStore;
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('interchange_idempotency', function (Blueprint $table) {
            $table->id();
            $table->string('scope');
            $table->string('key');
            $table->string('fingerprint', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
            $table->unique(['scope', 'key']);
        });
    }

    public function test_a_first_request_is_fresh(): void
    {
        $this->assertTrue($this->store->begin('ws_1', 'key_1', '{"a":1}')->isFresh());
    }

    public function test_same_key_same_body_replays_the_recorded_response(): void
    {
        $this->store->begin('ws_1', 'key_1', '{"a":1}');
        $this->store->complete('ws_1', 'key_1', 201, '{"id":"created"}');

        $result = $this->store->begin('ws_1', 'key_1', '{"a":1}');

        $this->assertTrue($result->isReplay());
        $this->assertSame(201, $result->status);
        $this->assertSame('{"id":"created"}', $result->body);
    }

    public function test_same_key_different_body_is_a_conflict(): void
    {
        // This is the behaviour Switchyard gains. Silently serving the first
        // response would hide a client bug; performing the second would break
        // the guarantee the key exists to provide.
        $this->store->begin('ws_1', 'key_1', '{"a":1}');
        $this->store->complete('ws_1', 'key_1', 201, '{}');

        $this->assertTrue($this->store->begin('ws_1', 'key_1', '{"a":999}')->isConflict());
    }

    public function test_keys_are_scoped_per_tenant(): void
    {
        // The same key in two workspaces is two different requests.
        $this->store->begin('ws_1', 'shared-key', '{"a":1}');

        $this->assertTrue($this->store->begin('ws_2', 'shared-key', '{"a":2}')->isFresh());
    }

    public function test_an_unfinished_request_does_not_replay_a_null_response(): void
    {
        // First request still in flight: returning a null-bodied 200 would be
        // worse than saying so.
        $this->store->begin('ws_1', 'key_1', '{"a":1}');

        $result = $this->store->begin('ws_1', 'key_1', '{"a":1}');

        $this->assertTrue($result->isReplay());
        $this->assertSame(409, $result->status);
    }

    public function test_an_expired_record_is_treated_as_fresh(): void
    {
        $this->store->begin('ws_1', 'key_1', '{"a":1}');
        $this->store->complete('ws_1', 'key_1', 201, '{}');

        DB::table('interchange_idempotency')
            ->where('key', 'key_1')
            ->update(['expires_at' => now()->subDay()]);

        $this->assertTrue($this->store->begin('ws_1', 'key_1', '{"a":1}')->isFresh());
    }

    public function test_prune_removes_only_expired_records(): void
    {
        $this->store->begin('ws_1', 'live', '{}');
        $this->store->begin('ws_1', 'stale', '{}');

        DB::table('interchange_idempotency')
            ->where('key', 'stale')
            ->update(['expires_at' => now()->subDay()]);

        $this->assertSame(1, $this->store->prune());
        $this->assertSame(1, DB::table('interchange_idempotency')->count());
    }
}
