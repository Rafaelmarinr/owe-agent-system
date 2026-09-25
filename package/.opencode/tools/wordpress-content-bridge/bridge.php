<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "BLOCKED:CLI_REQUIRED\n");
    exit(64);
}

require_once __DIR__ . '/checks.php';
require_once __DIR__ . '/validator.php';

/**
 * @param array<int, string> $arguments
 * @return array<string, string>
 */
function owe_content_parse_options(array $arguments): array
{
    $options = [];
    for ($index = 0, $count = count($arguments); $index < $count; $index++) {
        $argument = $arguments[$index];
        if (substr($argument, 0, 2) !== '--') {
            owe_content_fail('INVALID_ARGUMENT');
        }

        $raw = substr($argument, 2);
        $equals = strpos($raw, '=');
        if ($equals !== false) {
            $key = substr($raw, 0, $equals);
            $options[$key] = substr($raw, $equals + 1);
            continue;
        }

        if (!isset($arguments[$index + 1]) || substr($arguments[$index + 1], 0, 2) === '--') {
            owe_content_fail('INVALID_ARGUMENT');
        }
        $options[$raw] = $arguments[++$index];
    }

    return $options;
}

function owe_content_safe_project_path(
    string $projectRoot,
    string $relativePath,
    string $allowedDirectory,
    bool $mustExist
): string {
    $relativePath = str_replace('\\', '/', trim($relativePath));
    $allowedDirectory = trim(str_replace('\\', '/', $allowedDirectory), '/');
    if ($relativePath === ''
        || $relativePath[0] === '/'
        || preg_match('/^[A-Za-z]:\//', $relativePath)
        || in_array('..', explode('/', $relativePath), true)
    ) {
        owe_content_fail('UNSAFE_LOCAL_PATH');
    }

    $normalized = substr($relativePath, 0, 2) === './' ? substr($relativePath, 2) : $relativePath;
    if ($normalized !== $allowedDirectory && strpos($normalized, $allowedDirectory . '/') !== 0) {
        owe_content_fail('UNSAFE_LOCAL_PATH');
    }

    $candidate = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if ($mustExist) {
        $resolved = realpath($candidate);
        $allowed = realpath($projectRoot . DIRECTORY_SEPARATOR . $allowedDirectory);
        if ($resolved === false
            || $allowed === false
            || ($resolved !== $allowed && strpos($resolved, $allowed . DIRECTORY_SEPARATOR) !== 0)
        ) {
            owe_content_fail('LOCAL_FILE_NOT_FOUND');
        }
        return $resolved;
    }

    $directory = dirname($candidate);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        owe_content_fail('LOCAL_OUTPUT_DIRECTORY_FAILED');
    }
    $resolvedDirectory = realpath($directory);
    $allowed = realpath($projectRoot . DIRECTORY_SEPARATOR . $allowedDirectory);
    if ($resolvedDirectory === false
        || $allowed === false
        || ($resolvedDirectory !== $allowed && strpos($resolvedDirectory, $allowed . DIRECTORY_SEPARATOR) !== 0)
    ) {
        owe_content_fail('UNSAFE_LOCAL_PATH');
    }

    return $candidate;
}

/**
 * @return array<string, mixed>
 */
function owe_content_read_request(string $path): array
{
    $size = filesize($path);
    if ($size === false || $size > 5 * 1024 * 1024) {
        owe_content_fail('REQUEST_SIZE_INVALID');
    }
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        owe_content_fail('REQUEST_READ_FAILED');
    }
    $request = json_decode($raw, true);
    if (!is_array($request) || json_last_error() !== JSON_ERROR_NONE) {
        owe_content_fail('REQUEST_JSON_INVALID');
    }
    return $request;
}

/**
 * @param array<string, mixed> $data
 */
function owe_content_write_json(string $path, array $data): void
{
    $encoded = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        owe_content_fail('JSON_ENCODE_FAILED');
    }
    $temporary = $path . '.tmp';
    if (file_put_contents($temporary, $encoded . "\n", LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        owe_content_fail('LOCAL_OUTPUT_WRITE_FAILED');
    }
}

/**
 * @return array<string, array<int, array<string, mixed>>>
 */
function owe_content_rich_terms(WP_Post $post): array
{
    $result = [];
    foreach (get_object_taxonomies((string) $post->post_type, 'objects') as $taxonomy => $object) {
        if (!$object instanceof WP_Taxonomy || (!$object->public && !$object->show_ui)) {
            continue;
        }
        $terms = wp_get_object_terms((int) $post->ID, $taxonomy);
        if (is_wp_error($terms)) {
            owe_content_fail('TERMS_READ_FAILED');
        }
        $result[$taxonomy] = [];
        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                $result[$taxonomy][] = [
                    'id' => (int) $term->term_id,
                    'name' => (string) $term->name,
                    'slug' => (string) $term->slug,
                ];
            }
        }
    }
    ksort($result);
    return $result;
}

/**
 * @return array{selector_value: string, matches: array<int, array<string, mixed>>}
 */
function owe_content_find_terms(string $taxonomy, string $selectorType, string $selectorValue): array
{
    owe_content_get_taxonomy($taxonomy);

    if ($selectorType === 'name') {
        $normalizedValue = trim(owe_content_require_string(
            $selectorValue,
            'TERM_LOOKUP_NAME_INVALID',
            200,
            false
        ));
        $query = ['name' => $normalizedValue];
    } elseif ($selectorType === 'slug') {
        $normalizedValue = sanitize_title(owe_content_require_string(
            $selectorValue,
            'TERM_LOOKUP_SLUG_INVALID',
            200,
            false
        ));
        if ($normalizedValue === '') {
            owe_content_fail('TERM_LOOKUP_SLUG_INVALID');
        }
        $query = ['slug' => $normalizedValue];
    } else {
        owe_content_fail('TERM_LOOKUP_SELECTOR_INVALID');
    }

    $terms = get_terms(array_merge([
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
        'orderby' => 'term_id',
        'order' => 'ASC',
        'number' => 101,
    ], $query));
    if (is_wp_error($terms)) {
        owe_content_fail('TERM_SEARCH_FAILED');
    }
    if (count($terms) > 100) {
        owe_content_fail('TERM_SEARCH_TOO_BROAD');
    }

    $matches = [];
    foreach ($terms as $term) {
        if (!$term instanceof WP_Term) {
            continue;
        }
        $matches[] = [
            'id' => (int) $term->term_id,
            'taxonomy' => (string) $term->taxonomy,
            'name' => (string) $term->name,
            'slug' => (string) $term->slug,
            'parent' => (int) $term->parent,
            'count' => (int) $term->count,
        ];
    }

    return [
        'selector_value' => $normalizedValue,
        'matches' => $matches,
    ];
}

