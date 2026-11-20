<?php

namespace Blutrixx\GeneratorEngine\Targets\Bootbox;

use Blutrixx\GeneratorEngine\Core\ModuleResolver;
use Blutrixx\GeneratorEngine\Core\ModuleSpec;
use Blutrixx\GeneratorEngine\Core\SpecAwareResolver;
use Closure;
use RuntimeException;

/**
 * Generates a Bootbox module (P123): the starter's scaffolder writes the skeleton once, with --generated; this fills
 * the body slots, adds the imports the bodies need, writes its own files and the manifest keys it owns, and runs the
 * starter's formatter. Never --force on a module that exists.
 */
final class BootboxGenerator
{
    /** The generator contracts (kit.json "generator_contract") this target writes for. */
    /** 2 (kit 1.42.0): the web slots of P84, P86 and P115, and the phone's (D7, P124). */
    public const SUPPORTED_CONTRACTS = [3];

    /**
     * @param  string  $appRoot  the API app (Laravel base_path)
     * @param  Closure(string, array): int  $artisan  runs an artisan command, returns its exit code
     * @param  Closure(list<string>): void  $format  formats PHP files in place (Pint)
     * @param  Closure(list<string>, string): int|null  $node  runs node with arguments in a folder (the web scaffolder); null leaves the web side out
     */
    public function __construct(
        private readonly string $appRoot,
        private readonly ModuleResolver $modules,
        private readonly Closure $artisan,
        private readonly Closure $format,
        private readonly ?Closure $say = null,
        private readonly ?Closure $node = null,
    ) {}

    public static function contract(string $kitJson): int
    {
        $kit = is_file($kitJson) ? json_decode((string) file_get_contents($kitJson), true) : null;
        $contract = (int) ($kit['generator_contract'] ?? 0);
        if (! in_array($contract, self::SUPPORTED_CONTRACTS, true)) {
            throw new RuntimeException(sprintf(
                'This app\'s generator contract is %s (%s); this generator writes for %s. Update the one that is behind.',
                $contract ?: 'missing', $kitJson, implode(', ', self::SUPPORTED_CONTRACTS)
            ));
        }

        return $contract;
    }

    /** @return list<string> the files written, relative to the module folder */
    public function generate(string $specPath): array
    {
        $spec = ModuleSpec::fromFile($specPath);
        $modules = new SpecAwareResolver($spec, $this->modules); // it may point at itself before the app knows it
        self::contract(dirname($this->appRoot).'/kit.json');
        $dir = "{$this->appRoot}/app/Modules/Custom/{$spec->group()}/{$spec->key()}";

        if (! is_dir($dir)) {
            $options = ['name' => $spec->key(), '--group' => $spec->group(), '--scope' => $spec->scope(), '--label' => $spec->recordLabel(), '--label-column' => $spec->labelColumn(), '--generated' => true];
            if ($spec->sync()) {
                $options['--sync'] = true;
                if ($spec->keepDays() !== null) {
                    $options['--keep-days'] = $spec->keepDays();
                }
            }
            if (($this->artisan)('bootbox:module', $options) !== 0) {
                throw new RuntimeException("bootbox:module could not write the skeleton for {$spec->key()}");
            }
            $this->say("skeleton: bootbox:module {$spec->key()} --generated");
        } elseif (is_file("{$dir}/module.json")) {
            // the manifest's sync key is the scaffolder's (P123): a spec that disagrees is refused, never applied
            $manifest = json_decode((string) file_get_contents("{$dir}/module.json"), true) ?: [];
            if (array_key_exists('sync', $manifest) !== $spec->sync()) {
                throw new RuntimeException($spec->sync()
                    ? "{$spec->key()} is not a sync module: its generator.json says sync, its module.json does not. Add \"sync\": {} to module.json (and its Tests/{$spec->key()}SyncTest.php from bootbox:module --sync), or drop sync from the spec."
                    : "{$spec->key()} is a sync module: its module.json says sync, its generator.json does not. Add \"sync\": true to the spec, or remove sync from module.json.");
            }
        }
        if (realpath($specPath) !== realpath("{$dir}/generator.json")) {
            copy($specPath, "{$dir}/generator.json");
        }

        $render = (new ApiRenderer($spec, $modules))->render();
        $migration = $this->moduleMigration($dir, $spec->table());
        $written = [];
        foreach ($render['slots'] as $file => $slots) {
            $path = $file === 'MIGRATION' ? $migration : "{$dir}/{$file}";
            if (! is_file($path)) {
                throw new RuntimeException("{$path} is missing: the module was not made by bootbox:module --generated");
            }
            $text = (string) file_get_contents($path);
            foreach ($slots as $slot => $body) {
                $text = Slots::fill($text, $slot, $body, $this->relative($dir, $path));
            }
            $text = Imports::add($text, $render['imports'][$file] ?? []);
            file_put_contents($path, $text);
            $written[] = $this->relative($dir, $path);
        }
        $date = substr(basename($migration), 0, 10);
        foreach ($render['files'] as $file => $content) {
            $path = "{$dir}/".str_replace('DATE', $date, $file);
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, $content);
            $written[] = $this->relative($dir, $path);
        }
        $kept = $this->writeOnce($dir, $render['once'] ?? [], $written);

