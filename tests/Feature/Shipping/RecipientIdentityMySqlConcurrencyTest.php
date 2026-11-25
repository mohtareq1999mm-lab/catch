<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\NotificationIdentityConflict;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientFingerprint;
use App\Services\Notification\RecipientIdentityService;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Real-MySQL two-process race probe. Each `test_op_*` performs ONE racy
 * operation and exits; an external driver launches the two ops in parallel
 * against one shared seed (NO RefreshDatabase). Skipped unless
 * DB_CONNECTION=mysql. `test_verify_*` asserts convergence; seed/cleanup are
 * explicit, following tests/Feature/CouponDistribution/MySqlConcurrencyTest.php.
 */
class RecipientIdentityMySqlConcurrencyTest extends TestCase
{
    public const PROBE_VERSION = 'conc-recipient-v1';
    public const PROBE_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public const PROBE_CHANNEL = 'recipient-conc';
    public const FIRST_EMAIL = 'recipient-conc-first@test.local';
    public const SECOND_EMAIL = 'recipient-conc-second@test.local';

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency probe.');
        }
    }

    public function test_seed_shared_fixture(): void
    {
        $this->cleanup();

        $first = $this->seedUser(self::FIRST_EMAIL);
        $second = $this->seedUser(self::SECOND_EMAIL);

        echo "SEED first={$first->id} second={$second->id}" . PHP_EOL;
        $this->assertTrue(true);
    }

    public function test_op_resolve_as_first_user(): void
    {
        $this->runRaceOperation(self::FIRST_EMAIL, 'first');
    }

    public function test_op_resolve_as_second_user(): void
    {
        $this->runRaceOperation(self::SECOND_EMAIL, 'second');
    }

    public function test_verify_single_recipient_and_one_conflict(): void
    {
        $aliases = NotificationRecipientFingerprint::query()
            ->where('key_version', self::PROBE_VERSION)
            ->where('fingerprint_hash', self::PROBE_HASH)
            ->get();
        $recipientIds = $aliases->pluck('recipient_id')->unique()->values();
        $recipients = NotificationRecipient::query()->whereIn('id', $recipientIds)->count();
        $conflicts = NotificationIdentityConflict::query()->where('channel', self::PROBE_CHANNEL)->get();

        echo "ALIASES={$aliases->count()} RECIPIENTS={$recipients} CONFLICTS={$conflicts->count()}" . PHP_EOL;

        $this->assertSame(1, $aliases->count());
        $this->assertSame(1, $recipients);
        $this->assertSame(1, $conflicts->count());
        $this->assertSame('recipient_ownership_mismatch', $conflicts->first()->metadata['reason'] ?? null);
        $this->assertDatabaseMissing('notification_recipient_fingerprints', [
            'key_version' => self::PROBE_VERSION,
            'fingerprint_hash' => str_repeat('b', 64),
        ]);
    }

    public function test_cleanup_concurrency_fixture(): void
    {
        $this->cleanup();
        echo 'CLEANED' . PHP_EOL;
        $this->assertTrue(true);
    }

    private function runRaceOperation(string $email, string $label): void
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $result = app(RecipientIdentityService::class)->resolveRecipient([
            ['key_version' => self::PROBE_VERSION, 'fingerprint_hash' => self::PROBE_HASH],
        ], 'user', ['user' => $user, 'channel' => self::PROBE_CHANNEL]);

        echo 'OP_' . strtoupper($label) . '=' . $result['status'] . PHP_EOL;
        $this->assertContains($result['status'], ['resolved', 'ambiguous']);
    }

    private function seedUser(string $email): User
    {
        return User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => 'Recipient Concurrency', 'password' => bcrypt('password'), 'type' => 'user', 'is_active' => true],
        );
    }

    private function cleanup(): void
    {
        $conflictIds = NotificationIdentityConflict::query()
            ->where('channel', self::PROBE_CHANNEL)
            ->pluck('id');
        DB::table('activity_log')
            ->where('subject_type', NotificationIdentityConflict::class)
            ->whereIn('subject_id', $conflictIds)
            ->delete();
        NotificationIdentityConflict::query()->where('channel', self::PROBE_CHANNEL)->delete();

        $recipientIds = NotificationRecipientFingerprint::query()
            ->where('key_version', self::PROBE_VERSION)
            ->pluck('recipient_id');
        NotificationRecipientFingerprint::query()->where('key_version', self::PROBE_VERSION)->delete();
        NotificationRecipient::query()->whereIn('id', $recipientIds)->delete();
        User::query()->whereIn('email', [self::FIRST_EMAIL, self::SECOND_EMAIL])->delete();
    }
}
