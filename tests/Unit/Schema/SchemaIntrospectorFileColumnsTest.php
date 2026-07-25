<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\SchemaIntrospector;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for SchemaIntrospector::filterFileColumns() (the pure
 * half of fileColumns()) — the fix for "no schema-level file/upload column
 * detection". Previously, IntrospectionToConfig::buildFrontendFormFields()
 * only ever rendered a `file-input` field when the caller passed an
 * explicit `$meta['file_columns']` hint; there was zero automatic
 * inference from the live schema, so every generated module with an
 * image/document column needed hand-patching after generation.
 *
 * filterFileColumns() takes a SchemaIntrospector::columns()-shaped array
 * directly (rather than requiring a live DB connection this package has no
 * dev dependency to spin up in tests) so the heuristic itself can be
 * exercised in isolation. fileColumns() is a one-line wrapper around it
 * that feeds in the real live-schema columns() output.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\SchemaIntrospector::fileColumns()
 * @see \Blutrixx\GeneratorEngine\Schema\SchemaIntrospector::filterFileColumns()
 */
class SchemaIntrospectorFileColumnsTest extends TestCase
{
    /** Build a single columns()-shaped row with sane defaults, override as needed. */
    private function col(string $name, array $overrides = []): array
    {
        return array_merge([
            'name'            => $name,
            'type'            => 'varchar',
            'normalized_type' => 'string',
            'length'          => 255,
            'nullable'        => true,
            'default'         => null,
            'is_fk'           => false,
            'foreign_table'   => null,
            'foreign_column'  => null,
            'is_unique'       => false,
            'morph_role'      => null,
            'morph_name'      => null,
        ], $overrides);
    }

    public function test_suffix_patterns_are_detected(): void
    {
        $columns = [
            $this->col('profile_image'),
            $this->col('receipt_file'),
            $this->col('upload_path'),
            $this->col('user_photo'),
            $this->col('company_logo'),
            $this->col('id_avatar'),
            $this->col('scan_attachment'),
            $this->col('signed_document'),
        ];

        $result = SchemaIntrospector::filterFileColumns($columns);

        $this->assertSame([
            'profile_image', 'receipt_file', 'upload_path', 'user_photo',
            'company_logo', 'id_avatar', 'scan_attachment', 'signed_document',
        ], $result);
    }

    public function test_bare_exact_patterns_are_detected(): void
    {
        $columns = [
            $this->col('image'),
            $this->col('photo'),
            $this->col('avatar'),
            $this->col('logo'),
            $this->col('file'),
        ];

        $result = SchemaIntrospector::filterFileColumns($columns);

        $this->assertSame(['image', 'photo', 'avatar', 'logo', 'file'], $result);
    }

    public function test_foreign_keys_are_excluded_even_when_the_name_matches(): void
    {
        // A hypothetical FK column that happens to be named like a file
        // column (e.g. pointing at a media/attachments table) must never be
        // inferred as a raw file/path column — it's a relation.
        $columns = [
            $this->col('avatar_id', ['normalized_type' => 'foreignId', 'is_fk' => true, 'foreign_table' => 'media']),
        ];

        $this->assertSame([], SchemaIntrospector::filterFileColumns($columns));
    }

    public function test_url_suffixed_columns_are_not_matched(): void
    {
        // "*_url" columns read as external links, not local uploads — not
        // in the pattern list, so they must never match.
        $columns = [
            $this->col('image_url'),
            $this->col('avatar_url'),
            $this->col('website_url'),
        ];

        $this->assertSame([], SchemaIntrospector::filterFileColumns($columns));
    }

    public function test_bare_path_column_is_not_matched(): void
    {
        // Routing/menu-style tables often have a bare "path" column (the
        // URL/route path), which is not a file reference. Only the SUFFIX
        // form ("*_path") is trusted, never the bare word.
        $columns = [$this->col('path')];

        $this->assertSame([], SchemaIntrospector::filterFileColumns($columns));
    }

    public function test_non_string_typed_columns_are_never_matched_regardless_of_name(): void
    {
        // Already-typed json/boolean/numeric/date columns are never file
        // candidates no matter how their name reads.
        $columns = [
            $this->col('image', ['normalized_type' => 'json']),
            $this->col('logo', ['normalized_type' => 'boolean']),
            $this->col('file', ['normalized_type' => 'integer']),
            $this->col('avatar_id_number', ['normalized_type' => 'bigInteger']),
        ];

        $this->assertSame([], SchemaIntrospector::filterFileColumns($columns));
    }

    public function test_ordinary_business_columns_are_not_matched(): void
    {
        $columns = [
            $this->col('name'),
            $this->col('description'),
            $this->col('email'),
            $this->col('code'),
        ];

        $this->assertSame([], SchemaIntrospector::filterFileColumns($columns));
    }

    public function test_explicit_caller_supplied_file_columns_are_unaffected_by_this_method(): void
    {
        // filterFileColumns()/fileColumns() are purely additive inference —
        // they never read or override an explicit $meta['file_columns']
        // hint; that precedence lives entirely in
        // IntrospectionToConfig::buildFrontendFormFields(), untouched here.
        // This test documents the intended composition at the call site.
        $inferred = SchemaIntrospector::filterFileColumns([$this->col('receipt_file')]);
        $explicit = ['manually_flagged_column'];

        $merged = array_values(array_unique(array_merge($explicit, $inferred)));

        $this->assertSame(['manually_flagged_column', 'receipt_file'], $merged);
    }
}
