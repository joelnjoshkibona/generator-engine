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
     * Resolve list card field roles from config, with a fallback chain that
     * mirrors ListPageBareCards.vue's own runtime name-regex heuristics
     * exactly (badgeColumn/footerAmountColumn/footerDateColumn) -- so a
     * module with no explicit mobile_app.list.card config resolves to the
     * IDENTICAL roles the card already picks at runtime today; only a
     * module with explicit config gets an override baked in at generation
     * time. This is a rewrite, not a revival: the previous version resolved
     * titleField/subtitleFields/bodyFields/footerBadge-as-static-text, a
     * card shape (title + subtitle line + body paragraph + static footer
     * label) that doesn't match the badge/footer-amount/footer-date card
     * ListPageBareCards.vue actually renders -- it was also never called
     * from anywhere, so reviving it as-is would have wired up the wrong
     * design. It also read `frontendConfig['primaryDisplayField']`, a key
     * that doesn't exist anywhere in the real schema (the real key is
     * `primaryField`) -- fixed here too.
     *
     * @param array $config Module configuration
     * @return array {titleField, badgeField, footerAmountField, footerDateField, bodyFields}
     */
    public static function resolveListCard(array $config): array
    {
        $mobileConfig = $config['features']['mobile_app']['list']['card'] ?? [];
        $frontendListConfig = $config['features']['frontend']['list'] ?? [];
        $fields = $frontendListConfig['fields'] ?? $frontendListConfig['columns'] ?? [];
        $fieldKeys = array_values(array_filter(array_map(
            static fn ($field) => is_array($field) ? ($field['key'] ?? null) : null,
            $fields
        )));

        $titleField = self::resolveIfValid($mobileConfig['titleField'] ?? null, $fieldKeys)
            ?? self::resolveIfValid($frontendListConfig['primaryField'] ?? null, $fieldKeys)
            ?? ($fieldKeys[0] ?? null);

        $badgeField = self::resolveIfValid($mobileConfig['badgeField'] ?? null, $fieldKeys)
            ?? self::matchByPattern($fields, '/status/i', [$titleField]);

        $footerAmountField = self::resolveIfValid($mobileConfig['footerAmountField'] ?? null, $fieldKeys)
            ?? self::matchByPattern($fields, '/total|amount|price/i', [$titleField, $badgeField]);

        $footerDateField = self::resolveIfValid($mobileConfig['footerDateField'] ?? null, $fieldKeys)
            ?? self::matchByPattern($fields, '/date/i', [$titleField, $badgeField, $footerAmountField]);

        $bodyFields = $mobileConfig['bodyFields'] ?? [];
        if (is_array($bodyFields)) {
            $bodyFields = array_values(array_filter($bodyFields, static fn ($key) => in_array($key, $fieldKeys, true)));
        } else {
            $bodyFields = [];
        }
        if (empty($bodyFields)) {
            $used = array_filter([$titleField, $badgeField, $footerAmountField, $footerDateField]);
            $bodyFields = array_values(array_diff($fieldKeys, $used));
        }

        return [
            'titleField' => $titleField,
            'badgeField' => $badgeField,
            'footerAmountField' => $footerAmountField,
            'footerDateField' => $footerDateField,
            'bodyFields' => $bodyFields,
        ];
    }

    /**
     * A config-supplied field key is only honored if it still exists on the
     * module's current list fields -- a stale reference to a removed field
     * silently falls through to the next candidate in the chain instead of
     * annotating a column that will never be found at runtime.
     */
    private static function resolveIfValid(?string $key, array $fieldKeys): ?string
    {
        return ($key !== null && in_array($key, $fieldKeys, true)) ? $key : null;
    }

    /**
     * First field (by key or title) matching $pattern, excluding any key
     * already claimed by a higher-priority role. Same precedence order and
     * regexes as ListPageBareCards.vue's badgeColumn/footerAmountColumn/
     * footerDateColumn computeds.
     */
    private static function matchByPattern(array $fields, string $pattern, array $exclude): ?string
    {
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = $field['key'] ?? null;
            if ($key === null || in_array($key, $exclude, true)) {
                continue;
            }
            $title = $field['title'] ?? $key;
            if (preg_match($pattern, $key) || preg_match($pattern, $title)) {
                return $key;
            }
        }

        return null;
    }
}
