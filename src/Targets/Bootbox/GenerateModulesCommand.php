<?php

namespace Blutrixx\GeneratorEngine\Targets\Bootbox;

use Blutrixx\GeneratorEngine\Core\ModuleOrder;
use Blutrixx\GeneratorEngine\Core\ModuleSpec;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * php artisan generator:modules [--dir=specs/]
 *
 * Every generator.json (the app's Custom modules', or a folder of *.json specs) generated in dependency order, each in
 * its own generator:module process, so each one sees the modules made before it.
 */
class GenerateModulesCommand extends Command
{
    protected $signature = 'generator:modules {--dir= : A folder of generator.json specs (default: the Custom modules\' own)}';

    protected $description = 'Generate many modules from their generator.json, each after the modules it points at (P123)';

    public function handle(): int
    {
        $dir = $this->option('dir');
        $paths = $dir ? (glob(rtrim((string) $dir, '/').'/*.json') ?: []) : (glob(app_path('Modules/Custom/*/*/generator.json')) ?: []);
        if ($paths === []) {
            $this->error('No generator.json found'.($dir ? " in {$dir}" : ' under app/Modules/Custom').'.');

            return self::FAILURE;
        }
        try {
            $specs = [];
            foreach ($paths as $path) {
                $specs[$path] = ModuleSpec::fromFile($path);
            }
            $order = ModuleOrder::sort($specs);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        foreach ($order as $i => $path) {
            $this->line(sprintf('<info>[%d/%d]</info> %s', $i + 1, count($order), $specs[$path]->key()));
            $process = new Process([PHP_BINARY, base_path('artisan'), 'generator:module', $path], base_path(), null, null, 600);
            $process->run(fn ($type, $out) => $this->output->write($out));
            if (! $process->isSuccessful()) {
                $this->error("Stopped at {$specs[$path]->key()}: the modules after it were not generated.");

                return self::FAILURE;
            }
        }
        $this->info(count($order).' modules generated. Next: php artisan migrate && php artisan db:seed');

        return self::SUCCESS;
    }
}
