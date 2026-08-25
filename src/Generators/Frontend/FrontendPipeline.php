<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\Actions\ActionComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\CreateFormGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\DeleteFormGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\Delegations\DelegationModalComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\Delegations\DelegationTabComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\EditFormGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\ViewModalGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\CreatePageGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\DeletePageGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\EditPageGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ListPageGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ViewHistoryGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ViewLayoutGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ViewOverviewGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Routes\FrontendRoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Tests\PlaywrightTestGenerator;
use Blutrixx\GeneratorEngine\Helpers\DelegationConfigNormalizer;

/**
 * FrontendPipeline
 *
 * The frontend half of the per-module scaffold, as a standalone unit with no
 * Laravel dependency of any kind — no container, no `config()`, no `base_path()`,
 * no database. Everything it needs arrives as a config array plus whatever static
 * context the caller has already put on PathManager (project root, module
 * registry, FK graph, sub-group).
 *
 * That independence is the entire point. It lets the same pipeline drive two
 * very different entry points:
 *
 *   - `ModuleScaffolder` inside a real Laravel app, generating the frontend
 *     alongside the backend as it always has, and
 *   - `bin/gen-frontend`, a standalone CLI that scaffolds a throwaway prototype
 *     app from a JSON spec with no PHP framework, no MySQL and no backend at all.
 *
 * Generator labels and their order are part of the contract, not an
 * implementation detail: `--only=` matches on them (see shouldGenerate()), so
 * renaming or reordering silently changes what an existing command generates.
 */
class FrontendPipeline
{
    /** @var callable(string, string): void  function(message, level) */
    private $outputCallback;

    /** @var string[] `--only=` substring patterns; empty means "run everything". */
    private array $only = [];

    private bool $force = false;

    private int $created = 0;
    private int $skipped = 0;

    /** @var string[] */
    private array $errors = [];

    /**
     * @param callable|null $outputCallback Receives (message, level) where level is
     *                                      'info'|'warn'|'error'. Defaults to a no-op so
     *                                      library callers can ignore progress chatter.
     */
    public function __construct(?callable $outputCallback = null)
    {
        $this->outputCallback = $outputCallback ?? static function (string $message, string $level): void {
        };
    }

    public function setForce(bool $force): static
    {
        $this->force = $force;

        return $this;
    }

    /**
     * @param string[] $patterns Case-insensitive substrings matched against generator labels.
     */
    public function setOnly(array $patterns): static
    {
        $this->only = array_values(array_filter(
            array_map('trim', $patterns),
            static fn ($p) => $p !== ''
        ));

        return $this;
    }

    /**
     * Run every frontend generator for one module.
     *
     * @return array{created: int, skipped: int, errors: string[]}
     */
    public function run(string $moduleName, string $moduleGroup, array $config): array
    {
        $this->created = 0;
        $this->skipped = 0;
        $this->errors  = [];

        $this->runPages($moduleName, $moduleGroup, $config);
        $this->runRegistries($moduleName, $moduleGroup, $config);
        $this->runDelegations($moduleName, $moduleGroup, $config);
        $this->runActions($moduleName, $moduleGroup, $config);

        return [
            'created' => $this->created,
            'skipped' => $this->skipped,
            'errors'  => $this->errors,
        ];
    }

    // ─── Feature pages and forms ──────────────────────────────────────────────

