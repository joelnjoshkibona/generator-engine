<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Models;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;

/**
 * Injects a reverse MorphMany relation onto an ALREADY-GENERATED sibling
 * module's own Model file -- the one deliberately cross-module mutation in
 * this package. Every other generator only ever writes inside its own
 * $this->modulePath; this one reaches into a morph TARGET module's Model
 * (e.g. Vendors, for Payments.payable) to add a real `payments(): MorphMany`
 * method that nothing generating the OWNING module (Payments) could ever
 * place there -- ModelGenerator only ever emits relations derived from its
 * own $this->config, never another module's.
 *
 * Ordering: must run AFTER both the owning module (Payments) and this
 * target module (Vendors) already exist on disk. Unlike morphTo()
 * generation itself (order-independent -- resolves the target at runtime
 * from the stored type column, not at generation time), this needs the
 * target's real Model file physically present on disk to inject into. The
 * orchestrating consuming-app command (make:modules-from-db) is expected to
 * call this once per delegate-enabled morph target, after its own normal
 * generation pass for every module in the run has completed.
 *
 * Idempotent: checks for the relation method's own signature before
 * inserting, so re-running (e.g. a --force re-scaffold that regenerates
 * every module in the blueprint again) never duplicates the method.
 */
class ModelRelationInjector extends BaseGenerator
{
    private const MARKER = '// [[extraRelations]]';

    /**
     * Not a real entry point -- this class doesn't render a whole file from
     * a template like every other BaseGenerator subclass; it mutates an
     * already-generated one. Call injectMorphMany() directly instead.
     */
    public function generate(): bool
    {
        throw new \RuntimeException(
            self::class . '::generate() is not implemented -- call injectMorphMany() directly.'
        );
    }

    /**
     * @param string $relationName    Method name to add, e.g. 'payments'.
     * @param string $relatedModelFqcn Fully-qualified Model class of the
     *                                 morph-owning module, e.g.
     *                                 'App\Project\Modules\System\AccountsPayments\Payments\PaymentsModel'.
     * @param string $morphName       The morph's base name, e.g. 'payable'
     *                                (matches the $table->morphs('payable')
     *                                convention -- Laravel derives
     *                                payable_type/payable_id from it).
     * @return bool true if the relation was newly inserted, false if it was
     *              already present (idempotent no-op, not a failure).
     */
    public function injectMorphMany(string $relationName, string $relatedModelFqcn, string $morphName): bool
    {
        $modelFile = "{$this->modulePath}/{$this->moduleName}Model.php";

        if (!file_exists($modelFile)) {
            throw new \RuntimeException(
                "Cannot inject relation '{$relationName}' into '{$this->moduleName}Model.php' -- file " .
                "does not exist at {$modelFile}. This target module must already be generated before " .
                "its reverse morph relation can be injected -- run this after every module in the " .
                "current scaffold pass has been generated, not interleaved with generation."
            );
        }

        $content = file_get_contents($modelFile);

        if (preg_match('/function\s+' . preg_quote($relationName, '/') . '\s*\(/', $content)) {
            return false;
        }

        if (!str_contains($content, self::MARKER)) {
            throw new \RuntimeException(
                "Cannot inject relation '{$relationName}' into '{$modelFile}' -- no '" . self::MARKER .
                "' marker found. This model may predate model.stub gaining the marker, or is a " .
                "hand-maintained model (model_hand_maintained: true, written via a different " .
                "template) that must be edited by hand instead."
            );
        }

        $relatedModelFqcn = ltrim($relatedModelFqcn, '\\');
        $method = "    public function {$relationName}(): \\Illuminate\\Database\\Eloquent\\Relations\\MorphMany\n"
            . "    {\n"
            . "        return \$this->morphMany(\\{$relatedModelFqcn}::class, '{$morphName}');\n"
            . "    }\n\n"
            . '    ' . self::MARKER;

        $content = str_replace(self::MARKER, $method, $content);

        return file_put_contents($modelFile, $content) !== false;
    }
}
