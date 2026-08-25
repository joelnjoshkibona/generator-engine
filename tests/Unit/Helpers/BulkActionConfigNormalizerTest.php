<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Helpers;

use Blutrixx\GeneratorEngine\Helpers\BulkActionConfigNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * First dedicated Helpers/-namespace unit test (ActionConfigNormalizer and
 * DelegationConfigNormalizer are so far only exercised indirectly, through
 * the generators that call them). BulkActionConfigNormalizer earns one of
 * its own because normalizeAll() deliberately deviates from its two
 * siblings' shape — see its own docblock — and that deviation is exactly
 * the behavior most likely to silently regress without a direct test.
 */
class BulkActionConfigNormalizerTest extends TestCase
{
    public function test_normalize_fills_every_default_when_only_key_is_given(): void
    {
        $result = BulkActionConfigNormalizer::normalize(['key' => 'archive']);

        $this->assertSame([
            'key' => 'archive',
            'label' => 'archive',
            'icon' => '',
            'requiresPermission' => '',
            'confirmMessage' => '',
            'variant' => '',
            'status_target' => null,
        ], $result);
    }

    public function test_normalize_preserves_every_explicitly_given_value(): void
    {
        $action = [
            'key' => 'markReceived',
            'label' => 'Mark Received',
            'icon' => 'check',
            'requiresPermission' => 'PurchaseOrders.bulkAction',
            'confirmMessage' => 'Are you sure?',
            'variant' => 'primary',
            'status_target' => 'RECEIVED',
        ];

        $this->assertSame($action, BulkActionConfigNormalizer::normalize($action));
    }

    public function test_normalize_label_falls_back_to_key_not_a_hardcoded_string(): void
    {
        $result = BulkActionConfigNormalizer::normalize(['key' => 'cancel']);

        $this->assertSame('cancel', $result['label']);
    }

    public function test_normalize_on_a_completely_empty_action_yields_empty_key_and_label(): void
    {
        $result = BulkActionConfigNormalizer::normalize([]);

        $this->assertSame('', $result['key']);
        $this->assertSame('', $result['label']);
    }

    public function test_normalize_all_normalizes_every_entry(): void
    {
        $result = BulkActionConfigNormalizer::normalizeAll([
            ['key' => 'archive'],
            ['key' => 'cancel', 'label' => 'Cancel Order'],
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('archive', $result[0]['key']);
        $this->assertSame('archive', $result[0]['label']);
        $this->assertSame('cancel', $result[1]['key']);
        $this->assertSame('Cancel Order', $result[1]['label']);
    }

    /**
     * The behavior that justifies this class existing separately from a
     * plain array_map: normalizeAll() itself drops empty-key entries,
     * unlike ActionConfigNormalizer::normalizeAll()/DelegationConfigNormalizer::
     * normalizeAll(), which normalize everything and leave emptiness
     * checking to their own uncalled validate(). Fixes a confirmed real
     * inconsistency where one of three hand-rolled call sites
     * (ListServiceGenerator::generateBulkActionsArray(), pre-fix) forgot to
     * skip an empty key.
     */
    public function test_normalize_all_drops_entries_with_an_empty_key(): void
    {
        $result = BulkActionConfigNormalizer::normalizeAll([
            ['key' => 'archive'],
            ['label' => 'No key here'],
            ['key' => ''],
            ['key' => 'cancel'],
        ]);

        $this->assertCount(2, $result);
        $this->assertSame(['archive', 'cancel'], array_column($result, 'key'));
    }

    public function test_normalize_all_reindexes_after_filtering_so_no_gaps_remain(): void
    {
        $result = BulkActionConfigNormalizer::normalizeAll([
            ['key' => ''],
            ['key' => 'archive'],
        ]);

        $this->assertSame([0], array_keys($result));
    }

    public function test_normalize_all_on_an_empty_list_returns_an_empty_list(): void
    {
        $this->assertSame([], BulkActionConfigNormalizer::normalizeAll([]));
    }

    public function test_validate_reports_an_error_when_key_is_missing(): void
    {
        $errors = BulkActionConfigNormalizer::validate([]);

        $this->assertNotEmpty($errors);
        $this->assertSame('Bulk action key is required', $errors[0]);
    }

    public function test_validate_reports_an_error_when_key_is_an_empty_string(): void
    {
        $errors = BulkActionConfigNormalizer::validate(['key' => '']);

        $this->assertNotEmpty($errors);
    }

    public function test_validate_reports_no_errors_when_key_is_present(): void
    {
        $this->assertSame([], BulkActionConfigNormalizer::validate(['key' => 'archive']));
    }

    // ─── String shorthand ─────────────────────────────────────────────────────

    public function test_normalize_accepts_the_bare_string_shorthand_as_the_key(): void
    {
        $result = BulkActionConfigNormalizer::normalize('activate');

        $this->assertSame('activate', $result['key']);
        $this->assertSame('activate', $result['label'], 'label defaults to the key');
        $this->assertSame('', $result['icon']);
        $this->assertNull($result['status_target']);
    }

    public function test_normalize_all_accepts_a_list_of_bare_strings(): void
    {
        // The exact shape Core/Users/Users/module.json ships:
        //   "bulk_actions": ["activate", "deactivate"]
        // Before this was accepted, every consumer funnelling through normalizeAll()
        // (ListServiceGenerator, BulkActionServiceGenerator, ListPageGenerator) raised
        // a TypeError on that module — an \Error, not an \Exception, so it escaped
        // the per-generator catch and aborted the whole run.
        $result = BulkActionConfigNormalizer::normalizeAll(['activate', 'deactivate']);

        $this->assertCount(2, $result);
        $this->assertSame(['activate', 'deactivate'], array_column($result, 'key'));
    }

    public function test_normalize_all_accepts_string_and_map_entries_side_by_side(): void
    {
        $result = BulkActionConfigNormalizer::normalizeAll([
            'activate',
            ['key' => 'archive', 'label' => 'Archive Selected'],
        ]);

        $this->assertSame(['activate', 'archive'], array_column($result, 'key'));
        $this->assertSame(['activate', 'Archive Selected'], array_column($result, 'label'));
    }

    public function test_normalize_all_drops_an_empty_string_entry(): void
    {
        $this->assertSame([], BulkActionConfigNormalizer::normalizeAll(['']));
    }
}
