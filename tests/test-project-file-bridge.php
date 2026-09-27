<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'owe-project-file-' . bin2hex(random_bytes(6));
$bridge = __DIR__ . '/../package/.opencode/tools/project-file-bridge/bridge.php';

function test_file_bridge_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function test_file_bridge_run(string $bridge, string $root, string $command): string
{
    $commandLine = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bridge) . ' '
        . escapeshellarg($root) . ' ' . escapeshellarg($command) . ' --request .owe/requests/current-php.json 2>&1';
    $output = [];
    exec($commandLine, $output, $status);
    if ($status !== 0) {
        test_file_bridge_fail($command . ' failed: ' . implode("\n", $output));
    }
    return implode("\n", $output);
}

if (!mkdir($root . '/wp-content/themes/child-theme', 0755, true)
    || !mkdir($root . '/wp-includes', 0755, true)
    || !mkdir($root . '/.owe/requests', 0755, true)
) {
    test_file_bridge_fail('temporary directories could not be created');
}
file_put_contents($root . '/wp-load.php', "<?php\n");
file_put_contents($root . '/wp-content/themes/child-theme/style.css', "/* Theme Name: Test Child */\nTemplate: parent-theme\n");
$target = $root . '/wp-content/themes/child-theme/functions.php';
$before = "<?php\nfunction owe_test_value(): string { return 'before'; }\n";
$after = "<?php\nfunction owe_test_value(): string { return 'after'; }\n";
file_put_contents($target, $before);

$directInspectCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bridge) . ' ' . escapeshellarg($root)
    . ' inspect --path ' . escapeshellarg('wp-content/themes/child-theme/functions.php') . ' 2>&1';
exec($directInspectCommand, $directInspectOutput, $directInspectStatus);
if ($directInspectStatus !== 0 || !str_contains(implode("\n", $directInspectOutput), 'INSPECTED path=wp-content/themes/child-theme/functions.php')) {
    test_file_bridge_fail('direct path inspection failed');
}

$request = static function (string $path, string $hash, string $content, bool $confirmed): string {
    return json_encode([
        'schema' => 'owe-project-file-bridge/1.0',
        'operation' => 'replace',
        'user_confirmed' => $confirmed,
        'authorization' => [
            'user_confirmed' => $confirmed,
            'barrier_exceptions' => ['project.php_file'],
            'direct_scope' => [
                'operations' => ['replace'],
                'files' => [$path],
            ],
        ],
        'files' => [[
            'path' => $path,
            'expected_hash' => $hash,
            'change_summary' => 'test',
            'content' => $content,
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
};

$hash = hash_file('sha256', $target);
file_put_contents($root . '/.owe/requests/current-php.json', json_encode([
    'schema' => 'owe-project-file-bridge/1.0',
    'operation' => 'replace',
    'user_confirmed' => false,
    'files' => [['path' => 'wp-content/themes/child-theme/functions.php']],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$pathOnlyInspection = test_file_bridge_run($bridge, $root, 'inspect');
if (!str_contains($pathOnlyInspection, 'INSPECTED path=wp-content/themes/child-theme/functions.php')) {
    test_file_bridge_fail('path-only request inspection failed');
}
file_put_contents($root . '/.owe/requests/current-php.json', $request('wp-content/themes/child-theme/functions.php', $hash, $after, true));
$inspection = test_file_bridge_run($bridge, $root, 'inspect');
if (!str_contains($inspection, 'INSPECTED path=wp-content/themes/child-theme/functions.php')) {
    test_file_bridge_fail('inspect did not report the child theme file');
}
test_file_bridge_run($bridge, $root, 'diff');
test_file_bridge_run($bridge, $root, 'validate');
$applied = test_file_bridge_run($bridge, $root, 'apply');
if (!str_contains($applied, 'APPLIED path=wp-content/themes/child-theme/functions.php')
    || file_get_contents($target) !== $after
) {
    test_file_bridge_fail('valid child theme change was not applied');
}

$cssPath = 'wp-content/themes/child-theme/assets/css/cart.css';
$cssContent = "selector .cart { display: block; }\n";
file_put_contents($root . '/.owe/requests/current-php.json', json_encode([
    'schema' => 'owe-project-file-bridge/1.1',
    'operation' => 'create',
    'user_confirmed' => true,
    'authorization' => [
        'user_confirmed' => true,
        'barrier_exceptions' => ['project.create_file'],
        'direct_scope' => ['operations' => ['create'], 'files' => [$cssPath]],
    ],
    'files' => [[
        'path' => $cssPath,
        'expected_hash' => null,
        'content' => $cssContent,
    ]],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
test_file_bridge_run($bridge, $root, 'validate');
$cssApplied = test_file_bridge_run($bridge, $root, 'apply');
if (!str_contains($cssApplied, 'APPLIED path=' . $cssPath . ' operation=create')
    || file_get_contents($root . '/' . $cssPath) !== $cssContent
) {
    test_file_bridge_fail('authorized CSS creation was not applied');
}

file_put_contents($root . '/wp-includes/blocked.php', "<?php\n");
file_put_contents($root . '/.owe/requests/current-php.json', $request('wp-includes/blocked.php', hash_file('sha256', $root . '/wp-includes/blocked.php'), $after, true));
$blockedCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bridge) . ' ' . escapeshellarg($root)
    . ' apply --request .owe/requests/current-php.json 2>&1';
exec($blockedCommand, $blockedOutput, $blockedStatus);
if ($blockedStatus === 0 || !str_contains(implode("\n", $blockedOutput), 'BLOCKED:FILE_NOT_ALLOWED')) {
    test_file_bridge_fail('core file was not blocked');
}

echo "PASS: project-file-bridge\n";

register_shutdown_function(static function () use ($root): void {
    if (!is_dir($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
});
