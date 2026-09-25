<?php

declare(strict_types=1);

final class WP_Taxonomy
{
    /** @var bool */
    public $public = true;

    /** @var bool */
    public $show_ui = true;
}

final class WP_Term
{
    /** @var int */
    public $term_id;

    /** @var string */
    public $taxonomy;

    /** @var string */
    public $name;

    /** @var string */
    public $slug;

    /** @var string */
    public $description = '';

    /** @var int */
    public $parent;

    /** @var int */
    public $count;

    public function __construct(
        int $termId,
        string $taxonomy,
        string $name,
        string $slug,
        int $parent,
        int $count
    ) {
        $this->term_id = $termId;
        $this->taxonomy = $taxonomy;
        $this->name = $name;
        $this->slug = $slug;
        $this->parent = $parent;
        $this->count = $count;
    }
}

function sanitize_key(string $value): string
{
    return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)) ?? '';
}

function sanitize_title(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function wp_json_encode($value, int $flags = 0)
{
    return json_encode($value, $flags);
}

function get_current_user_id(): int
{
    return 1;
}

function current_user_can(string $capability): bool
{
    return true;
}

function wp_set_current_user(int $userId): int
{
    return $userId;
}

function get_users(array $arguments): array
{
    return [1];
}

function get_post(int $postId = 0)
{
    return null;
}

function wp_insert_post(array $post, bool $returnError = false): int
{
    return 1;
}

function get_taxonomy(string $taxonomy)
{
    if ($taxonomy !== 'portfolio_category' && $taxonomy !== 'category') {
        return null;
    }
    return new WP_Taxonomy();
}

function is_wp_error($value): bool
{
    return false;
}

/**
 * @return array<int, WP_Term>
 */
function get_terms(array $arguments): array
{
    $taxonomy = (string) ($arguments['taxonomy'] ?? '');
    $terms = [
        new WP_Term(7, 'portfolio_category', 'Color & Balayage', 'color-balayage', 0, 3),
        new WP_Term(8, 'portfolio_category', 'Hair', 'hair', 0, 5),
        new WP_Term(9, 'portfolio_category', 'Hair', 'hair-special', 4, 1),
    ];
    $terms = array_values(array_filter($terms, static function (WP_Term $term) use ($arguments, $taxonomy): bool {
        if ($term->taxonomy !== $taxonomy) {
            return false;
        }
        if (isset($arguments['name']) && $term->name !== (string) $arguments['name']) {
            return false;
        }
        if (isset($arguments['slug']) && $term->slug !== (string) $arguments['slug']) {
            return false;
        }
        return true;
    }));
    usort($terms, static function (WP_Term $left, WP_Term $right): int {
        return $left->term_id <=> $right->term_id;
    });

    return $terms;
}
