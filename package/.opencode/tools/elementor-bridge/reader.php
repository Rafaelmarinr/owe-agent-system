<?php

declare(strict_types=1);

/**
 * @param array<int, mixed> $elements
 */
function owe_bridge_elements_hash(array $elements): string
{
    $encoded = wp_json_encode($elements, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        owe_bridge_fail('ELEMENTOR_HASH_ENCODING_FAILED');
    }
    return hash('sha256', $encoded);
}

/** @return array<string, mixed> */
function owe_bridge_page_attributes_snapshot(WP_Post $post): array
{
    $templateExists = metadata_exists('post', (int) $post->ID, '_wp_page_template');
    $template = $templateExists ? get_post_meta((int) $post->ID, '_wp_page_template', true) : '';

    return [
        'post_id' => (int) $post->ID,
        'post_type' => (string) $post->post_type,
        'template' => [
            'exists' => $templateExists,
            'value' => is_string($template) ? $template : '',
        ],
        'parent_id' => (int) $post->post_parent,
        'menu_order' => (int) $post->menu_order,
    ];
}

function owe_bridge_page_attributes_hash(WP_Post $post): string
{
    return owe_bridge_page_attributes_snapshot_hash(owe_bridge_page_attributes_snapshot($post));
}

/** @param array<string, mixed> $snapshot */
function owe_bridge_page_attributes_snapshot_hash(array $snapshot): string
{
    $encoded = wp_json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        owe_bridge_fail('PAGE_ATTRIBUTES_HASH_ENCODING_FAILED');
    }

    return hash('sha256', $encoded);
}

/** @param array<string, string> $templates */
function owe_bridge_page_templates_hash(array $templates): string
{
    $slugs = array_keys($templates);
    sort($slugs, SORT_STRING);
    $encoded = wp_json_encode($slugs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        owe_bridge_fail('PAGE_TEMPLATES_HASH_ENCODING_FAILED');
    }
    return hash('sha256', $encoded);
}

/** @return array<string, string> */
function owe_bridge_available_page_templates(WP_Post $post, $document): array
{
    $class = get_class($document);
    if (!is_callable([$class, 'get_property'])
        || !$class::get_property('support_wp_page_templates')
        || !function_exists('get_page_templates')
    ) {
        return [];
    }

    $registered = get_page_templates($post, (string) $post->post_type);
    if (!is_array($registered)) {
        owe_bridge_fail('PAGE_TEMPLATES_UNAVAILABLE');
    }

    $templates = ['default' => 'Default'];
    foreach ($registered as $slug => $label) {
        if (is_string($slug) && $slug !== '' && is_string($label) && $label !== '') {
            $templates[$slug] = wp_strip_all_tags($label);
        }
    }
    ksort($templates);
    return $templates;
}

/**
 * @param array<int, mixed> $elements
 * @return array<int, string>
 */
function owe_bridge_top_level_hashes(array $elements): array
{
    $hashes = [];
    foreach ($elements as $element) {
        if (!is_array($element) || !isset($element['id']) || !is_string($element['id'])) {
            continue;
        }
        $encoded = wp_json_encode($element, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            owe_bridge_fail('ELEMENTOR_HASH_ENCODING_FAILED');
        }
        $hashes[$element['id']] = hash('sha256', $encoded);
    }
    return $hashes;
}

/**
 * @param array<int, mixed> $elements
 */
function owe_bridge_find_top_level_index(array $elements, string $targetId): int
{
    foreach ($elements as $index => $element) {
        if (is_array($element) && ($element['id'] ?? null) === $targetId) {
            return (int) $index;
        }
    }
    return -1;
}

/**
 * @param array<int, mixed> $elements
 * @return array<string, mixed>|null
 */
function owe_bridge_find_top_level_section(array $elements, string $targetId): ?array
{
    $index = owe_bridge_find_top_level_index($elements, $targetId);
    if ($index < 0 || !isset($elements[$index]) || !is_array($elements[$index])) {
        return null;
    }
    return $elements[$index];
}

/**
 * @return array<string, mixed>
 */
function owe_bridge_summarize_element(array $element, int $depth = 0): array
{
    $summary = [
        'id' => (string) ($element['id'] ?? ''),
        'type' => (string) ($element['elType'] ?? ''),
    ];

    if (isset($element['widgetType']) && is_string($element['widgetType'])) {
        $summary['widget'] = $element['widgetType'];
    }

    $children = isset($element['elements']) && is_array($element['elements']) ? $element['elements'] : [];
    $summary['children_count'] = count($children);

    if ($depth < 2 && $children !== []) {
        $summary['children'] = [];
        foreach ($children as $child) {
            if (is_array($child)) {
                $summary['children'][] = owe_bridge_summarize_element($child, $depth + 1);
            }
        }
    }

    return $summary;
}

