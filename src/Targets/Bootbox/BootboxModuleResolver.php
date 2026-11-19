<?php

namespace Blutrixx\GeneratorEngine\Targets\Bootbox;

use Blutrixx\GeneratorEngine\Core\ModuleResolver;
use RuntimeException;

/** Answers from the host's own manifests (Bootbox's ModuleRegistry: namespace, dir, manifest per module key). */
final class BootboxModuleResolver implements ModuleResolver
{
    private const REGISTRY = 'App\Platform\Modules\ModuleRegistry';

    private const ORGANISATION_TRAIT = 'App\Modules\Kernel\Tenancy\Organizations\Traits\BelongsToOrganization';

    /** @param array<string, array>|null $modules a registry's all(), or null to ask the host's */
    public function __construct(private ?array $modules = null) {}

    private function entry(string $module): array
    {
        $this->modules ??= class_exists(self::REGISTRY) ? (self::REGISTRY)::all() : [];

        return $this->modules[$module] ?? throw new RuntimeException("No module '{$module}' in this app (generator.json names it as a relatedModule)");
    }

    public function exists(string $module): bool
    {
        try {
            $this->entry($module);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function modelClass(string $module): string
    {
        return $this->entry($module)['namespace']."\\{$module}Model";
    }

    public function table(string $module): string
    {
        $class = $this->modelClass($module);
        if (defined("{$class}::TABLE")) {
            return constant("{$class}::TABLE");
        }

        return class_exists($class) ? (new $class)->getTable() : \Illuminate\Support\Str::snake($module);
    }

    public function labelColumn(string $module): string
    {
        return $this->entry($module)['manifest']['label'] ?? 'name';
    }

    public function isOrganisationScoped(string $module): bool
    {
        $class = $this->modelClass($module);

        return class_exists($class) && in_array(self::ORGANISATION_TRAIT, class_uses_recursive($class), true);
    }
}
