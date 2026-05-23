<?php

function generateCsrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['csrf_token_history']) || !is_array($_SESSION['csrf_token_history'])) {
        $_SESSION['csrf_token_history'] = [];
    }

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_history'] = [$_SESSION['csrf_token']];
    } elseif (!in_array($_SESSION['csrf_token'], $_SESSION['csrf_token_history'], true)) {
        array_unshift($_SESSION['csrf_token_history'], $_SESSION['csrf_token']);
        $_SESSION['csrf_token_history'] = array_slice(array_values(array_unique($_SESSION['csrf_token_history'])), 0, 5);
    }

    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $token = trim($token);
    if ($token === '') {
        return false;
    }

    $currentToken = $_SESSION['csrf_token'] ?? '';
    if (is_string($currentToken) && $currentToken !== '' && hash_equals($currentToken, $token)) {
        return true;
    }

    $tokenHistory = $_SESSION['csrf_token_history'] ?? [];
    if (!is_array($tokenHistory)) {
        return false;
    }

    foreach ($tokenHistory as $historyToken) {
        if (is_string($historyToken) && $historyToken !== '' && hash_equals($historyToken, $token)) {
            return true;
        }
    }

    return false;
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCsrfToken()) . '">';
}
