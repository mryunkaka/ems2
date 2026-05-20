<?php

require_once __DIR__ . '/runtime.php';

function logRecruitmentError(string $context, Exception $e): void
{
    $message = '[' . $context . '] ' . $e->getMessage();

    if (!emsRuntimeIsProduction()) {
        $message .= sprintf(
            ' in %s:%d | Stack: %s',
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
    }

    emsAppendLog('recruitment_error.log', $message);
}
