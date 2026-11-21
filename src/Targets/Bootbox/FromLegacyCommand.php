<?php

namespace Blutrixx\GeneratorEngine\Targets\Bootbox;

use Blutrixx\GeneratorEngine\Core\LegacyModuleConfig;
use Illuminate\Console\Command;

/**
 * php artisan generator:from-legacy legacy-module.json [--out=generator.json]
 * php artisan generator:from-legacy --dir=legacy/ --out=specs/
 *
 * A legacy module config (V1, SYSTEM_SHELL) as a Bootbox generator.json; every reason it cannot be one, at once. With
 * --dir, a whole set (every *.json in the folder, in name order): a parent's delegations become its children's tab_on
 * (P86), and each module that converts is written to --out as <Module>.json; the others list their reasons.
 */
class FromLegacyCommand extends Command
{
    protected $signature = 'generator:from-legacy {legacy? : A legacy module config (JSON)} {--dir= : A folder of legacy module configs, converted together} {--out= : Where to write: a file (one module) or a folder (--dir); default: print}';

    protected $description = 'Convert legacy module configs into Bootbox generator.json files, or say why they cannot be';

    public function handle(): int
    {
        if ($dir = $this->option('dir')) {
            return $this->all((string) $dir);
        }
        if ($this->argument('legacy') === null) {
            $this->error('Name a legacy module config, or a folder of them with --dir.');

            return self::FAILURE;
        }
        $legacy = json_decode((string) @file_get_contents((string) $this->argument('legacy')), true);
        if (! is_array($legacy)) {
            $this->error("{$this->argument('legacy')} is not a JSON object.");

            return self::FAILURE;
        }
        $result = LegacyModuleConfig::convert($legacy);
        if (! $this->report($legacy['module_name'] ?? 'The module', $result)) {
            return self::FAILURE;
        }
        $json = self::json($result['spec']);
        if ($out = $this->option('out')) {
            @mkdir(dirname((string) $out), 0755, true);
            file_put_contents((string) $out, $json);
            $this->info("Wrote {$out}");
        } else {
            $this->output->write($json);
        }

        return self::SUCCESS;
    }

    private function all(string $dir): int
    {
        $files = glob(rtrim($dir, '/').'/*.json') ?: [];
        sort($files);
        $legacies = [];
        foreach ($files as $file) {
            $legacy = json_decode((string) file_get_contents($file), true);
            if (is_array($legacy) && isset($legacy['module_name'])) {
                $legacies[] = $legacy;
            }
        }
        if ($legacies === []) {
            $this->error("No legacy module configs in {$dir}.");

            return self::FAILURE;
        }
        $out = $this->option('out') ? rtrim((string) $this->option('out'), '/') : null;
        if ($out !== null) {
            @mkdir($out, 0755, true);
        }
        $ok = 0;
        foreach (LegacyModuleConfig::convertAll($legacies) as $module => $result) {
            $this->line("<info>{$module}</info>");
            if (! $this->report($module, $result)) {
                continue;
            }
            $ok++;
            if ($out !== null) {
                file_put_contents("{$out}/{$module}.json", self::json($result['spec']));
                $this->line("  wrote {$out}/{$module}.json");
            }
        }
        $refused = count($legacies) - $ok;
        $this->newLine();
        $refused === 0 ? $this->info("All {$ok} converted.") : $this->warn("{$ok} converted; {$refused} cannot be yet (their reasons are above).");

        return $refused === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Prints the notes and, when it cannot be generated, why; answers whether it can. */
    private function report(string $module, array $result): bool
    {
        foreach ($result['notes'] as $note) {
            $this->line("  <comment>note:</comment> {$note}");
        }
        if ($result['spec'] !== null) {
            return true;
        }
        $this->error("{$module} cannot be generated for Bootbox:");
        foreach ($result['refused'] as $why) {
            $this->line("  - {$why}");
        }

        return false;
    }

    private static function json(array $spec): string
    {
        return json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}
