<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * A `json` column was validated only as `array` -- any nested content saved
 * as-is, with no way to declare its shape declaratively. NJIWA's
 * Policies.params (pacing caps, send windows, warm-up, balance check) needed
 * hand-added nested rules in its generated Create/Edit services, which the
 * next `--force` regenerate overwrote every time.
 *
 * `json_rules` is the durable, declarative fix: a per-column map of relative
 * dot-paths to Laravel validation rules, plus a `sample` value the generated
 * PHPUnit tests submit (Laravel's own `excludeUnvalidatedArrayKeys` prunes
 * any undeclared nested key from `validated()`, so a real payload with keys
 * outside `json_rules` silently loses them on save -- `sample` must be the
 * complete accepted shape). Every malformed declaration throws here, at
 * generation time, rather than producing a working-but-wrong emission.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::jsonRules()
 */
class ModuleConfigContractJsonRulesTest extends TestCase
{
    private function baseConfig(array $jsonRules = null): array
    {
        $config = [
            'columns' => [
                ['name' => 'params', 'type' => 'json'],
                ['name' => 'name', 'type' => 'string'],
            ],
        ];
        if ($jsonRules !== null) {
            $config['json_rules'] = $jsonRules;
        }

        return $config;
    }

    public function test_key_absent_returns_empty_array(): void
    {
        $this->assertSame([], ModuleConfigContract::jsonRules(['columns' => []]));
    }

    public function test_pipe_string_is_split_and_trimmed_and_a_list_rule_is_kept_verbatim(): void
    {
        $result = ModuleConfigContract::jsonRules($this->baseConfig([
            'params' => [
                'rules' => [
                    'max_per_hour' => 'required| integer',
                    'label' => ['regex:/^(a|b)$/'],
                ],
                'sample' => ['max_per_hour' => 20, 'label' => 'a'],
            ],
        ]));

        $this->assertSame(['required', 'integer'], $result['params']['rules']['max_per_hour']);
        $this->assertSame(['regex:/^(a|b)$/'], $result['params']['rules']['label']);
        $this->assertSame(['max_per_hour' => 20, 'label' => 'a'], $result['params']['sample']);
    }

    public function test_json_rules_for_a_column_not_in_columns_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/json_rules\.ghost.*type "json"/');

        ModuleConfigContract::jsonRules($this->baseConfig([
            'ghost' => ['rules' => ['a' => 'required'], 'sample' => ['a' => 1]],
        ]));
    }

    public function test_json_rules_for_a_non_json_column_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/json_rules\.name.*type "json"/');

        ModuleConfigContract::jsonRules($this->baseConfig([
            'name' => ['rules' => ['a' => 'required'], 'sample' => ['a' => 1]],
        ]));
    }

    public function test_missing_sample_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"sample" is required/');

        ModuleConfigContract::jsonRules($this->baseConfig([
            'params' => ['rules' => ['max_per_hour' => 'required']],
        ]));
    }

    public function test_invalid_paths_throw(): void
    {
        $count = 0;
        foreach (['windows..start', 'windows.', ''] as $badPath) {
            try {
                ModuleConfigContract::jsonRules($this->baseConfig([
                    'params' => ['rules' => [$badPath => 'required'], 'sample' => ['x' => 1]],
                ]));
                $this->fail("Expected an InvalidArgumentException for path \"{$badPath}\"");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('path', $e->getMessage());
                $count++;
            }
        }

        $this->assertSame(3, $count);
    }

    public function test_path_starting_with_the_column_name_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not "params\.windows"/');

        ModuleConfigContract::jsonRules($this->baseConfig([
            'params' => ['rules' => ['params.windows' => 'required'], 'sample' => ['windows' => []]],
        ]));
    }

    public function test_a_non_string_rule_and_a_nested_array_rule_both_throw(): void
    {
        $count = 0;
        foreach ([5, [['a']]] as $badRule) {
            try {
                ModuleConfigContract::jsonRules($this->baseConfig([
                    'params' => ['rules' => ['max_per_hour' => $badRule], 'sample' => ['max_per_hour' => 1]],
                ]));
                $this->fail('Expected an InvalidArgumentException for rule ' . json_encode($badRule));
            } catch (InvalidArgumentException $e) {
                $count++;
            }
        }

        $this->assertSame(2, $count);
    }

    public function test_json_rules_as_a_list_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ModuleConfigContract::jsonRules($this->baseConfig([['x']]));
    }
}
