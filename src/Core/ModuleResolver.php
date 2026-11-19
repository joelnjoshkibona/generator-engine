<?php

namespace Blutrixx\GeneratorEngine\Core;

/**
 * What the core needs to know about the modules around the one being generated. Each target answers from its own
 * host (Bootbox reads its manifests); tests answer from an array.
 */
interface ModuleResolver
{
    /** The related module's table, e.g. 'users'. */
    public function table(string $module): string;

    /** The related module's model class, fully qualified. */
    public function modelClass(string $module): string;

    /** The column the module's records are named by (its manifest's "label"), e.g. 'name'. */
    public function labelColumn(string $module): string;

    /** Rows belong to one organisation (validated with exists_in_org); false for shared tables. */
    public function isOrganisationScoped(string $module): bool;

    public function exists(string $module): bool;
}
