<?php

namespace Blutrixx\GeneratorEngine;

use Illuminate\Support\ServiceProvider;

class GeneratorEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/generator.php', 'generator');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/generator.php' => config_path('generator.php')], 'generator-config');
            $this->publishes([__DIR__.'/Generators/Templates' => base_path('stubs/generator')], 'generator-stubs');

            // Bootbox (P123): only in an app whose kit.json names a generator contract
            $kit = Targets\Bootbox\BootboxGenerator::productRoot(base_path()).'/kit.json'; // P136: beside the api, or in it
            if (is_file($kit) && isset(json_decode((string) file_get_contents($kit), true)['generator_contract'])) {
                $this->commands([Targets\Bootbox\GenerateModuleCommand::class, Targets\Bootbox\GenerateModulesCommand::class, Targets\Bootbox\FromLegacyCommand::class]);
            }
        }
    }
}
