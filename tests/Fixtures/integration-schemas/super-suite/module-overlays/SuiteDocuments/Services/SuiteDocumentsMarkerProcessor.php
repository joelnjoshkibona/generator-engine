<?php

namespace App\Project\Modules\System\Suite\SuiteDocuments\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * Hand-written processor (super-suite module-overlays/): the class the blueprint's `processors` entries
 * on SuiteDocuments name. It changes nothing -- it RECORDS each call, so a test can see which stage ran,
 * for which operation, whether a stored row was in scope, and what `fields`/`config` it was handed.
 *
 * It must not alter the data it returns: the generated Create/Edit PHPUnit and Playwright specs assert
 * that what was submitted is what was stored.
 */
class SuiteDocumentsMarkerProcessor
{
    /** @var list<array{stage: string, title: mixed, stored_title: mixed, has_model: bool, model_id: mixed, config: array}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public static function beforeSave(array $data, ?Model $model, array $fields = [], array $config = []): array
    {
        return self::record('before_save', $data, $model, $config);
    }

    public static function afterSave(array $data, ?Model $model, array $fields = [], array $config = []): array
    {
        return self::record('after_save', $data, $model, $config);
    }

    public static function beforeDelete(array $data, ?Model $model, array $fields = [], array $config = []): array
    {
        return self::record('before_delete', $data, $model, $config);
    }

    public static function afterDelete(array $data, ?Model $model, array $fields = [], array $config = []): array
    {
        return self::record('after_delete', $data, $model, $config);
    }

    private static function record(string $stage, array $data, ?Model $model, array $config): array
    {
        self::$calls[] = [
            'stage' => $stage,
            'title' => $data['title'] ?? null,
            'stored_title' => $model?->getAttribute('title'),
            'has_model' => $model !== null,
            'model_id' => $model?->getKey(),
            'config' => $config,
        ];

        return $data;
    }
}
