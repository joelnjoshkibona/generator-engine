<?php

namespace Blutrixx\GeneratorEngine\Helpers;

class MobileAppConfigResolver
{
    /**
     * Resolve the module icon from config with fallback chain.
     *
     * @param array $config Module configuration
     * @return string Icon name (e.g., 'LayersIcon')
     */
    public static function resolveModuleIcon(array $config): string
    {
        return $config['features']['mobile_app']['icon'] ?? 'LayersIcon';
    }

    /**
     * Resolve list card configuration from config with fallback chains.
     *
     * @param array $config Module configuration
     * @return array Card configuration with keys: icon, titleField, subtitleFields, bodyFields, footerBadge
     */
    public static function resolveListCard(array $config): array
    {
        $mobileConfig = $config['features']['mobile_app']['list']['card'] ?? [];
        $frontendConfig = $config['features']['frontend']['list'] ?? [];
        // Read from 'fields' (new key), fall back to 'columns' (legacy)
        $fields = $config['features']['frontend']['list']['fields']
            ?? $config['features']['frontend']['list']['columns']
            ?? [];

        // Resolve icon
        $icon = $mobileConfig['icon']
            ?? $config['features']['mobile_app']['icon']
            ?? 'LayersIcon';

        // Resolve title field
        $titleField = $mobileConfig['titleField']
            ?? $frontendConfig['primaryDisplayField']
            ?? $config['features']['frontend']['view']['primaryDisplayField']
            ?? 'name';

        // Resolve subtitle fields
        $subtitleFields = $mobileConfig['subtitleFields'] ?? [];
        if (empty($subtitleFields) && is_array($fields)) {
            $subtitleFields = self::findSubtitleFields($fields, $titleField);
        }

        // Resolve body fields
        $bodyFields = $mobileConfig['bodyFields'] ?? [];
        if (empty($bodyFields) && is_array($fields)) {
            $bodyFields = self::findBodyFields($fields, $titleField, $subtitleFields);
        }

        // Resolve footer badge
        $footerBadge = $mobileConfig['footerBadge'] ?? null;

        return [
            'icon' => $icon,
            'titleField' => $titleField,
            'subtitleFields' => $subtitleFields,
            'bodyFields' => $bodyFields,
            'footerBadge' => $footerBadge,
        ];
    }

    /**
     * Find subtitle fields from fields array.
     * Returns first field that is not the title, not a system field, and not an action/checkbox.
     *
     * @param array $fields Frontend list fields
     * @param string $titleField The primary title field
     * @return array Array of subtitle field keys
     */
    private static function findSubtitleFields(array $fields, string $titleField): array
    {
        $subtitleFields = [];
        $systemFieldPattern = '/^(id|uuid|created_at|updated_at|deleted_at|created_by|updated_by)$/i';

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $key = $field['key'] ?? null;
            $type = $field['type'] ?? null;

            if (empty($key)) {
                continue;
            }

            // Skip title field
            if ($key === $titleField) {
                continue;
            }

            // Skip system fields (id, uuid, timestamps, etc.)
            if (preg_match($systemFieldPattern, $key)) {
                continue;
            }

            // Skip action/checkbox field types
            if ($type === 'actions' || $type === 'checkbox') {
                continue;
            }

            // Found first eligible field
            $subtitleFields[] = $key;
            break;
        }

        return $subtitleFields;
    }

    /**
     * Find body fields from fields array.
     * Prefers a field matching description/body/notes/details/bio/summary/about pattern.
     * Returns empty array if no match found (no fallback guessing).
     *
     * @param array $fields Frontend list fields
     * @param string $titleField The primary title field
     * @param array $subtitleFields Subtitle field keys to skip
     * @return array Array of body field keys
     */
    private static function findBodyFields(array $fields, string $titleField, array $subtitleFields): array
    {
        $bodyFields = [];
        $skipKeys = array_merge([$titleField], $subtitleFields);
        $bodyFieldPattern = '/^(description|body|notes|details|bio|summary|about)$/i';

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $key = $field['key'] ?? null;

            if (empty($key)) {
                continue;
            }

            // Skip already used fields
            if (in_array($key, $skipKeys, true)) {
                continue;
            }

            // Check if key matches body-field pattern
            if (preg_match($bodyFieldPattern, $key)) {
                $bodyFields[] = $key;
                break;
            }
        }

        return $bodyFields;
    }
}
