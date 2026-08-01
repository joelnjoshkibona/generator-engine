<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\Action\ActionServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\CreateServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\CreateSplashServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\Delegation\DelegationServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\DeleteServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\EditServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\EditSplashServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\ViewServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a real, longstanding bug found 2026-07-30: every
 * generated Service's `catch (ApplicationException $e)` block returned
 * `Helpers::error($e->getMessage(), 500, ...)` -- a business-rule validation
 * failure (the exact scenario ApplicationException exists for -- a duplicate
 * check, a missing-related-record lookup, any custom `throw new
 * ApplicationException(...)`) surfaced as HTTP 500 instead of 422.
 *
 * Confirmed pre-existing, not caused by any of this session's other work:
 * every affected line's git history is a single, original `+` addition,
 * never modified since. 57 already-generated Service files in a real
 * consumer app (SYSTEM_SHELL) were live with this bug; 66 others had
 * independently been hand-corrected to 422 at some point, which is how the
 * "should be 422, same as every other module" expectation was established
 * in the first place -- the generator itself never emitted it.
 *
 * Every one of these generators shares the same one-line fix (500 -> 422)
 * in its own `.stub` template (or, for DelegationServiceGenerator, its own
 * PHP-built heredoc methods, since that generator doesn't use a `.stub` file
 * at all) -- confirmed via a full-tree grep that zero occurrences of the old
 * `getMessage(), 500, $e->getData())` pattern remain anywhere in `src/`.
 *
 * DeleteCheckServiceGenerator's ApplicationException catch intentionally
 * returns 404, not 422 (a delete-check ApplicationException always means
 * "the record you're checking doesn't exist") -- correctly excluded here,
 * not a member of this bug.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\BaseServiceGenerator
 */
class ApplicationExceptionStatusCodeTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-app-exception-status-test-' . uniqid();
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

    private function assertGeneratedFileUses422ForApplicationException(string $path): void
    {
        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString(
            "Helpers::error(\$e->getMessage(), 422, \$e->getData());",
            $content,
            "Expected ApplicationException to map to 422 in {$path}"
        );
        $this->assertStringNotContainsString(
            "Helpers::error(\$e->getMessage(), 500, \$e->getData());",
            $content,
            "ApplicationException must never map to 500 in {$path}"
        );
    }

    public function test_create_service_maps_application_exception_to_422(): void
    {
        $generator = new CreateServiceGenerator('Widgets', 'Core', [
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'name', 'rules' => 'required|string|max:255'],
            ]]]],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $this->assertGeneratedFileUses422ForApplicationException(
            $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets/Services/WidgetsCreateService.php'
        );
    }

    public function test_edit_service_maps_application_exception_to_422(): void
    {
        $generator = new EditServiceGenerator('Widgets', 'Core', [
            'features' => ['backend' => ['edit' => ['fields' => [
                ['field' => 'name', 'rules' => 'nullable|string|max:255'],
            ]]]],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $this->assertGeneratedFileUses422ForApplicationException(
            $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets/Services/WidgetsEditService.php'
        );
    }

    public function test_delete_service_maps_application_exception_to_422(): void
    {
        $generator = new DeleteServiceGenerator('Widgets', 'Core', [
            'features' => ['backend' => ['delete' => ['enabled' => true]]],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $this->assertGeneratedFileUses422ForApplicationException(
            $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets/Services/WidgetsDeleteService.php'
        );
    }

    public function test_view_service_maps_application_exception_to_422(): void
    {
        // Companion to ViewServiceGeneratorTest.php's withTrashed() coverage --
        // this file's own bug (a different line in the same stub).
        $generator = new ViewServiceGenerator('Widgets', 'Core', [
            'features' => ['backend' => ['view' => ['enabled' => true]]],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $this->assertGeneratedFileUses422ForApplicationException(
            $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets/Services/WidgetsViewService.php'
        );
    }

    public function test_create_splash_service_maps_application_exception_to_422(): void
    {
        $generator = new CreateSplashServiceGenerator('Widgets', 'Core', [
            'constants' => ['WIDGET_TYPES' => ['a', 'b']],
            'features' => ['backend' => ['createSplash' => ['enabled' => true]]],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $this->assertGeneratedFileUses422ForApplicationException(
            $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets/Services/WidgetsCreateSplashService.php'
        );
    }

    public function test_edit_splash_service_maps_application_exception_to_422(): void
    {
        $generator = new EditSplashServiceGenerator('Widgets', 'Core', [
            'constants' => ['WIDGET_TYPES' => ['a', 'b']],
            'features' => ['backend' => ['editSplash' => ['enabled' => true]]],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $this->assertGeneratedFileUses422ForApplicationException(
            $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets/Services/WidgetsEditSplashService.php'
        );
    }

    public function test_action_service_maps_application_exception_to_422(): void
    {
        $generator = new ActionServiceGenerator('Widgets', 'Core', [], 'approve', ['name' => 'Approve']);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $this->assertGeneratedFileUses422ForApplicationException(
            $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets/Services/WidgetsApproveService.php'
        );
    }

    public function test_delegation_service_maps_application_exception_to_422_across_all_four_crud_operations(): void
    {
        // DelegationServiceGenerator has no .stub file at all -- its create/
        // edit/view/delete methods (list uses ListServiceTrait and has no
        // catch(ApplicationException) block of its own) are built directly
        // as PHP heredocs, each with its own independent catch block, which
        // is exactly why this bug could (and did) exist here as four
        // separate copies rather than one shared template line.
        PathManager::setModuleRegistry([
            ['name' => 'Items', 'module_type' => 'Core'],
        ]);

        $generator = new DelegationServiceGenerator(
            'Widgets',
            'Core',
            [],
            'items',
            [
                'name' => 'Items',
                'relatedModule' => ['name' => 'Items', 'group' => null],
                'filterKey' => 'widget_id',
                // All four non-list operations are disabled by default (see
                // buildDelegationEntry()) -- each owns an independent
                // catch(ApplicationException) block only when enabled.
                'operations' => [
                    'create' => ['enabled' => true],
                    'edit'   => ['enabled' => true],
                    'view'   => ['enabled' => true],
                    'delete' => ['enabled' => true],
                ],
            ]
        );
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets/Services/WidgetsItemsService.php';
        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertSame(
            4,
            substr_count($content, "Helpers::error(\$e->getMessage(), 422, \$e->getData());"),
            'Expected all 4 CRUD delegation operation methods (create/edit/view/delete) to map ApplicationException to 422.'
        );
        $this->assertStringNotContainsString("Helpers::error(\$e->getMessage(), 500, \$e->getData());", $content);
    }
}
