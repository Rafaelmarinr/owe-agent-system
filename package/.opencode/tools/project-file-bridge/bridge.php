<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "BLOCKED:CLI_REQUIRED\n");
    exit(64);
}

require_once __DIR__ . '/checks.php';

function owe_file_read_request(string $path): array
{
    if (!is_file($path) || filesize($path) === false || filesize($path) > 5 * 1024 * 1024) {
        owe_file_fail('REQUEST_INVALID');
    }
    $raw = file_get_contents($path);
    $request = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($request) || json_last_error() !== JSON_ERROR_NONE) {
        owe_file_fail('REQUEST_JSON_INVALID');
    }
    return $request;
}

function owe_file_write_atomic(string $path, string $content): void
{
    $temporary = $path . '.owe-tmp-' . bin2hex(random_bytes(8));
    if (file_put_contents($temporary, $content, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        owe_file_fail('FILE_WRITE_FAILED');
    }
}

function owe_file_log(string $projectRoot, string $message): void
{
    $directory = $projectRoot . DIRECTORY_SEPARATOR . '.owe' . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($directory)) {
        @mkdir($directory, 0755, true);
    }
    @file_put_contents(
        $directory . DIRECTORY_SEPARATOR . 'project-file-bridge.log',
        gmdate('c') . "\t" . str_replace(["\r", "\n"], ' ', $message) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

$projectRoot = isset($argv[1]) ? realpath((string) $argv[1]) : false;
$command = isset($argv[2]) ? (string) $argv[2] : '';

try {
    if ($projectRoot === false || !is_dir($projectRoot)) {
        owe_file_fail('PROJECT_ROOT_NOT_FOUND');
    }
    if (!in_array($command, ['inspect', 'diff', 'validate', 'apply'], true)) {
        owe_file_fail('COMMAND_NOT_SUPPORTED');
    }

    $options = [];
    for ($index = 3; $index < count($argv); $index++) {
        if (!str_starts_with($argv[$index], '--') || !isset($argv[$index + 1])) {
            owe_file_fail('INVALID_ARGUMENT');
        }
        $options[substr($argv[$index], 2)] = $argv[++$index];
    }

    if ($command === 'inspect' && isset($options['path'])) {
        $policy = owe_file_load_policy($projectRoot);
        $relativePath = owe_file_normalize_relative((string) $options['path']);
        $candidate = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($candidate)) {
            owe_file_resolve_target($projectRoot, $relativePath, $policy, false);
            echo 'NOT_FOUND path=' . $relativePath . "\n";
            exit(0);
        }
        $path = owe_file_resolve_target($projectRoot, $relativePath, $policy, true);
        echo 'INSPECTED path=' . $relativePath . ' hash=' . owe_file_hash($path)
            . ' bytes=' . filesize($path) . "\n";
        exit(0);
    }

    $requestOption = (string) ($options['request'] ?? '');
    if ($requestOption === '' || str_contains($requestOption, '..') || $requestOption[0] === '/') {
        owe_file_fail('UNSAFE_REQUEST_PATH');
    }
    $requestPath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $requestOption);
    if (!owe_file_is_within($requestPath, $projectRoot . DIRECTORY_SEPARATOR . '.owe' . DIRECTORY_SEPARATOR . 'requests')) {
        owe_file_fail('UNSAFE_REQUEST_PATH');
    }
    $request = owe_file_read_request($requestPath);
    if (!in_array(($request['schema'] ?? null), ['owe-project-file-bridge/1.0', 'owe-project-file-bridge/1.1'], true)) {
        owe_file_fail('REQUEST_SCHEMA_UNSUPPORTED');
    }
    if (!in_array(($request['operation'] ?? null), ['replace', 'create', 'upsert'], true)) {
        owe_file_fail('OPERATION_UNSUPPORTED');
    }
    if ($command === 'apply' && ($request['user_confirmed'] ?? null) !== true) {
        owe_file_fail('USER_CONFIRMATION_REQUIRED');
    }
    if ($command === 'apply') {
        $authorization = $request['authorization'] ?? null;
        $exceptions = is_array($authorization) ? ($authorization['barrier_exceptions'] ?? null) : null;
        if (!is_array($authorization)
            || ($authorization['user_confirmed'] ?? null) !== true
            || !is_array($exceptions)
            || !in_array('project.php_file', $exceptions, true)
        ) {
            owe_file_fail('AUTHORIZATION_EXCEPTION_REQUIRED:project.php_file');
        }
        $scope = $authorization['direct_scope'] ?? null;
        if (!is_array($scope)
            || !is_array($scope['operations'] ?? null)
            || !is_array($scope['files'] ?? null)
            || array_filter($scope['files'], 'is_string') !== $scope['files']
        ) {
            owe_file_fail('AUTHORIZATION_SCOPE_REQUIRED');
        }
        $authorizedFiles = array_values(array_map(
            static fn (string $path): string => str_replace('\\', '/', ltrim($path, './')),
            $scope['files']
        ));
        foreach ($request['files'] ?? [] as $file) {
            if (is_array($file) && is_string($file['path'] ?? null)
                && !in_array(str_replace('\\', '/', ltrim($file['path'], './')), $authorizedFiles, true)
            ) {
                owe_file_fail('AUTHORIZATION_FILE_OUT_OF_SCOPE');
            }
        }
    }
    $files = $request['files'] ?? null;
    if (!is_array($files) || $files === [] || count($files) > 20) {
        owe_file_fail('FILES_INVALID');
    }
    $policy = owe_file_load_policy($projectRoot);
    $prepared = [];
    $seenPaths = [];
    foreach ($files as $file) {
        if (!is_array($file) || !is_string($file['path'] ?? null)) {
            owe_file_fail('FILE_ENTRY_INVALID');
        }
        $relativePath = owe_file_normalize_relative($file['path']);
        $fileOperation = $request['operation'] === 'upsert'
            ? ($file['operation'] ?? null)
            : $request['operation'];
        if (!in_array($fileOperation, ['replace', 'create'], true)) {
            owe_file_fail('FILE_OPERATION_INVALID');
        }
        if ($command === 'apply') {
            $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
            $requiredException = $fileOperation === 'create'
                ? 'project.create_file'
                : ($extension === 'css' ? 'project.css_file' : 'project.php_file');
            if (!in_array($requiredException, $exceptions, true)
                || !in_array($fileOperation, $scope['operations'], true)
            ) {
                owe_file_fail('AUTHORIZATION_EXCEPTION_REQUIRED:' . $requiredException);
            }
        }
        if (isset($seenPaths[$relativePath])) {
            owe_file_fail('DUPLICATE_FILE');
        }
        $seenPaths[$relativePath] = true;
        $exists = is_file($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if ($fileOperation === 'replace' && !$exists) {
            owe_file_fail('LOCAL_FILE_NOT_FOUND');
        }
        if ($fileOperation === 'create' && $exists) {
            owe_file_fail('FILE_ALREADY_EXISTS');
        }
        $path = owe_file_resolve_target($projectRoot, $relativePath, $policy, $exists);
        $before = $exists ? file_get_contents($path) : null;
        $beforeHash = $exists ? owe_file_hash($path) : null;
        if ($command === 'inspect') {
            $prepared[] = ['relative' => $relativePath, 'path' => $path, 'before' => $before, 'operation' => $fileOperation];
            continue;
        }
        if (!is_string($file['content'] ?? null)) {
            owe_file_fail('FILE_ENTRY_INVALID');
        }
        if ($fileOperation === 'replace') {
            if (!is_string($file['expected_hash'] ?? null) || !hash_equals((string) $beforeHash, $file['expected_hash'])) {
                owe_file_fail('STALE_FILE_HASH:' . $relativePath);
            }
        } elseif (($file['expected_hash'] ?? null) !== null) {
            owe_file_fail('CREATE_HASH_MUST_BE_NULL');
        }
        $content = (string) $file['content'];
        if (strlen($content) > 1024 * 1024) {
            owe_file_fail('FILE_SIZE_INVALID');
        }
        $prepared[] = [
            'relative' => $relativePath,
            'path' => $path,
            'before' => $before,
            'content' => $content,
            'operation' => $fileOperation,
        ];
    }

    if ($command === 'inspect') {
        foreach ($prepared as $file) {
            if ($file['before'] === null) {
                echo 'NOT_FOUND path=' . $file['relative'] . "\n";
                continue;
            }
            echo 'INSPECTED path=' . $file['relative'] . ' hash=' . hash('sha256', $file['before']) . "\n";
        }
        exit(0);
    }

    if ($command === 'diff') {
        foreach ($prepared as $file) {
            echo 'DIFF path=' . $file['relative'] . ' before=' . hash('sha256', (string) $file['before'])
                . ' after=' . hash('sha256', $file['content']) . ' bytes_before=' . strlen((string) $file['before'])
                . ' bytes_after=' . strlen($file['content']) . "\n";
        }
        exit(0);
    }

    if ($command === 'validate') {
        foreach ($prepared as $file) {
            owe_file_validate_content($file['path'], $file['content']);
        }
        echo 'VALIDATED files=' . count($prepared) . "\n";
        exit(0);
    }

    foreach ($prepared as $file) {
        owe_file_validate_content($file['path'], $file['content']);
    }

    $written = [];
    try {
        foreach ($prepared as $file) {
            $directory = dirname($file['path']);
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                owe_file_fail('LOCAL_OUTPUT_DIRECTORY_FAILED');
            }
            $temporary = tempnam(dirname($file['path']), '.owe-php-');
            if ($temporary === false || file_put_contents($temporary, $file['content'], LOCK_EX) === false) {
                owe_file_fail('FILE_WRITE_FAILED');
            }
            if (!rename($temporary, $file['path'])) {
                @unlink($temporary);
                owe_file_fail('FILE_WRITE_FAILED');
            }
            $written[] = $file;
        }

        foreach ($prepared as $file) {
            $afterHash = owe_file_hash($file['path']);
            if (!hash_equals($afterHash, hash('sha256', $file['content']))) {
                owe_file_fail('POST_WRITE_VALIDATION_FAILED');
            }
        }
    } catch (Throwable $writeError) {
        foreach (array_reverse($written) as $file) {
            if ($file['operation'] === 'create') {
                if (is_file($file['path']) && !unlink($file['path'])) {
                    owe_file_fail('ROLLBACK_FAILED');
                }
                continue;
            }
            $temporary = tempnam(dirname($file['path']), '.owe-rollback-');
            if ($temporary === false || file_put_contents($temporary, (string) $file['before'], LOCK_EX) === false
                || !rename($temporary, $file['path'])
            ) {
                @unlink($temporary);
                owe_file_fail('ROLLBACK_FAILED');
            }
        }
        throw $writeError;
    }

    foreach ($prepared as $file) {
        $afterHash = owe_file_hash($file['path']);
        echo 'APPLIED path=' . $file['relative'] . ' operation=' . $file['operation'] . ' hash=' . $afterHash . "\n";
    }
    exit(0);
} catch (Throwable $error) {
    if (is_string($projectRoot) && $projectRoot !== '') {
        owe_file_log($projectRoot, $error->getMessage());
    }
    fwrite(STDERR, 'BLOCKED:' . $error->getMessage() . "\n");
    exit(70);
}