    private function runPages(string $moduleName, string $moduleGroup, array $config): void
    {
        $frontendFeatures = $config['features']['frontend'] ?? [];

        if (isset($frontendFeatures['list'])) {
            // ListComponentGenerator (Components/{Module}List.vue) intentionally not run:
            // {Module}ListPage.vue embeds ListTable directly (page.stub) and never imports
            // the wrapper component — confirmed 2026-08-05 via live regeneration + import-site
            // grep against a scratch module. Generating it was pure dead weight, not a
            // reusable pattern anything actually used.
            $this->runGenerator('ListPage', ListPageGenerator::class, $moduleName, $moduleGroup, $config);
        }

        if (!empty($frontendFeatures['create'])) {
            // ModuleScaffolder guarded both of these behind a `compositeModules` list and
            // then rewrote the emitted form's `inline` prop default to true. All of it was
            // vestigial from the UX-Builder/composite subsystem removed in v3.0.0:
            // setCompositeModules() had no callers anywhere, so the list was always empty,
            // and the rewrite's search string (`inline: {default: false}`) appears in
            // neither create/form.stub nor any generated form in SYSTEM_SHELL. Dead on
            // both counts, so it is not carried into this seam.
            $this->runGenerator('CreatePage', CreatePageGenerator::class, $moduleName, $moduleGroup, $config);
            $this->runGenerator('CreateForm', CreateFormGenerator::class, $moduleName, $moduleGroup, $config);
        }

        if (!empty($frontendFeatures['edit'])) {
            $this->runGenerator('EditPage', EditPageGenerator::class, $moduleName, $moduleGroup, $config);
            $this->runGenerator('EditForm', EditFormGenerator::class, $moduleName, $moduleGroup, $config);
        }

        if (!empty($frontendFeatures['view'])) {
            $this->runGenerator('ViewLayout',   ViewLayoutGenerator::class,   $moduleName, $moduleGroup, $config);
            $this->runGenerator('ViewOverview', ViewOverviewGenerator::class, $moduleName, $moduleGroup, $config);
            $this->runGenerator('ViewHistory',  ViewHistoryGenerator::class,  $moduleName, $moduleGroup, $config);
            $this->runGenerator('ViewModal',    ViewModalGenerator::class,    $moduleName, $moduleGroup, $config);
        }

        if (!empty($frontendFeatures['delete'])) {
            $this->runGenerator('DeletePage', DeletePageGenerator::class, $moduleName, $moduleGroup, $config);
            $this->runGenerator('DeleteForm', DeleteFormGenerator::class, $moduleName, $moduleGroup, $config);
        }
    }

    // ─── Routing, registries, locales, specs ──────────────────────────────────

    private function runRegistries(string $moduleName, string $moduleGroup, array $config): void
    {
        $this->runGenerator('FrontendRoutes', FrontendRoutesGenerator::class, $moduleName, $moduleGroup, $config);
        $this->runGenerator('ModulesJson',    ModulesJsonGenerator::class,    $moduleName, $moduleGroup, $config);
        $this->runGenerator('MenusJson',      MenusJsonGenerator::class,      $moduleName, $moduleGroup, $config);

        // Emitted alongside modules.json/menus.json because it is the same kind of
        // artifact: a shared registry every module merges into, describing the API
        // the generated pages call. The browser-side mock reads it to stand a whole
        // backend up from nothing.
        $this->runGenerator('ApiContract',    ApiContractGenerator::class,    $moduleName, $moduleGroup, $config);

        // Per-module i18n locale files (en.json + sw.json). Reported as one unit
        // rather than two, which is why it does not go through run().
        try {
            $localeGen = (new FrontendLocaleGenerator($moduleName, $moduleGroup, $config))->setForce($this->force);
            if ($this->shouldGenerate('FrontendLocales') && $localeGen->generate()) {
                $this->report('  Created: FrontendLocales (en.json + sw.json)', 'info');
                $this->created++;
            } else {
                $this->report('  Skipped (already exists): FrontendLocales', 'warn');
                $this->skipped++;
            }
        } catch (\Throwable $e) {
            $msg = '[FrontendLocales] ' . $e->getMessage();
            $this->report("  Failed: {$msg}", 'error');
            $this->errors[] = $msg;
        }

        $this->runGenerator('PlaywrightTest', PlaywrightTestGenerator::class, $moduleName, $moduleGroup, $config);
    }

    // ─── Delegations ──────────────────────────────────────────────────────────

    private function runDelegations(string $moduleName, string $moduleGroup, array $config): void
    {
        $delegations = $config['delegations'] ?? [];
        if (empty($delegations)) {
            return;
        }

        foreach ($delegations as $delegationKey => $delegation) {
            $delegation = DelegationConfigNormalizer::normalize($delegation);
            $uiType     = $delegation['uiType'] ?? 'tab';

            $label = "Delegation{$uiType}Component [{$delegationKey}]";

            // Gated on shouldGenerate(), unlike the ModuleScaffolder code this was
            // lifted from. There the delegation component ran under ANY --only= filter,
            // and the consequence is not theoretical: running
            // `--only=ApiContract` against SYSTEM_SHELL rewrote a tracked, hand-tuned
            // LocationsUserLocationsTab.vue as a side effect. A filter that silently
            // overwrites files outside it is worse than one that generates too little,
            // and ActionComponent below was already gated — this just makes the two
            // consistent.
            if (!$this->shouldGenerate($label)) {
                continue;
            }

            try {
                $generatorClass = $uiType === 'modal'
                    ? DelegationModalComponentGenerator::class
                    : DelegationTabComponentGenerator::class;

                $gen = new $generatorClass($moduleName, $moduleGroup, $config);
                $gen->setForce($this->force);

                $ok = $gen->generateDelegation($delegationKey, $delegation);

                if ($ok) {
                    $this->report("  Created: {$label}", 'info');
                    $this->created++;
                } else {
                    $this->report("  Skipped (already exists or no output): {$label}", 'warn');
                    $this->skipped++;
                }
            } catch (\Throwable $e) {
                $msg = "[DelegationComponent:{$delegationKey}] " . $e->getMessage();
                $this->report("  Failed: {$msg}", 'error');
                $this->errors[] = $msg;
            }

            // Related-module form stubs — intentionally not run (2026-08-05).
            // The delegation tab component embeds the related module's own NATIVE
            // CreateForm/EditForm/DeleteForm/ViewModal directly now, reused unchanged
            // from that module's standalone page. Generating delegation-specific
            // versions here used to overwrite those exact same native files at the
            // same path — a confirmed, live file-collision bug, not just duplication.
        }
    }

