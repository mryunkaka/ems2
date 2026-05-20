<?php

require_once __DIR__ . '/env.php';

function emsRuntimeIsProduction(): bool
{
    $env = strtolower(trim((string)ems_env('APP_ENV', 'production')));
    return !in_array($env, ['local', 'development', 'dev', 'debug', 'testing', 'test'], true);
}

function emsRuntimeLogsDir(): string
{
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

function emsRuntimeLogPath(string $filename): string
{
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', trim($filename)) ?: 'app.log';
    return emsRuntimeLogsDir() . '/' . $safeName;
}

function emsAppendLog(string $filename, string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents(emsRuntimeLogPath($filename), $line, FILE_APPEND | LOCK_EX);
}

function emsApplyProductionPhpIni(?string $errorLogFile = null): void
{
    ini_set('log_errors', '1');
    ini_set('display_errors', emsRuntimeIsProduction() ? '0' : '1');
    ini_set('display_startup_errors', emsRuntimeIsProduction() ? '0' : '1');
    ini_set('expose_php', '0');

    if ($errorLogFile !== null && trim($errorLogFile) !== '') {
        ini_set('error_log', $errorLogFile);
    }
}
