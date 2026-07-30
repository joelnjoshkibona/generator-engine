<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services\Delegation;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\BaseServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use Illuminate\Support\Str;

class DelegationServiceGenerator extends BaseServiceGenerator
{
    protected array $delegation;
    protected string $delegationKey;
    protected string $relatedModuleName;

    /**
     * The related module's declared sub-group, as supplied by the caller
     * (e.g. parsed from the blueprint) -- null when the caller didn't
     * declare one. Kept distinct from the literal string 'Core' so
     * resolveRelatedModuleGroupPath() can tell "no sub-group hint at all"
     * apart from "caller explicitly said this lives flat under Core".
     */
    protected ?string $relatedModuleGroup;

    public function __construct(
        string $moduleName,
        string $moduleGroup = 'Core',
        array $config = [],
        string $delegationKey = '',
        array $delegation = []
    ) {
        parent::__construct($moduleName, $moduleGroup, $config);

        $this->delegationKey = $delegationKey;
        $this->delegation = $delegation;

        $relatedModule = $delegation['relatedModule'] ?? null;
        if ($relatedModule && is_array($relatedModule)) {
            $this->relatedModuleName = $relatedModule['name'] ?? '';
            $this->relatedModuleGroup = $relatedModule['group'] ?? null;
        } else {
            $this->relatedModuleName = is_string($relatedModule) ? $relatedModule : '';
            $this->relatedModuleGroup = null;
        }
    }

    /**
     * Resolve "Group\SubGroup" (e.g. "System\Custom") for the related
     * module's `use` import.
     *
     * Resolution order:
     *   1. Self-delegation (e.g. Accounts.children → Accounts): the caller
     *      only appends a module to PathManager's array registry *after* it
     *      finishes generating, so a module can't find *itself* there yet
     *      while it's still being generated. This module's own group/
     *      sub-group is already known directly -- use it, no lookup needed.
     *   2. Registry / default_modules.json, via PathManager -- authoritative
     *      whenever the related module is already known.
     *   3. The sub-group the caller declared for the related module
     *      ($this->relatedModuleGroup), combined with this module's own
     *      top-level group. This is the common case for delegations:
     *      unlike auto-derived FK relations (topologically sorted so the
     *      target always precedes the module referencing it), a delegation
     *      routinely points from a parent module (FK target, scaffolded
     *      first) to a child module (FK source, scaffolded later) that
     *      simply isn't in the registry yet. The caller-declared sub-group
     *      predates and doesn't depend on generation order -- it comes
     *      straight from the blueprint -- and every delegation observed in
     *      practice targets a sibling scaffolded into the same top-level
     *      group as the module declaring it.
     *   4. None of the above resolves: a delegation is an explicit, human/
     *      blueprint-authored declaration, not a guess -- fail loudly rather
     *      than silently emit a `use App\Project\Modules\Core\{Module}` that
     *      references a class that doesn't exist there. Mirrors
     *      ModelGenerator::resolveManualRelationModuleGroup().
     */
    protected function resolveRelatedModuleGroupPath(): string
    {
        if (empty($this->relatedModuleName)) {
            return $this->relatedModuleGroup ?? '';
        }

        if ($this->relatedModuleName === $this->moduleName) {
            return $this->moduleSubGroup
                ? "{$this->moduleGroup}\\{$this->moduleSubGroup}"
                : $this->moduleGroup;
        }

        $fullNs = PathManager::resolveBackendModuleNamespaceOrNull($this->relatedModuleName);
        if ($fullNs !== null) {
            return str_replace(
                ['App\\Project\\Modules\\', "\\{$this->relatedModuleName}"],
                '',
                $fullNs
            );
        }

        if (!empty($this->relatedModuleGroup)) {
            return $this->moduleGroup . '\\' . Str::studly($this->relatedModuleGroup);
        }

        throw new \RuntimeException(
            "Cannot resolve related module '{$this->relatedModuleName}' declared in delegation " .
            "'{$this->delegationKey}' on module '{$this->moduleName}': no matching module found in " .
            "the registry or shell defaults, and no sub-group was declared for it either. Check for " .
            "a typo in the related module name, or generate that module first."
        );
    }

