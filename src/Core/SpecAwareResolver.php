<?php

namespace Blutrixx\GeneratorEngine\Core;

/**
 * A module's questions about itself (a parent category, a task's parent task) answered from its own spec: on its
 * first generation the app does not know it yet, so the app's resolver would say it does not exist. Every other module
 * is the app's resolver's to answer.
 */
final class SpecAwareResolver implements ModuleResolver
{
    public function __construct(private readonly ModuleSpec $spec, private readonly ModuleResolver $app) {}

    private function self(string $module): bool
    {
        return $module === $this->spec->key();
    }

    public function table(string $module): string
    {
        return $this->self($module) ? $this->spec->table() : $this->app->table($module);
    }

    public function modelClass(string $module): string
    {
        return $this->self($module)
            ? "App\\Modules\\Custom\\{$this->spec->group()}\\{$module}\\{$module}Model"
            : $this->app->modelClass($module);
    }

    public function labelColumn(string $module): string
    {
        return $this->self($module) ? $this->spec->labelColumn() : $this->app->labelColumn($module);
    }

    public function isOrganisationScoped(string $module): bool
    {
        return $this->self($module) ? $this->spec->isOrganisationScoped() : $this->app->isOrganisationScoped($module);
    }

    public function exists(string $module): bool
    {
        return $this->self($module) || $this->app->exists($module);
    }

    public function scope(string $module): string
    {
        return $this->self($module) ? $this->spec->scope() : $this->app->scope($module);
    }
}