/**
 * @param array<string, string> $fields
 */
function owe_content_assert_post_fields(WP_Post $post, array $fields): void
{
    $mapping = [
        'title' => 'post_title',
        'content' => 'post_content',
        'excerpt' => 'post_excerpt',
        'status' => 'post_status',
        'slug' => 'post_name',
    ];
    foreach ($fields as $key => $expected) {
        $property = $mapping[$key];
        if ((string) $post->{$property} !== $expected) {
            owe_content_fail('POST_WRITE_VALIDATION_FAILED');
        }
    }
}

/**
 * @param array<string, mixed> $snapshot
 */
function owe_content_restore_post(array $snapshot): void
{
    $result = wp_update_post(wp_slash([
        'ID' => (int) $snapshot['id'],
        'post_title' => (string) $snapshot['title'],
        'post_content' => (string) $snapshot['content'],
        'post_excerpt' => (string) $snapshot['excerpt'],
        'post_status' => (string) $snapshot['status'],
        'post_name' => (string) $snapshot['slug'],
    ]), true);
    if (is_wp_error($result)) {
        owe_content_fail('ROLLBACK_FAILED');
    }
}

/**
 * @param array<string, mixed> $snapshot
 */
function owe_content_restore_term(array $snapshot): void
{
    $result = wp_update_term((int) $snapshot['id'], (string) $snapshot['taxonomy'], [
        'name' => (string) $snapshot['name'],
        'slug' => (string) $snapshot['slug'],
        'description' => (string) $snapshot['description'],
        'parent' => (int) $snapshot['parent'],
    ]);
    if (is_wp_error($result)) {
        owe_content_fail('ROLLBACK_FAILED');
    }
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function owe_content_apply_create_post(array $request, bool $emit = true): array
{
    $postType = isset($request['post_type']) ? sanitize_key((string) $request['post_type']) : '';
    $type = owe_content_get_post_type($postType);
    $createCapability = isset($type->cap->create_posts) ? (string) $type->cap->create_posts : (string) $type->cap->edit_posts;
    if (!current_user_can($createCapability)) {
        owe_content_fail('POST_CREATE_PERMISSION_DENIED');
    }

    $rawFields = isset($request['fields']) && is_array($request['fields']) ? $request['fields'] : [];
    $fields = owe_content_validate_post_fields($rawFields, true);
    if (($fields['status'] ?? 'draft') === 'publish' && !current_user_can((string) $type->cap->publish_posts)) {
        owe_content_fail('POST_PUBLISH_PERMISSION_DENIED');
    }

    $taxonomies = isset($request['taxonomies']) ? $request['taxonomies'] : [];
    if (!is_array($taxonomies)) {
        owe_content_fail('TAXONOMIES_INVALID');
    }
    $validatedTerms = [];
    foreach ($taxonomies as $taxonomy => $termIds) {
        if (!is_string($taxonomy) || !is_object_in_taxonomy($postType, $taxonomy)) {
            owe_content_fail('TAXONOMY_NOT_ALLOWED_FOR_POST_TYPE');
        }
        $taxonomyObject = owe_content_get_taxonomy($taxonomy);
        if (!current_user_can((string) $taxonomyObject->cap->assign_terms)) {
            owe_content_fail('TERM_ASSIGN_PERMISSION_DENIED');
        }
        $validatedTerms[$taxonomy] = owe_content_validate_term_ids($termIds);
        foreach ($validatedTerms[$taxonomy] as $termId) {
            owe_content_resolve_term($taxonomy, $termId);
        }
    }

    $data = [
        'post_type' => $postType,
        'post_author' => get_current_user_id(),
        'post_status' => $fields['status'] ?? 'draft',
        'post_title' => $fields['title'],
        'post_content' => $fields['content'] ?? '',
        'post_excerpt' => $fields['excerpt'] ?? '',
    ];
    if (array_key_exists('slug', $fields) && $fields['slug'] !== '') {
        $unique = wp_unique_post_slug($fields['slug'], 0, (string) $data['post_status'], $postType, 0);
        if ($unique !== $fields['slug']) {
            owe_content_fail('POST_SLUG_NOT_AVAILABLE');
        }
        $data['post_name'] = $fields['slug'];
    }

    $postId = 0;
    try {
        $result = wp_insert_post(wp_slash($data), true);
        if (is_wp_error($result) || (int) $result <= 0) {
            owe_content_fail('POST_CREATE_FAILED');
        }
        $postId = (int) $result;
        foreach ($validatedTerms as $taxonomy => $termIds) {
            $assigned = wp_set_object_terms($postId, $termIds, $taxonomy, false);
            if (is_wp_error($assigned)) {
                owe_content_fail('TERM_ASSIGN_FAILED');
            }
        }

        $post = get_post($postId);
        if (!$post instanceof WP_Post || owe_content_is_elementor_post($postId)) {
            owe_content_fail('POST_WRITE_VALIDATION_FAILED');
        }
        $expectedFields = $fields;
        $expectedFields['status'] = $fields['status'] ?? 'draft';
        $expectedFields['content'] = $fields['content'] ?? '';
        $expectedFields['excerpt'] = $fields['excerpt'] ?? '';
        if (!array_key_exists('slug', $data)) {
            unset($expectedFields['slug']);
        }
        owe_content_assert_post_fields($post, $expectedFields);
        $actualTerms = owe_content_term_ids_for_post($postId, $postType);
        foreach ($validatedTerms as $taxonomy => $termIds) {
            if (($actualTerms[$taxonomy] ?? []) !== $termIds) {
                owe_content_fail('TERM_ASSIGN_VALIDATION_FAILED');
            }
        }
    } catch (Throwable $error) {
        if ($postId > 0 && get_post($postId) instanceof WP_Post) {
            $deleted = wp_delete_post($postId, true);
            if (!$deleted instanceof WP_Post) {
                owe_content_fail('ROLLBACK_FAILED');
            }
        }
        throw $error;
    }

    $post = get_post($postId);
    $snapshot = owe_content_post_snapshot($post);
    $result = [
        'post_id' => $postId,
        'post_type' => $postType,
        'status' => (string) $post->post_status,
        'content_hash' => owe_content_snapshot_hash($snapshot),
    ];
    if ($emit) {
        echo 'APPLIED operation=create_post post_id=' . $result['post_id']
            . ' status=' . $result['status']
            . ' content_hash=' . $result['content_hash'] . "\n";
    }

    return $result;
}

/**
 * @param array<string, mixed> $request
 */
function owe_content_apply_update_post(array $request): void
{
    $post = owe_content_resolve_post(isset($request['target']) ? (string) $request['target'] : '');
    if (owe_content_is_elementor_post((int) $post->ID)) {
        owe_content_fail('POST_BUILT_WITH_ELEMENTOR');
    }
    $before = owe_content_post_snapshot($post);
    $expectedHash = isset($request['expected_content_hash']) ? (string) $request['expected_content_hash'] : '';
    owe_content_validate_hash($expectedHash, 'EXPECTED_CONTENT_HASH_REQUIRED');
    if (!hash_equals($expectedHash, owe_content_snapshot_hash($before))) {
        owe_content_fail('STALE_CONTENT_HASH');
    }

    $rawFields = isset($request['fields']) && is_array($request['fields']) ? $request['fields'] : [];
    $fields = owe_content_validate_post_fields($rawFields, false);
    $type = owe_content_get_post_type((string) $post->post_type);
    if (($fields['status'] ?? '') === 'publish' && !current_user_can((string) $type->cap->publish_posts)) {
        owe_content_fail('POST_PUBLISH_PERMISSION_DENIED');
    }

    $mapping = [
        'title' => 'post_title',
        'content' => 'post_content',
        'excerpt' => 'post_excerpt',
        'status' => 'post_status',
        'slug' => 'post_name',
    ];
    $data = ['ID' => (int) $post->ID];
    foreach ($fields as $key => $value) {
        $data[$mapping[$key]] = $value;
    }
    $hasChanges = false;
    foreach ($fields as $key => $value) {
        if ((string) $post->{$mapping[$key]} !== $value) {
            $hasChanges = true;
            break;
        }
    }
    if (!$hasChanges) {
        owe_content_fail('NO_CHANGES_APPLIED');
    }
    if (isset($fields['slug']) && $fields['slug'] !== '') {
        $status = $fields['status'] ?? (string) $post->post_status;
        $unique = wp_unique_post_slug($fields['slug'], (int) $post->ID, $status, (string) $post->post_type, (int) $post->post_parent);
        if ($unique !== $fields['slug']) {
            owe_content_fail('POST_SLUG_NOT_AVAILABLE');
        }
    }

    try {
        $result = wp_update_post(wp_slash($data), true);
        if (is_wp_error($result) || (int) $result !== (int) $post->ID) {
            owe_content_fail('POST_UPDATE_FAILED');
        }
        $afterPost = get_post((int) $post->ID);
        if (!$afterPost instanceof WP_Post) {
            owe_content_fail('POST_WRITE_VALIDATION_FAILED');
        }
        owe_content_assert_post_fields($afterPost, $fields);
        $after = owe_content_post_snapshot($afterPost);
        if (hash_equals(owe_content_snapshot_hash($before), owe_content_snapshot_hash($after))) {
            owe_content_fail('NO_CHANGES_APPLIED');
        }
    } catch (Throwable $error) {
        try {
            owe_content_restore_post($before);
        } catch (Throwable $rollbackError) {
            owe_content_fail('ROLLBACK_FAILED');
        }
        throw $error;
    }

    echo 'APPLIED operation=update_post post_id=' . (int) $post->ID
        . ' content_hash=' . owe_content_snapshot_hash($after) . "\n";
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function owe_content_apply_create_term(array $request, bool $emit = true): array
{
    $taxonomy = isset($request['taxonomy']) ? sanitize_key((string) $request['taxonomy']) : '';
    $taxonomyObject = owe_content_get_taxonomy($taxonomy);
    if (!current_user_can((string) $taxonomyObject->cap->manage_terms)) {
        owe_content_fail('TERM_CREATE_PERMISSION_DENIED');
    }
    $rawFields = isset($request['fields']) && is_array($request['fields']) ? $request['fields'] : [];
    $fields = owe_content_validate_term_fields($rawFields, true);
    if (!$taxonomyObject->hierarchical && ($fields['parent'] ?? 0) !== 0) {
        owe_content_fail('TERM_PARENT_NOT_SUPPORTED');
    }
    if (($fields['parent'] ?? 0) > 0) {
        owe_content_resolve_term($taxonomy, (int) $fields['parent']);
    }

    $name = (string) $fields['name'];
    unset($fields['name']);
    $termId = 0;
    try {
        $result = wp_insert_term($name, $taxonomy, $fields);
        if (is_wp_error($result)) {
            owe_content_fail('TERM_CREATE_FAILED');
        }
        $termId = (int) $result['term_id'];
        $term = owe_content_resolve_term($taxonomy, $termId);
        $expected = array_merge(['name' => $name], $fields);
        $snapshot = owe_content_term_snapshot($term);
        foreach ($expected as $key => $value) {
            if ((string) $snapshot[$key] !== (string) $value) {
                owe_content_fail('TERM_WRITE_VALIDATION_FAILED');
            }
        }
    } catch (Throwable $error) {
        if ($termId > 0 && get_term($termId, $taxonomy) instanceof WP_Term) {
            $deleted = wp_delete_term($termId, $taxonomy);
            if (is_wp_error($deleted) || $deleted === false) {
                owe_content_fail('ROLLBACK_FAILED');
            }
        }
        throw $error;
    }

    $result = [
        'taxonomy' => $taxonomy,
        'term_id' => $termId,
        'term_hash' => owe_content_snapshot_hash($snapshot),
    ];
    if ($emit) {
        echo 'APPLIED operation=create_term taxonomy=' . $result['taxonomy']
            . ' term_id=' . $result['term_id']
            . ' term_hash=' . $result['term_hash'] . "\n";
    }

    return $result;
}

/**
 * @param array<string, mixed> $request
 */
function owe_content_apply_update_term(array $request): void
{
    $taxonomy = isset($request['taxonomy']) ? sanitize_key((string) $request['taxonomy']) : '';
    $taxonomyObject = owe_content_get_taxonomy($taxonomy);
    if (!current_user_can((string) $taxonomyObject->cap->edit_terms)) {
        owe_content_fail('TERM_EDIT_PERMISSION_DENIED');
    }
    $termId = isset($request['term_id']) ? (int) $request['term_id'] : 0;
    $term = owe_content_resolve_term($taxonomy, $termId);
    $before = owe_content_term_snapshot($term);
    $expectedHash = isset($request['expected_term_hash']) ? (string) $request['expected_term_hash'] : '';
    owe_content_validate_hash($expectedHash, 'EXPECTED_TERM_HASH_REQUIRED');
    if (!hash_equals($expectedHash, owe_content_snapshot_hash($before))) {
        owe_content_fail('STALE_TERM_HASH');
    }
    $rawFields = isset($request['fields']) && is_array($request['fields']) ? $request['fields'] : [];
    $fields = owe_content_validate_term_fields($rawFields, false);
    if (!$taxonomyObject->hierarchical && ($fields['parent'] ?? 0) !== 0) {
        owe_content_fail('TERM_PARENT_NOT_SUPPORTED');
    }
    if (($fields['parent'] ?? 0) > 0) {
        if ((int) $fields['parent'] === $termId) {
            owe_content_fail('TERM_PARENT_INVALID');
        }
        owe_content_resolve_term($taxonomy, (int) $fields['parent']);
    }
    $hasChanges = false;
    foreach ($fields as $key => $value) {
        if ((string) $before[$key] !== (string) $value) {
            $hasChanges = true;
            break;
        }
    }
    if (!$hasChanges) {
        owe_content_fail('NO_CHANGES_APPLIED');
    }

    try {
        $result = wp_update_term($termId, $taxonomy, $fields);
        if (is_wp_error($result)) {
            owe_content_fail('TERM_UPDATE_FAILED');
        }
        $updated = owe_content_resolve_term($taxonomy, $termId);
        $after = owe_content_term_snapshot($updated);
        foreach ($fields as $key => $value) {
            if ((string) $after[$key] !== (string) $value) {
                owe_content_fail('TERM_WRITE_VALIDATION_FAILED');
            }
        }
        if (hash_equals(owe_content_snapshot_hash($before), owe_content_snapshot_hash($after))) {
            owe_content_fail('NO_CHANGES_APPLIED');
        }
    } catch (Throwable $error) {
        try {
            owe_content_restore_term($before);
        } catch (Throwable $rollbackError) {
            owe_content_fail('ROLLBACK_FAILED');
        }
        throw $error;
    }

    echo 'APPLIED operation=update_term taxonomy=' . $taxonomy
        . ' term_id=' . $termId
        . ' term_hash=' . owe_content_snapshot_hash($after) . "\n";
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function owe_content_apply_assign_terms(array $request, bool $emit = true): array
{
    $post = owe_content_resolve_post(isset($request['target']) ? (string) $request['target'] : '');
    $before = owe_content_post_snapshot($post);
    $expectedHash = isset($request['expected_content_hash']) ? (string) $request['expected_content_hash'] : '';
    owe_content_validate_hash($expectedHash, 'EXPECTED_CONTENT_HASH_REQUIRED');
    if (!hash_equals($expectedHash, owe_content_snapshot_hash($before))) {
        owe_content_fail('STALE_CONTENT_HASH');
    }

    $taxonomy = isset($request['taxonomy']) ? sanitize_key((string) $request['taxonomy']) : '';
    if (!is_object_in_taxonomy((string) $post->post_type, $taxonomy)) {
        owe_content_fail('TAXONOMY_NOT_ALLOWED_FOR_POST_TYPE');
    }
    $taxonomyObject = owe_content_get_taxonomy($taxonomy);
    if (!current_user_can((string) $taxonomyObject->cap->assign_terms)) {
        owe_content_fail('TERM_ASSIGN_PERMISSION_DENIED');
    }
    $termIds = owe_content_validate_term_ids($request['term_ids'] ?? null);
    foreach ($termIds as $termId) {
        owe_content_resolve_term($taxonomy, $termId);
    }
    $mode = isset($request['mode']) ? (string) $request['mode'] : '';
    if (!in_array($mode, ['replace', 'append'], true)) {
        owe_content_fail('TERM_ASSIGN_MODE_INVALID');
    }

    $currentTerms = $before['terms'][$taxonomy] ?? [];
    $expectedTerms = $mode === 'append'
        ? array_values(array_unique(array_merge($currentTerms, $termIds)))
        : $termIds;
    sort($expectedTerms, SORT_NUMERIC);
    if ($currentTerms === $expectedTerms) {
        owe_content_fail('NO_CHANGES_APPLIED');
    }

    try {
        $result = wp_set_object_terms((int) $post->ID, $termIds, $taxonomy, $mode === 'append');
        if (is_wp_error($result)) {
            owe_content_fail('TERM_ASSIGN_FAILED');
        }
        $updatedPost = get_post((int) $post->ID);
        if (!$updatedPost instanceof WP_Post) {
            owe_content_fail('TERM_ASSIGN_VALIDATION_FAILED');
        }
        $after = owe_content_post_snapshot($updatedPost);
        if (($after['terms'][$taxonomy] ?? []) !== $expectedTerms) {
            owe_content_fail('TERM_ASSIGN_VALIDATION_FAILED');
        }
    } catch (Throwable $error) {
        $rollback = wp_set_object_terms((int) $post->ID, $currentTerms, $taxonomy, false);
        if (is_wp_error($rollback)) {
            owe_content_fail('ROLLBACK_FAILED');
        }
        throw $error;
    }

    $result = [
        'post_id' => (int) $post->ID,
        'taxonomy' => $taxonomy,
        'content_hash' => owe_content_snapshot_hash($after),
    ];
    if ($emit) {
        echo 'APPLIED operation=assign_terms post_id=' . $result['post_id']
            . ' taxonomy=' . $result['taxonomy']
            . ' content_hash=' . $result['content_hash'] . "\n";
    }

    return $result;
}

/**
 * @param array<string, mixed> $value
 * @param array<int, string> $allowed
 */
function owe_content_batch_assert_keys(array $value, array $allowed, string $code): void
{
    foreach (array_keys($value) as $key) {
        if (!is_string($key) || !in_array($key, $allowed, true)) {
            owe_content_fail($code);
        }
    }
}

/**
 * @param array<int, int|string> $selectors
 * @param array<string, array<string, mixed>> $references
 * @return array<int, int|string>
 */
function owe_content_batch_validate_selectors(array $selectors, string $taxonomy, array $references): array
{
    foreach ($selectors as $selector) {
        if (is_int($selector)) {
            owe_content_resolve_term($taxonomy, $selector);
            continue;
        }

        $key = substr($selector, 1);
        if (!isset($references[$key])
            || ($references[$key]['type'] ?? '') !== 'term'
            || ($references[$key]['taxonomy'] ?? '') !== $taxonomy
        ) {
            owe_content_fail('BATCH_TERM_REFERENCE_INVALID');
        }
    }

    return $selectors;
}

/**
 * @param array<int, int|string> $selectors
 * @param array<string, array<string, mixed>> $resultsByReference
 * @return array<int, int>
 */
function owe_content_batch_resolve_selectors(
    array $selectors,
    string $taxonomy,
    array $resultsByReference
): array {
    $termIds = [];
    foreach ($selectors as $selector) {
        if (is_int($selector)) {
            $termIds[] = $selector;
            continue;
        }

        $key = substr($selector, 1);
        if (!isset($resultsByReference[$key])
            || ($resultsByReference[$key]['type'] ?? '') !== 'term'
            || ($resultsByReference[$key]['taxonomy'] ?? '') !== $taxonomy
        ) {
            owe_content_fail('BATCH_TERM_REFERENCE_UNRESOLVED');
        }
        $termIds[] = (int) $resultsByReference[$key]['term_id'];
    }

    $termIds = array_values(array_unique($termIds));
    sort($termIds, SORT_NUMERIC);
    return $termIds;
}

/**
 * @param array<string, mixed> $request
 * @return array<int, array<string, mixed>>
 */
function owe_content_batch_preflight(array $request): array
{
    owe_content_batch_assert_keys($request, ['schema', 'operation', 'actions'], 'BATCH_REQUEST_FIELD_NOT_ALLOWED');
    $actions = $request['actions'] ?? null;
    if (!is_array($actions)
        || $actions === []
        || count($actions) > 250
        || array_keys($actions) !== range(0, count($actions) - 1)
    ) {
        owe_content_fail('BATCH_ACTIONS_INVALID');
    }

    $normalized = [];
    $references = [];
    $termSlugs = [];
    $termNames = [];
    $postSlugs = [];
    $assignmentTargets = [];

    foreach ($actions as $rawAction) {
        if (!is_array($rawAction)) {
            owe_content_fail('BATCH_ACTION_INVALID');
        }
        $key = owe_content_validate_batch_key($rawAction['key'] ?? null);
        if (isset($references[$key])) {
            owe_content_fail('BATCH_ACTION_KEY_DUPLICATED');
        }
        $type = isset($rawAction['type']) && is_string($rawAction['type']) ? $rawAction['type'] : '';

        if ($type === 'create_term') {
            owe_content_batch_assert_keys(
                $rawAction,
                ['key', 'type', 'taxonomy', 'fields'],
                'BATCH_ACTION_FIELD_NOT_ALLOWED'
            );
            $taxonomy = sanitize_key(isset($rawAction['taxonomy']) ? (string) $rawAction['taxonomy'] : '');
            $taxonomyObject = owe_content_get_taxonomy($taxonomy);
            if (!current_user_can((string) $taxonomyObject->cap->manage_terms)) {
                owe_content_fail('TERM_CREATE_PERMISSION_DENIED');
            }
            $rawFields = isset($rawAction['fields']) && is_array($rawAction['fields'])
                ? $rawAction['fields']
                : [];
            $fields = owe_content_validate_term_fields($rawFields, true);
            if (!isset($fields['slug'])) {
                owe_content_fail('BATCH_TERM_SLUG_REQUIRED');
            }
            if (!$taxonomyObject->hierarchical && ($fields['parent'] ?? 0) !== 0) {
                owe_content_fail('TERM_PARENT_NOT_SUPPORTED');
            }
            if (($fields['parent'] ?? 0) > 0) {
                owe_content_resolve_term($taxonomy, (int) $fields['parent']);
            }

            $slugFingerprint = $taxonomy . ':' . $fields['slug'];
            $nameFingerprint = $taxonomy . ':' . sanitize_title((string) $fields['name']);
            if (isset($termSlugs[$slugFingerprint]) || isset($termNames[$nameFingerprint])) {
                owe_content_fail('BATCH_TERM_DUPLICATED');
            }
            if (term_exists((string) $fields['slug'], $taxonomy)
                || term_exists((string) $fields['name'], $taxonomy)
            ) {
                owe_content_fail('BATCH_TERM_ALREADY_EXISTS');
            }
            $termSlugs[$slugFingerprint] = true;
            $termNames[$nameFingerprint] = true;
            $references[$key] = ['type' => 'term', 'taxonomy' => $taxonomy];
            $normalized[] = [
                'key' => $key,
                'type' => $type,
                'taxonomy' => $taxonomy,
                'fields' => $fields,
            ];
            continue;
        }

        if ($type === 'create_post') {
            owe_content_batch_assert_keys(
                $rawAction,
                ['key', 'type', 'post_type', 'fields', 'taxonomies'],
                'BATCH_ACTION_FIELD_NOT_ALLOWED'
            );
            $postType = sanitize_key(isset($rawAction['post_type']) ? (string) $rawAction['post_type'] : '');
            $postTypeObject = owe_content_get_post_type($postType);
            $createCapability = isset($postTypeObject->cap->create_posts)
                ? (string) $postTypeObject->cap->create_posts
                : (string) $postTypeObject->cap->edit_posts;
            if (!current_user_can($createCapability)) {
                owe_content_fail('POST_CREATE_PERMISSION_DENIED');
            }
            $rawFields = isset($rawAction['fields']) && is_array($rawAction['fields'])
                ? $rawAction['fields']
                : [];
            $fields = owe_content_validate_post_fields($rawFields, true);
            if (!isset($fields['slug'])) {
                owe_content_fail('BATCH_POST_SLUG_REQUIRED');
            }
            if (($fields['status'] ?? 'draft') === 'publish'
                && !current_user_can((string) $postTypeObject->cap->publish_posts)
            ) {
                owe_content_fail('POST_PUBLISH_PERMISSION_DENIED');
            }
            $slugFingerprint = $postType . ':' . $fields['slug'];
            if (isset($postSlugs[$slugFingerprint])) {
                owe_content_fail('BATCH_POST_DUPLICATED');
            }
            $existing = get_page_by_path((string) $fields['slug'], OBJECT, $postType);
            $status = $fields['status'] ?? 'draft';
            $uniqueSlug = wp_unique_post_slug((string) $fields['slug'], 0, $status, $postType, 0);
            if ($existing instanceof WP_Post || $uniqueSlug !== $fields['slug']) {
                owe_content_fail('BATCH_POST_ALREADY_EXISTS');
            }
            $postSlugs[$slugFingerprint] = true;

            $taxonomies = isset($rawAction['taxonomies']) ? $rawAction['taxonomies'] : [];
            if (!is_array($taxonomies)) {
                owe_content_fail('TAXONOMIES_INVALID');
            }
            $normalizedTaxonomies = [];
            foreach ($taxonomies as $taxonomy => $rawSelectors) {
                if (!is_string($taxonomy) || !is_object_in_taxonomy($postType, $taxonomy)) {
                    owe_content_fail('TAXONOMY_NOT_ALLOWED_FOR_POST_TYPE');
                }
                $taxonomyObject = owe_content_get_taxonomy($taxonomy);
                if (!current_user_can((string) $taxonomyObject->cap->assign_terms)) {
                    owe_content_fail('TERM_ASSIGN_PERMISSION_DENIED');
                }
                $selectors = owe_content_validate_batch_term_selectors($rawSelectors);
                $normalizedTaxonomies[$taxonomy] = owe_content_batch_validate_selectors(
                    $selectors,
                    $taxonomy,
                    $references
                );
            }

            $references[$key] = ['type' => 'post', 'post_type' => $postType];
            $normalized[] = [
                'key' => $key,
                'type' => $type,
                'post_type' => $postType,
                'fields' => $fields,
                'taxonomies' => $normalizedTaxonomies,
            ];
            continue;
        }

        if ($type === 'assign_terms') {
            owe_content_batch_assert_keys(
                $rawAction,
                ['key', 'type', 'target', 'expected_content_hash', 'taxonomy', 'terms', 'mode'],
                'BATCH_ACTION_FIELD_NOT_ALLOWED'
            );
            $target = isset($rawAction['target']) && is_string($rawAction['target'])
                ? trim($rawAction['target'])
                : '';
            if ($target === '') {
                owe_content_fail('POST_REFERENCE_REQUIRED');
            }
            $postType = '';
            $targetFingerprint = '';
            if (substr($target, 0, 1) === '@') {
                $targetKey = owe_content_validate_batch_key(substr($target, 1));
                if (!isset($references[$targetKey]) || ($references[$targetKey]['type'] ?? '') !== 'post') {
                    owe_content_fail('BATCH_POST_REFERENCE_INVALID');
                }
                $postType = (string) $references[$targetKey]['post_type'];
                $targetFingerprint = '@' . $targetKey;
                $target = $targetFingerprint;
            } else {
                $post = owe_content_resolve_post($target);
                $postType = (string) $post->post_type;
                $snapshot = owe_content_post_snapshot($post);
                $expectedHash = isset($rawAction['expected_content_hash'])
                    ? (string) $rawAction['expected_content_hash']
                    : '';
                owe_content_validate_hash($expectedHash, 'EXPECTED_CONTENT_HASH_REQUIRED');
                if (!hash_equals($expectedHash, owe_content_snapshot_hash($snapshot))) {
                    owe_content_fail('STALE_CONTENT_HASH');
                }
                $targetFingerprint = 'post:' . (int) $post->ID;
            }

            $taxonomy = sanitize_key(isset($rawAction['taxonomy']) ? (string) $rawAction['taxonomy'] : '');
            if (!is_object_in_taxonomy($postType, $taxonomy)) {
                owe_content_fail('TAXONOMY_NOT_ALLOWED_FOR_POST_TYPE');
            }
            $taxonomyObject = owe_content_get_taxonomy($taxonomy);
            if (!current_user_can((string) $taxonomyObject->cap->assign_terms)) {
                owe_content_fail('TERM_ASSIGN_PERMISSION_DENIED');
            }
            $selectors = owe_content_validate_batch_term_selectors($rawAction['terms'] ?? null);
            $selectors = owe_content_batch_validate_selectors($selectors, $taxonomy, $references);
            $mode = isset($rawAction['mode']) ? (string) $rawAction['mode'] : '';
            if (!in_array($mode, ['replace', 'append'], true)) {
                owe_content_fail('TERM_ASSIGN_MODE_INVALID');
            }
            $assignmentFingerprint = $targetFingerprint . ':' . $taxonomy;
            if (isset($assignmentTargets[$assignmentFingerprint])) {
                owe_content_fail('BATCH_ASSIGNMENT_DUPLICATED');
            }
            $assignmentTargets[$assignmentFingerprint] = true;
            $references[$key] = ['type' => 'assignment'];
            $normalized[] = [
                'key' => $key,
                'type' => $type,
                'target' => $target,
                'taxonomy' => $taxonomy,
                'terms' => $selectors,
                'mode' => $mode,
            ];
            continue;
        }

        owe_content_fail('BATCH_ACTION_TYPE_UNSUPPORTED');
    }

    return $normalized;
}

/**
 * @param array<int, array<string, mixed>> $assignmentSnapshots
 * @param array<int, int> $createdPostIds
 * @param array<int, array<string, mixed>> $createdTerms
 */
function owe_content_batch_rollback(
    array $assignmentSnapshots,
    array $createdPostIds,
    array $createdTerms
): void {
    for ($index = count($assignmentSnapshots) - 1; $index >= 0; $index--) {
        $snapshot = $assignmentSnapshots[$index];
        $restored = wp_set_object_terms(
            (int) $snapshot['post_id'],
            $snapshot['term_ids'],
            (string) $snapshot['taxonomy'],
            false
        );
        if (is_wp_error($restored)) {
            owe_content_fail('ROLLBACK_FAILED');
        }
    }
    for ($index = count($createdPostIds) - 1; $index >= 0; $index--) {
        $postId = $createdPostIds[$index];
        if (get_post($postId) instanceof WP_Post) {
            $deleted = wp_delete_post($postId, true);
            if (!$deleted instanceof WP_Post) {
                owe_content_fail('ROLLBACK_FAILED');
            }
        }
    }
    for ($index = count($createdTerms) - 1; $index >= 0; $index--) {
        $term = $createdTerms[$index];
        if (get_term((int) $term['term_id'], (string) $term['taxonomy']) instanceof WP_Term) {
            $deleted = wp_delete_term((int) $term['term_id'], (string) $term['taxonomy']);
            if (is_wp_error($deleted) || $deleted === false) {
                owe_content_fail('ROLLBACK_FAILED');
            }
        }
    }
}

/**
 * @param array<string, mixed> $request
 */
function owe_content_apply_batch(array $request, string $projectRoot): void
{
    $actions = owe_content_batch_preflight($request);
    $resultsByReference = [];
    $results = [];
    $createdPostIds = [];
    $createdTerms = [];
    $assignmentSnapshots = [];
    $counts = ['posts_created' => 0, 'terms_created' => 0, 'assignments' => 0];

    try {
        foreach ($actions as $action) {
            $key = (string) $action['key'];
            if ($action['type'] === 'create_term') {
                $result = owe_content_apply_create_term([
                    'taxonomy' => $action['taxonomy'],
                    'fields' => $action['fields'],
                ], false);
                $createdTerms[] = [
                    'taxonomy' => $result['taxonomy'],
                    'term_id' => $result['term_id'],
                ];
                $resultsByReference[$key] = array_merge(['type' => 'term'], $result);
                $results[] = array_merge(['key' => $key, 'type' => 'create_term'], $result);
                $counts['terms_created']++;
                continue;
            }

            if ($action['type'] === 'create_post') {
                $taxonomies = [];
                foreach ($action['taxonomies'] as $taxonomy => $selectors) {
                    $taxonomies[$taxonomy] = owe_content_batch_resolve_selectors(
                        $selectors,
                        $taxonomy,
                        $resultsByReference
                    );
                }
                $result = owe_content_apply_create_post([
                    'post_type' => $action['post_type'],
                    'fields' => $action['fields'],
                    'taxonomies' => $taxonomies,
                ], false);
                $createdPostIds[] = (int) $result['post_id'];
                $resultsByReference[$key] = array_merge(['type' => 'post'], $result);
                $results[] = array_merge(['key' => $key, 'type' => 'create_post'], $result);
                $counts['posts_created']++;
                $counts['assignments'] += count($taxonomies);
                continue;
            }

            $target = (string) $action['target'];
            $createdInBatch = false;
            if (substr($target, 0, 1) === '@') {
                $targetKey = substr($target, 1);
                if (!isset($resultsByReference[$targetKey])
                    || ($resultsByReference[$targetKey]['type'] ?? '') !== 'post'
                ) {
                    owe_content_fail('BATCH_POST_REFERENCE_UNRESOLVED');
                }
                $target = (string) $resultsByReference[$targetKey]['post_id'];
                $createdInBatch = true;
            }
            $post = owe_content_resolve_post($target);
            $before = owe_content_post_snapshot($post);
            $termIds = owe_content_batch_resolve_selectors(
                $action['terms'],
                (string) $action['taxonomy'],
                $resultsByReference
            );
            if (!$createdInBatch) {
                $assignmentSnapshots[] = [
                    'post_id' => (int) $post->ID,
                    'taxonomy' => (string) $action['taxonomy'],
                    'term_ids' => $before['terms'][$action['taxonomy']] ?? [],
                ];
            }
            $result = owe_content_apply_assign_terms([
                'target' => (string) $post->ID,
                'expected_content_hash' => owe_content_snapshot_hash($before),
                'taxonomy' => $action['taxonomy'],
                'term_ids' => $termIds,
                'mode' => $action['mode'],
            ], false);
            $resultsByReference[$key] = array_merge(['type' => 'assignment'], $result);
            $results[] = array_merge(['key' => $key, 'type' => 'assign_terms'], $result);
            $counts['assignments']++;
        }
    } catch (Throwable $error) {
        try {
            owe_content_batch_rollback($assignmentSnapshots, $createdPostIds, $createdTerms);
        } catch (Throwable $rollbackError) {
            owe_content_fail('ROLLBACK_FAILED');
        }
        throw $error;
    }

    try {
        $report = [
            'schema' => 'owe-wordpress-content-batch-report/1.0',
            'generated_at_utc' => gmdate('c'),
            'status' => 'applied',
            'counts' => array_merge(['actions' => count($actions)], $counts),
            'results' => $results,
        ];
        $reportPath = owe_content_safe_project_path(
            $projectRoot,
            '.owe/runtime/wordpress-content-batch.json',
            '.owe/runtime',
            false
        );
        owe_content_write_json($reportPath, $report);
    } catch (Throwable $error) {
        try {
            owe_content_batch_rollback($assignmentSnapshots, $createdPostIds, $createdTerms);
        } catch (Throwable $rollbackError) {
            owe_content_fail('ROLLBACK_FAILED');
        }
        throw $error;
    }

    echo 'APPLIED operation=apply_batch actions=' . count($actions)
        . ' posts_created=' . $counts['posts_created']
        . ' terms_created=' . $counts['terms_created']
        . ' assignments=' . $counts['assignments']
        . ' report=.owe/runtime/wordpress-content-batch.json' . "\n";
}

$projectRoot = isset($argv[1]) ? (string) $argv[1] : '';
$command = isset($argv[2]) ? (string) $argv[2] : '';

try {
    if ($projectRoot === '' || !is_dir($projectRoot)) {
        owe_content_fail('PROJECT_ROOT_NOT_FOUND');
    }
    $projectRoot = (string) realpath($projectRoot);
    if (!in_array($command, ['check', 'inspect', 'inspect-term', 'find-term', 'apply'], true)) {
        owe_content_fail('COMMAND_NOT_SUPPORTED');
    }
    $options = owe_content_parse_options(array_slice($argv, 3));
    owe_content_boot_wordpress($projectRoot);
    owe_content_set_local_user();

    if ($command === 'check') {
        $postTypes = [];
        foreach (get_post_types(['show_ui' => true], 'objects') as $name => $object) {
            if ($object instanceof WP_Post_Type
                && !in_array($name, ['attachment', 'revision', 'nav_menu_item'], true)
                && post_type_supports($name, 'editor')
            ) {
                $postTypes[] = $name;
            }
        }
        sort($postTypes);
        echo 'READY wordpress=' . get_bloginfo('version')
            . ' post_types=' . implode(',', $postTypes) . "\n";
        exit(0);
    }

    if ($command === 'inspect') {
        $post = owe_content_resolve_post(isset($options['post']) ? $options['post'] : '');
        $snapshot = owe_content_post_snapshot($post);
        $mode = owe_content_is_elementor_post((int) $post->ID) ? 'elementor' : 'native';
        $includeContent = isset($options['include-content']) ? $options['include-content'] : 'no';
        $includeTerms = isset($options['include-terms']) ? $options['include-terms'] : 'no';
        if (!in_array($includeContent, ['yes', 'no'], true)
            || !in_array($includeTerms, ['yes', 'no'], true)
        ) {
            owe_content_fail('INSPECT_OPTION_INVALID');
        }
        $output = [
            'schema' => 'owe-wordpress-content-inspection/1.0',
            'generated_at_utc' => gmdate('c'),
            'content_mode' => $mode,
            'content_hash' => owe_content_snapshot_hash($snapshot),
            'post' => [
                'id' => (int) $post->ID,
                'post_type' => (string) $post->post_type,
                'status' => (string) $post->post_status,
                'slug' => (string) $post->post_name,
                'title' => (string) $post->post_title,
                'excerpt' => (string) $post->post_excerpt,
                'content_included' => $mode === 'native' && $includeContent === 'yes',
                'content' => $mode === 'native' && $includeContent === 'yes'
                    ? (string) $post->post_content
                    : null,
                'content_bytes' => strlen((string) $post->post_content),
                'url' => (string) get_permalink((int) $post->ID),
                'modified_gmt' => (string) $post->post_modified_gmt,
            ],
            'terms_included' => $includeTerms === 'yes',
            'terms' => $includeTerms === 'yes' ? owe_content_rich_terms($post) : null,
        ];
        $outputOption = isset($options['output']) ? $options['output'] : '.owe/runtime/wordpress-content.json';
        if (substr(strtolower($outputOption), -5) !== '.json') {
            owe_content_fail('OUTPUT_JSON_REQUIRED');
        }
        $outputPath = owe_content_safe_project_path($projectRoot, $outputOption, '.owe/runtime', false);
        owe_content_write_json($outputPath, $output);
        echo 'INSPECTED post_id=' . (int) $post->ID
            . ' content_mode=' . $mode
            . ' content_hash=' . $output['content_hash']
            . ' output=' . str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $outputPath) . "\n";
        exit(0);
    }

    if ($command === 'inspect-term') {
        $taxonomy = isset($options['taxonomy']) ? sanitize_key($options['taxonomy']) : '';
        $termId = isset($options['term-id']) ? (int) $options['term-id'] : 0;
        $term = owe_content_resolve_term($taxonomy, $termId);
        $snapshot = owe_content_term_snapshot($term);
        $output = [
            'schema' => 'owe-wordpress-term-inspection/1.0',
            'generated_at_utc' => gmdate('c'),
            'term_hash' => owe_content_snapshot_hash($snapshot),
            'term' => $snapshot,
        ];
        $outputOption = isset($options['output']) ? $options['output'] : '.owe/runtime/wordpress-term.json';
        if (substr(strtolower($outputOption), -5) !== '.json') {
            owe_content_fail('OUTPUT_JSON_REQUIRED');
        }
        $outputPath = owe_content_safe_project_path($projectRoot, $outputOption, '.owe/runtime', false);
        owe_content_write_json($outputPath, $output);
        echo 'INSPECTED_TERM taxonomy=' . $taxonomy
            . ' term_id=' . $termId
            . ' term_hash=' . $output['term_hash']
            . ' output=' . str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $outputPath) . "\n";
        exit(0);
    }

    if ($command === 'find-term') {
        $allowedOptions = ['taxonomy', 'name', 'slug', 'output'];
        foreach (array_keys($options) as $option) {
            if (!in_array($option, $allowedOptions, true)) {
                owe_content_fail('TERM_LOOKUP_OPTION_NOT_ALLOWED');
            }
        }

        $taxonomy = isset($options['taxonomy']) ? sanitize_key($options['taxonomy']) : '';
        $hasName = array_key_exists('name', $options);
        $hasSlug = array_key_exists('slug', $options);
        if ($taxonomy === '' || $hasName === $hasSlug) {
            owe_content_fail('TERM_LOOKUP_SELECTOR_INVALID');
        }

        $selectorType = $hasName ? 'name' : 'slug';
        $search = owe_content_find_terms(
            $taxonomy,
            $selectorType,
            (string) $options[$selectorType]
        );
        $matches = $search['matches'];
        $output = [
            'schema' => 'owe-wordpress-term-search/1.0',
            'generated_at_utc' => gmdate('c'),
            'taxonomy' => $taxonomy,
            'selector' => [
                'type' => $selectorType,
                'value' => $search['selector_value'],
            ],
            'count' => count($matches),
            'matches' => $matches,
        ];
        $outputOption = isset($options['output'])
            ? $options['output']
            : '.owe/runtime/wordpress-term-search.json';
        if (substr(strtolower($outputOption), -5) !== '.json') {
            owe_content_fail('OUTPUT_JSON_REQUIRED');
        }
        $outputPath = owe_content_safe_project_path($projectRoot, $outputOption, '.owe/runtime', false);
        owe_content_write_json($outputPath, $output);

        $termIds = array_map(static function (array $match): int {
            return (int) $match['id'];
        }, $matches);
        echo 'FOUND_TERMS taxonomy=' . $taxonomy
            . ' selector=' . $selectorType
            . ' count=' . count($matches)
            . ' term_ids=' . ($termIds === [] ? 'none' : implode(',', $termIds))
            . ' output=' . str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $outputPath) . "\n";
        exit(0);
    }

    $requestOption = isset($options['request']) ? $options['request'] : '';
    if (substr(strtolower($requestOption), -5) !== '.json') {
        owe_content_fail('REQUEST_JSON_REQUIRED');
    }
    $requestsDirectory = $projectRoot . DIRECTORY_SEPARATOR . '.owe' . DIRECTORY_SEPARATOR . 'requests';
    if (!is_dir($requestsDirectory) && !mkdir($requestsDirectory, 0755, true) && !is_dir($requestsDirectory)) {
        owe_content_fail('REQUEST_DIRECTORY_FAILED');
    }
    $requestPath = owe_content_safe_project_path($projectRoot, $requestOption, '.owe/requests', true);
    $request = owe_content_read_request($requestPath);
    if (($request['schema'] ?? null) !== 'owe-wordpress-content-bridge/1.0') {
        owe_content_fail('REQUEST_SCHEMA_UNSUPPORTED');
    }
    $operation = isset($request['operation']) ? (string) $request['operation'] : '';
    switch ($operation) {
        case 'create_post':
            owe_content_apply_create_post($request);
            break;
        case 'update_post':
            owe_content_apply_update_post($request);
            break;
        case 'create_term':
            owe_content_apply_create_term($request);
            break;
        case 'update_term':
            owe_content_apply_update_term($request);
            break;
        case 'assign_terms':
            owe_content_apply_assign_terms($request);
            break;
        case 'apply_batch':
            owe_content_apply_batch($request, $projectRoot);
            break;
        default:
            owe_content_fail('UNSUPPORTED_OPERATION');
    }
} catch (Throwable $error) {
    if ($projectRoot !== '' && is_dir($projectRoot)) {
        owe_content_log_error($projectRoot, $error);
    }
    $code = $error instanceof OWE_Content_Bridge_Exception ? $error->oweCode() : 'INTERNAL_ERROR';
    fwrite(STDERR, 'BLOCKED:' . $code . "\n");
    exit(70);
}
