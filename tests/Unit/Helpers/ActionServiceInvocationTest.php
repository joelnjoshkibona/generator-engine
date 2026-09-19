<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Helpers;

use Blutrixx\GeneratorEngine\Helpers\ActionServiceInvocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An action's service is write-once, so developers reshape its public entry
 * point (NJIWA's MessagesSendService has both `execute(ApiKeysModel, array,
 * ?string)` for the public API and `sendFromConsole(array $data)` for the
 * console). The controller method is regenerated on every --force and
 * always emitted `{Module}{Action}Service::execute($request->all()[, ...])`
 * -- so a --force produced a controller calling a method that no longer
 * exists, or with the wrong arguments: a runtime error on first click, no
 * warning while generating. `serviceMethod`/`serviceArgs` on the action
 * config record the real call shape; this class is the single place that
 * validates and renders it.
 *
 * @see \Blutrixx\GeneratorEngine\Helpers\ActionServiceInvocation
 */
class ActionServiceInvocationTest extends TestCase
{
    public function test_default_call_shape_when_keys_are_absent(): void
    {
        $resolved = ActionServiceInvocation::resolve('k', ['urlParams' => ['uuid']]);

        $this->assertSame('execute', $resolved['method']);
        $this->assertSame(['data', 'param:uuid'], $resolved['args']);
        $this->assertFalse($resolved['declared']);

        $this->assertSame('$request->all(), $uuid', ActionServiceInvocation::controllerArguments($resolved));
        $this->assertSame(
            'array $data, string $uuid, array $params = []',
            ActionServiceInvocation::serviceParameters($resolved)
        );
        $this->assertSame('$data, $uuid, $params', ActionServiceInvocation::processArguments($resolved));
    }

    public function test_empty_string_service_method_resolves_to_execute_and_undeclared(): void
    {
        $resolved = ActionServiceInvocation::resolve('k', ['serviceMethod' => '', 'urlParams' => []]);

        $this->assertSame('execute', $resolved['method']);
        $this->assertFalse($resolved['declared']);
    }

    public function test_custom_method_and_args_render_in_call_order(): void
    {
        $resolved = ActionServiceInvocation::resolve('k', [
            'serviceMethod' => 'sendFromConsole',
            'serviceArgs' => ['request', 'user', 'param:uuid', 'data'],
            'urlParams' => ['uuid'],
        ]);

        $this->assertSame(
            '$request, $request->user(), $uuid, $request->all()',
            ActionServiceInvocation::controllerArguments($resolved)
        );
        $this->assertSame(
            '\Illuminate\Http\Request $request, ?\Illuminate\Contracts\Auth\Authenticatable $user, string $uuid, array $data, array $params = []',
            ActionServiceInvocation::serviceParameters($resolved)
        );
        $this->assertSame(
            '$request, $user, $uuid, $data, $params',
            ActionServiceInvocation::processArguments($resolved)
        );
    }

    public function test_empty_service_args_renders_params_only(): void
    {
        $resolved = ActionServiceInvocation::resolve('k', ['serviceArgs' => [], 'urlParams' => []]);

        $this->assertSame('', ActionServiceInvocation::controllerArguments($resolved));
        $this->assertSame('array $params = []', ActionServiceInvocation::serviceParameters($resolved));
        $this->assertSame('$params', ActionServiceInvocation::processArguments($resolved));
    }

    public static function invalidDataProvider(): array
    {
        return [
            'not one of the vocabulary' => [['serviceArgs' => ['body']], [], 'is not one of'],
            'param not in urlParams' => [['serviceArgs' => ['param:year']], ['uuid'], 'names no entry in urlParams (uuid)'],
            'duplicate token' => [['serviceArgs' => ['data', 'data']], [], 'more than once'],
            'serviceArgs is a string' => [['serviceArgs' => 'data'], [], 'must be a JSON array of strings'],
            'serviceArgs is a keyed map' => [['serviceArgs' => ['a' => 'data']], [], 'must be a JSON array of strings'],
            'serviceArgs entries are not strings' => [['serviceArgs' => [1]], [], 'must be a JSON array of strings'],
            'method process' => [['serviceMethod' => 'process'], [], 'collides with'],
            'method Process (case-insensitive)' => [['serviceMethod' => 'Process'], [], 'collides with'],
            'method not a valid identifier' => [['serviceMethod' => 'send-now'], [], 'is not a valid PHP method name'],
            'param name is reserved' => [['serviceArgs' => ['param:params']], ['params'], 'is reserved'],
        ];
    }

    #[DataProvider('invalidDataProvider')]
    public function test_invalid_configs_throw_with_the_expected_reason(array $extra, array $urlParams, string $expectedSubstring): void
    {
        $action = array_merge(['urlParams' => $urlParams], $extra);

        $this->expectException(\InvalidArgumentException::class);
        try {
            ActionServiceInvocation::resolve('k', $action);
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("Action 'k':", $e->getMessage());
            $this->assertStringContainsString($expectedSubstring, $e->getMessage());
            throw $e;
        }
    }

    public function test_normalizer_keeps_both_keys_unchanged_and_validate_reports_one_error(): void
    {
        $action = \Blutrixx\GeneratorEngine\Helpers\ActionConfigNormalizer::normalize([
            'name' => 'k',
            'serviceMethod' => 'sendFromConsole',
            'serviceArgs' => ['data'],
        ]);

        $this->assertSame('sendFromConsole', $action['serviceMethod']);
        $this->assertSame(['data'], $action['serviceArgs']);

        $errors = \Blutrixx\GeneratorEngine\Helpers\ActionConfigNormalizer::validate([
            'name' => 'k',
            'serviceArgs' => ['body'],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('is not one of', $errors[0]);
    }
}