/**
 * @param array<int, mixed> $elements
 * @return array<int, mixed>
 */
function owe_bridge_structure_summary(array $elements): array
{
    $summary = [];
    foreach ($elements as $element) {
        if (is_array($element)) {
            $summary[] = owe_bridge_summarize_element($element);
        }
    }
    return $summary;
}

/**
 * @param array<int, mixed> $elements
 * @return array<string, bool>
 */
function owe_bridge_ids_in_section(array $elements, string $sectionId): array
{
    $section = owe_bridge_find_top_level_section($elements, $sectionId);
    if ($section === null) {
        owe_bridge_fail('TARGET_SECTION_NOT_FOUND');
    }

    $ids = [];
    owe_bridge_collect_ids($section, $ids);
    return $ids;
}

/**
 * @param object $widget
 */
function owe_bridge_is_official_widget($widget): bool
{
    try {
        $file = (new ReflectionClass($widget))->getFileName();
    } catch (ReflectionException $error) {
        return false;
    }
    if (!is_string($file) || realpath($file) === false) {
        return false;
    }

    $file = (string) realpath($file);
    foreach (['ELEMENTOR_PATH', 'ELEMENTOR_PRO_PATH'] as $constant) {
        if (!defined($constant)) {
            continue;
        }
        $root = realpath((string) constant($constant));
        if (is_string($root) && ($file === $root || strpos($file, $root . DIRECTORY_SEPARATOR) === 0)) {
            return true;
        }
    }
    return false;
}

/**
 * @return array<string, mixed>
 */
function owe_bridge_widget_controls(string $widgetType): array
{
    $manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
    if (!is_object($manager) || !method_exists($manager, 'get_widget_types')) {
        owe_bridge_fail('ELEMENTOR_WIDGET_MANAGER_UNAVAILABLE');
    }
    $widget = $manager->get_widget_types($widgetType);
    if (!is_object($widget) || !owe_bridge_is_official_widget($widget) || !method_exists($widget, 'get_controls')) {
        return [];
    }
    $controls = $widget->get_controls();
    return is_array($controls) ? $controls : [];
}

/**
 * Explicit editorial fields. Unknown official controls stay read-only until reviewed.
 * @return array<string, array<string, mixed>>
 */
