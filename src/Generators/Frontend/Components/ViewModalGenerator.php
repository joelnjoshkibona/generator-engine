<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Helpers\ActionConfigNormalizer;
use Illuminate\Support\Str;

class ViewModalGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        $content = $this->getTemplateContent('features/view/modal', 'frontend');
        $viewConfig = $this->config['features']['frontend']['view'] ?? [];
        $replacements = array_merge($this->buildActionReplacements(), [
            // Same source ViewLayoutGenerator uses for the details page's
            // title, so the modal and the page show the identical record name.
            '[[primaryDisplayField]]' => $viewConfig['titleData'] ?? 'name',
        ]);
        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$this->moduleName}ViewModal.vue";

        return $this->writeFile($filePath, $content);
    }

    /**
     * Render `config['actions']` into the view modal footer.
     *
     * Until now nothing in the frontend read `actions` at all: the backend
     * emitted a service, a controller method and a route, and the UI to reach
     * them was left for someone to hand-write — which is exactly what
     * UsersViewModal.vue is. This makes the config produce that shape.
     *
     * Placement follows the Users reference: `placement: "main"` puts the
     * button beside Edit, anything else becomes an item in the "More actions"
     * dropdown (the default, so a new action never crowds the primary row).
     *
     * @return array<string, string>
     */
    protected function buildActionReplacements(): array
    {
        $actions = ActionConfigNormalizer::normalizeAll($this->config['actions'] ?? []);
        $actions = array_values(array_filter(
            $actions,
            static fn (array $a): bool => $a['name'] !== '' && $a['hasUI']
        ));

        $mainButtons = [];
        $moreItems   = [];
        $imports     = [];
        $state       = [];
        $modals      = [];
        $permChecks  = [];

        foreach ($actions as $action) {
            $name       = $action['name'];
            $studly     = Str::studly($name);
            $kebab      = Str::kebab($name);
            $label      = $this->escapeForSingleQuotes($action['label']);
            $permission = "{$this->moduleName}.{$name}";
            $icon       = $action['icon'] !== '' ? $action['icon'] : 'ZapIcon';
            $openRef    = "{$name}Open";

            $permChecks[] = "hasPermission('{$permission}')";
            $state[]      = "const {$openRef} = ref(false)";

            // A 'page' action navigates instead of opening a dialog; only
            // 'modal' actions own a component here.
            $isModal = $action['uiType'] === 'modal';

            if ($isModal) {
                $imports[] = "import {$this->moduleName}{$studly}Form from './{$this->moduleName}{$studly}Form.vue'";
                $modals[]  = $this->renderModal($studly, $openRef, $label);
            }

            $click = $isModal
                ? "{$openRef} = true"
                : "router.push(`/" . Str::kebab($this->moduleName) . "/\${uuid}/{$kebab}`)";

            if ($action['placement'] === 'main') {
                $mainButtons[] = $this->renderMainButton($permission, $icon, $label, $click, $kebab);
            } else {
                $moreItems[] = $this->renderMoreItem($permission, $icon, $label, $click, $kebab, $action['destructive']);
            }
        }

        // Imports the injected markup depends on. The base stub imports only the
        // handful of icons it names directly, so anything action-driven has to
        // bring its own — otherwise the generated component references
        // `icons`/`AppDialog`/`router` that were never imported and the module
        // fails to compile.
        if ($actions !== []) {
            array_unshift($imports, "import * as icons from 'lucide-vue-next'");

            if ($modals !== []) {
                $imports[] = "import { AppDialog } from '@/components/app-dialog'";
            }

            $hasPageAction = array_filter($actions, static fn (array $a): bool => $a['uiType'] !== 'modal');
            if ($hasPageAction !== []) {
                array_unshift($imports, "import { useRouter } from 'vue-router'");
                array_unshift($state, 'const router = useRouter()');
            }
        }

        // The dropdown is shown when delete OR any more-placement action is
        // permitted, so it never renders as an empty menu — and never hides an
        // action just because the user cannot delete.
        $moreCondition = '';
        $moreOnly = array_values(array_filter(
            $actions,
            static fn (array $a): bool => $a['placement'] !== 'main'
        ));
        if ($moreOnly !== []) {
            $conds = array_map(fn (array $a): string => "hasPermission('{$this->moduleName}.{$a['name']}')", $moreOnly);
            $moreCondition = ' || ' . implode(' || ', $conds);
        }

        return array_merge($this->buildDelegationTabReplacements($imports), [
            '[[actionMainButtons]]'        => implode("\n", $mainButtons),
            '[[actionMoreItems]]'          => implode("\n", $moreItems),
            '[[actionMoreMenuCondition]]'  => $moreCondition,
            '[[actionImports]]'            => implode("\n", $imports),
            '[[actionState]]'              => $state === [] ? '' : implode("\n", $state),
            '[[actionModals]]'             => implode("\n", $modals),
        ]);
    }

    /**
     * Delegation tabs for the view modal's tab registry.
     *
     * The modal and the details page had independent tab sources: the page used
     * BaseComponentGenerator::generateTabNavigation() (delegation-aware), the
     * modal a hardcoded overview+history literal. A child list was therefore
     * reachable only by URL, never from the eye action most people use.
     *
     * @param array<int,string> $imports  appended to, so the tab components are imported
     * @return array<string,string>
     */
    protected function buildDelegationTabReplacements(array &$imports): array
    {
        $delegations = $this->config['delegations'] ?? [];
        $tabs = [];

        foreach ($delegations as $key => $delegation) {
            $uiType = $delegation['uiType'] ?? ($delegation['displayType'] ?? '');
            if ($uiType !== 'tab' && $uiType !== 'tab-action') {
                continue;
            }

            $rawName = $delegation['name'] ?? $key;
            // kebab id, matching FrontendRoutesGenerator's child route and
            // generateTabNavigation() — a StudlyCase id here would give the modal
            // a different tab id than the page for the same delegation.
            $id        = Str::kebab($rawName);
            $studly    = Str::studly($rawName);
            $label     = $this->escapeForSingleQuotes($delegation['label'] ?? $studly);
            $component = "{$this->moduleName}{$studly}Tab";

            $imports[] = "import {$component} from '../{$component}.vue'";

            $tabs[] = <<<JS
	{
		id: '{$id}',
		label: '{$label}',
		icon: ListIcon,
		component: {$component},
		props: () => ({ data: record.value, uuid: props.uuid }),
		lazy: true,
	},
JS;
        }

        if ($tabs !== []) {
            $imports[] = "import { ListIcon } from 'lucide-vue-next'";
        }

        return ['[[delegationTabs]]' => implode("\n", $tabs)];
    }

    protected function renderMainButton(string $permission, string $icon, string $label, string $click, string $kebab): string
    {
        $moduleName = strtolower($this->moduleName);

        return <<<VUE
				<Button
					v-if="hasPermission('{$permission}')"
					variant="outline"
					size="sm"
					:data-testid="`{$moduleName}-action-{$kebab}-\${uuid}`"
					@click="{$click}"
				>
					<component :is="icons['{$icon}']" class="h-3.5 w-3.5 mr-1.5" />{$label}
				</Button>
VUE;
    }

    protected function renderMoreItem(string $permission, string $icon, string $label, string $click, string $kebab, bool $destructive): string
    {
        $moduleName = strtolower($this->moduleName);
        $classes = $destructive
            ? 'text-xs text-destructive focus:text-destructive gap-1.5 px-2 py-1.5'
            : 'text-xs gap-1.5 px-2 py-1.5';

        return <<<VUE
						<DropdownMenuItem
							v-if="hasPermission('{$permission}')"
							class="{$classes}"
							:data-testid="`{$moduleName}-action-{$kebab}-\${uuid}`"
							@click="{$click}"
						>
							<component :is="icons['{$icon}']" class="h-3.5 w-3.5" />{$label}
						</DropdownMenuItem>
VUE;
    }

    protected function renderModal(string $studly, string $openRef, string $label): string
    {
        // `modal` is what tells the shared Form to emit instead of navigate —
        // the same prop the generated Create/Edit forms take.
        return <<<VUE
		<AppDialog v-model:open="{$openRef}" title="{$label}" size="lg">
			<{$this->moduleName}{$studly}Form
				:uuid="uuid"
				modal
				@cancel="{$openRef} = false"
				@success="{$openRef} = false"
			/>
		</AppDialog>
VUE;
    }

    protected function escapeForSingleQuotes(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }
}
