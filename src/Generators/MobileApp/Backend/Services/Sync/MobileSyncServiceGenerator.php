<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Services\Sync;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\BaseMobileBackendGenerator;
use Illuminate\Support\Str;

class MobileSyncServiceGenerator extends BaseMobileBackendGenerator
{
    public function generate(): bool
    {
        $content = $this->loadStub('services/sync');

        $tableName = $this->config['table_name'] ?? Str::snake(Str::plural($this->moduleName));

        $replacements = [
            '[[moduleGroup]]' => $this->moduleGroup,
            '[[moduleName]]'  => $this->moduleName,
            '[[tableName]]'   => $tableName,
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = "{$this->modulePath}/Services/{$this->moduleName}SyncService.php";

        return $this->writeFile($filePath, $content);
    }
}