function owe_bridge_copy_control_catalog(): array
{
    return [
        'heading' => ['direct' => ['title' => 'html']],
        'text-editor' => ['direct' => ['editor' => 'html']],
        'button' => ['direct' => ['text' => 'plain']],
        'image' => ['direct' => ['caption' => 'plain', 'custom_caption' => 'plain']],
        'divider' => ['direct' => ['text' => 'plain']],
        'icon-box' => ['direct' => ['title_text' => 'html', 'description_text' => 'html']],
        'image-box' => ['direct' => ['title_text' => 'html', 'description_text' => 'html']],
        'testimonial' => ['direct' => ['testimonial_content' => 'html', 'testimonial_name' => 'plain', 'testimonial_job' => 'plain']],
        'alert' => ['direct' => ['alert_title' => 'plain', 'alert_description' => 'html']],
        'counter' => ['direct' => ['prefix' => 'plain', 'suffix' => 'plain', 'title' => 'plain']],
        'progress' => ['direct' => ['title' => 'plain']],
        'star-rating' => ['direct' => ['title' => 'plain']],
        'icon-list' => ['repeaters' => ['icon_list' => ['text' => 'plain']]],
        'tabs' => ['repeaters' => ['tabs' => ['tab_title' => 'plain', 'tab_content' => 'html']]],
        'toggle' => ['repeaters' => ['tabs' => ['tab_title' => 'plain', 'tab_content' => 'html']]],
        'accordion' => ['repeaters' => ['tabs' => ['tab_title' => 'plain', 'tab_content' => 'html']]],
        'animated-headline' => ['direct' => ['before_text' => 'plain', 'highlighted_text' => 'plain', 'rotating_text' => 'multiline', 'after_text' => 'plain']],
        'blockquote' => ['direct' => ['blockquote_content' => 'html', 'author_name' => 'plain', 'tweet_button_label' => 'plain']],
        'call-to-action' => ['direct' => ['title' => 'html', 'description' => 'html', 'button' => 'plain']],
        'flip-box' => ['direct' => ['front_title' => 'html', 'front_description' => 'html', 'back_title' => 'html', 'back_description' => 'html', 'button_text' => 'plain']],
        'form' => [
            'direct' => ['button_text' => 'plain', 'success_message' => 'plain', 'error_message' => 'plain', 'required_message' => 'plain', 'invalid_message' => 'plain'],
            'repeaters' => ['form_fields' => ['field_label' => 'plain', 'placeholder' => 'plain', 'acceptance_text' => 'html']],
        ],
        'login' => ['direct' => ['button_text' => 'plain', 'user_label' => 'plain', 'user_placeholder' => 'plain', 'password_label' => 'plain', 'password_placeholder' => 'plain', 'lost_password_text' => 'plain', 'logged_in_message' => 'html']],
        'posts' => ['direct' => ['read_more_text' => 'plain', 'nothing_found_message' => 'plain']],
        'archive-posts' => ['direct' => ['read_more_text' => 'plain', 'nothing_found_message' => 'plain']],
        'price-list' => ['repeaters' => ['price_list' => ['title' => 'plain', 'item_description' => 'html']]],
        'price-table' => [
            'direct' => ['heading' => 'plain', 'sub_heading' => 'plain', 'period' => 'plain', 'button_text' => 'plain', 'footer_additional_info' => 'html'],
            'repeaters' => ['features_list' => ['item_text' => 'plain']],
        ],
        'slides' => ['repeaters' => ['slides' => ['heading' => 'html', 'description' => 'html', 'button_text' => 'plain']]],
        'table-of-contents' => ['direct' => ['heading_title' => 'plain']],
        'search-form' => ['direct' => ['placeholder' => 'plain', 'button_text' => 'plain']],
        'hotspot' => ['repeaters' => ['hotspot' => ['hotspot_label' => 'plain', 'tooltip_content' => 'html']]],
        'testimonial-carousel' => ['repeaters' => ['slides' => ['content' => 'html', 'name' => 'plain', 'title' => 'plain']]],
        'reviews' => ['repeaters' => ['slides' => ['review' => 'html', 'reviewer_name' => 'plain', 'reviewer_title' => 'plain']]],
        'countdown' => ['direct' => ['label_days' => 'plain', 'label_hours' => 'plain', 'label_minutes' => 'plain', 'label_seconds' => 'plain', 'message' => 'html']],
        'author-box' => ['direct' => ['name' => 'plain', 'biography' => 'html', 'link_text' => 'plain']],
    ];
}

function owe_bridge_copy_control_format(string $widgetType, string $name, string $repeater = ''): ?string
{
    $catalog = owe_bridge_copy_control_catalog();
    if (!isset($catalog[$widgetType])) {
        return null;
    }
    if ($repeater === '') {
        $format = $catalog[$widgetType]['direct'][$name] ?? null;
    } else {
        $format = $catalog[$widgetType]['repeaters'][$repeater][$name] ?? null;
    }
    return is_string($format) && in_array($format, ['plain', 'multiline', 'html'], true) ? $format : null;
}

