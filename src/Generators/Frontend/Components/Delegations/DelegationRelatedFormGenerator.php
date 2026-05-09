<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components\Delegations;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\CustomFeatures\RelatedModuleFormGenerator;

class DelegationRelatedFormGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        return false;
    }

    public function generateRelatedModuleForms(string $delegationKey, array $delegation): bool
    {
        $adapted = $this->adaptDelegationToCustomFeature($delegation);

        $generator = new RelatedModuleFormGenerator($this->moduleName, $this->moduleGroup, $this->config);
        return $generator->generateRelatedModuleForms($delegationKey, $adapted);
    }

    private function adaptDelegationToCustomFeature(array $delegation): array
    {
        $operations = $delegation['operations'] ?? [];

        $backendFeatures = [];
        $frontendFeatures = [];

        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            $opData = $operations[$op] ?? [];
            $backendOp = $opData['backend'] ?? [];
            $frontendOp = $opData['frontend'] ?? [];

            $backendFeatures[$op] = array_merge(
                ['enabled' => $opData['enabled'] ?? false],
                isset($opData['endpoint']) ? ['endpoint' => $opData['endpoint']] : [],
                $backendOp
            );

            $frontendFeatures[$op] = $frontendOp;
        }

        return [
            'name' => $delegation['name'] ?? '',
            'label' => $delegation['label'] ?? '',
            'displayType' => 'tab-action',
            'relatedModule' => $delegation['relatedModule'] ?? null,
            'parentKey' => $delegation['parentKey'] ?? 'uuid',
            'filterKey' => $delegation['filterKey'] ?? 'parent_id',
            'parentIdField' => $delegation['parentIdField'] ?? 'id',
            'features' => [
                'backend' => $backendFeatures,
                'frontend' => $frontendFeatures,
            ],
        ];
    }
}
