<?php

namespace Blutrixx\GeneratorEngine\Targets\Bootbox;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

/**
 * php artisan generator:module path/to/generator.json   (or a module key whose folder has one)
 *
 * Bootbox (P123): the skeleton from bootbox:module --generated, the body from the spec. Registered only in an app
 * whose kit.json carries a generator_contract.
 */
class GenerateModuleCommand extends Command
{
    protected $signature = 'generator:module {spec : A generator.json, or the key of a module that has one}';

    protected $description = 'Generate a module\'s body from its generator.json into the slots its skeleton marks (P123)';

    public function handle(): int
    {
        $spec = (string) $this->argument('spec');
        if (! is_file($spec)) {
            $found = glob(app_path('Modules/Custom/*/'.$spec.'/generator.json')) ?: [];
            $spec = $found[0] ?? '';
        }
        if (! is_file($spec)) {
            $this->error("No generator.json at {$this->argument('spec')}, and no module {$this->argument('spec')} with one.");

            return self::FAILURE;
        }

        $pint = base_path('vendor/bin/pint');
        $generator = new BootboxGenerator(
            base_path(),
            new BootboxModuleResolver,
            fn (string $command, array $options) => Artisan::call($command, $options, $this->output),
            function (array $files) use ($pint) {
                if ($files !== [] && is_file($pint)) {
                    (new Process([$pint, ...$files], base_path()))->run();
                }
            },
            fn (string $line) => $this->line("  <info>{$line}</info>"),
            // the web side, when the app has its web beside it (Bootbox: ../web)
            is_dir(dirname(base_path()).'/web/scripts') ? function (array $args, string $cwd) {
                $process = new Process(['node', ...$args], $cwd);
                $process->run();

                return (int) $process->getExitCode();
            } : null,
        );
        try {
            $generator->generate($spec);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            if ($this->output->isVerbose()) {
                $this->line($e->getFile().':'.$e->getLine()."\n".$e->getTraceAsString());
            }

            return self::FAILURE;
        }
        $key = \Blutrixx\GeneratorEngine\Core\ModuleSpec::fromFile($spec)->key();
        $this->info("Done. Next: php artisan migrate && php artisan db:seed && php artisan test --filter={$key}");

        return self::SUCCESS;
    }
}