function owe_bridge_content_hash(string $reference, string $value): string
{
    $encoded = wp_json_encode(
        ['ref' => $reference, 'value' => $value],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if (!is_string($encoded)) {
        owe_bridge_fail('ELEMENTOR_HASH_ENCODING_FAILED');
    }
    return hash('sha256', $encoded);
}

/**
 * @return array<string, mixed>
 */
function owe_bridge_content_entry(
    string $reference,
    string $widgetType,
    string $label,
    string $format,
    string $value,
    string $elementId,
    string $controlName,
    string $repeaterName = '',
    string $itemId = ''
): array {
    return [
        'ref' => $reference,
        'widget' => $widgetType,
        'label' => $label,
        'format' => $format,
        'value' => $value,
        'hash' => owe_bridge_content_hash($reference, $value),
        '_element_id' => $elementId,
        '_control' => $controlName,
        '_repeater' => $repeaterName,
        '_item_id' => $itemId,
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function owe_bridge_widget_content_index(array $element): array
{
    if (($element['elType'] ?? '') !== 'widget') {
        return [];
    }
    $elementId = isset($element['id']) && is_string($element['id']) ? $element['id'] : '';
    $widgetType = isset($element['widgetType']) && is_string($element['widgetType']) ? $element['widgetType'] : '';
    $settings = isset($element['settings']) && is_array($element['settings']) ? $element['settings'] : [];
    $dynamic = isset($settings['__dynamic__']) && is_array($settings['__dynamic__']) ? $settings['__dynamic__'] : [];
    $index = [];

    foreach (owe_bridge_widget_controls($widgetType) as $name => $control) {
        if (!is_string($name) || !is_array($control) || isset($dynamic[$name])) {
            continue;
        }
        $type = isset($control['type']) && is_string($control['type']) ? $control['type'] : '';
        $label = isset($control['label']) && is_string($control['label']) ? wp_strip_all_tags($control['label']) : $name;
        $format = owe_bridge_copy_control_format($widgetType, $name);
        if ($format !== null
            && in_array($type, ['text', 'textarea', 'wysiwyg'], true)
            && array_key_exists($name, $settings)
            && is_string($settings[$name])
        ) {
            $reference = $elementId . ':' . $name;
            if (isset($index[$reference])) {
                owe_bridge_fail('DUPLICATE_CONTENT_FIELD');
            }
            $index[$reference] = owe_bridge_content_entry(
                $reference,
                $widgetType,
                $label,
                $format,
                $settings[$name],
                $elementId,
                $name
            );
            continue;
        }
        if ($type !== 'repeater' || !isset($settings[$name]) || !is_array($settings[$name])) {
            continue;
        }

        $fields = isset($control['fields']) && is_array($control['fields']) ? $control['fields'] : [];
        foreach ($settings[$name] as $item) {
            if (!is_array($item) || !isset($item['_id']) || !is_string($item['_id']) || $item['_id'] === '') {
                continue;
            }
            foreach ($fields as $fieldName => $fieldControl) {
                $fieldType = is_array($fieldControl) && isset($fieldControl['type']) && is_string($fieldControl['type'])
                    ? $fieldControl['type']
                    : '';
                $fieldFormat = is_string($fieldName)
                    ? owe_bridge_copy_control_format($widgetType, $fieldName, $name)
                    : null;
                if (!is_string($fieldName)
                    || !is_array($fieldControl)
                    || $fieldFormat === null
                    || !in_array($fieldType, ['text', 'textarea', 'wysiwyg'], true)
                    || !array_key_exists($fieldName, $item)
                    || !is_string($item[$fieldName])
                ) {
                    continue;
                }
                $itemDynamic = isset($item['__dynamic__']) && is_array($item['__dynamic__']) ? $item['__dynamic__'] : [];
                if (isset($itemDynamic[$fieldName])) {
                    continue;
                }
                $fieldLabel = isset($fieldControl['label']) && is_string($fieldControl['label'])
                    ? wp_strip_all_tags($fieldControl['label'])
                    : $fieldName;
                $reference = $elementId . ':' . $name . ':' . $item['_id'] . ':' . $fieldName;
                if (isset($index[$reference])) {
                    owe_bridge_fail('DUPLICATE_CONTENT_FIELD');
                }
                $index[$reference] = owe_bridge_content_entry(
                    $reference,
                    $widgetType,
                    $label . ' / ' . $fieldLabel,
                    $fieldFormat,
                    $item[$fieldName],
                    $elementId,
                    $fieldName,
                    $name,
                    $item['_id']
                );
            }
        }
    }
    return $index;
}

/**
 * @return array<string, array<string, mixed>>
 */
function owe_bridge_section_content_index(array $section): array
{
    $index = owe_bridge_widget_content_index($section);
    foreach (($section['elements'] ?? []) as $child) {
        if (is_array($child)) {
            foreach (owe_bridge_section_content_index($child) as $reference => $entry) {
                if (isset($index[$reference])) {
                    owe_bridge_fail('DUPLICATE_CONTENT_FIELD');
                }
                $index[$reference] = $entry;
            }
        }
    }
    return $index;
}

/**
 * @return array<int, array<string, mixed>>
 */
function owe_bridge_public_content_fields(array $section): array
{
    $fields = [];
    $bytes = 0;
    foreach (owe_bridge_section_content_index($section) as $entry) {
        foreach (array_keys($entry) as $key) {
            if (substr($key, 0, 1) === '_') {
                unset($entry[$key]);
            }
        }
        $bytes += strlen((string) $entry['value']);
        if (count($fields) >= 100 || $bytes > 65536) {
            owe_bridge_fail('CONTENT_INSPECTION_LIMIT_EXCEEDED');
        }
        $fields[] = $entry;
    }
    return $fields;
}
