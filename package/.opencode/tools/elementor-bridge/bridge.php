<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "BLOCKED:CLI_REQUIRED\n");
    exit(64);
}

require_once __DIR__ . '/checks.php';
require_once __DIR__ . '/validator.php';
require_once __DIR__ . '/reader.php';
require_once __DIR__ . '/writer.php';
require_once __DIR__ . '/templates.php';

/**
 * @param array<int, string> $arguments
 * @return array<string, mixed>
 */
function owe_bridge_parse_options(array $arguments): array
{
    $options = [];
    for ($index = 0, $count = count($arguments); $index < $count; $index++) {
        $argument = $arguments[$index];
        if (substr($argument, 0, 2) !== '--') {
            owe_bridge_fail('INVALID_ARGUMENT');
        }

        $raw = substr($argument, 2);
        $equals = strpos($raw, '=');
        if ($equals !== false) {
            $key = substr($raw, 0, $equals);
            $options[$key] = substr($raw, $equals + 1);
            continue;
        }

        if ($raw === 'requires-pro') {
            $options[$raw] = true;
            continue;
        }

        if (!isset($arguments[$index + 1]) || substr($arguments[$index + 1], 0, 2) === '--') {
            owe_bridge_fail('INVALID_ARGUMENT');
        }
        $options[$raw] = $arguments[++$index];
    }

    return $options;
}

function owe_bridge_safe_project_path(
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
        owe_bridge_fail('UNSAFE_LOCAL_PATH');
    }

    $normalized = substr($relativePath, 0, 2) === './' ? substr($relativePath, 2) : $relativePath;
    if ($normalized !== $allowedDirectory && strpos($normalized, $allowedDirectory . '/') !== 0) {
        owe_bridge_fail('UNSAFE_LOCAL_PATH');
    }

    $candidate = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if ($mustExist) {
        $resolved = realpath($candidate);
        $allowed = realpath(rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $allowedDirectory);
        $root = realpath($projectRoot);
        if ($resolved === false
            || $allowed === false
            || $root === false
            || strpos($allowed, $root . DIRECTORY_SEPARATOR) !== 0
            || strpos($resolved, $allowed . DIRECTORY_SEPARATOR) !== 0
        ) {
            owe_bridge_fail('LOCAL_FILE_NOT_FOUND');
        }
        return $resolved;
    }

    $directory = dirname($candidate);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        owe_bridge_fail('LOCAL_OUTPUT_DIRECTORY_FAILED');
    }
    $resolvedDirectory = realpath($directory);
    $allowed = realpath(rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $allowedDirectory);
    $root = realpath($projectRoot);
    if ($resolvedDirectory === false
        || $allowed === false
        || $root === false
        || strpos($allowed, $root . DIRECTORY_SEPARATOR) !== 0
        || strpos($resolvedDirectory, $allowed) !== 0
    ) {
        owe_bridge_fail('UNSAFE_LOCAL_PATH');
    }

    return $candidate;
}

/**
 * @param array<string, mixed> $data
 */
function owe_bridge_write_json(string $path, array $data): void
{
    $encoded = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        owe_bridge_fail('JSON_ENCODE_FAILED');
    }

    $temporary = $path . '.tmp';
    if (file_put_contents($temporary, $encoded . "\n", LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        owe_bridge_fail('LOCAL_OUTPUT_WRITE_FAILED');
    }
}

/**
 * @return array<string, mixed>
 */
function owe_bridge_read_request(string $path): array
{
    $size = filesize($path);
    if ($size === false || $size > 5 * 1024 * 1024) {
        owe_bridge_fail('REQUEST_SIZE_INVALID');
    }
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        owe_bridge_fail('REQUEST_READ_FAILED');
    }

    $request = json_decode($raw, true);
    if (!is_array($request) || json_last_error() !== JSON_ERROR_NONE) {
        owe_bridge_fail('REQUEST_JSON_INVALID');
    }
    return $request;
}

function owe_bridge_log_error(string $projectRoot, Throwable $error): void
{
    $directory = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.owe' . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($directory)) {
        @mkdir($directory, 0755, true);
    }
    $code = $error instanceof OWE_Bridge_Exception ? $error->oweCode() : 'INTERNAL_ERROR';
    $line = gmdate('c') . "\t" . $code . "\t" . get_class($error) . "\t" . str_replace(["\r", "\n"], ' ', $error->getMessage()) . "\n";
    @file_put_contents($directory . DIRECTORY_SEPARATOR . 'elementor-bridge.log', $line, FILE_APPEND | LOCK_EX);
}

$projectRoot = isset($argv[1]) ? (string) $argv[1] : '';
$command = isset($argv[2]) ? (string) $argv[2] : '';

