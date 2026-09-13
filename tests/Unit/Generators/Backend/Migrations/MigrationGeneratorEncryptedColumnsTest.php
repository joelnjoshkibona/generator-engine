<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Migrations;

use Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * A hashed/encrypted column's stored bytes differ on every write (bcrypt
 * salts randomly, Laravel's encrypter uses a random IV per call) — a DB
 * unique index on one can never actually prevent two rows sharing the same
 * real secret, and would spuriously reject re-saving a row's own unchanged
 * value the next time it's written. Ciphertext is also opaque and
 * length-unstable: Laravel's `encrypted` cast routinely produces output
 * 2-4x longer than the plaintext, so a configured varchar length cannot be
 * trusted to hold it.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator::generateFieldSchema()
 */
class MigrationGeneratorEncryptedColumnsTest extends TestCase
{
    private string $tmpRoot;

    /** @var list<string> */
    private array $issues = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-migration-encrypted-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);

        $this->issues = [];
        PathManager::setIssueHandler(function (string $message, string $level = 'warning'): void {
            $this->issues[] = "[{$level}] {$message}";
        });
    }

    protected function tearDown(): void
    {
        PathManager::setIssueHandler(null);
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

    private function migrationsDir(string $module = 'Widgets', string $group = 'Core'): string
    {
        return $this->tmpRoot . "/BACKEND/app/Project/Modules/{$group}/{$module}/Migrations";
    }

    /** The one generated line for a given column name — the uuid column always carries its own ->unique(), so assertions must be scoped per-line. */
    private function fieldLine(string $content, string $columnName): string
    {
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            if (str_contains($line, "\$table->") && str_contains($line, "'{$columnName}'")) {
                return $line;
            }
        }
        $this->fail("Could not find a schema line for column '{$columnName}' in generated content.");
    }

    private function generateAndRead(array $column, string $module = 'Widgets', string $group = 'Core'): string
    {
        $config = [
            'table_name' => 'widgets',
            'id_type'    => 'autoincrement',
            'columns'    => [
                ['name' => 'id', 'type' => 'id'],
                $column,
            ],
        ];

        $generator = new MigrationGenerator($module, $group, $config);
        $this->assertTrue($generator->generate());

        $files = glob($this->migrationsDir($module, $group) . '/*_create_widgets_table.php') ?: [];
        $this->assertCount(1, $files);

        $content = file_get_contents($files[0]);
        $this->assertNotFalse($content);

        exec('php -l ' . escapeshellarg($files[0]) . ' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));

        return $content;
    }

    public function test_an_encrypted_column_becomes_text_with_no_length_argument(): void
    {
        $content = $this->generateAndRead(['name' => 'secret', 'type' => 'string', 'length' => 120]);

        $this->assertStringContainsString("\$table->text('secret');", $content);
        $this->assertStringNotContainsString("\$table->string('secret'", $content);
    }

    public function test_a_hashed_column_is_widened_to_255(): void
    {
        $content = $this->generateAndRead(['name' => 'pin', 'type' => 'string', 'length' => 10]);

        $this->assertStringContainsString("\$table->string('pin', 255)", $content);
    }

    public function test_a_hashed_column_with_no_configured_length_is_still_widened_explicitly(): void
    {
        $content = $this->generateAndRead(['name' => 'password', 'type' => 'string']);

        $this->assertStringContainsString("\$table->string('password', 255)", $content);
    }

    public function test_a_hashed_unique_column_drops_the_constraint_and_reports_an_error(): void
    {
        $content = $this->generateAndRead(['name' => 'password', 'type' => 'string', 'unique' => true]);

        $this->assertStringNotContainsString('->unique(', $this->fieldLine($content, 'password'));

        $errorIssues = array_filter($this->issues, fn (string $i): bool => str_starts_with($i, '[error]'));
        $this->assertCount(1, $errorIssues);
        $issueText = implode("\n", $errorIssues);
        $this->assertStringContainsString('password', $issueText);
        $this->assertStringContainsString('unique', $issueText);
    }

    public function test_an_encrypted_unique_column_drops_the_constraint_and_reports_an_error(): void
    {
        $content = $this->generateAndRead(['name' => 'secret', 'type' => 'string', 'unique' => true]);

        $this->assertStringNotContainsString('->unique(', $this->fieldLine($content, 'secret'));
        $this->assertCount(1, array_filter($this->issues, fn (string $i): bool => str_starts_with($i, '[error]')));
    }

    public function test_a_plain_storage_sensitive_unique_column_is_unaffected(): void
    {
        $content = $this->generateAndRead(['name' => 'key_hash', 'type' => 'string', 'length' => 64, 'unique' => true]);

        $this->assertStringContainsString('->unique(', $this->fieldLine($content, 'key_hash'));
        $this->assertSame([], $this->issues);
    }

    public function test_a_non_sensitive_unique_column_is_byte_identical_to_today(): void
    {
        $content = $this->generateAndRead(['name' => 'name', 'type' => 'string', 'length' => 100, 'unique' => true]);

        $line = $this->fieldLine($content, 'name');
        $this->assertStringContainsString("\$table->string('name', 100)", $line);
        $this->assertStringContainsString('->unique(', $line);
        $this->assertSame([], $this->issues);
    }
}
