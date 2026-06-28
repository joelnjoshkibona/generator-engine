<?php

namespace Blutrixx\GeneratorEngine;

use Illuminate\Support\ServiceProvider;
use Blutrixx\GeneratorEngine\Commands\MakeUxFromBlueprintCommand;

class GeneratorEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/generator.php', 'generator');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeUxFromBlueprintCommand::class,
            ]);

            $this->publishes([__DIR__.'/../config/generator.php' => config_path('generator.php')], 'generator-config');
            $this->publishes([__DIR__.'/Generators/Templates' => base_path('stubs/generator')], 'generator-stubs');
        }
    }
}
