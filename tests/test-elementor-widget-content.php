<?php

declare(strict_types=1);

namespace Elementor {
    final class Plugin
    {
        /** @var object */
        public static $instance;
    }

    final class Utils
    {
        public static function is_post_support(int $postId): bool
        {
            return $GLOBALS['owe_test_elementor_support'][$postId] ?? true;
        }
    }
}

namespace {
    define('ELEMENTOR_PATH', __DIR__ . DIRECTORY_SEPARATOR);

    final class WP_Post
    {
        public $ID;
        public $post_title;
        public $post_name;
        public $post_type;
        public $post_status;
        public $post_content;
        public $post_modified_gmt;
        public $post_parent;
        public $menu_order;

        public function __construct(
            int $id,
            string $title,
            string $slug,
            string $type = 'elementor_library',
            string $status = 'publish',
            string $content = '',
            string $modifiedGmt = '2026-01-01 00:00:00'
        )
        {
            $this->ID = $id;
            $this->post_title = $title;
            $this->post_name = $slug;
            $this->post_type = $type;
            $this->post_status = $status;
            $this->post_content = $content;
            $this->post_modified_gmt = $modifiedGmt;
            $this->post_parent = 0;
            $this->menu_order = 0;
        }
    }

    final class WP_Query
    {
        /** @var array<int, WP_Post> */
        public $posts = [];

        /** @param array<string, mixed> $args */
        public function __construct(array $args)
        {
            foreach ($GLOBALS['owe_test_posts'] ?? [] as $post) {
                if (!$post instanceof WP_Post || $post->post_type !== ($args['post_type'] ?? '')) {
                    continue;
                }
                if (isset($args['name']) && $post->post_name !== $args['name']) {
                    continue;
                }
                if (isset($args['title']) && $post->post_title !== $args['title']) {
                    continue;
                }
                $type = $GLOBALS['owe_test_template_types'][$post->ID] ?? '';
                $allowed = $args['meta_query'][0]['value'] ?? [];
                if (!in_array($type, $allowed, true)) {
                    continue;
                }
                $this->posts[] = $post;
            }
        }
    }

    function wp_json_encode($value, int $flags = 0)
    {
        return json_encode($value, $flags);
    }

    function wp_strip_all_tags(string $value): string
    {
        return strip_tags($value);
    }