    // ─── Actions ──────────────────────────────────────────────────────────────

    private function runActions(string $moduleName, string $moduleGroup, array $config): void
    {
        $actions = $config['actions'] ?? [];
        if (empty($actions)) {
            return;
        }

        foreach ($actions as $actionKey => $action) {
            try {
                // No setForce() here, deliberately — matching ModuleScaffolder. It would
                // be inert anyway (ActionComponentGenerator writes exclusively through
                // writeFileOnce(), which ignores force so a hand-written action form body
                // is never clobbered by a --force regenerate), but passing it would imply
                // a force-overwrite contract this generator does not honour.
                //
                // The skip reason is reported explicitly rather than folded into one generic
                // "no UI or already exists" line (confirmed live 2026-08-25: a consuming project
                // ran `--only=ActionComponent --force` expecting the flag to refresh a hand-edited
                // form, got the same generic skip either way, and had to read this class's source
                // to learn --force can never apply here). hasUI is checked here, before the
                // generator ever runs, because it is the one case generateAction() itself cannot
                // distinguish from "already exists" — both return false from the same line.
                if (empty($action['hasUI'])) {
                    $this->report("  Skipped (action has no UI — hasUI is false): ActionComponent [{$actionKey}]", 'warn');
                    $this->skipped++;
                    continue;
                }

                // Checked separately from generateAction()'s own result, not folded into one
                // `&&` chain: with a --only filter in effect, $ok being false could mean EITHER
                // "not selected by --only" or "write-once, already exists" — collapsing both into
                // one message would misreport a --only-filtered action as already existing.
                if (!$this->shouldGenerate("ActionComponent [{$actionKey}]")) {
                    $this->report("  Skipped (excluded by --only): ActionComponent [{$actionKey}]", 'warn');
                    $this->skipped++;
                    continue;
                }

                $gen = new ActionComponentGenerator($moduleName, $moduleGroup, $config);

                if ($gen->generateAction($actionKey, $action)) {
                    $this->report("  Created: ActionComponent [{$actionKey}]", 'info');
                    $this->created++;
                } else {
                    $this->report("  Skipped (write-once — Form.vue already exists; delete it and regenerate to refresh its content, --force does not apply here): ActionComponent [{$actionKey}]", 'warn');
                    $this->skipped++;
                }
            } catch (\Throwable $e) {
                $msg = "[ActionComponent:{$actionKey}] " . $e->getMessage();
                $this->report("  Failed: {$msg}", 'error');
                $this->errors[] = $msg;
            }
        }
    }

    // ─── Plumbing ─────────────────────────────────────────────────────────────

    /**
     * Instantiate and run one BaseGenerator subclass, tallying the outcome.
     *
     * @param class-string<\Blutrixx\GeneratorEngine\Generators\BaseGenerator> $generatorClass
     */
    private function runGenerator(
        string $label,
        string $generatorClass,
        string $moduleName,
        string $moduleGroup,
        array  $config
    ): void {
        if (!$this->shouldGenerate($label)) {
            return;
        }

        try {
            $gen = new $generatorClass($moduleName, $moduleGroup, $config);
            $gen->setForce($this->force);

            if ($gen->generate()) {
                $this->report("  Created: {$label}", 'info');
                $this->created++;
            } else {
                $this->report("  Skipped (already exists or no output): {$label}", 'warn');
                $this->skipped++;
            }
        } catch (\Throwable $e) {
            $msg = "[{$label}] " . $e->getMessage();
            $this->report("  Failed: {$msg}", 'error');
            $this->errors[] = $msg;
        }
    }

    /** Whether a generator with this label should run under the current --only filter. */
    private function shouldGenerate(string $label): bool
    {
        if ($this->only === []) {
            return true;
        }

        foreach ($this->only as $pattern) {
            if (stripos($label, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function report(string $message, string $level): void
    {
        ($this->outputCallback)($message, $level);
    }
}