try {
    if ($projectRoot === '' || !is_dir($projectRoot)) {
        owe_bridge_fail('PROJECT_ROOT_NOT_FOUND');
    }
    $projectRoot = (string) realpath($projectRoot);
    if (!in_array($command, [
        'check', 'inspect', 'inspect-content', 'export-section',
        'find-template', 'inspect-template', 'inspect-attributes', 'apply',
    ], true)) {
        owe_bridge_fail('COMMAND_NOT_SUPPORTED');
    }

    $options = owe_bridge_parse_options(array_slice($argv, 3));
    owe_bridge_boot_wordpress($projectRoot);
    owe_bridge_set_local_user();

    if ($command === 'find-template') {
        owe_bridge_check_elementor(false);
        $hasSlug = isset($options['slug']) && is_string($options['slug']) && trim($options['slug']) !== '';
        $hasName = isset($options['name']) && is_string($options['name']) && trim($options['name']) !== '';
        if ($hasSlug === $hasName) {
            owe_bridge_fail('TEMPLATE_SELECTOR_REQUIRED');
        }
        $selector = $hasSlug ? 'slug' : 'name';
        $value = (string) $options[$selector];
        $matches = owe_bridge_find_templates($selector, $value);
        $outputOption = isset($options['output'])
            ? (string) $options['output']
            : '.owe/runtime/elementor-template-search.json';
        if (substr(strtolower($outputOption), -5) !== '.json') {
            owe_bridge_fail('OUTPUT_JSON_REQUIRED');
        }
        $outputPath = owe_bridge_safe_project_path($projectRoot, $outputOption, '.owe/runtime', false);
        owe_bridge_write_json($outputPath, [
            'schema' => 'owe-elementor-template-search/1.0',
            'selector' => $selector,
            'value' => trim($value),
            'count' => count($matches),
            'matches' => $matches,
        ]);
        $ids = array_map(static function (array $match): string {
            return (string) $match['id'];
        }, $matches);
        echo 'TEMPLATES_FOUND selector=' . $selector
            . ' count=' . count($matches)
            . ' template_ids=' . ($ids === [] ? 'none' : implode(',', $ids))
            . ' output=' . $outputOption . "\n";
        exit(0);
    }

    if ($command === 'inspect-template') {
        owe_bridge_check_elementor(false);
        $templateId = isset($options['template-id']) && ctype_digit((string) $options['template-id'])
            ? (int) $options['template-id']
            : 0;
        $template = owe_bridge_resolve_template($templateId);
        $elements = owe_bridge_template_source_elements($templateId);
        $document = owe_bridge_get_document($templateId, true);
        $settings = owe_bridge_document_page_settings($document);
        $outputOption = isset($options['output'])
            ? (string) $options['output']
            : '.owe/runtime/elementor-template.json';
        if (substr(strtolower($outputOption), -5) !== '.json') {
            owe_bridge_fail('OUTPUT_JSON_REQUIRED');
        }
        $outputPath = owe_bridge_safe_project_path($projectRoot, $outputOption, '.owe/runtime', false);
        owe_bridge_write_json($outputPath, [
            'schema' => 'owe-elementor-template-inspection/1.0',
            'template' => owe_bridge_template_summary($template),
            'template_hash' => owe_bridge_elements_hash($elements),
            'root_elements' => count($elements),
            'requires_pro' => owe_bridge_template_requires_pro($elements),
            'unavailable_widgets' => owe_bridge_unavailable_template_widgets($elements),
            'page_settings_available' => $settings !== [],
            'page_setting_keys' => array_values(array_keys($settings)),
            'structure' => owe_bridge_structure_summary($elements),
        ]);
        echo 'TEMPLATE_INSPECTED template_id=' . $templateId
            . ' type=' . get_post_meta($templateId, '_elementor_template_type', true)
            . ' roots=' . count($elements)
            . ' template_hash=' . owe_bridge_elements_hash($elements)
            . ' output=' . $outputOption . "\n";
        exit(0);
    }

    if ($command === 'apply') {
        $requestOption = isset($options['request']) ? (string) $options['request'] : '';
        if (substr(strtolower($requestOption), -5) !== '.json') {
            owe_bridge_fail('REQUEST_JSON_REQUIRED');
        }
        $requestsDirectory = $projectRoot . DIRECTORY_SEPARATOR . '.owe' . DIRECTORY_SEPARATOR . 'requests';
        if (!is_dir($requestsDirectory) && !mkdir($requestsDirectory, 0755, true) && !is_dir($requestsDirectory)) {
            owe_bridge_fail('REQUEST_DIRECTORY_FAILED');
        }
        $requestPath = owe_bridge_safe_project_path($projectRoot, $requestOption, '.owe/requests', true);
        $request = owe_bridge_read_request($requestPath);

        if (($request['schema'] ?? null) !== 'owe-elementor-bridge/1.0') {
            owe_bridge_fail('REQUEST_SCHEMA_UNSUPPORTED');
        }
        $pageReference = isset($request['page']) ? (string) $request['page'] : '';
        $operation = isset($request['operation']) ? (string) $request['operation'] : '';
        $device = isset($request['device']) ? (string) $request['device'] : '';
        $targetId = isset($request['target_section_id']) ? (string) $request['target_section_id'] : '';
        $expectedHash = isset($request['expected_page_hash']) ? (string) $request['expected_page_hash'] : '';
        $requiresPro = ($request['requires_pro'] ?? false) === true;

        if ($operation !== 'enable_elementor_editor' && !preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
            owe_bridge_fail('EXPECTED_PAGE_HASH_REQUIRED');
        }
        if (!in_array($operation, [
            'append_section',
            'insert_section_before',
            'insert_section_after',
            'replace_section',
            'patch_responsive',
            'update_widget_content',
            'insert_template',
            'enable_elementor_editor',
            'update_page_attributes',
        ], true)) {
            owe_bridge_fail('UNSUPPORTED_OPERATION');
        }

        if ($operation === 'enable_elementor_editor') {
            $allowedRequestFields = [
                'schema', 'page', 'device', 'operation', 'expected_source_hash',
                'native_content_policy', 'user_confirmed',
            ];
            if (array_diff(array_keys($request), $allowedRequestFields) !== []) {
                owe_bridge_fail('ACTIVATION_REQUEST_FIELD_NOT_ALLOWED');
            }
            if ($device !== 'desktop') {
                owe_bridge_fail('DESKTOP_OPERATION_REQUIRED');
            }
            if (($request['native_content_policy'] ?? null) !== 'require_empty') {
                owe_bridge_fail('NATIVE_CONTENT_POLICY_REQUIRED');
            }
            if (($request['user_confirmed'] ?? null) !== true) {
                owe_bridge_fail('USER_CONFIRMATION_REQUIRED');
            }

            owe_bridge_check_elementor(false);
            $post = owe_bridge_resolve_post($pageReference);
            $document = owe_bridge_get_document((int) $post->ID, false);
            if (!is_callable([$document, 'set_is_built_with_elementor'])) {
                owe_bridge_fail('ELEMENTOR_ACTIVATION_UNAVAILABLE');
            }
            $issue = owe_bridge_elementor_activation_issue($post, $document);
            if ($issue !== '') {
                owe_bridge_fail($issue);
            }

            $expectedSourceHash = isset($request['expected_source_hash'])
                ? (string) $request['expected_source_hash']
                : '';
            if (!preg_match('/^[a-f0-9]{64}$/', $expectedSourceHash)
                || !hash_equals(owe_bridge_elementor_activation_hash($post), $expectedSourceHash)
            ) {
                owe_bridge_fail('STALE_SOURCE_HASH');
            }

            $freshPost = get_post((int) $post->ID);
            if (!$freshPost instanceof WP_Post
                || !hash_equals(owe_bridge_elementor_activation_hash($freshPost), $expectedSourceHash)
            ) {
                owe_bridge_fail('STALE_SOURCE_HASH');
            }
            $freshDocument = owe_bridge_get_document((int) $post->ID, false);
            $freshIssue = owe_bridge_elementor_activation_issue($freshPost, $freshDocument);
            if ($freshIssue !== '') {
                owe_bridge_fail($freshIssue);
            }
            if (!is_callable([$freshDocument, 'get_main_id'])
                || (int) $freshDocument->get_main_id() !== (int) $post->ID
            ) {
                owe_bridge_fail('ELEMENTOR_DOCUMENT_ID_MISMATCH');
            }

            $beforeSnapshot = owe_bridge_elementor_activation_snapshot($freshPost);
            $expectedActivatedSnapshot = $beforeSnapshot;
            $expectedActivatedSnapshot['elementor_meta']['_elementor_edit_mode'] = ['builder'];
            ksort($expectedActivatedSnapshot['elementor_meta']);
            $writeStarted = false;
            try {
                $writeStarted = true;
                $freshDocument->set_is_built_with_elementor(true);
                $persistedDocument = owe_bridge_get_document((int) $post->ID, false);
                $persistedPost = get_post((int) $post->ID);
                if (!$persistedDocument->is_built_with_elementor()
                    || !$persistedPost instanceof WP_Post
                    || owe_bridge_elementor_activation_snapshot($persistedPost) !== $expectedActivatedSnapshot
                ) {
                    owe_bridge_fail('ELEMENTOR_ACTIVATION_FAILED');
                }
            } catch (Throwable $activationError) {
                if ($writeStarted) {
                    try {
                        $rollbackDocument = owe_bridge_get_document((int) $post->ID, false);
                        $rollbackDocument->set_is_built_with_elementor(false);
                        $rolledBackPost = get_post((int) $post->ID);
                        if (!$rolledBackPost instanceof WP_Post
                            || !hash_equals(
                                owe_bridge_elementor_activation_hash($rolledBackPost),
                                $expectedSourceHash
                            )
                        ) {
                            owe_bridge_fail('ROLLBACK_FAILED');
                        }
                    } catch (Throwable $rollbackError) {
                        owe_bridge_log_error($projectRoot, $rollbackError);
                        owe_bridge_fail('ROLLBACK_FAILED');
                    }
                }
                throw $activationError;
            }

            echo 'APPLIED page_id=' . (int) $post->ID
                . ' operation=enable_elementor_editor'
                . ' enabled=yes' . "\n";
            exit(0);
        }

        owe_bridge_check_elementor($requiresPro);
        $post = owe_bridge_resolve_post($pageReference);
        $document = owe_bridge_get_document((int) $post->ID, true);
        $before = $document->get_elements_data();
        if (!is_array($before)) {
            owe_bridge_fail('ELEMENTOR_STRUCTURE_UNSUPPORTED');
        }
        if ($operation !== 'update_page_attributes') {
            owe_bridge_assert_compatible_structure($before);
            owe_bridge_validate_page_elements($before);
            owe_bridge_validate_available_widgets($before);
        }

        $beforeHash = owe_bridge_elements_hash($before);
        if (!hash_equals($expectedHash, $beforeHash)) {
            owe_bridge_fail('STALE_PAGE_HASH');
        }
        $beforeSectionHashes = owe_bridge_top_level_hashes($before);
        $newSectionId = '';

        $contentUpdates = [];
        $templateId = 0;
        $templatePosition = '';
        $templateElements = [];
        $templateRootIds = [];
        $applyPageSettings = false;
        $beforePageSettings = [];
        $afterPageSettings = null;
        $savePageSettings = null;
        $beforePageTemplate = ['exists' => false, 'value' => ''];
        $afterPageTemplate = ['exists' => false, 'value' => ''];
        $pageAttributeChanges = [];
        $beforePageAttributes = [];
        $afterPageAttributes = [];
        $expectedAttributesHash = '';
        $expectedTemplatesHash = '';
        if ($operation === 'update_page_attributes') {
            $allowedRequestFields = [
                'schema', 'page', 'device', 'operation', 'expected_page_hash',
                'expected_attributes_hash', 'expected_templates_hash', 'user_confirmed', 'attributes',
            ];
            if (array_diff(array_keys($request), $allowedRequestFields) !== []) {
                owe_bridge_fail('PAGE_ATTRIBUTES_REQUEST_FIELD_NOT_ALLOWED');
            }
            if ($device !== 'desktop') {
                owe_bridge_fail('DESKTOP_OPERATION_REQUIRED');
            }
            if (($request['user_confirmed'] ?? null) !== true) {
                owe_bridge_fail('USER_CONFIRMATION_REQUIRED');
            }
            $expectedAttributesHash = isset($request['expected_attributes_hash'])
                ? (string) $request['expected_attributes_hash']
                : '';
            if (!preg_match('/^[a-f0-9]{64}$/', $expectedAttributesHash)) {
                owe_bridge_fail('EXPECTED_ATTRIBUTES_HASH_REQUIRED');
            }
            $expectedTemplatesHash = isset($request['expected_templates_hash'])
                ? (string) $request['expected_templates_hash']
                : '';
            if (!preg_match('/^[a-f0-9]{64}$/', $expectedTemplatesHash)) {
                owe_bridge_fail('EXPECTED_TEMPLATES_HASH_REQUIRED');
            }
            if ((function_exists('wp_is_post_revision') && wp_is_post_revision((int) $post->ID))
                || (function_exists('wp_is_post_autosave') && wp_is_post_autosave((int) $post->ID))
            ) {
                owe_bridge_fail('PAGE_ATTRIBUTES_TARGET_INVALID');
            }
            $beforePageAttributes = owe_bridge_page_attributes_snapshot($post);
            if (!hash_equals(owe_bridge_page_attributes_hash($post), $expectedAttributesHash)) {
                owe_bridge_fail('STALE_PAGE_ATTRIBUTES');
            }
            $attributes = isset($request['attributes']) && is_array($request['attributes'])
                ? $request['attributes']
                : [];
            $availableTemplates = owe_bridge_available_page_templates($post, $document);
            if (!hash_equals(owe_bridge_page_templates_hash($availableTemplates), $expectedTemplatesHash)) {
                owe_bridge_fail('STALE_PAGE_TEMPLATES');
            }
            $pageAttributeChanges = owe_bridge_prepare_page_attributes(
                $attributes,
                $post,
                $document,
                $beforePageAttributes
            );
            $afterPageAttributes = owe_bridge_expected_page_attributes(
                $beforePageAttributes,
                $pageAttributeChanges
            );
            $after = $before;
        } elseif ($operation === 'insert_template') {
            $allowedRequestFields = [
                'schema', 'page', 'device', 'operation', 'expected_page_hash', 'requires_pro',
                'template_id', 'expected_template_hash', 'position', 'target_section_id',
                'apply_page_settings',
            ];
            if (array_diff(array_keys($request), $allowedRequestFields) !== []) {
                owe_bridge_fail('TEMPLATE_REQUEST_FIELD_NOT_ALLOWED');
            }
            if ($device !== 'desktop') {
                owe_bridge_fail('DESKTOP_OPERATION_REQUIRED');
            }
            $templateId = isset($request['template_id']) && is_int($request['template_id'])
                ? $request['template_id']
                : 0;
            $expectedTemplateHash = isset($request['expected_template_hash'])
                ? (string) $request['expected_template_hash']
                : '';
            if ($templateId < 1 || !preg_match('/^[a-f0-9]{64}$/', $expectedTemplateHash)) {
                owe_bridge_fail('TEMPLATE_REFERENCE_INVALID');
            }
            owe_bridge_resolve_template($templateId);
            $templateType = (string) get_post_meta($templateId, '_elementor_template_type', true);
            $sourceElements = owe_bridge_template_source_elements($templateId);
            if (!hash_equals($expectedTemplateHash, owe_bridge_elements_hash($sourceElements))) {
                owe_bridge_fail('STALE_TEMPLATE_HASH');
            }
            if (!array_key_exists('apply_page_settings', $request)
                || !is_bool($request['apply_page_settings'])
            ) {
                owe_bridge_fail('TEMPLATE_PAGE_SETTINGS_DECISION_REQUIRED');
            }
            $applyPageSettings = $request['apply_page_settings'];
            if ($applyPageSettings && $templateType !== 'page') {
                owe_bridge_fail('TEMPLATE_PAGE_SETTINGS_NOT_APPLICABLE');
            }
            $templateData = owe_bridge_load_template_clone($templateId, $applyPageSettings);
            $templateElements = owe_bridge_prepare_template_clone(
                $sourceElements,
                $templateData['content'],
                $before
            );
            $templateRootIds = array_values(array_map(static function (array $element): string {
                return (string) $element['id'];
            }, $templateElements));
            $templatePosition = isset($request['position']) ? (string) $request['position'] : '';
            $targetId = isset($request['target_section_id']) ? (string) $request['target_section_id'] : '';
            if (!in_array($templatePosition, ['prepend', 'append', 'before', 'after', 'replace'], true)) {
                owe_bridge_fail('TEMPLATE_POSITION_INVALID');
            }
            if (in_array($templatePosition, ['before', 'after'], true) && $targetId === '') {
                owe_bridge_fail('TARGET_SECTION_REQUIRED');
            }
            $after = owe_bridge_apply_template_operation(
                $before,
                $templateElements,
                $templatePosition,
                $targetId
            );
            if ($applyPageSettings) {
                $beforePageSettings = owe_bridge_document_page_settings($document);
                $beforePageTemplate = owe_bridge_document_page_template($document);
                $templateSettings = owe_bridge_validate_template_page_settings(
                    $templateData['page_settings'],
                    (int) $post->ID
                );
                if ($templateSettings === []) {
                    owe_bridge_fail('TEMPLATE_PAGE_SETTINGS_EMPTY');
                }
                $afterPageTemplate = isset($templateSettings['template'])
                    ? ['exists' => true, 'value' => (string) $templateSettings['template']]
                    : $beforePageTemplate;
                unset($templateSettings['template']);
                $afterPageSettings = array_replace_recursive($beforePageSettings, $templateSettings);
                $savePageSettings = $afterPageSettings;
                if ($afterPageTemplate['exists']) {
                    $savePageSettings['template'] = $afterPageTemplate['value'];
                }
            }
        } elseif ($operation === 'update_widget_content') {
            $allowedRequestFields = [
                'schema', 'page', 'device', 'operation', 'expected_page_hash',
                'requires_pro', 'target_section_id', 'updates',
            ];
            if (array_diff(array_keys($request), $allowedRequestFields) !== []) {
                owe_bridge_fail('CONTENT_REQUEST_FIELD_NOT_ALLOWED');
            }
            if ($device !== 'all') {
                owe_bridge_fail('CONTENT_DEVICE_REQUIRED');
            }
            if ($targetId === '') {
                owe_bridge_fail('TARGET_SECTION_REQUIRED');
            }
            $section = owe_bridge_find_top_level_section($before, $targetId);
            if ($section === null) {
                owe_bridge_fail('TARGET_SECTION_NOT_FOUND');
            }
            $updates = isset($request['updates']) && is_array($request['updates']) ? $request['updates'] : [];
            $contentUpdates = owe_bridge_prepare_content_updates(
                $updates,
                owe_bridge_section_content_index($section)
            );
            $after = owe_bridge_apply_content_operation($before, $targetId, $contentUpdates);
            owe_bridge_verify_content_isolation($before, $after, $targetId, $contentUpdates);
        } elseif ($operation === 'patch_responsive') {
            $patches = isset($request['patches']) && is_array($request['patches']) ? $request['patches'] : [];
            if ($targetId === '') {
                owe_bridge_fail('TARGET_SECTION_REQUIRED');
            }
            owe_bridge_validate_responsive_patches($patches, $device);
            $after = owe_bridge_apply_responsive_operation($before, $targetId, $patches);
        } else {
            if ($device !== 'desktop') {
                owe_bridge_fail('DESKTOP_OPERATION_REQUIRED');
            }
            if ($operation !== 'append_section' && $targetId === '') {
                owe_bridge_fail('TARGET_SECTION_REQUIRED');
            }
            $section = isset($request['section']) && is_array($request['section']) ? $request['section'] : [];
            owe_bridge_validate_section($section, $before, $operation, $targetId);
            $newSectionId = (string) $section['id'];
            $after = owe_bridge_apply_desktop_operation($before, $operation, $targetId, $section);
        }

        owe_bridge_validate_page_elements($after);
        $afterHash = owe_bridge_elements_hash($after);
        if ($operation !== 'update_page_attributes' && hash_equals($beforeHash, $afterHash)) {
            owe_bridge_fail('NO_CHANGES_APPLIED');
        }
        if ($operation === 'insert_template') {
            owe_bridge_verify_template_isolation($before, $after, $templateElements, $templatePosition);
        } elseif ($operation !== 'update_page_attributes') {
            owe_bridge_verify_section_isolation(
                $beforeSectionHashes,
                owe_bridge_top_level_hashes($after),
                $operation,
                $targetId,
                $newSectionId
            );
        }
        if ($operation === 'update_widget_content') {
            $normalizedBefore = owe_bridge_normalize_document_elements($document, $before);
            if (!hash_equals($beforeHash, owe_bridge_elements_hash($normalizedBefore))) {
                owe_bridge_fail('ELEMENTOR_DOCUMENT_NOT_CANONICAL');
            }
            $normalizedAfter = owe_bridge_normalize_document_elements($document, $after);
            if (!hash_equals($afterHash, owe_bridge_elements_hash($normalizedAfter))) {
                owe_bridge_fail('ELEMENTOR_SAVE_NORMALIZATION_REQUIRED');
            }
            owe_bridge_verify_content_isolation($before, $normalizedAfter, $targetId, $contentUpdates);
        }
        if ($operation === 'insert_template') {
            $normalizedBefore = owe_bridge_normalize_document_elements($document, $before);
            if (!hash_equals($beforeHash, owe_bridge_elements_hash($normalizedBefore))) {
                owe_bridge_fail('ELEMENTOR_DOCUMENT_NOT_CANONICAL');
            }
            $normalizedAfter = owe_bridge_normalize_document_elements($document, $after, $savePageSettings);
            if (!hash_equals($afterHash, owe_bridge_elements_hash($normalizedAfter))) {
                owe_bridge_fail('ELEMENTOR_SAVE_NORMALIZATION_REQUIRED');
            }
            owe_bridge_verify_template_isolation($before, $normalizedAfter, $templateElements, $templatePosition);
        }

        $writeStarted = false;
        try {
            $latestDocument = owe_bridge_get_document((int) $post->ID, true);
            $latest = $latestDocument->get_elements_data();
            if (!is_array($latest) || !hash_equals($beforeHash, owe_bridge_elements_hash($latest))) {
                owe_bridge_fail('STALE_PAGE_HASH');
            }
            if ($applyPageSettings
                && (owe_bridge_document_page_settings($latestDocument) !== $beforePageSettings
                    || owe_bridge_document_page_template($latestDocument) !== $beforePageTemplate)
            ) {
                owe_bridge_fail('STALE_PAGE_SETTINGS');
            }
            if ($operation === 'update_page_attributes') {
                $latestPost = get_post((int) $post->ID);
                if (!$latestPost instanceof WP_Post
                    || !hash_equals(owe_bridge_page_attributes_hash($latestPost), $expectedAttributesHash)
                ) {
                    owe_bridge_fail('STALE_PAGE_ATTRIBUTES');
                }
                $latestTemplates = owe_bridge_available_page_templates($latestPost, $latestDocument);
                if (!hash_equals(owe_bridge_page_templates_hash($latestTemplates), $expectedTemplatesHash)) {
                    owe_bridge_fail('STALE_PAGE_TEMPLATES');
                }
                owe_bridge_prepare_page_attributes(
                    isset($request['attributes']) && is_array($request['attributes']) ? $request['attributes'] : [],
                    $latestPost,
                    $latestDocument,
                    $beforePageAttributes
                );
            }
            $document = $latestDocument;
            $writeStarted = true;
            if ($operation === 'update_page_attributes') {
                owe_bridge_apply_page_attributes((int) $post->ID, $pageAttributeChanges);
            } else {
                owe_bridge_save_document($document, $after, $savePageSettings);
            }
            $persistedDocument = owe_bridge_get_document((int) $post->ID, true);
            $persisted = $persistedDocument->get_elements_data();
            if (!is_array($persisted) || !hash_equals($afterHash, owe_bridge_elements_hash($persisted))) {
                owe_bridge_fail('POST_WRITE_VALIDATION_FAILED');
            }
            if ($operation === 'insert_template') {
                owe_bridge_verify_template_isolation($before, $persisted, $templateElements, $templatePosition);
                if ($applyPageSettings
                    && (owe_bridge_document_page_settings($persistedDocument) !== $afterPageSettings
                        || owe_bridge_document_page_template($persistedDocument) !== $afterPageTemplate)
                ) {
                    owe_bridge_fail('TEMPLATE_PAGE_SETTINGS_VALIDATION_FAILED');
                }
            } elseif ($operation !== 'update_page_attributes') {
                owe_bridge_verify_section_isolation(
                    $beforeSectionHashes,
                    owe_bridge_top_level_hashes($persisted),
                    $operation,
                    $targetId,
                    $newSectionId
                );
            }
            if ($operation === 'update_widget_content') {
                owe_bridge_verify_content_isolation($before, $persisted, $targetId, $contentUpdates);
            }
            if ($operation === 'update_page_attributes') {
                $persistedPost = get_post((int) $post->ID);
                if (!$persistedPost instanceof WP_Post
                    || owe_bridge_page_attributes_snapshot($persistedPost) !== $afterPageAttributes
                ) {
                    owe_bridge_fail('PAGE_ATTRIBUTES_VALIDATION_FAILED');
                }
            }
        } catch (Throwable $writeError) {
            if ($writeStarted) {
                try {
                    $rollbackDocument = owe_bridge_get_document((int) $post->ID, true);
                    if ($operation === 'update_page_attributes') {
                        owe_bridge_restore_page_attributes(
                            $beforePageAttributes,
                            $pageAttributeChanges,
                            $afterPageAttributes
                        );
                    } else {
                        owe_bridge_restore_document(
                            $rollbackDocument,
                            $before,
                            $beforePageSettings,
                            $applyPageSettings,
                            $beforePageTemplate
                        );
                    }
                    $rolledBackDocument = owe_bridge_get_document((int) $post->ID, true);
                    $rolledBack = $rolledBackDocument->get_elements_data();
                    if (!is_array($rolledBack) || !hash_equals($beforeHash, owe_bridge_elements_hash($rolledBack))) {
                        owe_bridge_fail('ROLLBACK_FAILED');
                    }
                    if ($applyPageSettings
                        && (owe_bridge_document_page_settings($rolledBackDocument) !== $beforePageSettings
                            || owe_bridge_document_page_template($rolledBackDocument) !== $beforePageTemplate)
                    ) {
                        owe_bridge_fail('ROLLBACK_FAILED');
                    }
                    if ($operation === 'update_page_attributes') {
                        $rolledBackPost = get_post((int) $post->ID);
                        if (!$rolledBackPost instanceof WP_Post
                            || owe_bridge_page_attributes_snapshot($rolledBackPost) !== $beforePageAttributes
                        ) {
                            owe_bridge_fail('ROLLBACK_FAILED');
                        }
                    }
                } catch (Throwable $rollbackError) {
                    owe_bridge_log_error($projectRoot, $rollbackError);
                    owe_bridge_fail('ROLLBACK_FAILED');
                }
            }
            throw $writeError;
        }

        if ($operation === 'update_page_attributes') {
            echo 'APPLIED page_id=' . (int) $post->ID
                . ' operation=update_page_attributes'
                . ' attributes=' . implode(',', array_keys($pageAttributeChanges))
                . ' page_hash=' . $afterHash . "\n";
            exit(0);
        }

        $resultSectionId = $operation === 'insert_template'
            ? implode(',', $templateRootIds)
            : (in_array($operation, ['patch_responsive', 'update_widget_content'], true)
                ? $targetId
                : $newSectionId);
        echo 'APPLIED page_id=' . (int) $post->ID
            . ' operation=' . $operation
            . ' section_id=' . $resultSectionId
            . ($operation === 'insert_template' ? ' template_id=' . $templateId . ' position=' . $templatePosition : '')
            . ' page_hash=' . $afterHash
            . "\n";
        exit(0);
    }

    $pageReference = isset($options['page']) ? (string) $options['page'] : '';
    $requiresPro = ($options['requires-pro'] ?? false) === true;
    owe_bridge_check_elementor($requiresPro);
    $post = owe_bridge_resolve_post($pageReference);
    $document = owe_bridge_get_document((int) $post->ID, $command !== 'check');
    if ($command === 'check' && !$document->is_built_with_elementor()) {
        $issue = owe_bridge_elementor_activation_issue($post, $document);
        echo 'NEEDS_ELEMENTOR_ACTIVATION page_id=' . (int) $post->ID
            . ' native_content=' . (trim((string) $post->post_content) === '' ? 'empty' : 'present')
            . ' activation_supported=' . ($issue === '' ? 'yes' : 'no')
            . ($issue === '' ? '' : ' reason=' . $issue)
            . ' source_hash=' . owe_bridge_elementor_activation_hash($post)
            . "\n";
        exit(0);
    }
    $elements = $document->get_elements_data();
    if (!is_array($elements)) {
        owe_bridge_fail('ELEMENTOR_STRUCTURE_UNSUPPORTED');
    }
    if ($command !== 'inspect-attributes') {
        owe_bridge_assert_compatible_structure($elements);
        owe_bridge_validate_page_elements($elements);
    }
    $pageHash = owe_bridge_elements_hash($elements);

    if ($command === 'check') {
        echo 'READY page_id=' . (int) $post->ID
            . ' page_hash=' . $pageHash
            . ' sections=' . count($elements)
            . "\n";
        exit(0);
    }

    if ($command === 'inspect-attributes') {
        $snapshot = owe_bridge_page_attributes_snapshot($post);
        $attributesHash = owe_bridge_page_attributes_snapshot_hash($snapshot);
        $availableTemplates = owe_bridge_available_page_templates($post, $document);
        $templatesHash = owe_bridge_page_templates_hash($availableTemplates);
        $outputOption = isset($options['output'])
            ? (string) $options['output']
            : '.owe/runtime/elementor-attributes.json';
        if (substr(strtolower($outputOption), -5) !== '.json') {
            owe_bridge_fail('OUTPUT_JSON_REQUIRED');
        }
        $outputPath = owe_bridge_safe_project_path($projectRoot, $outputOption, '.owe/runtime', false);
        owe_bridge_write_json($outputPath, [
            'schema' => 'owe-elementor-page-attributes/1.0',
            'page_id' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'page_hash' => $pageHash,
            'attributes_hash' => $attributesHash,
            'templates_hash' => $templatesHash,
            'attributes' => $snapshot,
            'available_templates' => $availableTemplates,
            'supports_parent_and_order' => is_post_type_hierarchical((string) $post->post_type),
        ]);
        echo 'ATTRIBUTES_INSPECTED page_id=' . (int) $post->ID
            . ' attributes_hash=' . $attributesHash
            . ' templates_hash=' . $templatesHash
            . ' output=' . $outputOption . "\n";
        exit(0);
    }

    $defaultOutput = $command === 'inspect'
        ? '.owe/runtime/elementor-structure.json'
        : ($command === 'inspect-content'
            ? '.owe/runtime/elementor-content.json'
            : '.owe/runtime/elementor-section.json');
    $outputOption = isset($options['output']) ? (string) $options['output'] : $defaultOutput;
    if (substr(strtolower($outputOption), -5) !== '.json') {
        owe_bridge_fail('OUTPUT_JSON_REQUIRED');
    }
    $outputPath = owe_bridge_safe_project_path($projectRoot, $outputOption, '.owe/runtime', false);

    if ($command === 'inspect') {
        owe_bridge_write_json($outputPath, [
            'schema' => 'owe-elementor-inspection/1.0',
            'page_id' => (int) $post->ID,
            'page_title' => (string) get_the_title($post),
            'page_hash' => $pageHash,
            'sections' => owe_bridge_structure_summary($elements),
        ]);
        echo 'INSPECTED page_id=' . (int) $post->ID . ' page_hash=' . $pageHash . ' output=' . $outputOption . "\n";
        exit(0);
    }

    $sectionId = isset($options['section-id']) ? (string) $options['section-id'] : '';
    $section = owe_bridge_find_top_level_section($elements, $sectionId);
    if ($section === null) {
        owe_bridge_fail('TARGET_SECTION_NOT_FOUND');
    }
    if ($command === 'inspect-content') {
        $fields = owe_bridge_public_content_fields($section);
        owe_bridge_write_json($outputPath, [
            'schema' => 'owe-elementor-content-inspection/1.0',
            'page_id' => (int) $post->ID,
            'page_title' => (string) get_the_title($post),
            'page_hash' => $pageHash,
            'section_id' => $sectionId,
            'fields' => $fields,
        ]);
        echo 'CONTENT_INSPECTED page_id=' . (int) $post->ID
            . ' section_id=' . $sectionId
            . ' fields=' . count($fields)
            . ' output=' . $outputOption . "\n";
        exit(0);
    }

    owe_bridge_write_json($outputPath, [
        'schema' => 'owe-elementor-section/1.0',
        'page_id' => (int) $post->ID,
        'page_hash' => $pageHash,
        'section' => $section,
    ]);
    echo 'EXPORTED page_id=' . (int) $post->ID . ' section_id=' . $sectionId . ' output=' . $outputOption . "\n";
    exit(0);
} catch (Throwable $error) {
    if ($projectRoot !== '' && is_dir($projectRoot)) {
        owe_bridge_log_error($projectRoot, $error);
    }
    $code = $error instanceof OWE_Bridge_Exception ? $error->oweCode() : 'INTERNAL_ERROR';
    fwrite(STDERR, 'BLOCKED:' . $code . "\n");
    exit(70);
}
