<?php

declare(strict_types=1);

/**
 * @param mixed $value
 */
function owe_bridge_is_json_value($value): bool
{
    if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
        return true;
    }

    if (!is_array($value)) {
        return false;
    }

    foreach ($value as $child) {
        if (!owe_bridge_is_json_value($child)) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, bool> $seenIds
 */
function owe_bridge_validate_element(array $element, array &$seenIds): void
{
    $id = $element['id'] ?? null;
    $type = $element['elType'] ?? null;
    $settings = $element['settings'] ?? [];
    $children = $element['elements'] ?? null;

    if (!is_string($id) || !preg_match('/^[a-f0-9]{6,12}$/', $id)) {
        owe_bridge_fail('INVALID_ELEMENT_ID');
    }
    if (isset($seenIds[$id])) {
        owe_bridge_fail('DUPLICATE_ELEMENT_ID');
    }
    $seenIds[$id] = true;

    if (!is_string($type) || !in_array($type, ['container', 'section', 'column', 'widget'], true)) {
        owe_bridge_fail('INVALID_ELEMENT_TYPE');
    }
    if ($type === 'widget' && (!isset($element['widgetType']) || !is_string($element['widgetType']) || $element['widgetType'] === '')) {
        owe_bridge_fail('WIDGET_TYPE_REQUIRED');
    }
    if (!is_array($settings) || !owe_bridge_is_json_value($settings)) {
        owe_bridge_fail('INVALID_ELEMENT_SETTINGS');
    }
    if (!is_array($children)) {
        owe_bridge_fail('INVALID_ELEMENT_CHILDREN');
    }

    foreach ($children as $child) {
        if (!is_array($child)) {
            owe_bridge_fail('INVALID_CHILD_ELEMENT');
        }
        owe_bridge_validate_element($child, $seenIds);
    }
}

/**
 * @param array<int, mixed> $elements
 */
function owe_bridge_validate_page_elements(array $elements): void
{
    $seenIds = [];
    foreach ($elements as $element) {
        if (!is_array($element)) {
            owe_bridge_fail('INVALID_PAGE_ELEMENT');
        }
        owe_bridge_validate_element($element, $seenIds);
    }
}

/**
 * Block before save when Elementor cannot instantiate an existing widget.
 * @param array<int, mixed> $elements
 */
function owe_bridge_validate_available_widgets(array $elements): void
{
    $manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
    if (!is_object($manager) || !method_exists($manager, 'get_widget_types')) {
        owe_bridge_fail('ELEMENTOR_WIDGET_MANAGER_UNAVAILABLE');
    }
    foreach ($elements as $element) {
        if (!is_array($element)) {
            continue;
        }
        if (($element['elType'] ?? '') === 'widget') {
            $widgetType = isset($element['widgetType']) && is_string($element['widgetType']) ? $element['widgetType'] : '';
            if (!is_object($manager->get_widget_types($widgetType))) {
                owe_bridge_fail('ELEMENTOR_WIDGET_UNAVAILABLE');
            }
        }
        $children = isset($element['elements']) && is_array($element['elements']) ? $element['elements'] : [];
        owe_bridge_validate_available_widgets($children);
    }
}

function owe_bridge_validate_section(array $section, array $existingElements, string $operation, string $targetId): void
{
    $rootType = $section['elType'] ?? null;
    if (!in_array($rootType, ['container', 'section'], true)) {
        owe_bridge_fail('SECTION_ROOT_REQUIRED');
    }

    $seenIds = [];
    owe_bridge_validate_element($section, $seenIds);

    if ($operation === 'replace_section' && ($section['id'] ?? '') !== $targetId) {
        owe_bridge_fail('REPLACEMENT_MUST_PRESERVE_SECTION_ID');
    }

    $existingIds = [];
    foreach ($existingElements as $element) {
        if (!is_array($element)) {
            continue;
        }
        if ($operation === 'replace_section' && ($element['id'] ?? null) === $targetId) {
            continue;
        }
        owe_bridge_collect_ids($element, $existingIds);
    }

    foreach ($seenIds as $id => $_) {
        if (isset($existingIds[$id])) {
            owe_bridge_fail('ELEMENT_ID_COLLISION');
        }
    }
}

/**
 * @param array<string, bool> $ids
 */
function owe_bridge_collect_ids(array $element, array &$ids): void
{
    if (isset($element['id']) && is_string($element['id'])) {
        $ids[$element['id']] = true;
    }
    foreach (($element['elements'] ?? []) as $child) {
        if (is_array($child)) {
            owe_bridge_collect_ids($child, $ids);
        }
    }
}

/**
 * @param array<int, mixed> $patches
 */
function owe_bridge_validate_responsive_patches(array $patches, string $device): void
{
    if (!in_array($device, ['tablet', 'mobile'], true)) {
        owe_bridge_fail('RESPONSIVE_DEVICE_REQUIRED');
    }
    if ($patches === []) {
        owe_bridge_fail('RESPONSIVE_PATCHES_REQUIRED');
    }

    $suffix = '_' . $device;
    $seen = [];
    foreach ($patches as $patch) {
        if (!is_array($patch)) {
            owe_bridge_fail('INVALID_RESPONSIVE_PATCH');
        }
        $elementId = $patch['element_id'] ?? null;
        $settings = $patch['settings'] ?? null;
        if (!is_string($elementId) || $elementId === '' || !is_array($settings) || $settings === []) {
            owe_bridge_fail('INVALID_RESPONSIVE_PATCH');
        }
        if (isset($seen[$elementId])) {
            owe_bridge_fail('DUPLICATE_RESPONSIVE_PATCH');
        }
        $seen[$elementId] = true;

        foreach ($settings as $key => $value) {
            if (!is_string($key) || substr($key, -strlen($suffix)) !== $suffix || !owe_bridge_is_json_value($value)) {
                owe_bridge_fail('DESKTOP_SETTING_REJECTED');
            }
        }
    }
}

/** @return array<int, string> */
function owe_bridge_content_functional_tokens(string $value): array
{
    $tokens = [];
    if (preg_match_all('/<!--[\s\S]*?-->|<\/?(?:a|img|iframe|audio|video|source|track|object|embed|form|input|button|select|option|textarea)\b[^>]*>|<[^>]+\s+(?:id|class|style|href|src|srcset|action|name|value|data-[\w-]+)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)[^>]*>/i', $value, $matches)) {
        foreach ($matches[0] as $token) {
            $tokens[] = $token;
        }
    }
    if (function_exists('get_shortcode_regex')) {
        $pattern = '/' . get_shortcode_regex() . '/s';
    } else {
        $pattern = '/\[[A-Za-z_][A-Za-z0-9_-]*(?:\s[^\]]*)?\](?:[\s\S]*?\[\/[A-Za-z_][A-Za-z0-9_-]*\])?/';
    }
    if (preg_match_all($pattern, $value, $shortcodes)) {
        foreach ($shortcodes[0] as $shortcode) {
            $tokens[] = $shortcode;
        }
    }
    return $tokens;
}

function owe_bridge_sanitize_content_value(string $value, string $format, string $oldValue = ''): string
{
    if (strlen($value) > ($format === 'html' ? 102400 : 10240)) {
        owe_bridge_fail('CONTENT_VALUE_TOO_LARGE');
    }
    if (wp_check_invalid_utf8($value, true) !== $value || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
        owe_bridge_fail('CONTENT_VALUE_INVALID');
    }
    if ($format === 'html') {
        $sanitized = wp_kses_post($value);
        if (owe_bridge_content_functional_tokens($oldValue) !== owe_bridge_content_functional_tokens($value)) {
            owe_bridge_fail('CONTENT_FUNCTIONAL_MARKUP_CHANGED');
        }
    } elseif ($format === 'multiline') {
        $sanitized = sanitize_textarea_field($value);
    } else {
        $sanitized = sanitize_text_field($value);
    }
    if ($sanitized !== $value) {
        owe_bridge_fail('CONTENT_SANITIZATION_CHANGED');
    }
    return $sanitized;
}

/**
 * @param array<int, mixed> $updates
 * @param array<string, array<string, mixed>> $contentIndex
 * @return array<string, array<string, string>>
 */
function owe_bridge_prepare_content_updates(array $updates, array $contentIndex): array
{
    if ($updates === []) {
        owe_bridge_fail('CONTENT_UPDATES_REQUIRED');
    }
    if (count($updates) > 100) {
        owe_bridge_fail('CONTENT_UPDATE_LIMIT_EXCEEDED');
    }

    $prepared = [];
    foreach ($updates as $update) {
        if (!is_array($update) || array_diff(array_keys($update), ['ref', 'expected_content_hash', 'value']) !== []) {
            owe_bridge_fail('INVALID_CONTENT_UPDATE');
        }
        $reference = $update['ref'] ?? null;
        $expectedHash = $update['expected_content_hash'] ?? null;
        $value = $update['value'] ?? null;
        if (!is_string($reference)
            || !preg_match('/^[A-Za-z0-9_-]+(?::[A-Za-z0-9_-]+){1,3}$/', $reference)
            || !is_string($expectedHash)
            || !preg_match('/^[a-f0-9]{64}$/', $expectedHash)
            || !is_string($value)
        ) {
            owe_bridge_fail('INVALID_CONTENT_UPDATE');
        }
        if (isset($prepared[$reference])) {
            owe_bridge_fail('DUPLICATE_CONTENT_FIELD');
        }
        if (!isset($contentIndex[$reference])) {
            owe_bridge_fail('CONTENT_FIELD_NOT_FOUND');
        }
        $entry = $contentIndex[$reference];
        if (!hash_equals((string) $entry['hash'], $expectedHash)) {
            owe_bridge_fail('STALE_CONTENT_HASH');
        }
        $sanitized = owe_bridge_sanitize_content_value(
            $value,
            (string) $entry['format'],
            (string) $entry['value']
        );
        $prepared[$reference] = [
            'old' => (string) $entry['value'],
            'new' => $sanitized,
        ];
    }
    return $prepared;
}
