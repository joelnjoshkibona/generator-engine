<?php

namespace Blutrixx\GeneratorEngine\Commands;

use Illuminate\Console\Command;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Generators\Ux\CompositeGenerator;
use Blutrixx\GeneratorEngine\Generators\Ux\WizardGenerator;
use Blutrixx\GeneratorEngine\Generators\Ux\ShortcutGenerator;
use Blutrixx\GeneratorEngine\Generators\Ux\DashboardGenerator;

class MakeUxFromBlueprintCommand extends Command
{
    protected $signature = 'make:ux-from-blueprint {blueprint : Path to blueprint JSON file}';
    protected $description = 'Generate UX extension files (composites, wizards, shortcuts, dashboard) from blueprint';

    public function handle(): int
    {
        $blueprintPath = $this->argument('blueprint');
        if (!file_exists($blueprintPath)) {
            $this->error("Blueprint not found: {$blueprintPath}");
            return 1;
        }

        $blueprint = json_decode(file_get_contents($blueprintPath), true);
        if (!$blueprint) {
            $this->error('Invalid JSON in blueprint file.');
            return 1;
        }

        // When running from within BACKEND, project root is one level up.
        if (PathManager::getProjectRoot() === null) {
            PathManager::setProjectRoot(dirname(base_path()));
        }

        $totalCreated = 0;
        $totalSkipped = 0;

        $generators = [
            'Composites' => new CompositeGenerator($blueprint),
            'Wizards'    => new WizardGenerator($blueprint),
            'Shortcuts'  => new ShortcutGenerator($blueprint),
            'Dashboard'  => new DashboardGenerator($blueprint),
        ];

        foreach ($generators as $label => $generator) {
            $this->line("\n━━━ {$label} ━━━");
            $generator->generate();

            $frontendCreated = [];
            $mobileCreated   = [];
            $frontendSkipped = [];
            $mobileSkipped   = [];

            foreach ($generator->getCreated() as $path) {
                if ($this->isMobilePath($path)) {
                    $mobileCreated[] = $path;
                } else {
                    $frontendCreated[] = $path;
                }
                $totalCreated++;
            }
            foreach ($generator->getSkipped() as $path) {
                if ($this->isMobilePath($path)) {
                    $mobileSkipped[] = $path;
                } else {
                    $frontendSkipped[] = $path;
                }
                $totalSkipped++;
            }

            if (!empty($frontendCreated) || !empty($frontendSkipped)) {
                $this->line('  <fg=cyan>[Frontend]</>');
                foreach ($frontendCreated as $path) {
                    $this->line("    <fg=green>Created:</> " . $this->relativePath($path));
                }
                foreach ($frontendSkipped as $path) {
                    $this->line("    <fg=yellow>Skipped:</> " . $this->relativePath($path));
                }
            }

            if (!empty($mobileCreated) || !empty($mobileSkipped)) {
                $this->line('  <fg=magenta>[Mobile]</>');
                foreach ($mobileCreated as $path) {
                    $this->line("    <fg=green>Created:</> " . $this->relativePath($path));
                }
                foreach ($mobileSkipped as $path) {
                    $this->line("    <fg=yellow>Skipped:</> " . $this->relativePath($path));
                }
            }
        }

        $this->newLine();
        $this->line('═══════════════════════════════════════════════════════════');
        $this->line("Done.");
        $this->line("  Files created : {$totalCreated}");
        $this->line("  Files skipped : {$totalSkipped}");

        return 0;
    }

    private function relativePath(string $path): string
    {
        return str_replace(base_path() . '/', '', $path);
    }

    private function isMobilePath(string $path): bool
    {
        return str_contains($path, '/MOBILE_APP/') || str_contains($path, '/resources/js/');
    }
}
