<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp;

use Blutrixx\GeneratorEngine\Generators\Frontend\MenusJsonGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

/**
 * Updates menus.json in MOBILE_APP to register the generated module's menu entry.
 * Reuses FRONTEND MenusJsonGenerator logic but writes to MOBILE_APP path.
 */
class MobileAppMenusJsonGenerator extends MenusJsonGenerator
{
    public function generate(): bool
    {
        $menusJsonPath = PathManager::getMobileAppSrcPath() . '/menus.json';
        $existingMenus = $this->loadExistingMenus($menusJsonPath);

        // Add new module to menus.json
        $this->addModuleToMenus($existingMenus);

        return $this->writeFile($menusJsonPath, json_encode($existingMenus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function removeFromMenus(): bool
    {
        $menusJsonPath = PathManager::getMobileAppSrcPath() . '/menus.json';
        $existingMenus = $this->loadExistingMenus($menusJsonPath);

        $originalCount = $this->countModuleMenus($existingMenus);
        $this->removeModuleFromMenus($existingMenus);
        $newCount = $this->countModuleMenus($existingMenus);

        if ($originalCount > $newCount) {
            return $this->writeFile($menusJsonPath, json_encode($existingMenus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return true;
    }
}