    public function generate(): bool
    {
        $content = $this->getTemplateContent('Features/delegation/service', 'backend');

        $delegationName = Str::studly($this->delegation['name'] ?? $this->delegationKey);
        $parentKey = $this->delegation['parentKey'] ?? 'uuid';
        $filterKey = $this->delegation['filterKey'] ?? 'parent_id';
        $parentIdField = $this->delegation['parentIdField'] ?? 'id';
        $defaults = $this->buildDefaultsArray($this->delegation['defaults'] ?? []);

        // Resolve the full namespace path for the related module (e.g. "System\Custom")
        $relatedModuleGroupPath = $this->resolveRelatedModuleGroupPath();

        $replacements = [
            '[[DelegationName]]' => $delegationName,
            '[[RelatedModuleName]]' => $this->relatedModuleName,
            '[[RelatedModuleGroup]]' => $relatedModuleGroupPath,
            '[[parentKey]]' => $parentKey,
            '[[filterKey]]' => $filterKey,
            '[[parentIdField]]' => $parentIdField,
            '[[defaults]]' => $defaults,
            '[[filterableFields]]' => $this->buildFilterableFields(),
            '[[sortableFields]]' => $this->buildSortableFields(),
            '[[filterableRelationships]]' => $this->buildFilterableRelationships(),
            '[[eagerLoadRelationships]]' => $this->buildEagerLoadRelationships('list'),
            '[[listMethod]]' => $this->buildListMethod($parentKey, $filterKey, $parentIdField),
            '[[createMethod]]' => $this->buildCreateMethod($parentKey, $parentIdField, $filterKey),
            '[[editMethod]]' => $this->buildEditMethod($parentKey, $parentIdField),
            '[[viewMethod]]' => $this->buildViewMethod($parentKey, $parentIdField),
            '[[deleteMethod]]' => $this->buildDeleteMethod($parentKey, $parentIdField),
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        // Dedupe `use` statements — when a delegation self-references its owning module
        // (e.g. Accounts.children → Accounts, PayrollDepartments.children → PayrollDepartments)
        // the stub's two imports (module + related module) collapse to the same FQN,
        // producing a PHP "Duplicate use" warning. Collapse back-to-back identical imports.
        $content = $this->dedupeUseStatements($content);

        $serviceName = $this->moduleName . $delegationName . 'Service';
        $filePath = "{$this->modulePath}/Services/{$serviceName}.php";

        return $this->writeFile($filePath, $content);
    }

    /**
     * Collapse duplicate `use Foo\Bar\Baz;` lines in the generated source.
     * Preserves order and first-occurrence only.
     */
    protected function dedupeUseStatements(string $content): string
    {
        $lines = explode("\n", $content);
        $seenUses = [];
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^use\s+[^;]+;\s*$/', trim($line))) {
                $normalized = trim($line);
                if (isset($seenUses[$normalized])) {
                    continue; // skip duplicate
                }
                $seenUses[$normalized] = true;
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    private function buildDefaultsArray(array $defaults): string
    {
        if (empty($defaults)) {
            return '[]';
        }
        $parts = [];
        foreach ($defaults as $key => $value) {
            if (is_string($value)) {
                $parts[] = "'{$key}' => '{$value}'";
            } elseif (is_bool($value)) {
                $parts[] = "'{$key}' => " . ($value ? 'true' : 'false');
            } elseif (is_null($value)) {
                $parts[] = "'{$key}' => null";
            } else {
                $parts[] = "'{$key}' => {$value}";
            }
        }
        return '[' . implode(', ', $parts) . ']';
    }

    private function buildFilterableFields(): string
    {
        $listBackend = $this->delegation['operations']['list']['backend'] ?? [];

        $filterFields = $listBackend['filterFields'] ?? [];
        if (!empty($filterFields)) {
            $fields = array_filter(array_map(fn($f) => !empty($f['key']) ? "'{$f['key']}'" : null, $filterFields));
            return '[' . implode(', ', $fields) . ']';
        }

        $filterableFields = $listBackend['filterableFields'] ?? '';
        if (!empty($filterableFields)) {
            $fields = array_map(fn($f) => "'" . trim($f) . "'", explode(',', $filterableFields));
            return '[' . implode(', ', $fields) . ']';
        }

        return '[]';
    }

    private function buildSortableFields(): string
    {
        $sortableFields = $this->delegation['operations']['list']['backend']['sortableFields'] ?? '';

        if (!empty($sortableFields)) {
            if (is_string($sortableFields)) {
                $fields = array_map(fn($f) => "'" . trim($f) . "'", explode(',', $sortableFields));
                return '[' . implode(', ', $fields) . ']';
            }
            if (is_array($sortableFields)) {
                $fields = array_map(fn($f) => "'{$f}'", $sortableFields);
                return '[' . implode(', ', $fields) . ']';
            }
        }

        return $this->buildFilterableFields();
    }

    private function buildFilterableRelationships(): string
    {
        $filterFields = $this->delegation['operations']['list']['backend']['filterFields'] ?? [];
        $explicit = $this->delegation['operations']['list']['backend']['filterableRelationships'] ?? [];

        $relationships = [];

        foreach ($filterFields as $field) {
            $key = $field['key'] ?? '';
            if (str_ends_with($key, '_id')) {
                $rel = str_replace('_id', '', $key);
                $relationships[$rel] = ['id', 'name'];
            }
        }

        if (is_array($explicit)) {
            foreach ($explicit as $rel => $fields) {
                $relationships[$rel] = is_array($fields) ? $fields : ['id', 'name'];
            }
        }

        if (empty($relationships)) {
            return '[]';
        }

        $parts = [];
        foreach ($relationships as $rel => $fields) {
            $fieldStrs = array_map(fn($f) => "'{$f}'", $fields);
            $parts[] = "'{$rel}' => [" . implode(', ', $fieldStrs) . ']';
        }
        return '[' . implode(', ', $parts) . ']';
    }

    private function buildEagerLoadRelationships(string $op): string
    {
        // Same fix as BaseServiceGenerator::generateEagerLoadRelationships()
        // (this class doesn't reuse that method directly -- it reads from
        // $this->delegation['operations'][$op] rather than
        // $this->config['features']['backend'][$feature]) -- 'creator'/
        // 'updater' are only real relationships when creator/updater
        // tracking is actually in use, or the eager-load on the RELATED
        // module's model throws RelationNotFoundException.
        //
        // No per-related-module has_creator_updater signal exists anywhere
        // in this generator (delegation config only carries
        // relatedModule.name/group, never the related module's own schema
        // meta) -- $this->config (the PARENT module's own flag) is used as
        // the best available proxy, consistent with this being a
        // project-wide convention in practice (confirmed: no real module in
        // this codebase mixes has_creator_updater true and false).
        $creatorUpdater = ModuleConfigContract::hasCreatorUpdater($this->config)
            ? ["'creator'", "'updater'"]
            : [];

        $eagerLoad = $this->delegation['operations'][$op]['backend']['eagerLoadRelationships'] ?? '';

        if (!empty($eagerLoad) && is_string($eagerLoad)) {
            $rels = array_filter(array_map('trim', explode(',', $eagerLoad)));
            $all = array_merge(array_map(fn($r) => "'{$r}'", $rels), $creatorUpdater);
            return empty($all) ? '[]' : '[' . implode(', ', $all) . ']';
        }

        return empty($creatorUpdater) ? '[]' : '[' . implode(', ', $creatorUpdater) . ']';
    }

    private function buildValidationRules(string $op): string
    {
        $fields = $this->delegation['operations'][$op]['backend']['fields'] ?? [];
        if (empty($fields)) {
            return '[]';
        }
        $rules = [];
        foreach ($fields as $field) {
            $name = $field['field'] ?? '';
            $fieldRules = $field['rules'] ?? '';
            if (!empty($name) && !empty($fieldRules)) {
                $ruleArr = array_map(fn($r) => '"' . trim($r) . '"', array_filter(explode('|', $fieldRules)));
                $rules[] = "'{$name}' => [" . implode(', ', $ruleArr) . ']';
            }
        }
        return '[' . implode(",\n            ", $rules) . ']';
    }

    private function buildValidationMessages(string $op): string
    {
        $fields = $this->delegation['operations'][$op]['backend']['fields'] ?? [];
        if (empty($fields)) {
            return '[]';
        }
        $messages = [];
        foreach ($fields as $field) {
            $name = $field['field'] ?? '';
            foreach ($field['messages'] ?? [] as $msg) {
                $ruleKey = $msg['ruleKey'] ?? '';
                $text = $msg['message'] ?? '';
                if (!empty($ruleKey) && !empty($text)) {
                    $messages[] = "'{$name}.{$ruleKey}' => '{$text}'";
                }
            }
        }
        return '[' . implode(",\n            ", $messages) . ']';
    }

    private function buildListMethod(string $parentKey, string $filterKey, string $parentIdField): string
    {
        if (empty($this->delegation['operations']['list']['enabled'])) {
            return '';
        }

        $filterFields = $this->buildFilterFieldsArray();

        return <<<PHP

    public function list(string \${$parentKey}, array \$params = []): array
    {
        try {
            \$parent = {$this->moduleName}Model::where('{$parentKey}', \${$parentKey})->firstOrFail();

            \$data = ['params' => \$params['params'] ?? [], 'filters' => \$params['filters'] ?? []];
            \$validData = validator(\$data, [
                'filters' => ['nullable', 'array'],
                'params' => ['nullable', 'array'],
                'params.page' => ['nullable', 'integer'],
                'params.per_page' => ['nullable', 'integer'],
                'params.sort' => ['nullable', 'string'],
                'params.order' => ['nullable', 'string'],
            ])->validate();

            \$query = {$this->relatedModuleName}Model::query();
            \$query->where('{$filterKey}', \$parent->{$parentIdField});

            \$validData = self::processListQuery(\$validData, \$query);
            \$validData['filterFields'] = {$filterFields};

            return Helpers::success(\$validData, 'Records fetched successfully');
        } catch (ApplicationException \$e) {
            return Helpers::error(\$e->getMessage(), 500, \$e->getData());
        } catch (\Exception \$e) {
            throw \$e;
        }
    }
PHP;
    }

    private function buildFilterFieldsArray(): string
    {
        $filterFields = $this->delegation['operations']['list']['backend']['filterFields'] ?? [];
        if (empty($filterFields)) {
            return '[]';
        }
        $parts = [];
        foreach ($filterFields as $field) {
            $key = $field['key'] ?? '';
            $label = $field['label'] ?? $key;
            $type = $field['type'] ?? 'text';
            if (!empty($key)) {
                $parts[] = "['key' => '{$key}', 'label' => '{$label}', 'type' => '{$type}']";
            }
        }
        return '[' . implode(', ', $parts) . ']';
    }

    private function buildCreateMethod(string $parentKey, string $parentIdField, string $filterKey): string
    {
        if (empty($this->delegation['operations']['create']['enabled'])) {
            return '';
        }

        $validationRules = $this->buildValidationRules('create');
        $validationMessages = $this->buildValidationMessages('create');

        return <<<PHP

    public function create(string \${$parentKey}, array \$data): array
    {
        DB::beginTransaction();
        try {
            \$parent = {$this->moduleName}Model::where('{$parentKey}', \${$parentKey})->firstOrFail();

            \$validData = validator(\$data, {$validationRules}, {$validationMessages})->validate();
            \$validData['{$filterKey}'] = \$parent->{$parentIdField};
            \$validData['created_by_id'] = Auth::id();

            \$model = {$this->relatedModuleName}Model::create(\$validData);
            DB::commit();
            return Helpers::success(\$model->fresh(), 'Record created successfully');
        } catch (ApplicationException \$e) {
            DB::rollBack();
            return Helpers::error(\$e->getMessage(), 500, \$e->getData());
        } catch (\Exception \$e) {
            DB::rollBack();
            throw \$e;
        }
    }
PHP;
    }

    private function buildEditMethod(string $parentKey, string $parentIdField): string
    {
        if (empty($this->delegation['operations']['edit']['enabled'])) {
            return '';
        }

        $validationRules = $this->buildValidationRules('edit');
        $validationMessages = $this->buildValidationMessages('edit');

        return <<<PHP

    public function edit(string \${$parentKey}, string \$itemUuid, array \$data): array
    {
        DB::beginTransaction();
        try {
            \$parent = {$this->moduleName}Model::where('{$parentKey}', \${$parentKey})->firstOrFail();
            \$model = {$this->relatedModuleName}Model::where('uuid', \$itemUuid)->firstOrFail();

            \$validData = validator(\$data, {$validationRules}, {$validationMessages})->validate();
            \$validData['updated_by_id'] = Auth::id();

            \$model->update(\$validData);
            DB::commit();
            return Helpers::success(\$model->fresh(), 'Record updated successfully');
        } catch (ApplicationException \$e) {
            DB::rollBack();
            return Helpers::error(\$e->getMessage(), 500, \$e->getData());
        } catch (\Exception \$e) {
            DB::rollBack();
            throw \$e;
        }
    }
PHP;
    }

    private function buildViewMethod(string $parentKey, string $parentIdField): string
    {
        if (empty($this->delegation['operations']['view']['enabled'])) {
            return '';
        }

        $eagerLoad = $this->buildEagerLoadRelationships('view');

        return <<<PHP

    public function view(string \${$parentKey}, string \$itemUuid): array
    {
        try {
            \$parent = {$this->moduleName}Model::where('{$parentKey}', \${$parentKey})->firstOrFail();
            \$model = {$this->relatedModuleName}Model::where('uuid', \$itemUuid)->firstOrFail();
            \$model->load({$eagerLoad});
            return Helpers::success(\$model->toArray(), 'Record fetched successfully');
        } catch (ApplicationException \$e) {
            return Helpers::error(\$e->getMessage(), 500, \$e->getData());
        } catch (\Exception \$e) {
            throw \$e;
        }
    }
PHP;
    }

    private function buildDeleteMethod(string $parentKey, string $parentIdField): string
    {
        if (empty($this->delegation['operations']['delete']['enabled'])) {
            return '';
        }

        $successMessage = $this->delegation['operations']['delete']['backend']['success_message'] ?? 'Record deleted successfully';

        return <<<PHP

    public function delete(string \${$parentKey}, string \$itemUuid): array
    {
        DB::beginTransaction();
        try {
            \$parent = {$this->moduleName}Model::where('{$parentKey}', \${$parentKey})->firstOrFail();
            \$model = {$this->relatedModuleName}Model::where('uuid', \$itemUuid)->firstOrFail();
            \$model->delete();
            DB::commit();
            return Helpers::success([], '{$successMessage}');
        } catch (ApplicationException \$e) {
            DB::rollBack();
            return Helpers::error(\$e->getMessage(), 500, \$e->getData());
        } catch (\Exception \$e) {
            DB::rollBack();
            throw \$e;
        }
    }
PHP;
    }
}
