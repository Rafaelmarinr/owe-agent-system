<?php

declare(strict_types=1);

final class OWE_Content_Bridge_Exception extends RuntimeException
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

function owe_content_fail(string $code, string $message = ''): void
{
    throw new OWE_Content_Bridge_Exception($code, $message);
}

function owe_content_boot_wordpress(string $projectRoot): void
{
    $wpLoad = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'wp-load.php';
    if (!is_file($wpLoad)) {
        owe_content_fail('WORDPRESS_NOT_FOUND');
    }

    if (!defined('WP_USE_THEMES')) {
        define('WP_USE_THEMES', false);
    }

    ob_start();
    require_once $wpLoad;
    ob_end_clean();

    if (!function_exists('get_post')
        || !function_exists('wp_insert_post')
        || !function_exists('wp_set_current_user')
    ) {
        owe_content_fail('WORDPRESS_LOAD_FAILED');
    }
}

function owe_content_set_local_user(): int
{
    $currentId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    if ($currentId > 0 && current_user_can('edit_posts')) {
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
        owe_content_fail('LOCAL_EDITOR_USER_NOT_FOUND');
    }

    $userId = (int) $administrators[0];
    wp_set_current_user($userId);
    if (!current_user_can('edit_posts')) {
        owe_content_fail('LOCAL_EDITOR_PERMISSION_DENIED');
    }

    return $userId;
}

/**
 * @return WP_Post_Type
 */
function owe_content_get_post_type(string $postType)
{
    $postType = sanitize_key($postType);
    $object = get_post_type_object($postType);
    if (!$object instanceof WP_Post_Type
        || in_array($postType, ['attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset'], true)
        || (!$object->public && !$object->show_ui)
        || !post_type_supports($postType, 'editor')
    ) {
        owe_content_fail('POST_TYPE_NOT_SUPPORTED');
    }

    return $object;
}

/**
 * @return WP_Taxonomy
 */
function owe_content_get_taxonomy(string $taxonomy)
{
    $taxonomy = sanitize_key($taxonomy);
    $object = get_taxonomy($taxonomy);
    if (!$object instanceof WP_Taxonomy || (!$object->public && !$object->show_ui)) {
        owe_content_fail('TAXONOMY_NOT_SUPPORTED');
    }

    return $object;
}

/**
 * @return WP_Post
 */
function owe_content_resolve_post(string $reference)
{
    $reference = trim($reference);
    if ($reference === '') {
        owe_content_fail('POST_REFERENCE_REQUIRED');
    }

    $postId = 0;
    if (ctype_digit($reference)) {
        $postId = (int) $reference;
    } else {
        $postId = (int) url_to_postid($reference);
        if ($postId === 0) {
            $path = (string) parse_url($reference, PHP_URL_PATH);
            $path = trim($path === '' ? $reference : $path, '/');
            if ($path !== '') {
                $postTypes = get_post_types(['show_ui' => true], 'names');
                $post = get_page_by_path($path, OBJECT, array_values($postTypes));
                if ($post instanceof WP_Post) {
                    $postId = (int) $post->ID;
                }
            }
        }
    }

    $post = $postId > 0 ? get_post($postId) : null;
    if (!$post instanceof WP_Post || in_array($post->post_status, ['trash', 'auto-draft'], true)) {
        owe_content_fail('POST_NOT_FOUND');
    }
    owe_content_get_post_type((string) $post->post_type);
    if (!current_user_can('edit_post', $postId)) {
        owe_content_fail('POST_EDIT_PERMISSION_DENIED');
    }

    return $post;
}

function owe_content_is_elementor_post(int $postId): bool
{
    $editMode = (string) get_post_meta($postId, '_elementor_edit_mode', true);
    $elementorData = get_post_meta($postId, '_elementor_data', true);

    return $editMode === 'builder'
        || (is_string($elementorData) && trim($elementorData) !== '' && trim($elementorData) !== '[]')
        || (is_array($elementorData) && $elementorData !== []);
}

/**
 * @return array<string, array<int, int>>
 */
function owe_content_term_ids_for_post(int $postId, string $postType): array
{
    $result = [];
    $taxonomies = get_object_taxonomies($postType, 'objects');
    foreach ($taxonomies as $taxonomy => $object) {
        if (!$object instanceof WP_Taxonomy || (!$object->public && !$object->show_ui)) {
            continue;
        }
        $ids = wp_get_object_terms($postId, $taxonomy, ['fields' => 'ids']);
        if (is_wp_error($ids)) {
            owe_content_fail('TERMS_READ_FAILED');
        }
        $normalized = array_map('intval', $ids);
        sort($normalized, SORT_NUMERIC);
        $result[$taxonomy] = $normalized;
    }
    ksort($result);

    return $result;
}

/**
 * @return array<string, mixed>
 */
function owe_content_post_snapshot(WP_Post $post): array
{
    return [
        'id' => (int) $post->ID,
        'post_type' => (string) $post->post_type,
        'status' => (string) $post->post_status,
        'slug' => (string) $post->post_name,
        'title' => (string) $post->post_title,
        'excerpt' => (string) $post->post_excerpt,
        'content' => (string) $post->post_content,
        'terms' => owe_content_term_ids_for_post((int) $post->ID, (string) $post->post_type),
    ];
}

/**
 * @param array<string, mixed> $snapshot
 */
function owe_content_snapshot_hash(array $snapshot): string
{
    return hash('sha256', (string) wp_json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * @return array<string, mixed>
 */
function owe_content_term_snapshot(WP_Term $term): array
{
    return [
        'id' => (int) $term->term_id,
        'taxonomy' => (string) $term->taxonomy,
        'name' => (string) $term->name,
        'slug' => (string) $term->slug,
        'description' => (string) $term->description,
        'parent' => (int) $term->parent,
    ];
}

/**
 * @return WP_Term
 */
function owe_content_resolve_term(string $taxonomy, int $termId)
{
    owe_content_get_taxonomy($taxonomy);
    $term = get_term($termId, $taxonomy);
    if (!$term instanceof WP_Term) {
        owe_content_fail('TERM_NOT_FOUND');
    }
    return $term;
}

function owe_content_log_error(string $projectRoot, Throwable $error): void
{
    $directory = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.owe' . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($directory)) {
        @mkdir($directory, 0755, true);
    }
    $code = $error instanceof OWE_Content_Bridge_Exception ? $error->oweCode() : 'INTERNAL_ERROR';
    $line = gmdate('c') . "\t" . $code . "\t" . get_class($error) . "\t"
        . str_replace(["\r", "\n"], ' ', $error->getMessage()) . "\n";
    @file_put_contents($directory . DIRECTORY_SEPARATOR . 'wordpress-content-bridge.log', $line, FILE_APPEND | LOCK_EX);
}
