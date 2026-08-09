<?php

namespace Blutrixx\GeneratorEngine\Schema;

/**
 * MorphAliasValidator
 *
 * Relation::morphMap() is a single, Laravel-global keyed registry -- not
 * scoped per table or per module. If two independently-generated models
 * each register the SAME alias for two DIFFERENT model classes (e.g.
 * Payments.morphs registers 'supplier' => SuppliersModel, and some other
 * unrelated morph column also registers 'supplier' => a different model),
 * whichever model's boot() runs last silently wins -- the earlier
 * registration is overwritten with no error, no warning, and the bug only
 * shows up later as a *_type value resolving to the wrong class.
 *
 * This class is the single place that decides whether a set of alias
 * declarations, taken together, is safe: the same alias mapped to the same
 * model twice is a harmless, idempotent duplicate (allowed); the same alias
 * mapped to two different models is a real conflict (rejected). Pure and
 * side-effect free so it can be exercised directly in a unit test -- callers
 * decide what to do with a non-empty conflict list (both real callers hard-
 * fail generation before anything is written).
 */
class MorphAliasValidator
{
    /**
     * @param array<int, array{alias: string, model: string, source: string}> $declarations
     *        One entry per (module, morph, target) triple being validated
     *        together. 'source' is a human-readable origin for the error
     *        message, e.g. "Payments.payable" or "Orders.billable".
     * @return string[] Conflict messages; empty means every declaration is safe.
     */
    public static function findConflicts(array $declarations): array
    {
        $byAlias = []; // alias => ['model' => fqcn, 'source' => string]
        $conflicts = [];

        foreach ($declarations as $declaration) {
            $alias = $declaration['alias'] ?? '';
            $model = $declaration['model'] ?? '';
            $source = $declaration['source'] ?? '(unknown source)';

            if ($alias === '' || $model === '') {
                continue;
            }

            $normalizedModel = '\\' . ltrim($model, '\\');

            if (!isset($byAlias[$alias])) {
                $byAlias[$alias] = ['model' => $normalizedModel, 'source' => $source];
                continue;
            }

            if ($byAlias[$alias]['model'] !== $normalizedModel) {
                $conflicts[] = "Morph alias '{$alias}' is registered for two different models: " .
                    "{$byAlias[$alias]['model']} (via {$byAlias[$alias]['source']}) and " .
                    "{$normalizedModel} (via {$source}). Relation::morphMap() is a single global " .
                    "registry -- the same alias key cannot point at two different models. Rename " .
                    "one alias or point both targets at the same model.";
                continue;
            }

            // Same alias, same model: harmless idempotent duplicate -- allowed silently.
        }

        return $conflicts;
    }
}
