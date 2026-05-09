<?php

namespace Blutrixx\GeneratorEngine\Helpers;

class ActionConfigNormalizer
{
    public static function normalize(array $action): array
    {
        $action['name'] = $action['name'] ?? '';
        $action['label'] = $action['label'] ?? $action['name'];
        $action['hasUI'] = $action['hasUI'] ?? false;
        $action['uiType'] = $action['uiType'] ?? null;
        $action['urlParams'] = $action['urlParams'] ?? [];
        $action['methodName'] = $action['methodName'] ?? '';
        $action['serviceName'] = $action['serviceName'] ?? '';

        $action['operations'] = self::normalizeOperations($action['operations'] ?? []);

        return $action;
    }

    private static function normalizeOperations(array $operations): array
    {
        $defaults = [];
        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            $defaults[$op] = [
                'enabled' => false,
                'endpoint' => ['method' => 'GET', 'path' => '', 'permission' => ''],
            ];
        }
        return array_replace_recursive($defaults, $operations);
    }

    public static function normalizeAll(array $actions): array
    {
        return array_map([self::class, 'normalize'], $actions);
    }

    public static function validate(array $action): array
    {
        $errors = [];

        if (empty($action['name'])) {
            $errors[] = 'Action name is required';
        }

        return $errors;
    }

    public static function getEnabledOperations(array $action): array
    {
        $enabled = [];
        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            if (!empty($action['operations'][$op]['enabled'])) {
                $enabled[] = $op;
            }
        }
        return $enabled;
    }
}
