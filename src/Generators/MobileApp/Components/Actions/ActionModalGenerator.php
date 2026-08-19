<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Components\Actions;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\Actions\ActionComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

/**
 * Generates action Form + Page components for MOBILE_APP.
 *
 * Mirrors ActionComponentGenerator's generate() logic (real fields[]/wizard/
 * confirm-step support via the shared BaseComponentGenerator helpers this
 * class inherits) rather than the old bare-stub version -- that version
 * never mapped fields, wizard steps, or the real endpoint expression, so
 * every generated mobile action modal was permanently a TODO placeholder.
 *
 * Deliberately ALWAYS generates the page shape regardless of the action's
 * configured `uiType` -- mobile's Create/Edit forms are always full pages
 * too (no modal variant exists anywhere in MOBILE_APP), so an action
 * declared uiType: 'modal' for web still gets a real, reachable mobile page
 * instead of a dialog convention mobile doesn't otherwise use.
 */
class ActionModalGenerator extends ActionComponentGenerator
{
    protected function getModulePath(): string
    {
        return PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName);
    }

    public function generateAction(string $actionKey, array $action): bool
    {
        if (empty($action['hasUI'])) {
            return false;
        }

        $actionName  = Str::studly($action['name'] ?? $actionKey);
        $actionLabel = $action['label'] ?? $actionName;
        $actionRoute = Str::kebab($action['name'] ?? $actionKey);
        $moduleRoute = Str::kebab($this->moduleName);
        $permissionName = "{$this->moduleName}." . ($action['name'] ?? $actionKey);

        $rawFields = $action['fields'] ?? [];
        $mappedFields = !empty($rawFields) ? $this->mapNewFormFieldsToLegacy($rawFields) : [];

        $wizardConfig = $action['wizard'] ?? [];
        $isWizard = ($wizardConfig['enabled'] ?? false) === true && !empty($wizardConfig['steps']);
        $confirmStepConfig = $action['confirm_step'] ?? [];
        $requiresConfirmation = ($confirmStepConfig['enabled'] ?? $isWizard) === true;
        $confirmStepConfig['enabled'] = $requiresConfirmation;
        $actionConfirmCheckboxBlock = '';

        if ($isWizard) {
            [$actionFieldsBlock, , $hasFkFieldLabels] = $this->generateWizardSteps($wizardConfig, $mappedFields, [], $confirmStepConfig);
            $actionWizardStateBlock = $this->generateWizardStateBlock($wizardConfig, false, $confirmStepConfig, $hasFkFieldLabels);
            $actionWizardNavButtons = "<Button v-if=\"currentStep > 0\" type=\"button\" variant=\"outline\" size=\"sm\" @click=\"goBack\" :disabled=\"isSubmitting\">\n"
                . "\t\t\t\t{{ \$t('common.back') }}\n"
                . "\t\t\t</Button>\n\t\t\t"
                . "<Button v-if=\"currentStep < wizardSteps.length - 1\" type=\"button\" size=\"sm\" @click=\"goNext\" :disabled=\"isSubmitting\">\n"
                . "\t\t\t\t{{ \$t('common.next') }}\n"
                . "\t\t\t</Button>\n\t\t\t";
            $actionSubmitVIf = ' v-else';
            $actionSubmitDisabled = $requiresConfirmation ? 'isSubmitting || !confirmed' : 'isSubmitting';
        } elseif (!empty($mappedFields)) {
            $actionFieldsBlock = "<div class=\"grid grid-cols-1 gap-4\">\n"
                . $this->generateFieldsGrid($mappedFields)
                . "\n\t\t</div>";
            $actionWizardStateBlock = $requiresConfirmation ? "const confirmed = ref(false)\n" : '';
            $actionWizardNavButtons = '';
            $actionSubmitVIf = '';
            $actionSubmitDisabled = $requiresConfirmation ? 'isSubmitting || !confirmed' : 'isSubmitting';
            if ($requiresConfirmation) {
                $actionConfirmCheckboxBlock = "\n" . $this->generateConfirmCheckbox($confirmStepConfig);
            }
        } else {
            $actionFieldsBlock = '<p class="text-sm text-muted-foreground">Confirm to proceed.</p>';
            $actionWizardStateBlock = $requiresConfirmation ? "const confirmed = ref(false)\n" : '';
            $actionWizardNavButtons = '';
            $actionSubmitVIf = '';
            $actionSubmitDisabled = $requiresConfirmation ? 'isSubmitting || !confirmed' : 'isSubmitting';
            if ($requiresConfirmation) {
                $actionConfirmCheckboxBlock = "\n" . $this->generateConfirmCheckbox($confirmStepConfig);
            }
        }

        $actionFormFields = $this->generateFormFields(['fields' => $mappedFields]);
        $actionFormFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
        if ($isWizard) {
            $actionFormFieldImports .= "\nimport { Stepper } from '@/components/ui/stepper';";
        }
        if ($requiresConfirmation && !str_contains($actionFormFieldImports, 'CheckboxField')) {
            $actionFormFieldImports .= "\nimport CheckboxField from '@/components/form-fields/CheckboxField.vue';";
        }

        $replacements = [
            '[[ModuleName]]' => $this->moduleName,
            '[[ActionName]]' => $actionName,
            '[[ActionLabel]]' => $actionLabel,
            '[[actionRoute]]' => "/{$moduleRoute}/{$actionRoute}",
            '[[actionPermission]]' => $permissionName,
            '[[actionCancelLink]]' => "/{$moduleRoute}/list",
            '[[actionEndpointExpr]]' => $this->buildEndpointExpression($action, $moduleRoute, $actionRoute),
            '[[actionFieldsBlock]]' => $actionFieldsBlock,
            '[[actionConfirmCheckboxBlock]]' => $actionConfirmCheckboxBlock,
            '[[actionFormFields]]' => $actionFormFields,
            '[[actionFormFieldImports]]' => $actionFormFieldImports,
            '[[actionWizardStateBlock]]' => $actionWizardStateBlock,
            '[[actionWizardNavButtons]]' => $actionWizardNavButtons,
            '[[actionSubmitVIf]]' => $actionSubmitVIf,
            '[[actionSubmitDisabled]]' => $actionSubmitDisabled,
        ];

        // writeFileOnce(), matching web's own reasoning: an action's form
        // routinely needs hand-added logic today's fields[]/wizard config
        // can't express, and plain writeFile() would force-overwrite it on
        // every regenerate.
        $formWritten = $this->writeFileOnce(
            "{$this->modulePath}/Components/{$this->moduleName}{$actionName}Form.vue",
            $this->replacePlaceholders($this->getTemplateContent('features/action/form', 'mobile_app'), $replacements)
        );

        $pageWritten = $this->writeFileOnce(
            "{$this->modulePath}/{$this->moduleName}{$actionName}Page.vue",
            $this->replacePlaceholders($this->getTemplateContent('features/action/page', 'mobile_app'), $replacements)
        );

        return $formWritten || $pageWritten;
    }
}
