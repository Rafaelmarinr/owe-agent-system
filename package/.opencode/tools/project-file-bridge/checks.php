<?php

declare(strict_types=1);

/** @param array<string, mixed> $context */
function owe_file_fail(string $code, string $message = ''): never
{
    throw new RuntimeException($code . ($message === '' ? '' : ':' . $message));
}

function owe_file_normalize_relative(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || $path[0] === '/' || preg_match('/^[A-Za-z]:\//', $path)) {
        owe_file_fail('UNSAFE_LOCAL_PATH');
    }
    $parts = explode('/', $path);
    if (in_array('..', $parts, true) || in_array('', $parts, true)) {
        owe_file_fail('UNSAFE_LOCAL_PATH');
    }
    return str_starts_with($path, './') ? substr($path, 2) : $path;
}

function owe_file_is_within(string $path, string $root): bool
{
    $path = realpath($path);
    $root = realpath($root);
    return $path !== false && $root !== false
        && ($path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR));
}

/** @return array<string, mixed> */
function owe_file_load_policy(string $projectRoot): array
{
    $path = $projectRoot . DIRECTORY_SEPARATOR . '.owe' . DIRECTORY_SEPARATOR . 'project-file-policy.json';
    if (!is_file($path)) {
        return ['allowed_plugin_roots' => []];
    }
    $raw = file_get_contents($path);
    $policy = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($policy) || json_last_error() !== JSON_ERROR_NONE) {
        owe_file_fail('POLICY_INVALID');
    }
    $roots = $policy['allowed_plugin_roots'] ?? [];
    if (!is_array($roots) || array_filter($roots, 'is_string') !== $roots) {
        owe_file_fail('POLICY_INVALID');
    }
    return ['allowed_plugin_roots' => array_values($roots)];
}

function owe_file_theme_child_root(string $projectRoot, string $relativePath): ?string
{
    if (!preg_match('#^wp-content/themes/([^/]+)/.+$#', $relativePath, $matches)) {
        return null;
    }
    $themeRoot = $projectRoot . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . $matches[1];
    $style = $themeRoot . DIRECTORY_SEPARATOR . 'style.css';
    if (!is_file($style)) {
        return null;
    }
    $header = file_get_contents($style);
    return is_string($header) && preg_match('/^\s*Template\s*:/mi', $header) === 1 ? $themeRoot : null;
}

function owe_file_allowed(string $projectRoot, string $relativePath, array $policy): bool
{
    if (!in_array(strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)), ['php', 'css'], true)) {
        return false;
    }
    if (preg_match('#^(wp-admin|wp-includes|vendor|wp-content/(uploads|cache|upgrade))(/|$)#', $relativePath)) {
        return false;
    }
    if (in_array(basename($relativePath), ['wp-config.php', 'php.ini'], true)) {
        return false;
    }
    if (owe_file_theme_child_root($projectRoot, $relativePath) !== null) {
        return true;
    }
    foreach ($policy['allowed_plugin_roots'] as $root) {
        $root = trim(str_replace('\\', '/', (string) $root), '/');
        if ($root !== '' && ($relativePath === $root || str_starts_with($relativePath, $root . '/'))
            && preg_match('#^wp-content/(plugins|mu-plugins)/#', $root)
        ) {
            return true;
        }
    }
    return false;
}

function owe_file_allowed_root(string $projectRoot, string $relativePath, array $policy): ?string
{
    $themeRoot = owe_file_theme_child_root($projectRoot, $relativePath);
    if ($themeRoot !== null) {
        return $themeRoot;
    }
    foreach ($policy['allowed_plugin_roots'] as $root) {
        $root = trim(str_replace('\\', '/', (string) $root), '/');
        if ($root !== '' && ($relativePath === $root || str_starts_with($relativePath, $root . '/'))
            && preg_match('#^wp-content/(plugins|mu-plugins)/#', $root)
        ) {
            return $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $root);
        }
    }
    return null;
}

function owe_file_resolve_target(string $projectRoot, string $relativePath, array $policy, bool $mustExist): string
{
    $relativePath = owe_file_normalize_relative($relativePath);
    if (!owe_file_allowed($projectRoot, $relativePath, $policy)) {
        owe_file_fail('FILE_NOT_ALLOWED');
    }
    $candidate = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $directory = dirname($candidate);
    if ($mustExist) {
        $resolved = realpath($candidate);
        if ($resolved === false || !owe_file_is_within($resolved, $projectRoot)) {
            owe_file_fail('LOCAL_FILE_NOT_FOUND');
        }
        if (is_link($candidate)) {
            owe_file_fail('SYMLINK_NOT_ALLOWED');
        }
        return $resolved;
    }
    $allowedRoot = owe_file_allowed_root($projectRoot, $relativePath, $policy);
    if ($allowedRoot === null) {
        owe_file_fail('FILE_NOT_ALLOWED');
    }
    $existingDirectory = $directory;
    while (!is_dir($existingDirectory) && dirname($existingDirectory) !== $existingDirectory) {
        $existingDirectory = dirname($existingDirectory);
    }
    if (!is_dir($existingDirectory)
        || !owe_file_is_within($existingDirectory, $projectRoot)
        || !owe_file_is_within($existingDirectory, $allowedRoot)
    ) {
        owe_file_fail('LOCAL_OUTPUT_DIRECTORY_INVALID');
    }
    if (file_exists($candidate) && is_link($candidate)) {
        owe_file_fail('SYMLINK_NOT_ALLOWED');
    }
    return $candidate;
}

function owe_file_hash(string $path): string
{
    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        owe_file_fail('FILE_HASH_FAILED');
    }
    return $hash;
}

function owe_file_validate_php(string $path): void
{
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1';
    exec($command, $output, $status);
    if ($status !== 0) {
        owe_file_fail('PHP_LINT_FAILED', trim(implode("\n", $output)));
    }
}

function owe_file_validate_css(string $path): void
{
    $content = file_get_contents($path);
    if (!is_string($content) || strlen($content) > 1024 * 1024
        || str_contains($content, "\0")
        || preg_match('/<\/?(?:script|style|iframe|object)|<\?php|javascript\s*:/i', $content)
    ) {
        owe_file_fail('UNSAFE_CSS');
    }
}

function owe_file_validate_content(string $path, string $content): void
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $temporary = tempnam(sys_get_temp_dir(), 'owe-file-');
    if ($temporary === false || file_put_contents($temporary, $content) === false) {
        owe_file_fail('VALIDATION_TEMPORARY_FILE_FAILED');
    }
    try {
        if ($extension === 'php') {
            owe_file_validate_php($temporary);
        } elseif ($extension === 'css') {
            owe_file_validate_css($temporary);
        } else {
            owe_file_fail('FILE_TYPE_NOT_ALLOWED');
        }
    } finally {
        @unlink($temporary);
    }
}