        $manifest = json_decode((string) file_get_contents("{$dir}/module.json"), true);
        file_put_contents("{$dir}/module.json", ManifestMerger::encode(ManifestMerger::merge($manifest, $spec, $modules)));
        $written[] = 'module.json';

        ($this->format)(array_map(fn ($f) => "{$dir}/{$f}", array_filter($written, fn ($f) => str_ends_with($f, '.php'))));
        $this->say('filled: '.implode(', ', $written));
        if ($kept !== []) {
            $this->say('kept (yours): '.implode(', ', $kept));
        }

        if ($this->node !== null) {
            $written = [...$written, ...array_map(fn ($f) => "web: {$f}", $this->generateWeb($spec, $modules))];
            if ($spec->phone()) {
                $written = [...$written, ...array_map(fn ($f) => "phone: {$f}", $this->generatePhone($spec, $modules))];
            }
        }

        return $written;
    }

    /** The web side (P123): the web scaffolder's skeleton once, then its slots and locale keys. */
    private function generateWeb(ModuleSpec $spec, \Blutrixx\GeneratorEngine\Core\ModuleResolver $modules): array
    {
        $web = dirname($this->appRoot).'/web';
        $render = new WebRenderer($spec, $modules);
        $kebab = $render->kebab();
        $dir = "{$web}/src/modules/{$kebab}";
        if (! is_dir($dir)) {
            $args = ['scripts/make-module.mjs', $kebab, '--key', $spec->key(), '--label', $spec->recordLabel(), '--labels', \Illuminate\Support\Str::plural($spec->recordLabel()), '--label-column', $spec->labelColumn(), '--scope', $spec->scope(), '--generated'];
            if (($this->node)($args, $web) !== 0) {
                throw new RuntimeException("make-module.mjs could not write the web skeleton for {$kebab}");
            }
            $this->say("web skeleton: make-module.mjs {$kebab} --generated");
        }
        $out = $render->render();
        $written = [];
        foreach ($out['slots'] as $file => $slots) {
            $path = "{$dir}/{$file}";
            if (! is_file($path)) {
                throw new RuntimeException("{$path} is missing: the web module was not made by make-module.mjs --generated");
            }
            $text = (string) file_get_contents($path);
            foreach ($slots as $slot => $body) {
                $text = Slots::fill($text, $slot, $body, "web/{$file}");
            }
            $text = Slots::fill($text, 'imports', TsImports::slot($text, $out['imports'][$file] ?? []), "web/{$file}");
            file_put_contents($path, $text);
            $written[] = $file;
        }
        $kept = $this->writeOnce($dir, $out['once'] ?? [], $written);
        $ns = \Illuminate\Support\Str::camel(str_replace('-', '_', $kebab));
        foreach (['en', 'sw'] as $lang) {
            $path = "{$dir}/locales/{$lang}/{$ns}.json";
            $locale = is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
            $fallback = $lang === 'sw' ? $out['locales']['sw_fallback'] : [];
            file_put_contents($path, LocaleMerger::encode(LocaleMerger::merge($locale, $out['locales'][$lang], $fallback)));
            $written[] = "locales/{$lang}/{$ns}.json";
        }
        $this->say('web filled: '.implode(', ', $written));
        if ($kept !== []) {
            $this->say('web kept (yours): '.implode(', ', $kept));
        }

        return $written;
    }

    /**
     * P123: files written once, then the developer's (an action's service and dialog): written when missing, never
     * touched again. Adds the new ones to $written.
     *
     * @return list<string> the ones already there, kept
     */
    private function writeOnce(string $dir, array $files, array &$written): array
    {
        $kept = [];
        foreach ($files as $file => $content) {
            $path = "{$dir}/{$file}";
            if (is_file($path)) {
                $kept[] = $file;

                continue;
            }
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, $content);
            $written[] = $file;
        }

        return $kept;
    }

    /** The phone side (D7, P123): the phone scaffolder's skeleton once, then its slots, its store's rules and its labels. */
    private function generatePhone(ModuleSpec $spec, \Blutrixx\GeneratorEngine\Core\ModuleResolver $modules): array
    {
        $mobile = dirname($this->appRoot).'/mobile';
        $spa = "{$mobile}/resources/spa";
        if (! is_file("{$spa}/scripts/make-module.mjs")) {
            throw new RuntimeException("generator.json asks for phone screens, but there is no phone app at {$spa}");
        }
        $render = new PhoneRenderer($spec, $modules);
        $kebab = $spec->route();
        $dir = "{$spa}/src/modules/{$kebab}";
        if (! is_dir($dir)) {
            $args = ['scripts/make-module.mjs', $kebab, '--key', $spec->key(), '--label', $spec->recordLabel(), '--labels', \Illuminate\Support\Str::plural($spec->recordLabel()), '--label-column', $spec->labelColumn(), '--scope', $spec->scope(), '--generated'];
            if (($this->node)($args, $spa) !== 0) {
                throw new RuntimeException("the phone's make-module.mjs could not write the skeleton for {$kebab}");
            }
            $this->say("phone skeleton: make-module.mjs {$kebab} --generated");
        }
        $out = $render->render();
        $written = [];
        foreach ($out['slots'] as $file => $slots) {
            $path = "{$dir}/{$file}";
            if (! is_file($path)) {
                throw new RuntimeException("{$path} is missing: the phone module was not made by make-module.mjs --generated");
            }
            $text = (string) file_get_contents($path);
            foreach ($slots as $slot => $body) {
                $text = Slots::fill($text, $slot, $body, "phone/{$file}");
            }
            if (isset($out['imports'][$file])) {
                $text = Slots::fill($text, 'imports', TsImports::slot($text, $out['imports'][$file]), "phone/{$file}");
            }
            file_put_contents($path, $text);
            $written[] = $file;
        }
        $local = "{$mobile}/app/Local/Modules/{$spec->key()}.php";
        if (! is_file($local)) {
            throw new RuntimeException("{$local} is missing: the phone module was not made by make-module.mjs --generated");
        }
        file_put_contents($local, Slots::fill((string) file_get_contents($local), 'rules', $out['rules'], "phone/Local/{$spec->key()}.php"));
        ($this->format)([$local]);
        $written[] = "app/Local/Modules/{$spec->key()}.php";
        $ns = \Illuminate\Support\Str::camel(str_replace('-', '_', $kebab));
        foreach (['en', 'sw'] as $lang) {
            $path = "{$dir}/locales/{$lang}/{$ns}.json";
            $locale = is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
            $fallback = $lang === 'sw' ? $out['locales']['sw_fallback'] : [];
            file_put_contents($path, LocaleMerger::encode(LocaleMerger::merge($locale, $out['locales'][$lang], $fallback)));
            $written[] = "locales/{$lang}/{$ns}.json";
        }
        $this->say('phone filled: '.implode(', ', $written));

        return $written;
    }

    private function moduleMigration(string $dir, string $table): string
    {
        $found = glob("{$dir}/Migrations/*_create_{$table}_table.php") ?: [];

        return $found[0] ?? throw new RuntimeException("{$dir}/Migrations has no *_create_{$table}_table.php");
    }

    private function relative(string $dir, string $path): string
    {
        return ltrim(substr($path, strlen($dir)), '/');
    }

    private function say(string $line): void
    {
        if ($this->say) {
            ($this->say)($line);
        }
    }
}
