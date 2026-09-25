<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este inspector solo puede ejecutarse desde la terminal.\n");
    exit(64);
}

$projectRoot = $argv[1] ?? '';
$projectRoot = is_string($projectRoot) ? rtrim($projectRoot, DIRECTORY_SEPARATOR) : '';
$wpLoad = $projectRoot . DIRECTORY_SEPARATOR . 'wp-load.php';

if ($projectRoot === '' || !is_file($wpLoad)) {
    fwrite(STDERR, "No se encontró una instalación WordPress válida.\n");
    exit(66);
}

define('WP_USE_THEMES', false);
ob_start();
require_once $wpLoad;
ob_end_clean();

if (!function_exists('get_bloginfo') || !function_exists('get_option')) {
    fwrite(STDERR, "WordPress no pudo inicializarse correctamente.\n");
    exit(70);
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

function owe_md(string $value): string
{
    $value = str_replace(["\r", "\n", "|"], [' ', ' ', '\\|'], trim($value));
    return $value === '' ? 'Not available' : $value;
}

$generatedAt = function_exists('current_time')
    ? (string) current_time('Y-m-d H:i:s T')
    : gmdate('Y-m-d H:i:s T');
$siteAddress = function_exists('home_url') ? (string) home_url('/') : '';
$wordpressVersion = (string) get_bloginfo('version');
$isMultisite = function_exists('is_multisite') && is_multisite();
$theme = function_exists('wp_get_theme') ? wp_get_theme() : null;
$activePlugins = (array) get_option('active_plugins', []);
$networkPlugins = $isMultisite ? array_keys((array) get_site_option('active_sitewide_plugins', [])) : [];
$plugins = function_exists('get_plugins') ? get_plugins() : [];
$updates = function_exists('get_site_transient') ? get_site_transient('update_plugins') : false;
$updateResponses = is_object($updates) && isset($updates->response) && is_array($updates->response)
    ? $updates->response
    : [];

echo "# Project Environment\n\n";
echo "> Generated reference. Read only when the current task strictly requires technical environment data.\n\n";
echo "- Generated at: " . owe_md($generatedAt) . "\n";
echo "- Project root: " . owe_md($projectRoot) . "\n";
echo "- Site Address: " . owe_md($siteAddress) . "\n";

echo "\n## Runtime\n\n";
echo "- PHP: " . owe_md(PHP_VERSION) . "\n";
echo "- WordPress: " . owe_md($wordpressVersion) . "\n";
echo "- Multisite: " . ($isMultisite ? 'Yes' : 'No') . "\n";

echo "\n## Active theme\n\n";
if ($theme && method_exists($theme, 'get')) {
    echo "- Name: " . owe_md((string) $theme->get('Name')) . "\n";
    echo "- Version: " . owe_md((string) $theme->get('Version')) . "\n";
    echo "- Template: " . owe_md((string) $theme->get_template()) . "\n";
} else {
    echo "- Theme information: Not available\n";
}

echo "\n## Plugins\n\n";
echo "| Plugin | Status | Version | Available update |\n";
echo "|---|---|---:|---:|\n";

uasort($plugins, static function (array $a, array $b): int {
    return strcasecmp((string) ($a['Name'] ?? ''), (string) ($b['Name'] ?? ''));
});

foreach ($plugins as $pluginFile => $pluginData) {
    $status = in_array($pluginFile, $networkPlugins, true)
        ? 'Network active'
        : (in_array($pluginFile, $activePlugins, true) ? 'Active' : 'Inactive');
    $availableUpdate = isset($updateResponses[$pluginFile]->new_version)
        ? (string) $updateResponses[$pluginFile]->new_version
        : '-';
    echo '| ' . owe_md((string) ($pluginData['Name'] ?? $pluginFile))
        . ' | ' . $status
        . ' | ' . owe_md((string) ($pluginData['Version'] ?? ''))
        . ' | ' . owe_md($availableUpdate)
        . " |\n";
}

if ($plugins === []) {
    echo "| No plugins detected | - | - | - |\n";
}
