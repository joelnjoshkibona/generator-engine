<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Plan 032 stops the generator from ever *serving back* a sensitive column;
 * this is the write-side half — classifying HOW each one must be stored so
 * it is never left in the clear.
 *
 * The classification is not "hash everything": NJIWA's Webhooks `secret` is
 * read back in plain text to compute an HMAC signature
 * (WebhookSigningService::sign()) — a one-way hash would break that feature
 * outright, so it needs `encrypted` (Eloquent decrypts transparently on
 * attribute access). Its `ApiKeys.key_hash`/`Devices.token_hash` columns
 * already hold a hash the *application* computed before ever touching the
 * model — casting those would hash the hash, breaking the app's own
 * comparison. `remember_token` is sensitive by name but Laravel's
 * `EloquentUserProvider::retrieveByToken()` compares it with `hash_equals()`
 * directly against the raw cookie value, never `Hash::check()` — casting it
 * `hashed` would break "remember me" for every login.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::sensitiveColumnStorage()
 */
class ModuleConfigContractSensitiveColumnStorageTest extends TestCase
{
    public function test_password_shaped_columns_are_hashed(): void
    {
        foreach (['password', 'pin', 'otp', 'otp_code', 'salt', 'user_pin', 'login_password', 'device_otp'] as $name) {
            $this->assertSame('hashed', ModuleConfigContract::sensitiveColumnStorage([], $name), "expected '{$name}' to be hashed");
        }
    }

    public function test_secret_shaped_columns_are_encrypted(): void
    {
        foreach (['secret', 'token', 'api_key', 'private_key', 'client_secret', 'access_token', 'webhook_secret'] as $name) {
            $this->assertSame('encrypted', ModuleConfigContract::sensitiveColumnStorage([], $name), "expected '{$name}' to be encrypted");
        }
    }

    public function test_already_hashed_by_the_app_columns_stay_plain(): void
    {
        foreach (['key_hash', 'token_hash', 'body_hash'] as $name) {
            $this->assertSame('plain', ModuleConfigContract::sensitiveColumnStorage([], $name), "expected '{$name}' to stay plain");
        }
    }

    public function test_remember_token_always_stays_plain_despite_being_sensitive(): void
    {
        $this->assertSame('plain', ModuleConfigContract::sensitiveColumnStorage([], 'remember_token'));
    }

    public function test_a_non_sensitive_column_is_plain(): void
    {
        $this->assertSame('plain', ModuleConfigContract::sensitiveColumnStorage([], 'name'));
        $this->assertSame('plain', ModuleConfigContract::sensitiveColumnStorage([], 'email'));
    }

    public function test_storage_override_wins_over_the_heuristic_in_both_directions(): void
    {
        $this->assertSame('plain', ModuleConfigContract::sensitiveColumnStorage(
            ['sensitive_columns' => ['storage' => ['pin' => 'plain']]],
            'pin',
        ));

        $this->assertSame('hashed', ModuleConfigContract::sensitiveColumnStorage(
            ['sensitive_columns' => ['storage' => ['webhook_secret' => 'hashed']]],
            'webhook_secret',
        ));
    }

    public function test_an_invalid_storage_override_value_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/sensitive_columns\.storage/');
        $this->expectExceptionMessageMatches('/pin/');

        ModuleConfigContract::sensitiveColumnStorage(
            ['sensitive_columns' => ['storage' => ['pin' => 'rot13']]],
            'pin',
        );
    }
}
