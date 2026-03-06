<?php

// ===============================
// JANGAN ADA KODE EKSEKUSI DI LUAR FUNCTION
// ===============================

function get_headers_safe()
{
    $headers = [];

    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = $value;
        }
    }

    return $headers;
}

function validate_api_request(PDO $pdo)
{
    $headers = get_headers_safe();

    if (!isset($headers['authorization'])) {
        http_response_code(401);
        exit(json_encode(['error' => 'No Authorization']));
    }

    if (!isset($headers['x-client-id'])) {
        http_response_code(401);
        exit(json_encode(['error' => 'No Client ID']));
    }

    $token    = trim(str_replace('Bearer', '', $headers['authorization']));
    $clientId = trim($headers['x-client-id']);

    // DEBUG (AMAN)
    file_put_contents(
        __DIR__ . '/../logs/php_error.log',
        "RECV TOKEN=[$token] | CLIENT=[$clientId]\n",
        FILE_APPEND
    );

    $stmt = $pdo->prepare("
        SELECT id FROM api_tokens
        WHERE TRIM(token) = ?
          AND TRIM(client_id) = ?
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$token, $clientId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(403);
        exit(json_encode(['error' => 'Unauthorized']));
    }
}
