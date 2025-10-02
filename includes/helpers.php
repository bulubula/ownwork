<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_currency($amount): string
{
    return number_format((float) $amount, 2, '.', ',');
}

function app_base_path(): string
{
    static $basePath;
    if ($basePath !== null) {
        return $basePath;
    }

    $projectRoot = realpath(__DIR__ . '/..');
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

    if ($projectRoot === false) {
        $basePath = '';
        return $basePath;
    }

    $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
    $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');

    if ($documentRoot !== '' && strpos($projectRoot, $documentRoot) === 0) {
        $relative = substr($projectRoot, strlen($documentRoot));
        $relative = str_replace('\\', '/', $relative);
        $basePath = $relative === '' ? '' : '/' . ltrim($relative, '/');
    } else {
        $basePath = '';
    }

    return $basePath;
}

function path_to_root(): string
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $scriptDir = $scriptName !== '' ? str_replace('\\', '/', dirname($scriptName)) : '';
    if ($scriptDir === '\\' || $scriptDir === '.') {
        $scriptDir = '';
    }
    $scriptDir = rtrim($scriptDir, '/');

    $basePath = app_base_path();
    if ($basePath !== '' && strpos($scriptDir, $basePath) === 0) {
        $relative = trim(substr($scriptDir, strlen($basePath)), '/');
    } else {
        $relative = trim($scriptDir, '/');
    }

    if ($relative === '') {
        return '';
    }

    $depth = substr_count($relative, '/') + 1;
    return str_repeat('../', $depth);
}

function url_for(string $path): string
{
    $path = ltrim($path, '/');
    return path_to_root() . $path;
}

function asset_url(string $path): string
{
    return url_for($path);
}

