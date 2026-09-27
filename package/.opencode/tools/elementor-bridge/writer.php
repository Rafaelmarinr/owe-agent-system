<?php

declare(strict_types=1);

/**
 * @param array<int, mixed> $elements
 * @return array<int, mixed>
 */
function owe_bridge_apply_desktop_operation(
    array $elements,
    string $operation,
    string $targetId,
    array $section
): array {
    if ($operation === 'append_section') {
        $elements[] = $section;
        return $elements;
    }

    $targetIndex = owe_bridge_find_top_level_index($elements, $targetId);
    if ($targetIndex < 0) {
        owe_bridge_fail('TARGET_SECTION_NOT_FOUND');
    }

    if ($operation === 'insert_section_before') {
        array_splice($elements, $targetIndex, 0, [$section]);
        return $elements;
    }
    if ($operation === 'insert_section_after') {
        array_splice($elements, $targetIndex + 1, 0, [$section]);
        return $elements;
    }
    if ($operation === 'replace_section') {
        $elements[$targetIndex] = $section;
        return array_values($elements);
    }

    owe_bridge_fail('UNSUPPORTED_OPERATION');
}

/**
 * @param array<int, mixed> $elements
 * @param array<string, array<string, mixed>> $patchMap
 * @param array<string, bool> $allowedIds
 * @param array<string, bool> $foundIds
 * @return array<int, mixed>
 */
function owe_bridge_patch_responsive_elements(
    array $elements,
    array $patchMap,
    array $allowedIds,
    array &$foundIds
): array {
    foreach ($elements as $index => $element) {
        if (!is_array($element)) {
            continue;
        }

        $elementId = isset($element['id']) && is_string($element['id']) ? $element['id'] : '';
        if ($elementId !== '' && isset($patchMap[$elementId])) {
            if (!isset($allowedIds[$elementId])) {
                owe_bridge_fail('PATCH_OUTSIDE_TARGET_SECTION');
            }
            $settings = isset($element['settings']) && is_array($element['settings']) ? $element['settings'] : [];
            foreach ($patchMap[$elementId] as $key => $value) {
                $settings[$key] = $value;
            }
            $element['settings'] = $settings;
            $foundIds[$elementId] = true;
        }

        if (isset($element['elements']) && is_array($element['elements'])) {
            $element['elements'] = owe_bridge_patch_responsive_elements(
                $element['elements'],
                $patchMap,
                $allowedIds,
                $foundIds
            );
        }

        $elements[$index] = $element;
    }

    return $elements;
}

/**
 * @param array<int, mixed> $elements
 * @param array<int, mixed> $patches
 * @return array<int, mixed>
 */
function owe_bridge_apply_responsive_operation(array $elements, string $sectionId, array $patches): array
{
    $allowedIds = owe_bridge_ids_in_section($elements, $sectionId);
    $patchMap = [];
    foreach ($patches as $patch) {
        $patchMap[(string) $patch['element_id']] = $patch['settings'];
    }

    $foundIds = [];
    $elements = owe_bridge_patch_responsive_elements($elements, $patchMap, $allowedIds, $foundIds);

    foreach ($patchMap as $elementId => $_) {
        if (!isset($foundIds[$elementId])) {
            owe_bridge_fail('PATCH_ELEMENT_NOT_FOUND');
        }
    }

    return $elements;
}

/**
 * @param array<string, string> $values
 * @param array<string, bool> $found
 */
function owe_bridge_replace_widget_content(array $element, array $values, array &$found): array
{
    $index = owe_bridge_widget_content_index($element);
    if ($index !== []) {
        $settings = isset($element['settings']) && is_array($element['settings']) ? $element['settings'] : [];
        foreach ($index as $reference => $entry) {
            if (!array_key_exists($reference, $values)) {
                continue;
            }
            $repeater = (string) $entry['_repeater'];
            if ($repeater === '') {
                $settings[(string) $entry['_control']] = $values[$reference];
                $found[$reference] = true;
                continue;
            }
            foreach ($settings[$repeater] as $itemIndex => $item) {
                if (is_array($item) && ($item['_id'] ?? null) === $entry['_item_id']) {
                    $settings[$repeater][$itemIndex][(string) $entry['_control']] = $values[$reference];
                    $found[$reference] = true;
                    break;
                }
            }
        }
        $element['settings'] = $settings;
    }
    foreach (($element['elements'] ?? []) as $index => $child) {
        if (is_array($child)) {
            $element['elements'][$index] = owe_bridge_replace_widget_content($child, $values, $found);
        }
    }
    return $element;
}

