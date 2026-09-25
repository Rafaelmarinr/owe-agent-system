<?php

declare(strict_types=1);

final class OWE_Bridge_Exception extends RuntimeException
{
    /** @var string */
    private $oweCode;

    public function __construct(string $oweCode, string $message = '')
    {
        parent::__construct($message === '' ? $oweCode : $message);
        $this->oweCode = $oweCode;
    }

    public function oweCode(): string
    {
        return $this->oweCode;
    }
}

function owe_bridge_fail(string $code, string $message = ''): void
{
    throw new OWE_Bridge_Exception($code, $message);
}

function owe_bridge_boot_wordpress(string $projectRoot): void
{
    $wpLoad = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'wp-load.php';
    if (!is_file($wpLoad)) {
        owe_bridge_fail('WORDPRESS_NOT_FOUND');
    }

    if (!defined('WP_USE_THEMES')) {
        define('WP_USE_THEMES', false);
    }

    ob_start();
    require_once $wpLoad;
    ob_end_clean();

    if (!function_exists('get_post') || !function_exists('wp_set_current_user')) {
        owe_bridge_fail('WORDPRESS_LOAD_FAILED');
    }
}

function owe_bridge_set_local_user(): int
{
    $currentId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    if ($currentId > 0 && current_user_can('edit_pages')) {
        return $currentId;
    }

    $administrators = get_users([
        'role' => 'administrator',
        'number' => 1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'fields' => 'ID',
    ]);

    if ($administrators === []) {
        owe_bridge_fail('LOCAL_EDITOR_USER_NOT_FOUND');
    }

    $userId = (int) $administrators[0];
    wp_set_current_user($userId);

    if (!current_user_can('edit_pages')) {
        owe_bridge_fail('LOCAL_EDITOR_PERMISSION_DENIED');
    }

    return $userId;
}

function owe_bridge_check_elementor(bool $requiresPro = false): void
{
    if (!class_exists('Elementor\\Plugin')) {
        owe_bridge_fail('ELEMENTOR_NOT_ACTIVE');
    }

    $plugin = \Elementor\Plugin::$instance;
    if (!isset($plugin->documents) || !isset($plugin->db)) {
        owe_bridge_fail('ELEMENTOR_API_UNAVAILABLE');
    }

    if ($requiresPro && !defined('ELEMENTOR_PRO_VERSION')) {
        owe_bridge_fail('ELEMENTOR_PRO_REQUIRED');
    }
}

/**
 * @return WP_Post
 */
function owe_bridge_resolve_post(string $pageReference)
{
    $pageReference = trim($pageReference);
    if ($pageReference === '') {
        owe_bridge_fail('PAGE_REFERENCE_REQUIRED');
    }

    $postId = 0;
    if (ctype_digit($pageReference)) {
        $postId = (int) $pageReference;
    } else {
        $postId = (int) url_to_postid($pageReference);

        if ($postId === 0) {
            $path = (string) parse_url($pageReference, PHP_URL_PATH);
            $path = trim($path === '' ? $pageReference : $path, '/');
            if ($path !== '') {
                $postTypes = get_post_types(['public' => true], 'names');
                $post = get_page_by_path($path, OBJECT, array_values($postTypes));
                if ($post instanceof WP_Post) {
                    $postId = (int) $post->ID;
                }
            }
        }
    }

    $post = $postId > 0 ? get_post($postId) : null;
    if (!$post instanceof WP_Post || in_array($post->post_status, ['trash', 'auto-draft'], true)) {
        owe_bridge_fail('PAGE_NOT_FOUND');
    }

    if (!current_user_can('edit_post', $postId)) {
        owe_bridge_fail('PAGE_EDIT_PERMISSION_DENIED');
    }

    return $post;
}

/**
 * @return Elementor\Core\Base\Document
 */
function owe_bridge_get_document(int $postId, bool $requireElementorPage = true)
{
    $document = \Elementor\Plugin::$instance->documents->get($postId, false);
    if (!$document || !method_exists($document, 'get_elements_data') || !method_exists($document, 'save')) {
        owe_bridge_fail('ELEMENTOR_DOCUMENT_UNAVAILABLE');
    }

    if ($requireElementorPage && !$document->is_built_with_elementor()) {
        owe_bridge_fail('PAGE_NOT_BUILT_WITH_ELEMENTOR');
    }

    if (!$document->is_editable_by_current_user()) {
        owe_bridge_fail('ELEMENTOR_DOCUMENT_NOT_EDITABLE');
    }

    return $document;
}

/**
 * @param array<int, mixed> $elements
 */
function owe_bridge_assert_compatible_structure(array $elements): void
{
    foreach ($elements as $element) {
        if (!is_array($element)
            || !isset($element['id'], $element['elType'], $element['elements'])
            || !is_string($element['id'])
            || !is_string($element['elType'])
            || !is_array($element['elements'])
        ) {
            owe_bridge_fail('ELEMENTOR_STRUCTURE_UNSUPPORTED');
        }
    }
}

/** @return array<string, mixed> */
function owe_bridge_elementor_activation_snapshot(WP_Post $post): array
{
    $elementorMeta = [];
    foreach (get_post_meta((int) $post->ID) as $key => $values) {
        if (is_string($key) && strpos($key, '_elementor_') === 0) {
            $elementorMeta[$key] = is_array($values) ? array_values($values) : [$values];
        }
    }
    ksort($elementorMeta);

    return [
        'post_id' => (int) $post->ID,
        'post_type' => (string) $post->post_type,
        'post_status' => (string) $post->post_status,
        'post_content' => (string) $post->post_content,
        'post_modified_gmt' => (string) $post->post_modified_gmt,
        'elementor_meta' => $elementorMeta,
    ];
}

function owe_bridge_elementor_activation_hash(WP_Post $post): string
{
    $encoded = wp_json_encode(
        owe_bridge_elementor_activation_snapshot($post),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if (!is_string($encoded)) {
        owe_bridge_fail('ACTIVATION_HASH_ENCODING_FAILED');
    }

    return hash('sha256', $encoded);
}

function owe_bridge_elementor_activation_issue(WP_Post $post, $document): string
{
    if ($document->is_built_with_elementor()) {
        return 'DOCUMENT_ALREADY_ENABLED';
    }
    if (!class_exists('Elementor\\Utils')
        || !is_callable(['Elementor\\Utils', 'is_post_support'])
    ) {
        return 'ELEMENTOR_SUPPORT_CHECK_UNAVAILABLE';
    }
    if (!\Elementor\Utils::is_post_support((int) $post->ID)) {
        return 'POST_TYPE_NOT_SUPPORTED';
    }
    if (trim((string) $post->post_content) !== '') {
        return 'NATIVE_CONTENT_PRESENT';
    }
    $snapshot = owe_bridge_elementor_activation_snapshot($post);
    if (($snapshot['elementor_meta'] ?? []) !== []) {
        return 'ELEMENTOR_STATE_INCONSISTENT';
    }

    return '';
}
