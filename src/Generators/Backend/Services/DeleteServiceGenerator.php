<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

class DeleteServiceGenerator extends BaseServiceGenerator
{
    public function generate(): bool
    {
        $backendConfig = $this->config['features']['backend']['delete'] ?? null;
        if (empty($backendConfig)) {
            return false; // Feature not enabled
        }

        $content = $this->getTemplateContent('Features/delete/service', 'backend');

        // Wire processor calls for before/after delete stages
        $beforeDeleteCode = $this->generateProcessorCalls('delete', 'before_delete');
        $afterDeleteCode = $this->generateProcessorCalls('delete', 'after_delete');

        $replacements = [
            '[[beforeDelete]]' => empty($beforeDeleteCode) ? '' : $beforeDeleteCode,
            '[[afterDelete]]' => empty($afterDeleteCode) ? '' : $afterDeleteCode,
            '[[inlineItemsCascadeDelete]]' => $this->generateInlineItemsCascadeDelete(),
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $serviceName = $this->moduleName . 'DeleteService';
        $filePath = "{$this->modulePath}/Services/{$serviceName}.php";

        return $this->writeFile($filePath, $content);
    }

    /**
     * Cascade-delete every inline_items child when the parent is deleted.
     *
     * Bug (found + fixed 2026-08-02, deferred at first as a design decision
     * -- see the package README's former "Known limitation" note): before
     * this, deleting a parent (e.g. an Order) left its inline_items children
     * (e.g. OrderItems) completely untouched -- orphaned, pointing at a
     * (possibly soft-deleted) parent forever.
     *
     * Cascade, unconditionally, is the correct default here specifically
     * because inline_items children have no independent lifecycle by
     * design: EditServiceGenerator's own sync logic already deletes any
     * child row not present in the parent form's payload on every edit (see
     * generateInlineItemsSync()) -- a child only ever exists because its
     * parent's form said so. That is a materially different relationship
     * than an arbitrary FK reference (where blocking, not cascading, is the
     * safer default -- see DeleteCheckServiceGenerator's generic FK-graph
     * dependent-count check, which already covers ANY *_id-column
     * relationship including a typical inline_items parent_fk, entirely
     * independent of inline_items awareness).
     *
     * `{ChildModel}::where($parentFk, $model->id)->delete()` automatically
     * does the right thing for the child's own delete mode (soft or hard)
     * because it goes through the child Eloquent model's own query builder,
     * which respects the SoftDeletes trait if present -- no extra
     * "child_has_soft_deletes" flag needed (unlike child_has_creator_updater,
     * which genuinely can't be inferred this way since Auth::id() has no
     * query-builder equivalent).
     *
     * Deliberately NOT wired into DeleteCheckServiceGenerator or as a guard
     * inside this same class: that would mean also making every
     * {Module}DeleteService call {Module}DeleteCheckService before
     * deleting, which is a much larger, separate architectural decision
     * (it would change delete behavior for every FK relationship in the
     * whole codebase, not just inline_items) that was not in scope here --
     * confirmed via a live SYSTEM_SHELL scratch module that the check
     * endpoint and the delete endpoint are already two independently
     * callable routes with no enforcement between them, for any module.
     */
    private function generateInlineItemsCascadeDelete(): string
    {
        $inlineItems = $this->config['inline_items'] ?? [];
        if (empty($inlineItems)) {
            return '';
        }

        $lines = [];
        foreach ($inlineItems as $item) {
            $key        = $item['key'];
            $parentFk   = $item['parent_fk'];
            $childNs    = $this->buildChildNamespace($item['child_group'], $item['child_module']);
            $modelClass = "\\{$childNs}\\{$item['child_module']}Model";

            $lines[] = "// Cascade delete {$key} -- inline_items children have no independent lifecycle.";
            $lines[] = "{$modelClass}::where('{$parentFk}', \$model->id)->delete();";
        }

        return implode("\n        ", $lines);
    }
}

