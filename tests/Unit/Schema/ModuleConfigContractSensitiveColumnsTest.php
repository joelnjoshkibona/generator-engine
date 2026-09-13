<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Real cost, read live: NJIWA's Webhooks Model has no $hidden, so its signing
 * `secret` is returned on every list row and is even listed as sortable
 * (WebhooksListService.php:29) — and SYSTEM_SHELL's UsersListService lists
 * `password` as filterable AND sortable, with the list filter's `begins`
 * operator (`LIKE 'value%'`) turning `Users.list` into a prefix oracle
 * against password hashes. Nothing in the generator has ever classified a
 * column as secret; this is that classification, plus a per-module
 * `sensitive_columns` override for the false positives/negatives a
 * name-only heuristic can never fully avoid (e.g. `body_hash`, a content
 * fingerprint, not a secret).
 *
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract
 */
class ModuleConfigContractSensitiveColumnsTest extends TestCase
{
    public function test_heuristic_is_true_for_known_secret_shaped_names(): void
    {
        foreach ([
            'password', 'remember_token', 'key_hash', 'token_hash', 'pin_hash', 'body_hash',
            'secret', 'client_secret', 'secret_key', 'api_key', 'private_key', 'access_token',
            'smtp_password', 'pin', 'user_pin', 'otp', 'otp_code', 'salt', 'Password',
        ] as $name) {
            $this->assertTrue(ModuleConfigContract::isSensitiveColumnName($name), "expected '{$name}' to be sensitive");
        }
    }

    public function test_heuristic_is_false_for_lookalike_names(): void
    {
        foreach ([
            'password_set_at', 'api_key_id', 'token_expires_at', 'secretary_id', 'secretary_name',
            'shipping_address', 'opinion', 'pinned', 'tokens_used', 'name', 'email', 'description',
            'status_id', 'code', 'uuid', 'created_at',
        ] as $name) {
            $this->assertFalse(ModuleConfigContract::isSensitiveColumnName($name), "expected '{$name}' to NOT be sensitive");
        }
    }

    public function test_include_override_makes_an_otherwise_plain_column_sensitive(): void
    {
        $this->assertTrue(ModuleConfigContract::isSensitive(['sensitive_columns' => ['include' => ['url']]], 'url'));
    }

    public function test_exclude_override_makes_a_heuristic_false_positive_not_sensitive(): void
    {
        $this->assertFalse(ModuleConfigContract::isSensitive(['sensitive_columns' => ['exclude' => ['body_hash']]], 'body_hash'));
    }

    public function test_sensitiveColumns_lists_column_order_then_include_only_names(): void
    {
        $config = [
            'columns' => [['name' => 'name'], ['name' => 'secret'], ['name' => 'url']],
            'sensitive_columns' => ['include' => ['legacy_key', 'url']],
        ];

        $this->assertSame(['secret', 'url', 'legacy_key'], ModuleConfigContract::sensitiveColumns($config));
    }

    public function test_sensitiveColumns_is_empty_with_no_columns_and_no_override(): void
    {
        $this->assertSame([], ModuleConfigContract::sensitiveColumns([]));
    }

    public function test_a_malformed_sensitive_columns_override_throws(): void
    {
        try {
            ModuleConfigContract::sensitiveColumns(['sensitive_columns' => 'x']);
            $this->fail('expected an InvalidArgumentException for a non-array sensitive_columns value');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('sensitive_columns', $e->getMessage());
        }

        try {
            ModuleConfigContract::sensitiveColumns(['sensitive_columns' => ['include' => [5]]]);
            $this->fail('expected an InvalidArgumentException for a non-string include entry');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('sensitive_columns', $e->getMessage());
        }
    }
}
