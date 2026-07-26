<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\MobileApp\Backend\Models;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Models\MobileModelGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for MobileModelGenerator — defect class 5
 * (unsubstituted MOBILE_APP scaffold placeholders).
 *
 * Bug: mobile_app/backend/model.stub references [[connection]] and
 * [[timestamps]] placeholders, but MobileModelGenerator::generate()'s
 * $replacements array never included keys for them — so BaseGenerator::
 * replacePlaceholders() (whose $defaultReplacements array also doesn't
 * define them) left the literal text "[[connection]]" / "[[timestamps]]"
 * in every generated MOBILE_APP model. Confirmed in
 * MOBILE_APP/.../LedgerTransactionsModel.php and MemberPhonesModel.php.
 *
 * Fix: generate() now supplies both replacements explicitly.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Models\MobileModelGenerator::generate()
 */
class MobileModelGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-mobile-model-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        $this->removeDirectory($this->tmpRoot);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function generate(array $config): string
    {
        $generator = new MobileModelGenerator('TestLedger', 'Sacco', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/MOBILE_APP/app/Modules/Sacco/TestLedger/TestLedgerModel.php';
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function test_no_unsubstituted_placeholder_text_survives_in_generated_output(): void
    {
        $content = $this->generate([
            'table_name' => 'ledger_transactions',
            'columns' => [
                ['name' => 'amount', 'type' => 'decimal'],
                ['name' => 'is_reversal', 'type' => 'boolean'],
            ],
        ]);

        $this->assertStringNotContainsString('[[', $content, 'No "[[placeholder]]" token may survive into generated MOBILE_APP model output.');
        $this->assertStringNotContainsString(']]', $content);
    }

    public function test_connection_and_timestamps_placeholders_specifically_are_resolved(): void
    {
        $content = $this->generate([
            'table_name' => 'ledger_transactions',
            'columns' => [],
        ]);

        $this->assertStringNotContainsString('[[connection]]', $content);
        $this->assertStringNotContainsString('[[timestamps]]', $content);
    }

    public function test_connection_line_is_emitted_when_configured(): void
    {
        $content = $this->generate([
            'table_name' => 'ledger_transactions',
            'columns'    => [],
            'connection' => 'mobile_sqlite',
        ]);

        $this->assertStringContainsString("protected \$connection = 'mobile_sqlite';", $content);
    }

    /**
     * Guards against the "malformed duplicated casts() block" symptom
     * mentioned alongside the placeholder leak: exactly one casts() method
     * must ever be emitted, never two.
     */
    public function test_casts_method_is_emitted_exactly_once(): void
    {
        $content = $this->generate([
            'table_name' => 'ledger_transactions',
            'columns' => [
                ['name' => 'amount', 'type' => 'decimal'],
            ],
        ]);

        $this->assertSame(1, substr_count($content, 'function casts()'), 'Exactly one casts() method must be emitted — never a duplicated block.');
    }

    /**
     * Unlike the BACKEND model stub, generated MOBILE_APP models
     * deliberately do NOT define a newFactory() override, do NOT use the
     * HasFactory trait, and do NOT extend BaseModel. There is no mobile
     * equivalent of FactoryGenerator anywhere in the generation pipeline
     * (see src/Generators/MobileApp/**), so a co-located `{Module}Factory`
     * class is never emitted for mobile modules. Adding a `newFactory()`
     * override to the mobile stub would reference a class that does not
     * exist on disk, which would be a fatal error the moment Eloquent
     * needed to resolve a factory. This test locks in the current,
     * intentional absence of that override as expected behavior, not a
     * gap to silently regress away from.
     */
    public function test_generated_model_does_not_reference_a_nonexistent_factory(): void
    {
        $content = $this->generate([
            'table_name' => 'ledger_transactions',
            'columns' => [
                ['name' => 'amount', 'type' => 'decimal'],
            ],
        ]);

        $this->assertStringNotContainsString('newFactory', $content);
        $this->assertStringNotContainsString('HasFactory', $content);
        $this->assertStringNotContainsString('Factory::new()', $content);
    }
}