    function wp_check_invalid_utf8(string $value, bool $strip = false): string
    {
        return preg_match('//u', $value) === 1 ? $value : ($strip ? '' : $value);
    }

    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags(preg_replace('/[\r\n\t ]+/', ' ', $value) ?? $value));
    }

    function sanitize_textarea_field(string $value): string
    {
        return trim(strip_tags($value));
    }

    function wp_kses_post(string $value): string
    {
        return strip_tags($value, '<p><br><strong><em><ul><ol><li><a>');
    }

    function apply_filters(string $hook, $value)
    {
        return $value;
    }

    function current_user_can(string $capability, int $id = 0): bool
    {
        return true;
    }

    function get_post_meta(int $id, string $key = '', bool $single = false)
    {
        if ($key === '') {
            return $GLOBALS['owe_test_post_meta'][$id] ?? [];
        }
        if (isset($GLOBALS['owe_test_post_meta'][$id])
            && array_key_exists($key, $GLOBALS['owe_test_post_meta'][$id])
        ) {
            $values = $GLOBALS['owe_test_post_meta'][$id][$key];
            return $single ? ($values[0] ?? '') : $values;
        }
        if ($key === '_elementor_template_type') {
            return $GLOBALS['owe_test_template_types'][$id] ?? '';
        }
        if ($key === '_wp_page_template') {
            return $GLOBALS['owe_test_page_templates'][$id] ?? '';
        }
        return '';
    }

    function metadata_exists(string $type, int $id, string $key): bool
    {
        return (isset($GLOBALS['owe_test_post_meta'][$id])
                && array_key_exists($key, $GLOBALS['owe_test_post_meta'][$id]))
            || ($key === '_wp_page_template'
                && array_key_exists($id, $GLOBALS['owe_test_page_templates'] ?? []));
    }

    function get_post(int $id)
    {
        foreach ($GLOBALS['owe_test_posts'] ?? [] as $post) {
            if ($post instanceof WP_Post && $post->ID === $id) {
                return $post;
            }
        }
        return null;
    }

    /** @return array<string, string> */
    function get_page_templates($post = null, string $postType = 'page'): array
    {
        return $GLOBALS['owe_test_page_template_options'][$postType] ?? [];
    }

    function is_post_type_hierarchical(string $postType): bool
    {
        return in_array($postType, $GLOBALS['owe_test_hierarchical_types'] ?? [], true);
    }

    /** @return array<int, int> */
    function get_post_ancestors($post): array
    {
        $id = $post instanceof WP_Post ? (int) $post->ID : (int) $post;
        return $GLOBALS['owe_test_post_ancestors'][$id] ?? [];
    }

    function wp_update_post(array $data, bool $wpError = false)
    {
        $post = get_post((int) ($data['ID'] ?? 0));
        if (!$post instanceof WP_Post) {
            return 0;
        }
        if (array_key_exists('post_parent', $data)) {
            $post->post_parent = (int) $data['post_parent'];
        }
        if (array_key_exists('menu_order', $data)) {
            $post->menu_order = (int) $data['menu_order'];
        }
        return (int) $post->ID;
    }

    function update_post_meta(int $id, string $key, $value)
    {
        $GLOBALS['owe_test_post_meta'][$id][$key] = [$value];
        return true;
    }

    function delete_post_meta(int $id, string $key): bool
    {
        unset($GLOBALS['owe_test_post_meta'][$id][$key]);
        return true;
    }

    function is_wp_error($value): bool
    {
        return false;
    }

    require_once __DIR__ . '/../package/.opencode/tools/elementor-bridge/checks.php';
    require_once __DIR__ . '/../package/.opencode/tools/elementor-bridge/validator.php';
    require_once __DIR__ . '/../package/.opencode/tools/elementor-bridge/reader.php';
    require_once __DIR__ . '/../package/.opencode/tools/elementor-bridge/writer.php';
    require_once __DIR__ . '/../package/.opencode/tools/elementor-bridge/templates.php';

    final class OWE_Test_Widget
    {
        /** @var array<string, mixed> */
        private $controls;

        /** @param array<string, mixed> $controls */
        public function __construct(array $controls)
        {
            $this->controls = $controls;
        }

        /** @return array<string, mixed> */
        public function get_controls(): array
        {
            return $this->controls;
        }
    }

    final class OWE_Test_Widget_Manager
    {
        /** @var array<string, object> */
        private $widgets;

        /** @param array<string, object> $widgets */
        public function __construct(array $widgets)
        {
            $this->widgets = $widgets;
        }

        public function get_widget_types(string $name)
        {
            return $this->widgets[$name] ?? null;
        }
    }

    final class OWE_Test_Document
    {
        /** @var bool */
        private $saving = false;

        /** @var bool */
        private $built = false;

        public function is_saving(): bool
        {
            return $this->saving;
        }

        public function set_is_saving(bool $saving): void
        {
            $this->saving = $saving;
        }

        public function get_main_id(): int
        {
            return 99;
        }

        public static function get_property(string $name)
        {
            return $name === 'support_wp_page_templates';
        }

        public function is_built_with_elementor(): bool
        {
            return $this->built;
        }

        public function set_is_built_with_elementor(bool $built): void
        {
            $this->built = $built;
        }

        /** @param array<int, mixed> $elements */
        public function get_elements_raw_data(array $elements, bool $withHtml = false): array
        {
            if (!$this->saving) {
                throw new \RuntimeException('Preflight did not enable save normalization.');
            }
            return $elements;
        }
    }

    function owe_test_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    $plugin = new \stdClass();
    $plugin->widgets_manager = new OWE_Test_Widget_Manager([
        'heading' => new OWE_Test_Widget([
            'title' => ['type' => 'textarea', 'label' => 'Title'],
        ]),
        'tabs' => new OWE_Test_Widget([
            'tabs' => [
                'type' => 'repeater',
                'label' => 'Tabs',
                'fields' => [
                    'tab_title' => ['type' => 'text', 'label' => 'Title'],
                    'tab_content' => ['type' => 'wysiwyg', 'label' => 'Content'],
                ],
            ],
        ]),
        'video' => new OWE_Test_Widget([
            'youtube_url' => ['type' => 'text', 'label' => 'URL'],
        ]),
    ]);
    \Elementor\Plugin::$instance = $plugin;

    $elements = [[
        'id' => 'abc123',
        'elType' => 'container',
        'settings' => ['padding' => '20'],
        'elements' => [
            [
                'id' => 'def456',
                'elType' => 'widget',
                'widgetType' => 'heading',
                'settings' => ['title' => 'Old heading'],
                'elements' => [],
            ],
            [
                'id' => 'fedcba',
                'elType' => 'widget',
                'widgetType' => 'tabs',
                'settings' => [
                    'tabs' => [[
                        '_id' => 'item01',
                        'tab_title' => 'Old tab',
                        'tab_content' => '<p>Old content</p>',
                    ]],
                ],
                'elements' => [],
            ],
            [
                'id' => 'a1b2c3',
                'elType' => 'widget',
                'widgetType' => 'video',
                'settings' => ['youtube_url' => 'https://example.test/video'],
                'elements' => [],
            ],
            [
                'id' => 'b1c2d3',
                'elType' => 'widget',
                'widgetType' => 'heading',
                'settings' => ['title' => 'Dynamic', '__dynamic__' => ['title' => '[site-title]']],
                'elements' => [],
            ],
        ],
    ]];

    $index = owe_bridge_section_content_index($elements[0]);
    owe_test_assert(isset($index['def456:title']), 'heading copy was not indexed');
    owe_test_assert(isset($index['fedcba:tabs:item01:tab_title']), 'repeater title was not indexed');
    owe_test_assert(isset($index['fedcba:tabs:item01:tab_content']), 'repeater content was not indexed');
    owe_test_assert(!isset($index['a1b2c3:youtube_url']), 'functional URL was exposed as copy');
    owe_test_assert(!isset($index['b1c2d3:title']), 'Dynamic Tag field was exposed as copy');

    $requestUpdates = [
        [
            'ref' => 'def456:title',
            'expected_content_hash' => $index['def456:title']['hash'],
            'value' => '<strong>New heading</strong>',
        ],
        [
            'ref' => 'fedcba:tabs:item01:tab_content',
            'expected_content_hash' => $index['fedcba:tabs:item01:tab_content']['hash'],
            'value' => '<p>New content</p>',
        ],
    ];
    $prepared = owe_bridge_prepare_content_updates($requestUpdates, $index);
    $after = owe_bridge_apply_content_operation($elements, 'abc123', $prepared);
    owe_bridge_verify_content_isolation($elements, $after, 'abc123', $prepared);
    owe_test_assert($after[0]['elements'][0]['settings']['title'] === '<strong>New heading</strong>', 'heading copy was not updated');
    owe_test_assert($after[0]['elements'][1]['settings']['tabs'][0]['tab_content'] === '<p>New content</p>', 'repeater copy was not updated');
    owe_test_assert($after[0]['elements'][2]['settings']['youtube_url'] === 'https://example.test/video', 'functional URL changed');

    $tampered = $after;
    $tampered[0]['settings']['padding'] = '40';
    try {
        owe_bridge_verify_content_isolation($elements, $tampered, 'abc123', $prepared);
        owe_test_assert(false, 'out-of-scope setting change was accepted');
    } catch (OWE_Bridge_Exception $error) {
        owe_test_assert($error->oweCode() === 'CONTENT_DIFF_OUT_OF_SCOPE', 'unexpected isolation error');
    }

    $stale = $requestUpdates;
    $stale[0]['expected_content_hash'] = str_repeat('0', 64);
    try {
        owe_bridge_prepare_content_updates($stale, $index);
        owe_test_assert(false, 'stale content hash was accepted');
    } catch (OWE_Bridge_Exception $error) {
        owe_test_assert($error->oweCode() === 'STALE_CONTENT_HASH', 'unexpected stale hash error');
    }

    try {
        owe_bridge_sanitize_content_value('<script>alert(1)</script>', 'html');
        owe_test_assert(false, 'unsafe HTML was accepted');
    } catch (OWE_Bridge_Exception $error) {
        owe_test_assert($error->oweCode() === 'CONTENT_SANITIZATION_CHANGED', 'unexpected sanitization error');
    }

    $oldLinkedCopy = '<p><a href="/contact">Old text</a> [current_year]</p>';
    $newLinkedCopy = '<p><a href="/contact">New text</a> [current_year]</p>';
    owe_test_assert(
        owe_bridge_sanitize_content_value($newLinkedCopy, 'html', $oldLinkedCopy) === $newLinkedCopy,
        'preserved functional markup was rejected'
    );
    try {
        owe_bridge_sanitize_content_value('<p><a href="/other">New text</a> [current_year]</p>', 'html', $oldLinkedCopy);
        owe_test_assert(false, 'changed link was accepted as copy');
    } catch (OWE_Bridge_Exception $error) {
        owe_test_assert($error->oweCode() === 'CONTENT_FUNCTIONAL_MARKUP_CHANGED', 'unexpected link protection error');
    }
    try {
        owe_bridge_sanitize_content_value('<p><a href="/contact">New text</a></p>', 'html', $oldLinkedCopy);
        owe_test_assert(false, 'removed shortcode was accepted as copy');
    } catch (OWE_Bridge_Exception $error) {
        owe_test_assert($error->oweCode() === 'CONTENT_FUNCTIONAL_MARKUP_CHANGED', 'unexpected shortcode protection error');
    }

    owe_bridge_validate_available_widgets($elements);
    owe_test_assert(
        owe_bridge_normalize_document_elements(new OWE_Test_Document(), $elements) === $elements,
        'Elementor save preflight changed canonical elements'
    );
    unset($GLOBALS['owe_test_page_templates'][99]);
    owe_test_assert(
        owe_bridge_document_page_template(new OWE_Test_Document()) === ['exists' => false, 'value' => ''],
        'missing page-template metadata was normalized incorrectly'
    );
    $GLOBALS['owe_test_page_templates'][99] = 'default';
    owe_test_assert(
        owe_bridge_document_page_template(new OWE_Test_Document()) === ['exists' => true, 'value' => 'default'],
        'existing page-template metadata was not preserved'
    );
    $GLOBALS['owe_test_post_meta'] = [];
    $GLOBALS['owe_test_elementor_support'] = [20 => true, 21 => true, 22 => true, 23 => false];
    $nativePost = new WP_Post(20, 'Empty native page', 'empty-native', 'page', 'draft');
    $nativeDocument = new OWE_Test_Document();
    $sourceHash = owe_bridge_elementor_activation_hash($nativePost);
    owe_test_assert((bool) preg_match('/^[a-f0-9]{64}$/', $sourceHash), 'activation source hash is invalid');
    owe_test_assert(
        owe_bridge_elementor_activation_issue($nativePost, $nativeDocument) === '',
        'clean empty native page was not eligible for activation'
    );
    $portfolioPost = new WP_Post(22, 'Portfolio item', 'portfolio-item', 'case', 'publish');
    owe_test_assert(
        owe_bridge_elementor_activation_issue($portfolioPost, new OWE_Test_Document()) === '',
        'Elementor-supported custom post type was not eligible for activation'
    );
    $unsupportedPost = new WP_Post(23, 'Unsupported item', 'unsupported-item', 'private_record', 'draft');
    owe_test_assert(
        owe_bridge_elementor_activation_issue($unsupportedPost, new OWE_Test_Document()) === 'POST_TYPE_NOT_SUPPORTED',
        'unsupported custom post type was accepted for activation'
    );
    $contentPost = new WP_Post(21, 'Native content', 'native-content', 'post', 'draft', '<p>Keep me</p>');
    owe_test_assert(
        owe_bridge_elementor_activation_issue($contentPost, new OWE_Test_Document()) === 'NATIVE_CONTENT_PRESENT',
        'native content was accepted for activation'
    );
    owe_test_assert(
        !hash_equals($sourceHash, owe_bridge_elementor_activation_hash($contentPost)),
        'activation hash did not include native state'
    );
    $GLOBALS['owe_test_post_meta'][20]['_elementor_css'] = ['stale'];
    owe_test_assert(
        owe_bridge_elementor_activation_issue($nativePost, $nativeDocument) === 'ELEMENTOR_STATE_INCONSISTENT',
        'partial Elementor state was accepted for activation'
    );
    unset($GLOBALS['owe_test_post_meta'][20]);
    $nativeDocument->set_is_built_with_elementor(true);
    owe_test_assert(
        owe_bridge_elementor_activation_issue($nativePost, $nativeDocument) === 'DOCUMENT_ALREADY_ENABLED',
        'existing Elementor activation was not detected'
    );
    $attributePost = new WP_Post(30, 'Portfolio attributes', 'portfolio-attributes', 'portfolio', 'publish');
    $attributeParent = new WP_Post(31, 'Portfolio parent', 'portfolio-parent', 'portfolio', 'publish');
    $GLOBALS['owe_test_posts'] = [$attributePost, $attributeParent];
    $GLOBALS['owe_test_hierarchical_types'] = ['page', 'portfolio'];
    $GLOBALS['owe_test_page_template_options']['portfolio'] = [
        'elementor_canvas' => 'Elementor Canvas',
        'elementor_header_footer' => 'Elementor Full Width',
        'portfolio-custom.php' => 'Portfolio Custom',
    ];
    $availableTemplates = owe_bridge_available_page_templates($attributePost, new OWE_Test_Document());
    owe_test_assert(
        isset($availableTemplates['elementor_header_footer'], $availableTemplates['portfolio-custom.php']),
        'registered custom-post templates were not exposed'
    );
    $attributeSnapshot = owe_bridge_page_attributes_snapshot($attributePost);
    $attributeHash = owe_bridge_page_attributes_hash($attributePost);
    owe_test_assert((bool) preg_match('/^[a-f0-9]{64}$/', $attributeHash), 'attribute hash is invalid');
    $preparedAttributes = owe_bridge_prepare_page_attributes([
        'template' => 'elementor_header_footer',
        'parent_id' => 31,
        'menu_order' => 4,
    ], $attributePost, new OWE_Test_Document(), $attributeSnapshot);
    owe_test_assert(
        $preparedAttributes === [
            'template' => 'elementor_header_footer',
            'parent_id' => 31,
            'menu_order' => 4,
        ],
        'standard page attributes were not prepared'
    );
    $expectedAttributes = owe_bridge_expected_page_attributes($attributeSnapshot, $preparedAttributes);
    owe_test_assert(
        $expectedAttributes['template'] === ['exists' => true, 'value' => 'elementor_header_footer']
            && $expectedAttributes['parent_id'] === 31
            && $expectedAttributes['menu_order'] === 4,
        'expected page attributes are invalid'
    );
    owe_bridge_apply_page_attributes(30, $preparedAttributes);
    owe_test_assert(
        owe_bridge_page_attributes_snapshot($attributePost) === $expectedAttributes,
        'page attributes were not persisted through allowlisted WordPress APIs'
    );
    owe_bridge_restore_page_attributes($attributeSnapshot, $preparedAttributes, $expectedAttributes);
    owe_test_assert(
        owe_bridge_page_attributes_snapshot($attributePost) === $attributeSnapshot,
        'page attributes rollback did not restore the original snapshot'
    );
    try {
        owe_bridge_prepare_page_attributes(
            ['template' => 'elementor_full_width'],
            $attributePost,
            new OWE_Test_Document(),
            $attributeSnapshot
        );
        owe_test_assert(false, 'unregistered page template was accepted');
    } catch (OWE_Bridge_Exception $error) {
        owe_test_assert($error->oweCode() === 'PAGE_TEMPLATE_NOT_AVAILABLE', 'unexpected page-template error');
    }
    $GLOBALS['owe_test_post_ancestors'][31] = [30];
    try {
        owe_bridge_prepare_page_attributes(
            ['parent_id' => 31],
            $attributePost,
            new OWE_Test_Document(),
            $attributeSnapshot
        );
        owe_test_assert(false, 'cyclic page parent was accepted');
    } catch (OWE_Bridge_Exception $error) {
        owe_test_assert($error->oweCode() === 'PAGE_PARENT_CYCLE', 'unexpected page-parent error');
    }
    unset($GLOBALS['owe_test_post_ancestors'][31]);
    $unavailable = $elements;
    $unavailable[0]['elements'][] = [
        'id' => 'c1d2e3',
        'elType' => 'widget',
        'widgetType' => 'missing-widget',
        'settings' => [],
        'elements' => [],
    ];
    try {
        owe_bridge_validate_available_widgets($unavailable);
        owe_test_assert(false, 'unavailable widget was accepted');
    } catch (OWE_Bridge_Exception $error) {
        owe_test_assert($error->oweCode() === 'ELEMENTOR_WIDGET_UNAVAILABLE', 'unexpected widget availability error');
    }

    $GLOBALS['owe_test_posts'] = [
        new WP_Post(10, 'For Services', 'for-services'),
        new WP_Post(11, 'Shared Name', 'shared-one'),
        new WP_Post(12, 'Shared Name', 'shared-two'),
        new WP_Post(13, 'Header Services', 'header-services'),
    ];
    $GLOBALS['owe_test_template_types'] = [10 => 'page', 11 => 'section', 12 => 'container', 13 => 'header'];
    $slugMatches = owe_bridge_find_templates('slug', 'for-services');
    owe_test_assert(count($slugMatches) === 1 && $slugMatches[0]['id'] === 10, 'exact slug lookup failed');
    owe_test_assert(owe_bridge_find_templates('slug', 'for') === [], 'partial slug lookup was accepted');
    $nameMatches = owe_bridge_find_templates('name', 'Shared Name');
    owe_test_assert(count($nameMatches) === 2, 'duplicate exact names were not returned');
    owe_test_assert(owe_bridge_find_templates('slug', 'header-services') === [], 'Theme Builder template was exposed');

    $templateSource = [[
        'id' => 'abc111',
        'elType' => 'container',
        'settings' => ['custom_selector' => '.elementor-element-abc112'],
        'elements' => [[
            'id' => 'abc112',
            'elType' => 'widget',
            'widgetType' => 'heading',
            'settings' => ['title' => 'Template heading'],
            'elements' => [],
        ]],
    ]];
    $templateClone = [[
        'id' => 'def222',
        'elType' => 'container',
        'settings' => ['custom_selector' => '.elementor-element-abc112'],
        'elements' => [[
            'id' => 'def223',
            'elType' => 'widget',
            'widgetType' => 'heading',
            'settings' => ['title' => 'Template heading'],
            'elements' => [],
        ]],
    ]];
    $preparedTemplate = owe_bridge_prepare_template_clone($templateSource, $templateClone, $elements);
    owe_test_assert(
        $preparedTemplate[0]['settings']['custom_selector'] === '.elementor-element-def223',
        'internal Elementor selector was not remapped'
    );
    $prefixMap = ['abc123' => 'def123', 'abc1234' => 'def1234'];
    owe_test_assert(
        owe_bridge_rewrite_template_references('.elementor-element-abc1234 .elementor-element-abc123', $prefixMap)
            === '.elementor-element-def1234 .elementor-element-def123',
        'prefixed Elementor IDs were remapped incorrectly'
    );
    $inserted = owe_bridge_apply_template_operation($elements, $preparedTemplate, 'append', '');
    owe_bridge_verify_template_isolation($elements, $inserted, $preparedTemplate, 'append');
    owe_test_assert(count($inserted) === 2 && $inserted[1]['id'] === 'def222', 'template append failed');
    $replaced = owe_bridge_apply_template_operation($elements, $preparedTemplate, 'replace', '');
    owe_bridge_verify_template_isolation($elements, $replaced, $preparedTemplate, 'replace');
    owe_test_assert(count($replaced) === 1 && $replaced[0]['id'] === 'def222', 'template replace failed');
    try {
        owe_bridge_validate_template_page_settings(['custom_css' => '.unsafe{}']);
        owe_test_assert(false, 'custom CSS page setting was accepted');
    } catch (OWE_Bridge_Exception $error) {
        owe_test_assert($error->oweCode() === 'TEMPLATE_PAGE_SETTINGS_UNSUPPORTED', 'unexpected page setting error');
    }
    owe_test_assert(
        owe_bridge_validate_template_page_settings(['template' => 'elementor_canvas']) === ['template' => 'elementor_canvas'],
        'page template setting was rejected'
    );
    try {
        owe_bridge_validate_template_page_settings(['template' => 'unknown-layout.php']);
        owe_test_assert(false, 'unknown page layout was accepted');
    } catch (OWE_Bridge_Exception $error) {
        owe_test_assert($error->oweCode() === 'TEMPLATE_PAGE_LAYOUT_INVALID', 'unexpected page layout error');
    }
    try {
        owe_bridge_validate_template_page_settings(['post_title' => 'Changed outside scope']);
        owe_test_assert(false, 'special WordPress setting was accepted');
    } catch (OWE_Bridge_Exception $error) {
        owe_test_assert($error->oweCode() === 'TEMPLATE_PAGE_SETTINGS_UNSUPPORTED', 'unexpected special setting error');
    }
    $reorderedClone = $templateClone;
    $reorderedClone[0]['elements'][0]['widgetType'] = 'button';
    try {
        owe_bridge_prepare_template_clone($templateSource, $reorderedClone, $elements);
        owe_test_assert(false, 'structurally changed clone was accepted');
    } catch (OWE_Bridge_Exception $error) {
        owe_test_assert($error->oweCode() === 'TEMPLATE_CLONE_STRUCTURE_CHANGED', 'unexpected clone structure error');
    }

    echo "Elementor widget content tests passed.\n";
}
