<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators;

use Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator;
use PHPUnit\Framework\TestCase;

/**
 * An `in:` rule names a column's entire valid domain, so no value a generator invents from the
 * column's TYPE can be right. Neither test generator honoured it before 2026-08-25.
 *
 * Confirmed live on a rental-CRM domain: `billing_cycle_months` is `required|integer|in:1,2,3,6,12`
 * while the numeric branch fills `(1000000 + (stamp % 900000))` — a 7-digit value that fits the
 * column perfectly and is rejected outright by the very rules the generator itself wrote. Every
 * Contracts create 422'd with "The selected billing cycle months is invalid", and the spec failed
 * on a later step it had never reached. Enum columns were already covered through `enum_values`;
 * this is the validation-layer equivalent for a plain column.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator::allowedValuesFromRules()
 */
class AllowedValuesFromRulesTest extends TestCase
{
    public function test_extracts_values_in_declared_order(): void
    {
        $this->assertSame(
            ['1', '2', '3', '6', '12'],
            PhpUnitTestGenerator::allowedValuesFromRules('required|integer|in:1,2,3,6,12')
        );
    }

    public function test_returns_empty_when_there_is_no_in_rule(): void
    {
        $this->assertSame([], PhpUnitTestGenerator::allowedValuesFromRules('required|integer|min:0'));
        $this->assertSame([], PhpUnitTestGenerator::allowedValuesFromRules(''));
    }

    public function test_trims_surrounding_whitespace_on_each_member(): void
    {
        $this->assertSame(
            ['draft', 'active'],
            PhpUnitTestGenerator::allowedValuesFromRules('required|in: draft , active ')
        );
    }

    /**
     * A `regex:` pattern can legitimately contain the substring "in:", and `not_in:` is a different
     * rule with the opposite meaning. Matching only a segment that STARTS with `in:` keeps both out.
     */
    public function test_does_not_match_in_inside_another_rules_argument(): void
    {
        $this->assertSame(
            [],
            PhpUnitTestGenerator::allowedValuesFromRules('nullable|string|regex:/^(in:foo)$/')
        );
        $this->assertSame(
            [],
            PhpUnitTestGenerator::allowedValuesFromRules('required|not_in:a,b')
        );
    }

    public function test_an_empty_in_rule_is_ignored_rather_than_returning_a_blank_value(): void
    {
        $this->assertSame([], PhpUnitTestGenerator::allowedValuesFromRules('required|in:'));
        $this->assertSame([], PhpUnitTestGenerator::allowedValuesFromRules('required|in: , '));
    }

    public function test_single_member_domain_is_returned_as_is(): void
    {
        $this->assertSame(['ACTIVE'], PhpUnitTestGenerator::allowedValuesFromRules('required|in:ACTIVE'));
    }
}