/**
 * @param array<int, mixed> $elements
 * @param array<string, string> $values
 * @return array<int, mixed>
 */
function owe_bridge_replace_content_values(array $elements, string $sectionId, array $values): array
{
    $targetIndex = owe_bridge_find_top_level_index($elements, $sectionId);
    if ($targetIndex < 0 || !is_array($elements[$targetIndex])) {
        owe_bridge_fail('TARGET_SECTION_NOT_FOUND');
    }
    $found = [];
    $elements[$targetIndex] = owe_bridge_replace_widget_content($elements[$targetIndex], $values, $found);
    foreach ($values as $reference => $_) {
        if (!isset($found[$reference])) {
            owe_bridge_fail('CONTENT_FIELD_NOT_FOUND');
        }
    }
    return $elements;
}

/**
 * @param array<int, mixed> $before
 * @param array<string, array<string, string>> $updates
 * @return array<int, mixed>
 */
function owe_bridge_apply_content_operation(array $before, string $sectionId, array $updates): array
{
    $values = [];
    foreach ($updates as $reference => $update) {
        $values[$reference] = $update['new'];
    }
    return owe_bridge_replace_content_values($before, $sectionId, $values);
}

/**
 * @param array<string, string> $values
 * @param array<string, bool> $found
 */
function owe_bridge_replace_widget_style(array $element, array $values, array &$found): array
{
    $elementId = isset($element['id']) && is_string($element['id']) ? $element['id'] : '';
    if (($element['elType'] ?? '') === 'widget' && isset($values[$elementId])) {
        $settings = isset($element['settings']) && is_array($element['settings']) ? $element['settings'] : [];
        $settings['custom_css'] = $values[$elementId];
        $element['settings'] = $settings;
        $found[$elementId] = true;
    }
    foreach (($element['elements'] ?? []) as $index => $child) {
        if (is_array($child)) {
            $element['elements'][$index] = owe_bridge_replace_widget_style($child, $values, $found);
        }
    }
    return $element;
}

/**
 * @param array<int, mixed> $before
 * @param array<string, array<string, string>> $updates
 * @return array<int, mixed>
 */
function owe_bridge_apply_style_operation(array $before, string $sectionId, array $updates): array
{
    $targetIndex = owe_bridge_find_top_level_index($before, $sectionId);
    if ($targetIndex < 0 || !is_array($before[$targetIndex])) {
        owe_bridge_fail('TARGET_SECTION_NOT_FOUND');
    }
    $values = [];
    foreach ($updates as $elementId => $update) {
        $values[$elementId] = $update['new'];
    }
    $found = [];
    $before[$targetIndex] = owe_bridge_replace_widget_style($before[$targetIndex], $values, $found);
    foreach ($values as $elementId => $_) {
        if (!isset($found[$elementId])) {
            owe_bridge_fail('STYLE_WIDGET_NOT_FOUND');
        }
    }
    return $before;
}

/**
 * @param array<int, mixed> $before
 * @param array<int, mixed> $after
 * @param array<string, array<string, string>> $updates
 */
function owe_bridge_verify_style_isolation(array $before, array $after, string $sectionId, array $updates): void
{
    $oldValues = [];
    foreach ($updates as $elementId => $update) {
        $oldValues[$elementId] = $update['old'];
    }
    $masked = owe_bridge_apply_style_operation($after, $sectionId, array_map(
        static fn (string $value): array => ['new' => $value],
        $oldValues
    ));
    if (!hash_equals(owe_bridge_elements_hash($before), owe_bridge_elements_hash($masked))) {
        owe_bridge_fail('STYLE_DIFF_OUT_OF_SCOPE');
    }
}

/**
 * @param array<int, mixed> $before
 * @param array<int, mixed> $after
 * @param array<string, array<string, string>> $updates
 */
