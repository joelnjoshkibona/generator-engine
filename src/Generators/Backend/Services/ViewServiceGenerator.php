<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;

class ViewServiceGenerator extends BaseServiceGenerator
{
    public function generate(): bool
    {
        $backendConfig = $this->config['features']['backend']['view'] ?? null;
        if (empty($backendConfig)) {
            return false; // Feature not enabled
        }

        $content = $this->getTemplateContent('Features/view/service', 'backend');

        $replacements = [
            // withTrashed() only compiles when the model actually `use`s
            // SoftDeletes — calling it unconditionally threw a
            // BadMethodCallException for every module without
            // has_soft_deletes, since Eloquent's __callStatic() has no such
            // method to forward to. ModuleConfigContract::hasSoftDeletes()
            // is the single sanctioned place to read this fact (see its own
            // docblock) rather than re-deriving it here.
            '[[withTrashedCall]]'        => ModuleConfigContract::hasSoftDeletes($this->config) ? 'withTrashed()->' : '',
            '[[eagerLoadRelationships]]' => $this->generateEagerLoadRelationships('view'),
            '[[inlineItemsLoad]]'        => $this->generateInlineItemsLoad(),
        ];
        
        $content = $this->replacePlaceholders($content, $replacements);
        
        $serviceName = $this->moduleName . 'ViewService';
        $filePath = "{$this->modulePath}/Services/{$serviceName}.php";
        
        return $this->writeFile($filePath, $content);
    }

    /**
     * Bug (found live 2026-08-18, PurchaseOrders' View/Edit modals): an
     * inline_items row's own FK fields (e.g. PurchaseOrderItems.item_id)
     * were never eager-loaded here -- the frontend's InlineItemsComponent
     * only ever resolves a select/api-select field's display label from an
     * in-memory `{field}_object` the PICKER itself attaches at
     * selection-time (handleSelectedObject()); that object is never
     * persisted server-side (not a real column), so the moment a record is
     * reloaded from the API -- View, or Edit reusing View's own data load --
     * every child row's FK field silently fell back to rendering the raw
     * numeric id ("1", "2") instead of the related record's name.
     *
     * The child model already has a real belongsTo() relation for every one
     * of its own foreignId columns (ModelGenerator::
     * generateAutoRelationshipsFromForeignIds()) -- eager-loading it and
     * re-attaching it under the exact `{field}_object` key the frontend
     * already expects needs zero frontend changes at all.
     */
    private function generateInlineItemsLoad(): string
    {
        $inlineItems = $this->config['inline_items'] ?? [];
        if (empty($inlineItems)) {
            return '';
        }

        $lines = [];
        foreach ($inlineItems as $item) {
            $key        = $item['key'];
            $parentFk   = $item['parent_fk'];
            $childNs    = $this->buildChildNamespace($item['child_module']);
            $modelClass = "\\{$childNs}\\{$item['child_module']}Model";

            $fkFields = $this->extractInlineItemFkFields($item['fields'] ?? []);

            if (empty($fkFields)) {
                $lines[] = "\$data['{$key}'] = {$modelClass}::where('{$parentFk}', \$model->id)->get()->toArray();";
                continue;
            }

            $relationMethods = array_values(array_unique(array_column($fkFields, 'relation')));
            $withList        = "['" . implode("', '", $relationMethods) . "']";

            $mapLines = [];
            foreach ($fkFields as $fkField) {
                $mapLines[] = "\$row['{$fkField['key']}_object'] = \$childRow->{$fkField['relation']} ? \$childRow->{$fkField['relation']}->toArray() : null;";
            }
            $mapBody = implode("\n                ", $mapLines);

            $lines[] = "\$data['{$key}'] = {$modelClass}::where('{$parentFk}', \$model->id)->with({$withList})->get()->map(function (\$childRow) {\n"
                . "                \$row = \$childRow->toArray();\n"
                . "                {$mapBody}\n"
                . "                return \$row;\n"
                . "            })->toArray();";
        }

        return implode("\n        ", $lines);
    }

    /**
     * Which of an inline_items entry's own fields[] are select/api-select
     * (FK-shaped) -- these are exactly the fields whose display in the
     * frontend depends on a `{field}_object`, and exactly the ones whose
     * column name (matching a real foreignId column on the child model)
     * corresponds to a real belongsTo() relation method. Relation method
     * name derivation mirrors ModelGenerator::deriveRelationshipMethodName()
     * EXACTLY (strip a trailing `_id`, camelCase) -- these must stay in sync,
     * since this reads the SAME relation that method's own output defines.
     *
     * @return array<int, array{key: string, relation: string}>
     */
    private function extractInlineItemFkFields(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            $type = $field['type'] ?? '';
            if ($type !== 'select' && $type !== 'api-select') {
                continue;
            }
            $key = $field['key'] ?? null;
            if (!$key) {
                continue;
            }
            $base     = str_ends_with($key, '_id') ? substr($key, 0, -3) : $key;
            $relation = lcfirst(\Illuminate\Support\Str::camel($base));
            $result[] = ['key' => $key, 'relation' => $relation];
        }
        return $result;
    }
}

