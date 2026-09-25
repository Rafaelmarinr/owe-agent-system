<?php

declare(strict_types=1);

/** @return array<int, string> */
function owe_bridge_allowed_template_types(): array
{
    return ['section', 'container', 'page'];
}

/** @return array<int, string> */
function owe_bridge_template_statuses(): array
{
    return ['publish', 'private', 'draft', 'pending', 'future'];
}

/** @return array<string, mixed> */
function owe_bridge_template_summary(WP_Post $post): array
{
    return [
        'id' => (int) $post->ID,
        'title' => (string) $post->post_title,
        'slug' => (string) $post->post_name,
        'type' => (string) get_post_meta((int) $post->ID, '_elementor_template_type', true),
        'status' => (string) $post->post_status,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function owe_bridge_find_templates(string $selector, string $value): array
{
    if (!in_array($selector, ['slug', 'name'], true) || trim($value) === '') {
        owe_bridge_fail('TEMPLATE_SELECTOR_REQUIRED');
    }
    $value = trim($value);
    $args = [
        'post_type' => 'elementor_library',
        'post_status' => owe_bridge_template_statuses(),
        'posts_per_page' => -1,
        'no_found_rows' => true,
        'orderby' => 'ID',
        'order' => 'ASC',
        'meta_query' => [[
            'key' => '_elementor_template_type',
            'value' => owe_bridge_allowed_template_types(),
            'compare' => 'IN',
        ]],
    ];
    $args[$selector === 'slug' ? 'name' : 'title'] = $value;

    $query = new WP_Query($args);
    $matches = [];
    foreach ($query->posts as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }
        $actual = $selector === 'slug' ? (string) $post->post_name : (string) $post->post_title;
        if ($actual !== $value || !current_user_can('edit_post', (int) $post->ID)) {
            continue;
        }
        $type = (string) get_post_meta((int) $post->ID, '_elementor_template_type', true);
        if (!in_array($type, owe_bridge_allowed_template_types(), true)) {
            continue;
        }
        $matches[] = owe_bridge_template_summary($post);
    }
    return $matches;
}

function owe_bridge_resolve_template(int $templateId): WP_Post
{
    $post = $templateId > 0 ? get_post($templateId) : null;
    if (!$post instanceof WP_Post
        || (string) $post->post_type !== 'elementor_library'
        || !in_array((string) $post->post_status, owe_bridge_template_statuses(), true)
    ) {
        owe_bridge_fail('TEMPLATE_NOT_FOUND');
    }
    if (!current_user_can('edit_post', $templateId)) {
        owe_bridge_fail('TEMPLATE_PERMISSION_DENIED');
    }
    $type = (string) get_post_meta($templateId, '_elementor_template_type', true);
    if (!in_array($type, owe_bridge_allowed_template_types(), true)) {
        owe_bridge_fail('TEMPLATE_TYPE_NOT_SUPPORTED');
    }
    return $post;
}

/** @return array<int, mixed> */
function owe_bridge_template_source_elements(int $templateId): array
{
    $document = owe_bridge_get_document($templateId, true);
    $elements = $document->get_elements_data();
    if (!is_array($elements) || $elements === []) {
        owe_bridge_fail('TEMPLATE_CONTENT_EMPTY');
    }
    owe_bridge_assert_compatible_structure($elements);
    owe_bridge_validate_page_elements($elements);
    return $elements;
}

/**
 * @param array<int, mixed> $elements
 * @return array<int, string>
 */
function owe_bridge_unavailable_template_widgets(array $elements): array
{
    $unavailable = [];
    $manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
    foreach ($elements as $element) {
        if (!is_array($element)) {
            continue;
        }
        if (($element['elType'] ?? '') === 'widget') {
            $type = isset($element['widgetType']) && is_string($element['widgetType']) ? $element['widgetType'] : '';
            $widget = is_object($manager) && method_exists($manager, 'get_widget_types')
                ? $manager->get_widget_types($type)
                : null;
            if ($type !== '' && !is_object($widget)) {
                $unavailable[$type] = true;
            }
        }
        $children = isset($element['elements']) && is_array($element['elements']) ? $element['elements'] : [];
        foreach (owe_bridge_unavailable_template_widgets($children) as $type) {
            $unavailable[$type] = true;
        }
    }
    $types = array_keys($unavailable);
    sort($types, SORT_STRING);
    return $types;
}

/**
 * @param array<int, mixed> $elements
 * @param array<int, string> $ids
 */
function owe_bridge_collect_ordered_ids(array $elements, array &$ids): void
{
    foreach ($elements as $element) {
        if (!is_array($element) || !isset($element['id']) || !is_string($element['id'])) {
            owe_bridge_fail('TEMPLATE_STRUCTURE_UNSUPPORTED');
        }
        $ids[] = $element['id'];
        $children = isset($element['elements']) && is_array($element['elements']) ? $element['elements'] : [];
        owe_bridge_collect_ordered_ids($children, $ids);
    }
}

/**
 * @param mixed $value
 * @param array<string, string> $idMap
 * @return mixed
 */
function owe_bridge_rewrite_template_references($value, array $idMap, string $key = '')
{
    if (is_array($value)) {
        foreach ($value as $childKey => $child) {
            $value[$childKey] = owe_bridge_rewrite_template_references(
                $child,
                $idMap,
                is_string($childKey) ? $childKey : ''
            );
        }
        return $value;
    }
    if (!is_string($value)) {
        return $value;
    }
    if (preg_match('/^(?:element_id|target_id|trigger_id)$/', $key) && isset($idMap[$value])) {
        return $idMap[$value];
    }
    $oldIds = array_keys($idMap);
    usort($oldIds, static function (string $left, string $right): int {
        return strlen($right) <=> strlen($left);
    });
    if ($oldIds !== []) {
        $pattern = '/([.#]elementor-element-)(' . implode('|', array_map('preg_quote', $oldIds)) . ')(?![a-f0-9])/';
        $value = (string) preg_replace_callback(
            $pattern,
            static function (array $matches) use ($idMap): string {
                return $matches[1] . $idMap[$matches[2]];
            },
            $value
        );
    }
    return $value;
}

/**
 * @param array<int, mixed> $source
 * @param array<int, mixed> $clone
 * @param array<string, string> $idMap
 */
function owe_bridge_build_template_id_map(array $source, array $clone, array &$idMap): void
{
    if (count($source) !== count($clone)) {
        owe_bridge_fail('TEMPLATE_CLONE_STRUCTURE_CHANGED');
    }
    foreach ($source as $index => $sourceElement) {
        $cloneElement = $clone[$index] ?? null;
        if (!is_array($sourceElement)
            || !is_array($cloneElement)
            || ($sourceElement['elType'] ?? null) !== ($cloneElement['elType'] ?? null)
            || ($sourceElement['widgetType'] ?? null) !== ($cloneElement['widgetType'] ?? null)
            || !isset($sourceElement['id'], $cloneElement['id'])
            || !is_string($sourceElement['id'])
            || !is_string($cloneElement['id'])
        ) {
            owe_bridge_fail('TEMPLATE_CLONE_STRUCTURE_CHANGED');
        }
        $idMap[$sourceElement['id']] = $cloneElement['id'];
        $sourceChildren = isset($sourceElement['elements']) && is_array($sourceElement['elements'])
            ? $sourceElement['elements']
            : [];
        $cloneChildren = isset($cloneElement['elements']) && is_array($cloneElement['elements'])
            ? $cloneElement['elements']
            : [];
        owe_bridge_build_template_id_map($sourceChildren, $cloneChildren, $idMap);
    }
}

/**
 * @param array<int, mixed> $elements
 * @param array<string, string> $idMap
 * @return array<int, mixed>
 */
function owe_bridge_remap_template_tree(array $elements, array $idMap): array
{
    foreach ($elements as $index => $element) {
        if (!is_array($element) || !isset($element['id']) || !is_string($element['id'])) {
            owe_bridge_fail('TEMPLATE_STRUCTURE_UNSUPPORTED');
        }
        $oldId = $element['id'];
        if (!isset($idMap[$oldId])) {
            owe_bridge_fail('TEMPLATE_ID_MAP_FAILED');
        }
        $element['id'] = $idMap[$oldId];
        if (isset($element['settings']) && is_array($element['settings'])) {
            $element['settings'] = owe_bridge_rewrite_template_references($element['settings'], $idMap);
        }
        $children = isset($element['elements']) && is_array($element['elements']) ? $element['elements'] : [];
        $element['elements'] = owe_bridge_remap_template_tree($children, $idMap);
        $elements[$index] = $element;
    }
    return $elements;
}

/**
 * @param array<int, mixed> $source
 * @param array<int, mixed> $clone
 * @param array<int, mixed> $destination
 * @return array<int, mixed>
 */
function owe_bridge_prepare_template_clone(array $source, array $clone, array $destination): array
{
    if ($clone === []) {
        owe_bridge_fail('TEMPLATE_CONTENT_EMPTY');
    }
    owe_bridge_assert_compatible_structure($clone);
    owe_bridge_validate_page_elements($clone);

    $sourceIds = [];
    $cloneIds = [];
    $destinationIds = [];
    owe_bridge_collect_ordered_ids($source, $sourceIds);
    owe_bridge_collect_ordered_ids($clone, $cloneIds);
    owe_bridge_collect_ordered_ids($destination, $destinationIds);
    if (array_intersect($sourceIds, $cloneIds) !== []) {
        owe_bridge_fail('TEMPLATE_IDS_NOT_REGENERATED');
    }
    if (array_intersect($cloneIds, $destinationIds) !== []) {
        owe_bridge_fail('TEMPLATE_ID_COLLISION');
    }
    $idMap = [];
    owe_bridge_build_template_id_map($source, $clone, $idMap);
    $clone = owe_bridge_rewrite_template_references($clone, $idMap);
    $expectedClone = owe_bridge_remap_template_tree($source, $idMap);
    if (!hash_equals(owe_bridge_elements_hash($expectedClone), owe_bridge_elements_hash($clone))) {
        owe_bridge_fail('TEMPLATE_CLONE_CONTENT_CHANGED');
    }
    owe_bridge_validate_page_elements($clone);
    owe_bridge_validate_available_widgets($clone);
    return $clone;
}

/** @return array{content: array<int, mixed>, page_settings: array<string, mixed>} */
function owe_bridge_load_template_clone(int $templateId, bool $withPageSettings): array
{
    $manager = \Elementor\Plugin::$instance->templates_manager ?? null;
    if (!is_object($manager) || !method_exists($manager, 'get_template_data')) {
        owe_bridge_fail('ELEMENTOR_TEMPLATE_API_UNAVAILABLE');
    }
    $data = $manager->get_template_data([
        'source' => 'local',
        'template_id' => $templateId,
        'with_page_settings' => $withPageSettings,
    ]);
    if (!is_array($data) || !isset($data['content']) || !is_array($data['content'])) {
        owe_bridge_fail('TEMPLATE_LOAD_FAILED');
    }
    $settings = $data['page_settings'] ?? [];
    if (!is_array($settings)) {
        owe_bridge_fail('TEMPLATE_PAGE_SETTINGS_INVALID');
    }
    return ['content' => $data['content'], 'page_settings' => $settings];
}

/** @return array<string, mixed> */
function owe_bridge_validate_template_page_settings(array $settings, int $targetPostId = 0): array
{
    $special = [
        'id', 'post_title', 'post_status', 'post_excerpt', 'post_featured_image',
        'menu_order', 'comment_status',
    ];
    foreach ($settings as $key => $value) {
        if (!is_string($key)
            || in_array($key, $special, true)
            || preg_match('/(?:custom_css|custom_js|javascript|script|code)/i', $key)
            || !owe_bridge_is_json_value($value)
        ) {
            owe_bridge_fail('TEMPLATE_PAGE_SETTINGS_UNSUPPORTED');
        }
    }
    if (array_key_exists('template', $settings)) {
        $template = $settings['template'];
        if (!is_string($template) || $template === '') {
            owe_bridge_fail('TEMPLATE_PAGE_LAYOUT_INVALID');
        }
        $allowed = ['default', 'elementor_canvas', 'elementor_header_footer'];
        if ($targetPostId > 0 && function_exists('get_page_templates')) {
            $post = get_post($targetPostId);
            $postType = $post instanceof WP_Post ? (string) $post->post_type : 'page';
            $registered = get_page_templates($post, $postType);
            if (is_array($registered)) {
                foreach ($registered as $file) {
                    if (is_string($file) && $file !== '') {
                        $allowed[] = $file;
                    }
                }
            }
        }
        if (!in_array($template, array_values(array_unique($allowed)), true)) {
            owe_bridge_fail('TEMPLATE_PAGE_LAYOUT_INVALID');
        }
    }
    return $settings;
}

/**
 * @param array<int, mixed> $before
 * @param array<int, mixed> $template
 * @return array<int, mixed>
 */
function owe_bridge_apply_template_operation(
    array $before,
    array $template,
    string $position,
    string $targetSectionId
): array {
    if ($position === 'replace') {
        return array_values($template);
    }
    if ($position === 'prepend') {
        return array_values(array_merge($template, $before));
    }
    if ($position === 'append') {
        return array_values(array_merge($before, $template));
    }
    if (!in_array($position, ['before', 'after'], true) || $targetSectionId === '') {
        owe_bridge_fail('TEMPLATE_POSITION_INVALID');
    }
    $index = owe_bridge_find_top_level_index($before, $targetSectionId);
    if ($index < 0) {
        owe_bridge_fail('TARGET_SECTION_NOT_FOUND');
    }
    $offset = $position === 'before' ? $index : $index + 1;
    array_splice($before, $offset, 0, $template);
    return array_values($before);
}

/**
 * @param array<int, mixed> $before
 * @param array<int, mixed> $after
 * @param array<int, mixed> $template
 */
function owe_bridge_verify_template_isolation(array $before, array $after, array $template, string $position): void
{
    $afterHashes = owe_bridge_top_level_hashes($after);
    if ($position !== 'replace') {
        foreach (owe_bridge_top_level_hashes($before) as $id => $hash) {
            if (!isset($afterHashes[$id]) || !hash_equals($hash, $afterHashes[$id])) {
                owe_bridge_fail('TEMPLATE_INSERT_ISOLATION_FAILED');
            }
        }
    }
    foreach (owe_bridge_top_level_hashes($template) as $id => $hash) {
        if (!isset($afterHashes[$id]) || !hash_equals($hash, $afterHashes[$id])) {
            owe_bridge_fail('TEMPLATE_INSERT_VALIDATION_FAILED');
        }
    }
    if ($position === 'replace' && count($after) !== count($template)) {
        owe_bridge_fail('TEMPLATE_REPLACE_VALIDATION_FAILED');
    }
}

function owe_bridge_template_requires_pro(array $elements): bool
{
    foreach ($elements as $element) {
        if (!is_array($element)) {
            continue;
        }
        if (($element['elType'] ?? '') === 'widget') {
            $type = isset($element['widgetType']) && is_string($element['widgetType']) ? $element['widgetType'] : '';
            $manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
            $widget = is_object($manager) && method_exists($manager, 'get_widget_types')
                ? $manager->get_widget_types($type)
                : null;
            if (is_object($widget) && defined('ELEMENTOR_PRO_PATH')) {
                try {
                    $file = (new ReflectionClass($widget))->getFileName();
                    $root = realpath((string) constant('ELEMENTOR_PRO_PATH'));
                    if (is_string($file) && is_string($root)) {
                        $resolved = realpath($file);
                        if (is_string($resolved) && strpos($resolved, $root . DIRECTORY_SEPARATOR) === 0) {
                            return true;
                        }
                    }
                } catch (ReflectionException $error) {
                    owe_bridge_fail('TEMPLATE_WIDGET_INSPECTION_FAILED');
                }
            }
        }
        $children = isset($element['elements']) && is_array($element['elements']) ? $element['elements'] : [];
        if (owe_bridge_template_requires_pro($children)) {
            return true;
        }
    }
    return false;
}