function owe_bridge_verify_content_isolation(array $before, array $after, string $sectionId, array $updates): void
{
    $oldValues = [];
    foreach ($updates as $reference => $update) {
        $oldValues[$reference] = $update['old'];
    }
    $masked = owe_bridge_replace_content_values($after, $sectionId, $oldValues);
    if (!hash_equals(owe_bridge_elements_hash($before), owe_bridge_elements_hash($masked))) {
        owe_bridge_fail('CONTENT_DIFF_OUT_OF_SCOPE');
    }
}

/**
 * @param array<string, string> $beforeHashes
 * @param array<string, string> $afterHashes
 */
function owe_bridge_verify_section_isolation(
    array $beforeHashes,
    array $afterHashes,
    string $operation,
    string $targetId,
    string $newSectionId
): void {
    foreach ($beforeHashes as $sectionId => $hash) {
        $mayChange = in_array($operation, ['replace_section', 'patch_responsive', 'update_widget_content', 'update_widget_style'], true)
            && $sectionId === $targetId;
        if ($mayChange) {
            continue;
        }
        if (!isset($afterHashes[$sectionId]) || !hash_equals($hash, $afterHashes[$sectionId])) {
            owe_bridge_fail('SECTION_ISOLATION_FAILED');
        }
    }

    if (in_array($operation, ['append_section', 'insert_section_before', 'insert_section_after'], true)
        && !isset($afterHashes[$newSectionId])
    ) {
        owe_bridge_fail('NEW_SECTION_NOT_FOUND_AFTER_WRITE');
    }
    if (in_array($operation, ['replace_section', 'patch_responsive', 'update_widget_content', 'update_widget_style'], true)
        && !isset($afterHashes[$targetId])
    ) {
        owe_bridge_fail('TARGET_SECTION_NOT_FOUND_AFTER_WRITE');
    }
}

/**
 * @param Elementor\Core\Base\Document $document
 * @param array<int, mixed> $elements
 */
function owe_bridge_save_document($document, array $elements, ?array $settings = null): void
{
    if (method_exists(\Elementor\Plugin::$instance->documents, 'switch_to_document')) {
        \Elementor\Plugin::$instance->documents->switch_to_document($document);
    }
    if (method_exists(\Elementor\Plugin::$instance->db, 'switch_to_post')) {
        \Elementor\Plugin::$instance->db->switch_to_post((int) $document->get_main_id());
    }

    $data = ['elements' => $elements];
    if ($settings !== null) {
        $data['settings'] = $settings;
    }
    $saved = $document->save($data);
    if ($saved !== true) {
        owe_bridge_fail('ELEMENTOR_SAVE_FAILED');
    }
}

/**
 * Run Elementor's element serialization without writing, so save-time normalization
 * cannot silently broaden an authorized copy change.
 * @param Elementor\Core\Base\Document $document
 * @param array<int, mixed> $elements
 * @return array<int, mixed>
 */
function owe_bridge_normalize_document_elements($document, array $elements, ?array $settings = null): array
{
    if (!is_callable([$document, 'get_elements_raw_data'])
        || !is_callable([$document, 'set_is_saving'])
        || !is_callable([$document, 'is_saving'])
    ) {
        owe_bridge_fail('ELEMENTOR_PREFLIGHT_UNAVAILABLE');
    }

    $requestedData = ['elements' => $elements];
    if ($settings !== null) {
        $requestedData['settings'] = $settings;
    }
    $saveData = apply_filters('elementor/document/save/data', $requestedData, $document);
    $allowedKeys = $settings === null ? ['elements'] : ['elements', 'settings'];
    if (!is_array($saveData)
        || array_keys($saveData) !== $allowedKeys
        || !isset($saveData['elements'])
        || !is_array($saveData['elements'])
    ) {
        owe_bridge_fail('ELEMENTOR_SAVE_FILTER_REJECTED');
    }
    if ($settings !== null && (!isset($saveData['settings']) || $saveData['settings'] !== $settings)) {
        owe_bridge_fail('TEMPLATE_PAGE_SETTINGS_NORMALIZATION_REQUIRED');
    }

    $wasSaving = (bool) $document->is_saving();
    $document->set_is_saving(true);
    try {
        $normalized = $document->get_elements_raw_data($saveData['elements'], false);
    } finally {
        $document->set_is_saving($wasSaving);
    }
    if (!is_array($normalized)) {
        owe_bridge_fail('ELEMENTOR_PREFLIGHT_FAILED');
    }
    return $normalized;
}

