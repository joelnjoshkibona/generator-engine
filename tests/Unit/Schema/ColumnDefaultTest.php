<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\ColumnDefault;
use PHPUnit\Framework\TestCase;

/**
 * MariaDB reports a NULL default as the string 'NULL' and a string default with its quotes. Copied verbatim they
 * became `->nullable()->default('NULL')` (MySQL 1067 on a fresh database) and a factory value of `'\'UNPAID\''`.
 */
class ColumnDefaultTest extends TestCase
{
    public function test_the_string_null_is_a_real_null(): void
    {
        foreach (['NULL', 'null', 'Null', ' NULL '] as $raw) {
            $this->assertNull(ColumnDefault::normalize($raw), var_export($raw, true));
        }
    }

    public function test_a_quoted_string_loses_its_quotes(): void
    {
        $this->assertSame('UNPAID', ColumnDefault::normalize("'UNPAID'"));
        $this->assertSame('', ColumnDefault::normalize("''"), 'an empty quoted string is an empty default');
        $this->assertSame("it's", ColumnDefault::normalize("'it''s'"), 'a doubled quote is SQL for one quote');
        $this->assertSame('a b', ColumnDefault::normalize("  'a b'  "));
    }

    public function test_everything_else_is_left_alone(): void
    {
        foreach (['0', '1', '12.50', 'CURRENT_TIMESTAMP', 'current_timestamp()', 'UNPAID', '', "'"] as $raw) {
            $this->assertSame($raw, ColumnDefault::normalize($raw), var_export($raw, true));
        }
        $this->assertNull(ColumnDefault::normalize(null));
        $this->assertTrue(ColumnDefault::normalize(true));
        $this->assertSame(5, ColumnDefault::normalize(5));
    }

    public function test_a_normalised_default_is_stable_when_normalised_again(): void
    {
        foreach ([null, 'UNPAID', '1', 'CURRENT_TIMESTAMP', ''] as $clean) {
            $this->assertSame($clean, ColumnDefault::normalize(ColumnDefault::normalize($clean)));
        }
        $once = ColumnDefault::normalize("'UNPAID'");
        $this->assertSame($once, ColumnDefault::normalize($once));
    }

    public function test_a_config_is_normalised_for_columns_and_frontend_fields_and_nothing_else(): void
    {
        $config = [
            'module_name' => 'Invoices',
            'columns' => [
                ['name' => 'paid_at', 'type' => 'datetime', 'default' => 'NULL'],
                ['name' => 'status', 'type' => 'string', 'default' => "'UNPAID'"],
                ['name' => 'note', 'type' => 'string'],
            ],
            'features' => ['frontend' => ['create' => ['fields' => [['name' => 'status', 'default' => "'UNPAID'"]]]]],
            'constants' => ['LABEL' => "'kept as written'"],
        ];

        $out = ColumnDefault::normalizeConfig($config);

        $this->assertNull($out['columns'][0]['default']);
        $this->assertSame('UNPAID', $out['columns'][1]['default']);
        $this->assertArrayNotHasKey('default', $out['columns'][2], 'a column with no default key gains none');
        $this->assertSame('UNPAID', $out['features']['frontend']['create']['fields'][0]['default']);
        $this->assertSame("'kept as written'", $out['constants']['LABEL'], 'only defaults are touched');
    }
}
