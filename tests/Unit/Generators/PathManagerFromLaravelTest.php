<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators;

use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for PathManager::fromLaravel(), the guard around every Laravel helper
 * this package touches.
 *
 * The bug it exists for is a nasty one, because the obvious guard is wrong in a way
 * no test in this repo could previously see. Five call sites used
 * `function_exists('config')` / `function_exists('base_path')` and called straight
 * through. That check answers the wrong question:
 *
 *   - in THIS package's test suite the helpers genuinely do not exist, so the guard
 *     is false and the branch never runs — every test passes;
 *   - in a CONSUMING project the helpers are autoloaded by vendor/autoload.php and
 *     therefore DO exist — but `vendor/bin/gen-frontend` is a plain CLI that never
 *     boots the framework, so the call reached an unbooted container and died with
 *     `ReflectionException: Class "config" does not exist`.
 *
 * So the failure appeared only when the package was installed somewhere real and
 * invoked the one way the docs recommend. Existence is necessary but not sufficient;
 * the call has to actually survive, which is what this wrapper establishes.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\PathManager::fromLaravel()
 */
class PathManagerFromLaravelTest extends TestCase
{
    public function test_returns_the_callables_value_when_it_succeeds(): void
    {
        $this->assertSame(
            '/somewhere/stubs',
            PathManager::fromLaravel(static fn (): string => '/somewhere/stubs')
        );
    }

    public function test_returns_the_default_when_the_helper_throws(): void
    {
        // Exactly what an unbooted container does: the helper exists and explodes.
        $result = PathManager::fromLaravel(
            static fn () => throw new \ReflectionException('Class "config" does not exist'),
            ['fallback']
        );

        $this->assertSame(['fallback'], $result);
    }

    public function test_catches_errors_not_just_exceptions(): void
    {
        // A container failure can surface as an \Error (a TypeError from a null binding,
        // say), which is not an \Exception — catching the narrower type would let it
        // through and kill a whole multi-module CLI run.
        $result = PathManager::fromLaravel(
            static fn () => throw new \TypeError('unbooted binding'),
            'default'
        );

        $this->assertSame('default', $result);
    }

    public function test_default_is_null_when_not_supplied(): void
    {
        $this->assertNull(
            PathManager::fromLaravel(static fn () => throw new \RuntimeException('boom'))
        );
    }

    public function test_generator_config_falls_back_to_an_empty_array_with_no_application(): void
    {
        // getConfig() is protected and memoised; getDefaultModuleGroup() reads through it
        // and is the cheapest public path to prove the fallback resolves rather than throws.
        $this->assertSame('Core', PathManager::getDefaultModuleGroup());
    }
}
