<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\MorphAliasValidator;
use PHPUnit\Framework\TestCase;

/**
 * Relation::morphMap() is a single Laravel-global keyed registry, not scoped
 * per table or module. Two different models registered under the same alias
 * is a real bug -- whichever generates last silently wins, with no error at
 * generation time and no error at runtime until a *_type value resolves to
 * the wrong class. This is the pure decision logic both real callers
 * (make:modules-from-db's full-blueprint pass, make:module's on-disk-sibling
 * pass) hard-fail generation on before writing anything.
 */
class MorphAliasValidatorTest extends TestCase
{
    public function test_no_declarations_produces_no_conflicts(): void
    {
        $this->assertSame([], MorphAliasValidator::findConflicts([]));
    }

    public function test_distinct_aliases_never_conflict(): void
    {
        $conflicts = MorphAliasValidator::findConflicts([
            ['alias' => 'supplier', 'model' => 'App\\Models\\SuppliersModel', 'source' => 'Payments.payable'],
            ['alias' => 'customer', 'model' => 'App\\Models\\CustomersModel', 'source' => 'Payments.payable'],
        ]);

        $this->assertSame([], $conflicts);
    }

    public function test_same_alias_same_model_is_a_harmless_duplicate(): void
    {
        $conflicts = MorphAliasValidator::findConflicts([
            ['alias' => 'supplier', 'model' => 'App\\Models\\SuppliersModel', 'source' => 'Payments.payable'],
            ['alias' => 'supplier', 'model' => 'App\\Models\\SuppliersModel', 'source' => 'Invoices.billable'],
        ]);

        $this->assertSame([], $conflicts);
    }

    public function test_leading_backslash_does_not_defeat_the_same_model_check(): void
    {
        // A declaration authored with a leading backslash and one without
        // must still be recognized as the same model.
        $conflicts = MorphAliasValidator::findConflicts([
            ['alias' => 'supplier', 'model' => 'App\\Models\\SuppliersModel', 'source' => 'Payments.payable'],
            ['alias' => 'supplier', 'model' => '\\App\\Models\\SuppliersModel', 'source' => 'Invoices.billable'],
        ]);

        $this->assertSame([], $conflicts);
    }

    public function test_same_alias_different_model_is_a_real_conflict(): void
    {
        $conflicts = MorphAliasValidator::findConflicts([
            ['alias' => 'supplier', 'model' => 'App\\Models\\SuppliersModel', 'source' => 'Payments.payable'],
            ['alias' => 'supplier', 'model' => 'App\\Models\\ContractorsModel', 'source' => 'Invoices.billable'],
        ]);

        $this->assertCount(1, $conflicts);
        $this->assertStringContainsString("Morph alias 'supplier'", $conflicts[0]);
        $this->assertStringContainsString('SuppliersModel', $conflicts[0]);
        $this->assertStringContainsString('ContractorsModel', $conflicts[0]);
        $this->assertStringContainsString('Payments.payable', $conflicts[0]);
        $this->assertStringContainsString('Invoices.billable', $conflicts[0]);
    }

    public function test_third_conflicting_declaration_is_compared_against_the_first_seen_model(): void
    {
        $conflicts = MorphAliasValidator::findConflicts([
            ['alias' => 'x', 'model' => 'App\\Models\\A', 'source' => 'One'],
            ['alias' => 'x', 'model' => 'App\\Models\\A', 'source' => 'Two'], // duplicate, fine
            ['alias' => 'x', 'model' => 'App\\Models\\B', 'source' => 'Three'], // conflict
        ]);

        $this->assertCount(1, $conflicts);
        $this->assertStringContainsString('Three', $conflicts[0]);
    }

    public function test_declarations_missing_alias_or_model_are_ignored_not_flagged(): void
    {
        $conflicts = MorphAliasValidator::findConflicts([
            ['alias' => '', 'model' => 'App\\Models\\A', 'source' => 'One'],
            ['alias' => 'x', 'model' => '', 'source' => 'Two'],
        ]);

        $this->assertSame([], $conflicts);
    }
}