/** @return array<string, mixed> */
function owe_bridge_document_page_settings($document): array
{
    if (!is_callable([$document, 'get_db_document_settings'])) {
        owe_bridge_fail('ELEMENTOR_PAGE_SETTINGS_UNAVAILABLE');
    }
    $settings = $document->get_db_document_settings();
    return is_array($settings) ? $settings : [];
}

/** @return array{exists: bool, value: string} */
function owe_bridge_document_page_template($document): array
{
    if (!is_callable([$document, 'get_main_id'])) {
        owe_bridge_fail('ELEMENTOR_PAGE_SETTINGS_UNAVAILABLE');
    }
    $postId = (int) $document->get_main_id();
    $exists = metadata_exists('post', $postId, '_wp_page_template');
    $template = $exists ? get_post_meta($postId, '_wp_page_template', true) : '';
    return [
        'exists' => $exists,
        'value' => is_string($template) ? $template : '',
    ];
}

/**
 * @param Elementor\Core\Base\Document $document
 * @param array<int, mixed> $elements
 * @param array<string, mixed> $settings
 */
function owe_bridge_restore_document(
    $document,
    array $elements,
    array $settings,
    bool $restoreSettings,
    array $pageTemplate = ['exists' => false, 'value' => '']
): void
{
    $restore = null;
    if ($restoreSettings) {
        $restore = $settings;
        if (($pageTemplate['exists'] ?? false) === true) {
            $restore['template'] = (string) ($pageTemplate['value'] ?? '');
        }
    }
    owe_bridge_save_document($document, $elements, $restore);
    if ($restoreSettings && ($pageTemplate['exists'] ?? false) !== true) {
        if (!is_callable([$document, 'delete_meta'])) {
            owe_bridge_fail('ROLLBACK_FAILED');
        }
        $document->delete_meta('_wp_page_template');
    }
}

/**
 * @param array<string, mixed> $attributes
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>
 */
function owe_bridge_prepare_page_attributes(
    array $attributes,
    WP_Post $post,
    $document,
    array $snapshot
): array {
    if ($attributes === [] || array_diff(array_keys($attributes), ['template', 'parent_id', 'menu_order']) !== []) {
        owe_bridge_fail('PAGE_ATTRIBUTES_INVALID');
    }

    $prepared = [];
    if (array_key_exists('template', $attributes)) {
        $template = $attributes['template'];
        if (!is_string($template) || $template === '') {
            owe_bridge_fail('PAGE_TEMPLATE_INVALID');
        }
        $available = owe_bridge_available_page_templates($post, $document);
        if (!array_key_exists($template, $available)) {
            owe_bridge_fail('PAGE_TEMPLATE_NOT_AVAILABLE');
        }
        $current = ($snapshot['template']['exists'] ?? false) === true
            ? (string) ($snapshot['template']['value'] ?? '')
            : 'default';
        if ($template !== $current) {
            $prepared['template'] = $template;
        }
    }

    if (array_key_exists('parent_id', $attributes)) {
        $parentId = $attributes['parent_id'];
        if (!is_int($parentId) || $parentId < 0 || !is_post_type_hierarchical((string) $post->post_type)) {
            owe_bridge_fail('PAGE_PARENT_INVALID');
        }
        if ($parentId === (int) $post->ID) {
            owe_bridge_fail('PAGE_PARENT_CYCLE');
        }
        if ($parentId > 0) {
            $parent = get_post($parentId);
            if (!$parent instanceof WP_Post
                || (string) $parent->post_type !== (string) $post->post_type
                || in_array($parent->post_status, ['trash', 'auto-draft'], true)
            ) {
                owe_bridge_fail('PAGE_PARENT_INVALID');
            }
            if (in_array((int) $post->ID, array_map('intval', get_post_ancestors($parent)), true)) {
                owe_bridge_fail('PAGE_PARENT_CYCLE');
            }
        }
        if ($parentId !== (int) ($snapshot['parent_id'] ?? 0)) {
            $prepared['parent_id'] = $parentId;
        }
    }

    if (array_key_exists('menu_order', $attributes)) {
        $menuOrder = $attributes['menu_order'];
        if (!is_int($menuOrder) || !is_post_type_hierarchical((string) $post->post_type)) {
            owe_bridge_fail('PAGE_MENU_ORDER_INVALID');
        }
        if ($menuOrder !== (int) ($snapshot['menu_order'] ?? 0)) {
            $prepared['menu_order'] = $menuOrder;
        }
    }

    if ($prepared === []) {
        owe_bridge_fail('NO_CHANGES_APPLIED');
    }
    return $prepared;
}

