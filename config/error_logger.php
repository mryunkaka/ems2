<?php

function logRecruitmentError(string $context, Exception $e): void
{
    $logFile = __DIR__ . '/../storage/recruitment_error.log';
    $message = sprintf(
        "[%s] [%s] %s in %s:%d\nStack: %s\n%s\n",
        date('Y-m-d H:i:s'),
        $context,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString(),
        str_repeat('=', 80)
    );

    file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
}
