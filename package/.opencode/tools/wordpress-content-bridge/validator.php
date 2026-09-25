<?php

declare(strict_types=1);

/**
 * @param mixed $value
 */
function owe_content_require_string($value, string $code, int $maximum, bool $allowEmpty = true): string
{
    if (!is_string($value) || strlen($value) > $maximum || (!$allowEmpty && trim($value) === '')) {
        owe_content_fail($code);
    }
    return $value;
}

/**
 * @param mixed $value
 * @return array<int, int>
 */
function owe_content_validate_term_ids($value): array
{
    if (!is_array($value)) {
        owe_content_fail('TERM_IDS_INVALID');
    }
    $ids = [];
    foreach ($value as $termId) {
        if (!is_int($termId) && !(is_string($termId) && ctype_digit($termId))) {
            owe_content_fail('TERM_IDS_INVALID');
        }
        $normalized = (int) $termId;
        if ($normalized <= 0 || in_array($normalized, $ids, true)) {
            owe_content_fail('TERM_IDS_INVALID');
        }
        $ids[] = $normalized;
    }
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function owe_content_validate_hash(string $hash, string $code): void
{
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
        owe_content_fail($code);
    }
}

function owe_content_validate_batch_key($value): string
{
    if (!is_string($value) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value)) {
        owe_content_fail('BATCH_ACTION_KEY_INVALID');
    }

    return $value;
}

/**
 * @param mixed $value
 * @return array<int, int|string>
 */
function owe_content_validate_batch_term_selectors($value): array
{
    if (!is_array($value) || $value === []) {
        owe_content_fail('BATCH_TERMS_INVALID');
    }

    $selectors = [];
    foreach ($value as $selector) {
        if (is_int($selector) || (is_string($selector) && ctype_digit($selector))) {
            $termId = (int) $selector;
            if ($termId <= 0 || in_array($termId, $selectors, true)) {
                owe_content_fail('BATCH_TERMS_INVALID');
            }
            $selectors[] = $termId;
            continue;
        }

        if (!is_string($selector) || substr($selector, 0, 1) !== '@') {
            owe_content_fail('BATCH_TERMS_INVALID');
        }
        $reference = '@' . owe_content_validate_batch_key(substr($selector, 1));
        if (in_array($reference, $selectors, true)) {
            owe_content_fail('BATCH_TERMS_INVALID');
        }
        $selectors[] = $reference;
    }

    return $selectors;
}

/**
 * @param array<string, mixed> $fields
 * @return array<string, string>
 */
function owe_content_validate_post_fields(array $fields, bool $creating): array
{
    $allowed = ['title', 'content', 'excerpt', 'status', 'slug'];
    foreach (array_keys($fields) as $key) {
        if (!is_string($key) || !in_array($key, $allowed, true)) {
            owe_content_fail('POST_FIELD_NOT_ALLOWED');
        }
    }
    if ($fields === []) {
        owe_content_fail('POST_FIELDS_REQUIRED');
    }
    if ($creating && !array_key_exists('title', $fields)) {
        owe_content_fail('POST_TITLE_REQUIRED');
    }

    $validated = [];
    if (array_key_exists('title', $fields)) {
        $validated['title'] = owe_content_require_string($fields['title'], 'POST_TITLE_INVALID', 500, !$creating);
    }
    if (array_key_exists('content', $fields)) {
        $validated['content'] = owe_content_require_string($fields['content'], 'POST_CONTENT_INVALID', 2 * 1024 * 1024, true);
    }
    if (array_key_exists('excerpt', $fields)) {
        $validated['excerpt'] = owe_content_require_string($fields['excerpt'], 'POST_EXCERPT_INVALID', 200000, true);
    }
    if (array_key_exists('status', $fields)) {
        $status = owe_content_require_string($fields['status'], 'POST_STATUS_INVALID', 20, false);
        if (!in_array($status, ['draft', 'pending', 'private', 'publish'], true)) {
            owe_content_fail('POST_STATUS_NOT_ALLOWED');
        }
        $validated['status'] = $status;
    }
    if (array_key_exists('slug', $fields)) {
        $slug = owe_content_require_string($fields['slug'], 'POST_SLUG_INVALID', 200, false);
        $slug = sanitize_title($slug);
        if ($slug === '') {
            owe_content_fail('POST_SLUG_INVALID');
        }
        $validated['slug'] = $slug;
    }

    return $validated;
}

/**
 * @param array<string, mixed> $fields
 * @return array<string, mixed>
 */
function owe_content_validate_term_fields(array $fields, bool $creating): array
{
    $allowed = ['name', 'slug', 'description', 'parent'];
    foreach (array_keys($fields) as $key) {
        if (!is_string($key) || !in_array($key, $allowed, true)) {
            owe_content_fail('TERM_FIELD_NOT_ALLOWED');
        }
    }
    if ($fields === []) {
        owe_content_fail('TERM_FIELDS_REQUIRED');
    }
    if ($creating && !array_key_exists('name', $fields)) {
        owe_content_fail('TERM_NAME_REQUIRED');
    }

    $validated = [];
    if (array_key_exists('name', $fields)) {
        $validated['name'] = owe_content_require_string($fields['name'], 'TERM_NAME_INVALID', 200, false);
    }
    if (array_key_exists('slug', $fields)) {
        $slug = sanitize_title(owe_content_require_string($fields['slug'], 'TERM_SLUG_INVALID', 200, false));
        if ($slug === '') {
            owe_content_fail('TERM_SLUG_INVALID');
        }
        $validated['slug'] = $slug;
    }
    if (array_key_exists('description', $fields)) {
        $validated['description'] = owe_content_require_string($fields['description'], 'TERM_DESCRIPTION_INVALID', 200000, true);
    }
    if (array_key_exists('parent', $fields)) {
        $parent = $fields['parent'];
        if (!is_int($parent) && !(is_string($parent) && ctype_digit($parent))) {
            owe_content_fail('TERM_PARENT_INVALID');
        }
        $validated['parent'] = (int) $parent;
    }

    return $validated;
}
