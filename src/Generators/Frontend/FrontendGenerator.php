<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend;

use Illuminate\Support\Str;

use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\ViewModalGenerator;

class FrontendGenerator extends BaseGenerator
{
    protected array $features;
    protected array $fields;
    protected array $customFeatures;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);
        
        // Use new payload only
        $this->features = [];
        $this->fields = [];

        // Detect enabled features from features.frontend
        $frontendFeatures = $config['features']['frontend'] ?? [];
        foreach (['list','create','view','edit','delete'] as $feature) {
            if (isset($frontendFeatures[$feature])) {
                $this->features[] = $feature;
            }
        }
        $this->features = array_unique($this->features);
        
        // Keep columns-derived fields for generic fallbacks
        foreach(($config['columns'] ?? []) as $column) {
            $this->fields[] = $this->convertToField($column);
        }
        
        $this->customFeatures = $config['delegations'] ?? [];
    }

    private function convertToField($column) {
        $type = 'input';
        $required = $column['nullable'] ? false : true;
        $label = ucfirst($column['name']);
        $placeholder = "Enter {$label}";

        $fieldConfig = [
            'key' => $column['name'],
            'title' => ucfirst($column['name']),
            'field' => $column['name'],
            'type' => $type,
            'required' => $required,
            'placeholder' => $placeholder,
        ];

        switch($column['type']) {
            case 'integer':
            case 'bigInteger':
                $fieldConfig['type'] = 'number';
                break;
            case 'date':
            case 'dateTime':
                $fieldConfig['type'] = 'date';
                break;
            case 'text':
            case 'longText':
                $fieldConfig['type'] = 'textarea';
                break;
            case 'foreignKey':
                $fieldConfig['type'] = 'select';
                $fieldConfig['placeholder'] = "Select {$label}";
                $fieldConfig['options'] = $column['relatedModule'];
                break;
            default:
                $fieldConfig['type'] = 'input';
        }
        return $fieldConfig;
    }

    public function generate(): bool
    {
        // All frontend generation is now handled by individual generators:
        // - ListPageGenerator
        // - CreatePageGenerator, CreateFormGenerator
        // - EditPageGenerator, EditFormGenerator
        // - ViewLayoutGenerator, ViewOverviewGenerator
        // - ViewModalGenerator
        // - CustomFeatureTabComponentGenerator, CustomFeatureModalComponentGenerator
        // - FrontendRoutesGenerator
        // This class is kept for backward compatibility but no longer generates anything
        // directly — individual generators are invoked by ModuleScaffolder instead.
        (new ViewModalGenerator($this->moduleName, $this->moduleGroup, $this->config))->generate();
        return true;
    }

    // List feature generation removed - now handled by ListPageGenerator

    protected function generateColumns(array $config): string
    {
        $fields = $config['columns'] ?? [];
        $columns = [];
        
        // Find primary column (first field or explicitly marked)
        $primaryField = $this->findPrimaryField($fields);
        $primaryKey = $primaryField['name'];
        $primaryTitle = $primaryField['title'] ?? ucfirst($primaryKey);
        
        // Primary column
        $columns[] = "{ key: \"{$primaryKey}\", title: \"{$primaryTitle}\", sortable: true, class: \"min-w-[200px]\" }";
        
        // Other columns based on fields
        foreach ($fields as $field) {
            if (($field['name']) === $primaryKey) continue; // Skip primary field as it's already added
            
            $column = $this->generateColumn($field);
            if ($column) {
                $columns[] = $column;
            }
        }
        
        // Actions column
        $columns[] = '{ key: "actions", title: "Actions", class: "w-[120px] text-right" }';
        
        return implode(",\n\t", $columns);
    }

    protected function generateColumn(array $field): ?string
    {
        $key = $field['name'];
        $sortable = $field['sortable'] ?? true;
        $responsive = $field['responsive'] ?? 'lg';
        $minWidth = $field['min_width'] ?? '120px';
        $type = $field['type'] ?? 'text';
        $display = $field['display'] ?? 'text';
        
        $class = "hidden {$responsive}:table-cell min-w-[{$minWidth}]";
        
        // For relationships, add data mapping and proper title
        if ($type === 'foreignKey') {
            $relationship = $field['relationship'] ?? str_replace('_id', '', $key);
            $displayField = $field['displayField'] ?? 'name';
            $data = "{$relationship}.{$displayField}";
            
            // Generate proper title for relationship fields
            $title = $field['title'] ?? ucfirst($relationship);
            
            return "{ key: \"{$key}\", title: \"{$title}\", sortable: " . ($sortable ? 'true' : 'false') . ", class: \"{$class}\", data: \"{$data}\" }";
        }
        
        // For regular fields, use the provided title or generate from key
        $title = $field['title'] ?? ucfirst($key);
        
        // For boolean fields
        if ($type === 'boolean') {
            return "{ key: \"{$key}\", title: \"{$title}\", sortable: " . ($sortable ? 'true' : 'false') . ", class: \"{$class}\" }";
        }
        
        return "{ key: \"{$key}\", title: \"{$title}\", sortable: " . ($sortable ? 'true' : 'false') . ", class: \"{$class}\" }";
    }

    protected function generateCustomCellRenderers(array $config): string
    {
        $fields = $config['fields'] ?? [];
        $renderers = [];
        
        foreach ($fields as $field) {
            if (isset($field['custom_renderer']) && $field['custom_renderer']) {
                $renderer = $this->generateCustomCellRenderer($field);
                if ($renderer) {
                    $renderers[] = $renderer;
                }
            }
        }
        
        return implode("\n\n\t\t", $renderers);
    }

    protected function generateCustomCellRenderer(array $field): ?string
    {
        $key = $field['name'];
        $type = $field['type'] ?? 'text';
        
        switch ($type) {
            case 'relationship':
                return $this->generateRelationshipCellRenderer($field);
            case 'badge':
                return $this->generateBadgeCellRenderer($field);
            case 'boolean':
                return $this->generateBooleanCellRenderer($field);
            default:
                return null;
        }
    }

    protected function generateRelationshipCellRenderer(array $field): string
    {
        $key = $field['key'] ?? $field['name'];
        $relationship = $field['relationship'] ?? str_replace('_id', '', $key);
        $display = $field['display'] ?? 'text';
        $displayField = $field['displayField'] ?? 'name';
        
        // Support for nested relationships and multiple display fields
        if (is_array($relationship) && isset($relationship['display_fields'])) {
            return $this->generateComplexRelationshipRenderer($key, $relationship);
        }
        
        // Badge display for relationships
        if ($display === 'badge') {
            return "<!-- Relationship badge renderer for {$key} -->
            <template #cell-{$key}=\"{ item }\">
                <div class=\"flex items-center space-x-2\">
                    <Badge v-if=\"item.{$relationship}\" variant=\"outline\" class=\"px-3 py-1 flex items-center gap-1\">
                        {{ item.{$relationship}.{$displayField} }}
                    </Badge>
                </div>
            </template>";
        }
        
        // Default relationship display
        return "<!-- Relationship renderer for {$key} -->
        <template #cell-{$key}=\"{ item }\">
            <div class=\"flex items-center space-x-2\">
                <span v-if=\"item.{$relationship}\">{{ item.{$relationship}.{$displayField} }}</span>
                <span v-else>N/A</span>
            </div>
        </template>";
    }

    protected function generateComplexRelationshipRenderer(string $key, array $config): string
    {
        $model = $config['model'] ?? $key;
        $displayFields = $config['display_fields'] ?? ['name'];
        $colorField = $config['color_field'] ?? null;
        $badge = $config['badge'] ?? false;
        
        $colorIndicator = '';
        if ($colorField) {
            $colorPath = str_replace('.', '?.', $colorField);
            $colorIndicator = "<div 
                v-if=\"item.{$model}?.{$colorPath}\"
                class=\"w-3 h-3 rounded-full border\"
                :style=\"{ backgroundColor: item.{$model}.{$colorPath} }\"
            ></div>";
        }
        
        $displayContent = [];
        foreach ($displayFields as $field) {
            $fieldPath = str_replace('.', '?.', $field);
            $displayContent[] = "{{ item.{$model}?.{$fieldPath} || 'Unknown' }}";
        }
        
        if ($badge) {
            return "<!-- Custom cell renderer for {$key} -->
            <template #cell-{$key}=\"{ item }\">
                <Badge v-if=\"item.{$model}\" variant=\"outline\">
                    " . implode(' • ', $displayContent) . "
                </Badge>
            </template>";
        }
        
        return "<!-- Custom cell renderer for {$key} -->
        <template #cell-{$key}=\"{ item }\">
            <div class=\"flex items-center space-x-2\">
                {$colorIndicator}
                <div>
                    <div class=\"font-medium\">" . $displayContent[0] . "</div>
                    " . (count($displayContent) > 1 ? "<div class=\"text-sm text-muted-foreground\">" . implode(' • ', array_slice($displayContent, 1)) . "</div>" : "") . "
                </div>
            </div>
        </template>";
    }

    protected function generateSimpleRelationshipRenderer(string $key, string $relationshipKey): string
    {
        return "<!-- Custom cell renderer for {$key} -->
        <template #cell-{$key}=\"{ item }\">
            <div class=\"flex items-center space-x-2\">
                <div v-if=\"item.{$relationshipKey}?.color\" :style=\"{ backgroundColor: item.{$relationshipKey}.color }\"
                    class=\"w-3 h-3 rounded-full\"></div>
                <span>{{ item.{$relationshipKey}?.name || 'N/A' }}</span>
            </div>
        </template>";
    }

    protected function generateBadgeCellRenderer(array $field): string
    {
        $key = $field['key'] ?? $field['name'];
        
        return "<!-- Custom cell renderer for {$key} -->
		<template #cell-{$key}=\"{ item }\">
			<div class=\"flex items-center space-x-2\">
				<Badge
					v-if=\"item.{$key}\"
					variant=\"outline\"
					class=\"px-3 py-1 flex items-center gap-1\"
				>
					{{ item.{$key} }}
				</Badge>
			</div>
		</template>";
    }

    protected function generateBooleanCellRenderer(array $field): string
    {
        $key = $field['key'] ?? $field['name'];
        
        return "<!-- Custom cell renderer for {$key} -->
		<template #cell-{$key}=\"{ item }\">
			<div class=\"flex items-center space-x-2\">
				<Badge
					variant=\"outline\"
					class=\"px-3 py-1 flex items-center gap-1\"
					:class=\"item.{$key} ? 'bg-green-50 text-green-700 border-green-200' : 'bg-gray-50 text-gray-700 border-gray-200'\"
				>
					<component :is=\"icons[item.{$key} ? 'CheckIcon' : 'XIcon']\" class=\"h-3 w-3\" />
					{{ item.{$key} ? 'Yes' : 'No' }}
				</Badge>
			</div>
		</template>";
    }

    protected function findPrimaryField(array $fields): array
    {
        // Look for explicitly marked primary field
        foreach ($fields as $field) {
            if (isset($field['primary']) && $field['primary']) {
                return $field;
            }
        }
        
        // If no explicit primary, use first field
        return $fields[0];
    }

    protected function generatePrimaryCellContent(array $config): string
    {
        $fields = $config['columns'] ?? [];
        $primaryField = $this->findPrimaryField($fields);
        $primaryKey = $primaryField['key'] ?? $primaryField['name'] ?? 'name';
        
        $content = [];
        
        // Add responsive content for other fields
        foreach ($fields as $field) {
            if (($field['name']) === $primaryKey) continue; // Skip primary field itself
            
            $key = $field['key'] ?? $field['name'];
            $label = $field['label'] ?? $field['title'] ?? ucfirst($key);
            $showInPrimary = $field['show_in_primary'] ?? true; // Default to true for responsive design
            
            if ($showInPrimary) {
                if ($field['type'] === 'relationship') {
                    $relationship = $field['relationship'] ?? $key;
                    $content[] = $this->generateRelationshipPrimaryContent($field, $relationship, $label);
                } elseif ($field['type'] === 'boolean') {
                    $content[] = $this->generateBooleanPrimaryContent($field, $key, $label);
                } else {
                    $content[] = "<div class=\"text-xs text-muted-foreground lg:hidden\">
					{$label}: {{ item.{$key} || 'N/A' }}
				</div>";
                }
            }
        }
        
        return implode("\n\t\t\t\t", $content);
    }

    protected function generateRelationshipPrimaryContent(array $field, string $relationship, string $label): string
    {
        $key = $field['key'] ?? $field['name'];
        
        if (isset($field['color_field']) && $field['color_field']) {
            return "<div class=\"flex items-center gap-1 text-xs text-muted-foreground lg:hidden\">
					{$label}:
					<div v-if=\"item.{$relationship}?.color\" :style=\"{ backgroundColor: item.{$relationship}.color }\"
						class=\"w-3 h-3 rounded-full\"></div>
					{{ item.{$relationship}?.name || 'N/A' }}
				</div>";
        } else {
            return "<div class=\"text-xs text-muted-foreground lg:hidden\">
					{$label}: {{ item.{$relationship}?.name || 'N/A' }}
				</div>";
        }
    }

    protected function generateBooleanPrimaryContent(array $field, string $key, string $label): string
    {
        return "<div class=\"text-xs text-muted-foreground lg:hidden\">
				{$label}: {{ item.{$key} ? 'Yes' : 'No' }}
			</div>";
    }

    protected function generateStats(array $config): string
    {
        $stats = $config['stats'] ?? [];
        
        if (empty($stats)) {
            // Default stats
            return "{
		title: \"Total [[ModuleName]]\",
		value: stats_data.value?.total_[[moduleName]] || 0,
		icon: icons['BuildingIcon'],
		color: \"text-blue-600\",
		bgColor: \"bg-blue-50\",
	}";
        }
        
        $statItems = [];
        foreach ($stats as $stat) {
            $title = $stat['title'] ?? 'Stat';
            $value = $stat['value'] ?? 'total_[[moduleName]]';
            $icon = $stat['icon'] ?? 'BuildingIcon';
            $color = $stat['color'] ?? 'text-blue-600';
            $bgColor = $stat['bg_color'] ?? 'bg-blue-50';
            
            $statItems[] = "{
		title: \"{$title}\",
		value: stats_data.value?.{$value} || 0,
		icon: icons['{$icon}'],
		color: \"{$color}\",
		bgColor: \"{$bgColor}\",
	}";
        }
        
        return implode(",\n\t", $statItems);
    }

    protected function generateAdditionalImports(array $config): string
    {
        $imports = [];
        
        // Check if Badge is needed
        $fields = $config['fields'] ?? [];
        foreach ($fields as $field) {
            if (($field['type'] ?? 'text') === 'badge' || ($field['type'] ?? 'text') === 'boolean') {
                $imports[] = 'import { Badge } from "@/components/ui/badge";';
                break;
            }
        }
        
        return implode("\n", $imports);
    }

    // All frontend generation has been moved to individual generators:
    // - ListPageGenerator
    // - CreatePageGenerator, CreateFormGenerator
    // - EditPageGenerator, EditFormGenerator
    // - ViewLayoutGenerator, ViewOverviewGenerator
    // - CustomFeatureTabComponentGenerator, CustomFeatureModalComponentGenerator
    // - FrontendRoutesGenerator
    // This class is kept for backward compatibility but no longer generates files
    // All helper methods have been moved to BaseComponentGenerator
}
