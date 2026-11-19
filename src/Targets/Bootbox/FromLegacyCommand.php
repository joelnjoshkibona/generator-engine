<?php

namespace Blutrixx\GeneratorEngine\Targets\Bootbox;

use Blutrixx\GeneratorEngine\Core\LegacyModuleConfig;
use Illuminate\Console\Command;

/**
 * php artisan generator:from-legacy legacy-module.json [--out=generator.json]
 *
 * A legacy module config (V1, SYSTEM_SHELL) as a Bootbox generator.json; every reason it cannot be one, at once.
 */
class FromLegacyCommand extends Command
{
    protected $signature = 'generator:from-legacy {legacy : A legacy module config (JSON)} {--out= : Where to write the generator.json (default: print it)}';

    protected $description = 'Convert a legacy module config into a Bootbox generator.json, or say why it cannot be';

    public function handle(): int
    {
        $legacy = json_decode((string) @file_get_contents((string) $this->argument('legacy')), true);
        if (! is_array($legacy)) {
            $this->error("{$this->argument('legacy')} is not a JSON object.");

            return self::FAILURE;
        }
        $result = LegacyModuleConfig::convert($legacy);
        foreach ($result['notes'] as $note) {
            $this->line("  <comment>note:</comment> {$note}");
        }
        if ($result['spec'] === null) {
            $this->error(($legacy['module_name'] ?? 'The module').' cannot be generated for Bootbox:');
            foreach ($result['refused'] as $why) {
                $this->line("  - {$why}");
            }

            return self::FAILURE;
        }
        $json = json_encode($result['spec'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        if ($out = $this->option('out')) {
            @mkdir(dirname((string) $out), 0755, true);
            file_put_contents((string) $out, $json);
            $this->info("Wrote {$out}");
        } else {
            $this->output->write($json);
        }

        return self::SUCCESS;
    }
}