/**
 * @param array<string, mixed> $snapshot
 * @param array<string, mixed> $changes
 * @return array<string, mixed>
 */
function owe_bridge_expected_page_attributes(array $snapshot, array $changes): array
{
    if (isset($changes['template'])) {
        $snapshot['template'] = ['exists' => true, 'value' => (string) $changes['template']];
    }
    if (isset($changes['parent_id'])) {
        $snapshot['parent_id'] = (int) $changes['parent_id'];
    }
    if (isset($changes['menu_order'])) {
        $snapshot['menu_order'] = (int) $changes['menu_order'];
    }
    return $snapshot;
}

/** @param array<string, mixed> $changes */
function owe_bridge_apply_page_attributes(int $postId, array $changes): void
{
    $postFields = ['ID' => $postId];
    if (array_key_exists('parent_id', $changes)) {
        $postFields['post_parent'] = (int) $changes['parent_id'];
    }
    if (array_key_exists('menu_order', $changes)) {
        $postFields['menu_order'] = (int) $changes['menu_order'];
    }
    if (count($postFields) > 1) {
        $updated = wp_update_post($postFields, true);
        if (is_wp_error($updated) || (int) $updated !== $postId) {
            owe_bridge_fail('PAGE_ATTRIBUTES_SAVE_FAILED');
        }
    }
    if (array_key_exists('template', $changes)) {
        update_post_meta($postId, '_wp_page_template', (string) $changes['template']);
    }
}

/**
 * @param array<string, mixed> $snapshot
 * @param array<string, mixed> $pageSettings
 * @param array<string, mixed> $changes
 */
function owe_bridge_restore_page_attributes(
    array $snapshot,
    array $changes,
    array $writtenSnapshot
): void {
    $current = get_post((int) $snapshot['post_id']);
    if (!$current instanceof WP_Post) {
        owe_bridge_fail('ROLLBACK_CONFLICT');
    }
    $currentSnapshot = owe_bridge_page_attributes_snapshot($current);
    $restoreChanges = [];
    foreach (array_keys($changes) as $key) {
        $beforeValue = $snapshot[$key] ?? null;
        $writtenValue = $writtenSnapshot[$key] ?? null;
        $currentValue = $currentSnapshot[$key] ?? null;
        if ($currentValue !== $beforeValue && $currentValue !== $writtenValue) {
            owe_bridge_fail('ROLLBACK_CONFLICT');
        }
        if ($currentValue === $writtenValue && $currentValue !== $beforeValue) {
            $restoreChanges[$key] = true;
        }
    }

    $restore = [];
    if (array_key_exists('parent_id', $restoreChanges)) {
        $restore['parent_id'] = (int) $snapshot['parent_id'];
    }
    if (array_key_exists('menu_order', $restoreChanges)) {
        $restore['menu_order'] = (int) $snapshot['menu_order'];
    }
    owe_bridge_apply_page_attributes((int) $snapshot['post_id'], $restore);

    if (array_key_exists('template', $restoreChanges)) {
        if (($snapshot['template']['exists'] ?? false) === true) {
            update_post_meta(
                (int) $snapshot['post_id'],
                '_wp_page_template',
                (string) ($snapshot['template']['value'] ?? '')
            );
        } else {
            delete_post_meta((int) $snapshot['post_id'], '_wp_page_template');
        }
    }
}
