<?php

namespace Blutrixx\GeneratorEngine\Helpers;

class DelegationConfigNormalizer
{
    public static function normalize(array $delegation): array
    {
        $delegation['name'] = $delegation['name'] ?? '';
        $delegation['label'] = $delegation['label'] ?? $delegation['name'];
        $delegation['uiType'] = $delegation['uiType'] ?? 'tab';

        // Normalize relatedModule to object format
        if (isset($delegation['relatedModule'])) {
            if (is_string($delegation['relatedModule'])) {
                $delegation['relatedModule'] = [
                    'name' => $delegation['relatedModule'],
                    'group' => 'Core',
                ];
            } elseif (is_array($delegation['relatedModule'])) {
                $delegation['relatedModule']['name'] = $delegation['relatedModule']['name'] ?? '';
                $delegation['relatedModule']['group'] = $delegation['relatedModule']['group'] ?? 'Core';
            }
        }

        $delegation['parentKey'] = $delegation['parentKey'] ?? 'uuid';
        $delegation['filterKey'] = $delegation['filterKey'] ?? 'parent_id';
        $delegation['parentIdField'] = $delegation['parentIdField'] ?? 'id';
        $delegation['defaults'] = $delegation['defaults'] ?? [];

        $delegation['operations'] = self::normalizeOperations($delegation['operations'] ?? []);

        return $delegation;
    }

    private static function normalizeOperations(array $operations): array
    {
        $defaults = self::getOperationDefaults();
        return array_replace_recursive($defaults, $operations);
    }

    private static function getOperationDefaults(): array
    {
        $ops = [];
        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            $ops[$op] = [
                'enabled' => false,
                // Per-operation, not a blanket 'GET': RoutesGenerator::
                // generateDelegationRoutes() reads this via
                // $endpoint['method'] ?? (list/view ? 'get' : 'post'), and
                // that fallback only fires on a NULL/missing method — a
                // concrete (if generic) 'GET' here defeated it for every
                // operation, so every delegation's create/edit/delete
                // operation was registered as a GET route regardless of
                // what the caller configured.
                'endpoint' => [
                    'method' => in_array($op, ['list', 'view'], true) ? 'GET' : 'POST',
                    'path' => '',
                    'permission' => '',
                ],
                'backend' => self::getBackendOperationDefaults($op),
                'frontend' => self::getFrontendOperationDefaults($op),
            ];
        }
        return $ops;
    }

    private static function getBackendOperationDefaults(string $op): array
    {
        if ($op === 'list') {
            return [
                'filterableFields' => '',
                'sortableFields' => '',
                'filterFields' => [],
                'eagerLoadRelationships' => '',
                'filterableRelationships' => [],
            ];
        }
        if (in_array($op, ['create', 'edit'])) {
            return ['fields' => []];
        }
        if ($op === 'view') {
            return ['eagerLoadRelationships' => ''];
        }
        if ($op === 'delete') {
            return ['success_message' => 'Record deleted successfully'];
        }
        return [];
    }

    private static function getFrontendOperationDefaults(string $op): array
    {
        if ($op === 'list') {
            return ['primaryField' => '', 'fields' => []];
        }
        if (in_array($op, ['create', 'edit'])) {
            return [
                'fields' => [],
                'sections' => [],
                'button_text' => $op === 'create' ? 'Create' : 'Update',
                'hiddens' => [],
                'defaults' => [],
            ];
        }
        if ($op === 'view') {
            return ['fields' => [], 'titleData' => '', 'badges' => []];
        }
        if ($op === 'delete') {
            return ['fields' => []];
        }
        return [];
    }

    public static function normalizeAll(array $delegations): array
    {
        return array_map([self::class, 'normalize'], $delegations);
    }

    public static function validate(array $delegation): array
    {
        $errors = [];

        if (empty($delegation['name'])) {
            $errors[] = 'Delegation name is required';
        }

        $uiType = $delegation['uiType'] ?? '';
        if (!in_array($uiType, ['tab', 'modal'])) {
            $errors[] = "Invalid uiType: {$uiType}. Must be one of: tab, modal";
        }

        if (empty($delegation['relatedModule'])) {
            $errors[] = 'relatedModule is required for delegations';
        }

        return $errors;
    }

    public static function getEnabledOperations(array $delegation): array
    {
        $enabled = [];
        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            if (!empty($delegation['operations'][$op]['enabled'])) {
                $enabled[] = $op;
            }
        }
        return $enabled;
    }

    public static function hasEnabledOperations(array $delegation): bool
    {
        return !empty(self::getEnabledOperations($delegation));
    }
}
