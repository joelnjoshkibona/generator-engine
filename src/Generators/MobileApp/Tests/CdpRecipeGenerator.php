<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Tests;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

/**
 * MOBILE_APP has no CI-runnable test framework (see
 * SYSTEM_SHELL/MOBILE_APP/CDP_DEVICE_TESTING.md, ported from the same
 * pattern used on the Agrovet NativePHP port) — verification instead means
 * driving the real app's WebView over Chrome DevTools Protocol against a
 * live device/emulator. This generator scaffolds that per-module: a set of
 * small, disposable `.js` snippets (one per step — matches the documented
 * "small and disposable beats one big script" workflow) plus a README tying
 * them into the right order for THIS module's actual route and fields.
 *
 * Deliberately NOT a PHPUnit/Playwright-style assertion suite — nothing
 * here runs in CI, nothing here is regenerated blindly on every --force
 * (skip-if-exists, same as everything else this generator writes), and a
 * developer is expected to read/adjust a snippet before running it, exactly
 * as CDP_DEVICE_TESTING.md's own workflow describes.
 */
class CdpRecipeGenerator extends BaseGenerator
{
    protected function getModulePath(): string
    {
        return PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName);
    }

    public function generate(): bool
    {
        $hasList = !empty($this->config['features']['frontend']['list']);
        if (!$hasList) {
            // No list page means no route to navigate to at all — nothing
            // to scaffold a recipe against.
            return false;
        }

        $routeBase = '/' . Str::kebab(Str::plural($this->moduleName));
        $createFields = $this->config['features']['frontend']['create']['fields'] ?? [];
        $hasCreate = !empty($this->config['features']['frontend']['create']) && !empty($createFields);

        $dir = $this->getModulePath() . '/CdpRecipes';

        $allWritten = true;
        $allWritten = $this->writeFile("{$dir}/01-navigate-to-list.js", $this->buildNavigateSnippet($routeBase)) && $allWritten;
        $allWritten = $this->writeFile("{$dir}/02-dump-list.js", $this->buildDumpSnippet()) && $allWritten;

        $steps = [
            ['01-navigate-to-list.js', "go to {$routeBase}"],
            ['02-dump-list.js', 'confirm the list rendered'],
        ];

        if ($hasCreate) {
            $allWritten = $this->writeFile("{$dir}/03-open-create.js", $this->buildOpenCreateSnippet()) && $allWritten;
            $allWritten = $this->writeFile("{$dir}/04-fill-form.js", $this->buildFillFormSnippet($createFields)) && $allWritten;
            $allWritten = $this->writeFile("{$dir}/05-submit.js", $this->buildSubmitSnippet()) && $allWritten;
            $allWritten = $this->writeFile("{$dir}/06-verify-created.js", $this->buildDumpSnippet()) && $allWritten;
            $steps[] = ['03-open-create.js', 'click Create'];
            $steps[] = ['04-fill-form.js', 'fill each create field'];
            $steps[] = ['05-submit.js', 'submit the form'];
            $steps[] = ['06-verify-created.js', 'dump state, confirm the new row appears'];
        }

        $allWritten = $this->writeFile("{$dir}/README.md", $this->buildReadme($routeBase, $steps)) && $allWritten;

        return $allWritten;
    }

    protected function buildNavigateSnippet(string $routeBase): string
    {
        return <<<JS
        // Navigate to {$this->moduleName}'s list page. This app uses
        // createWebHistory() (not hash-mode) -- pushState + a dispatched
        // popstate event is what vue-router's own listener reacts to. See
        // CDP_DEVICE_TESTING.md's "Navigate directly to a route" recipe.
        (function () {
          window.history.pushState({}, '', '{$routeBase}');
          window.dispatchEvent(new PopStateEvent('popstate'));
          return JSON.stringify({ href: window.location.href });
        })()

        JS;
    }

    protected function buildDumpSnippet(): string
    {
        return <<<'JS'
        // Collapse the whole visible page to one line -- run this after
        // every action to see what actually changed.
        JSON.stringify({ snippet: document.body.innerText.split('\n').filter(Boolean).join(' / '), href: window.location.href })

        JS;
    }

    protected function buildOpenCreateSnippet(): string
    {
        return <<<'JS'
        (function () {
          const b = [...document.querySelectorAll('button, a')].find(x => /create/i.test(x.textContent));
          if (b) b.click();
          return JSON.stringify({ clicked: !!b });
        })()

        JS;
    }

    protected function buildFillFormSnippet(array $fields): string
    {
        $lines = [];
        foreach ($fields as $field) {
            $name = $field['field'] ?? null;
            if (!$name) {
                continue;
            }
            $placeholder = addslashes($field['placeholder'] ?? $field['label'] ?? $name);
            $value = $this->sampleValueFor($field);
            $lines[] = "  results['{$name}'] = fill('{$placeholder}', {$value});";
        }
        $fillLines = implode("\n", $lines);

        return <<<JS
        // Fills each create field by matching its placeholder text. Just
        // setting el.value does NOT trigger Vue's v-model reactivity -- go
        // through the native property setter and dispatch input/change, the
        // same as a real keystroke would. See CDP_DEVICE_TESTING.md's
        // "Fill a text input reliably" recipe.
        (function () {
          function setVal(el, val) {
            const proto = el.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
            const d = Object.getOwnPropertyDescriptor(proto, 'value');
            d.set.call(el, val);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
          }
          function fill(placeholderMatch, value) {
            const el = [...document.querySelectorAll('input, textarea')]
              .find(i => i.placeholder && i.placeholder.toLowerCase().includes(placeholderMatch.toLowerCase()));
            if (el) setVal(el, value);
            return !!el;
          }
          const results = {};
        {$fillLines}
          return JSON.stringify({ filled: results });
        })()

        JS;
    }

    protected function buildSubmitSnippet(): string
    {
        return <<<'JS'
        (function () {
          const b = [...document.querySelectorAll('button')].find(x => /save|submit|create/i.test(x.textContent));
          if (b) b.click();
          return JSON.stringify({ clicked: !!b });
        })()

        JS;
    }

    /**
     * Cheap, deterministic sample literal for a create field -- matches
     * $field['type']/$field['field_type'] the way IntrospectionToConfig
     * emits them (see mobile CreateFormGenerator's own field shape), not the
     * exhaustive rule-string sniffing PhpUnitTestGenerator does -- this is
     * throwaway test data for a manual recipe, not a validated HTTP payload.
     */
    protected function sampleValueFor(array $field): string
    {
        $type = $field['type'] ?? $field['field_type'] ?? 'text';
        $label = $field['label'] ?? $field['field'] ?? 'value';

        return match (true) {
            $type === 'number' => "'123'",
            $type === 'date' => "'" . date('Y-m-d') . "'",
            default => "'CDP Test " . addslashes((string) $label) . "'",
        };
    }

    protected function buildReadme(string $routeBase, array $steps): string
    {
        $recipesPath = 'resources/js/src/pages/modules/' . strtolower($this->moduleGroup) . "/{$this->moduleName}/CdpRecipes";
        $stepLines = implode("\n", array_map(
            fn (array $s) => "1. `node scripts/cdp.cjs {$recipesPath}/{$s[0]}` -- {$s[1]}",
            $steps
        ));

        return <<<MD
        # {$this->moduleName} -- CDP device-testing recipe

        Generated scaffold for live-verifying {$this->moduleName} against a real
        running app on a device/emulator, per `CDP_DEVICE_TESTING.md` at the
        MOBILE_APP repo root. Not a CI test suite -- run these by hand, adjusting
        selectors/values as needed, after `adb forward tcp:9222 ...` is set up.

        Route under test: `{$routeBase}`

        ## Sequence

        {$stepLines}

        Dump page state (`node scripts/cdp.cjs <the relevant dump/verify file>`)
        after every step rather than assuming a click worked -- see the root doc's
        "Practical tips" section for debounce/timing gotchas.
        MD;
    }
}
