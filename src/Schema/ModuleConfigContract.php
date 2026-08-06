<?php

namespace Blutrixx\GeneratorEngine\Schema;

/**
 * ModuleConfigContract
 *
 * THE single sanctioned place to read derived module-level facts
 * (has_soft_deletes / has_timestamps / has_uuid / has_creator_updater) off a
 * built GeneratorModule config array.
 *
 * Bug this guards against: before this class existed, the same fact was
 * re-derived independently in at least three places with three different
 * defaulting/fallback rules —
 *   - IntrospectionToConfig::build() defaulted an absent 'has_soft_deletes'
 *     meta key to `false` silently (see the strict-mode validation added
 *     alongside this class).
 *   - ModelGenerator::hasSoftDeletes() read the config flag AND, when the
 *     flag key was entirely absent from $config, separately rescanned
 *     $fields for a literal 'deleted_at' entry.
 *   - MigrationGenerator::hasSoftDeletes() read the config flag with a bare
 *     `?? false` and never rescanned fields at all.
 * A caller that forgot to thread the live-introspected value into $meta
 * therefore got a silent `false` — indistinguishable from an intentional
 * `false` — and the two generators could each reach a DIFFERENT answer for
 * the exact same config, depending on which of the three rules it happened
 * to implement. This actually shipped: a downstream command discarded the
 * live introspection result and every generated module quietly lost
 * SoftDeletes.
 *
 * Fix: every generator that needs one of these facts calls the matching
 * static accessor here instead of re-deriving it. Each accessor documents
 * its ONE resolution rule. Where a rescan-the-fields fallback is genuinely
 * useful (ModelGenerator's historical deleted_at-column rescan), it is
 * folded in here, explicitly, as a documented fallback shared by every
 * caller — not a private behaviour only one generator happened to have.
 */
final class ModuleConfigContract
{
    /**
     * Whether the module's underlying table has a `deleted_at` column
     * (i.e. `$table->softDeletes()` was used / the Eloquent model should
     * `use SoftDeletes`).
     *
     * Resolution rule (single source of truth):
     *   1. If $config['has_soft_deletes'] is present, trust it verbatim
     *      (cast to bool) — this is expected to have been set by
     *      IntrospectionToConfig::build() from the live
     *      SchemaIntrospector::hasSoftDeletes() result.
     *   2. Otherwise (flag key entirely absent — a hand-authored or legacy
     *      config that predates the flag), fall back to scanning
     *      $config['columns'] for a field literally named 'deleted_at'.
     *      This fallback exists ONLY for configs that never had the flag
     *      threaded through at all; any config built via
     *      IntrospectionToConfig::build() in strict mode always has the key
     *      present, so this branch is never reached for it.
     *   3. If neither is present, default to `false` — soft deletes are an
     *      opt-in migration feature; most tables don't have deleted_at.
     */
    public static function hasSoftDeletes(array $config): bool
    {
        if (array_key_exists('has_soft_deletes', $config)) {
            return (bool) $config['has_soft_deletes'];
        }

        foreach ($config['columns'] ?? [] as $field) {
            if (($field['name'] ?? null) === 'deleted_at') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the module's underlying table has `created_at` / `updated_at`
     * columns (i.e. `$table->timestamps()` was used).
     *
     * Resolution rule (single source of truth):
     *   1. If $config['has_timestamps'] is present, trust it verbatim (cast
     *      to bool) — expected to have been set by
     *      IntrospectionToConfig::build() from the live
     *      SchemaIntrospector::hasTimestamps() result.
     *   2. Otherwise default to `true` — the Laravel migration convention
     *      default is $table->timestamps(); created_at/updated_at are
     *      deliberately excluded from $config['columns'] (see
     *      SchemaIntrospector::SKIP_COLUMNS), so — unlike soft deletes —
     *      their absence from columns[] is never evidence either way and is
     *      not used as a fallback signal here.
     */
    public static function hasTimestamps(array $config): bool
    {
        return array_key_exists('has_timestamps', $config) ? (bool) $config['has_timestamps'] : true;
    }

    /**
     * Whether the module's underlying table has a separate public-addressing
     * `uuid` column (independent of the `id` column's own type).
     *
     * Resolution rule (single source of truth):
     *   1. If $config['has_uuid'] is present, trust it verbatim (cast to
     *      bool).
     *   2. Otherwise default to `true` — the project convention is that
     *      every table gets a routing uuid unless a caller that actually
     *      introspected the real table says otherwise.
     */
    public static function hasUuid(array $config): bool
    {
        return array_key_exists('has_uuid', $config) ? (bool) $config['has_uuid'] : true;
    }

    /**
     * Whether the module's underlying table has paired
     * `created_by_id` / `updated_by_id` audit columns.
     *
     * Resolution rule (single source of truth):
     *   1. If $config['has_creator_updater'] is present, trust it verbatim
     *      (cast to bool).
     *   2. Otherwise default to `true` — most tables in this project's
     *      convention have paired creator/updater tracking.
     */
    public static function hasCreatorUpdater(array $config): bool
    {
        return array_key_exists('has_creator_updater', $config) ? (bool) $config['has_creator_updater'] : true;
    }

    /**
     * Whether this module's Model.php is hand-maintained and must never be
     * touched by generation, regardless of --force.
     *
     * Unlike the has_X flags above (introspected facts about the real
     * table), this is a developer *declaration* with no introspected
     * default — it defaults to false (the generator owns Model.php, as for
     * every other module) unless module.json explicitly opts a module out.
     *
     * Built for modules whose Model can't be expressed by the generator's
     * plain-BaseModel template at all — e.g. Users, which extends
     * Authenticatable with Sanctum/Notifiable/SoftDeletes/HasHistory and a
     * set of hand-written relationships. The generator's own
     * Authenticatable-capable template (model_users.stub) exists but is
     * unreachable (ModelGenerator hardcodes $modelType = 'default') and, even
     * if reached, has no slot for those traits/relationships — teaching it
     * to express this one module's exact shape was judged not worth it for
     * a single caller. This flag is the escape hatch: ModelGenerator uses it
     * to switch from writeFile() to writeFileOnce() (see BaseGenerator) so a
     * developer's hand-maintained Model.php survives every future --force
     * regenerate of everything else in the module.
     */
    public static function isModelHandMaintained(array $config): bool
    {
        return (bool) ($config['model_hand_maintained'] ?? false);
    }
}
