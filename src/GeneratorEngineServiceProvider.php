<?php

namespace Blutrixx\GeneratorEngine;

use Illuminate\Support\ServiceProvider;
use Blutrixx\GeneratorEngine\Commands\MakeUxFromBlueprintCommand;

class GeneratorEngineServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeUxFromBlueprintCommand::class,
            ]);
        }
    }
}
