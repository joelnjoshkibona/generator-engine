<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Core;

use Blutrixx\GeneratorEngine\Core\ModuleResolver;

/** The modules around the one generated, from an array: key => [table, class, label, scoped]. */
final class ArrayModuleResolver implements ModuleResolver
{
    public function __construct(private array $modules = [
        'Users' => ['users', 'App\Modules\Core\People\Users\UsersModel', 'name', false],
        'Locations' => ['locations', 'App\Modules\Core\Places\Locations\LocationsModel', 'name', true],
        'Projects' => ['projects', 'App\Modules\Custom\Work\Projects\ProjectsModel', 'title', true],
    ]) {}

    public function table(string $module): string { return $this->modules[$module][0]; }

    public function modelClass(string $module): string { return $this->modules[$module][1]; }

    public function labelColumn(string $module): string { return $this->modules[$module][2]; }

    public function isOrganisationScoped(string $module): bool { return $this->modules[$module][3]; }

    public function exists(string $module): bool { return isset($this->modules[$module]); }
}
