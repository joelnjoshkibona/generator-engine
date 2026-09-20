<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\CreateSplashServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\EditSplashServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * When a splash service is written must agree with when its route and controller method are.
 *
 * RoutesGenerator, ControllerGenerator and PhpUnitTestGenerator treat `createSplash`/`editSplash` as
 * enabled when the key is PRESENT (with non-empty `constants`) -- and the docs call `"editSplash": {}` valid,
 * "no extra data to preload". The service generators used `empty()`, so that declaration produced a route and
 * a controller method that called a class nothing had generated: a 500 on every form mount.
 */
class SplashServiceGeneratorGatingTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-splash-gating-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::setModuleRegistry([]);
        PathManager::resetProjectRoot();
        PathManager::resetModuleSubGroup();
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function servicePath(string $name): string
    {
        return $this->tmpRoot . "/BACKEND/app/Project/Modules/Core/Widgets/Services/Widgets{$name}.php";
    }

    /** @return array<string, array{class-string, string, string}> */
    public static function sides(): array
    {
        return [
            'create' => [CreateSplashServiceGenerator::class, 'createSplash', 'CreateSplashService'],
            'edit' => [EditSplashServiceGenerator::class, 'editSplash', 'EditSplashService'],
        ];
    }

    #[DataProvider('sides')]
    public function test_an_empty_declaration_still_generates_the_service_its_route_calls(string $generatorClass, string $key, string $service): void
    {
        $generator = new $generatorClass('Widgets', 'Core', [
            'constants' => ['KIND' => 1],
            'features' => ['backend' => [$key => []]],
        ]);
        $generator->setForce(true);

        $this->assertTrue($generator->generate());
        $this->assertFileExists($this->servicePath($service));
    }

    #[DataProvider('sides')]
    public function test_nothing_is_generated_without_constants_or_without_the_key(string $generatorClass, string $key, string $service): void
    {
        $noConstants = new $generatorClass('Widgets', 'Core', ['features' => ['backend' => [$key => ['splashData' => []]]]]);
        $noConstants->setForce(true);
        $this->assertFalse($noConstants->generate());

        $noKey = new $generatorClass('Widgets', 'Core', ['constants' => ['KIND' => 1], 'features' => ['backend' => ['list' => ['fields' => []]]]]);
        $noKey->setForce(true);
        $this->assertFalse($noKey->generate());

        $this->assertFileDoesNotExist($this->servicePath($service));
    }
}
