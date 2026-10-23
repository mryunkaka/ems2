<?php
declare(strict_types=1);

/**
 * Encrypted storage for organization-owned OCR.Space API keys.
 * The encryption secret must be supplied outside the database.
 */
final class OcrKeyManager
{
    private const CIPHER = 'aes-256-gcm';
    private const MAX_KEY_LENGTH = 512;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $encryptionSecret,
        private readonly ?Closure $httpTransport = null
    ) {
    }

    public function encryptKey(string $key): string
    {
        $this->assertEncryptionSecret();
        $key = trim($key);
        if ($key === '' || strlen($key) > self::MAX_KEY_LENGTH) {
            throw new InvalidArgumentException('API key must contain between 1 and 512 bytes.');
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $key,
            self::CIPHER,
            hash('sha256', $this->encryptionSecret, true),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'ems-ocr-api-key-v1',
            16
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Unable to encrypt OCR API key.');
        }

        return base64_encode(json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR));
    }

    public function decryptKey(string $ciphertext): string
    {
        $this->assertEncryptionSecret();
        $decoded = base64_decode($ciphertext, true);
        $envelope = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($envelope) || ($envelope['v'] ?? null) !== 1) {
            throw new UnexpectedValueException('Stored OCR API key has an invalid encrypted envelope.');
        }

        $iv = base64_decode((string)($envelope['iv'] ?? ''), true);
        $tag = base64_decode((string)($envelope['tag'] ?? ''), true);
        $data = base64_decode((string)($envelope['data'] ?? ''), true);
        if (!is_string($iv) || strlen($iv) !== 12 || !is_string($tag) || strlen($tag) !== 16 || !is_string($data)) {
            throw new UnexpectedValueException('Stored OCR API key has an invalid encrypted envelope.');
        }

        $key = openssl_decrypt(
            $data,
            self::CIPHER,
            hash('sha256', $this->encryptionSecret, true),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'ems-ocr-api-key-v1'
        );
        if (!is_string($key) || $key === '') {
            throw new UnexpectedValueException('Stored OCR API key failed authentication.');
        }

        return $key;
    }

    /** @return list<array<string, mixed>> */
    public function listKeys(bool $enabledOnly = false): array
    {
        $sql = 'SELECT id, label, encrypted_key, enabled, last_tested_at, last_test_status,
                       last_error_category, last_error_at, last_success_at,
                       count_engine1, count_engine2, count_engine3, usage_checked_at
                FROM ocr_api_keys' . ($enabledOnly ? ' WHERE enabled = 1' : '') . ' ORDER BY id';
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $row['id'] = (int)$row['id'];
            $row['enabled'] = (bool)$row['enabled'];
            $row['masked_key'] = '********';
            unset($row['encrypted_key']);
            return $row;
        }, $rows);
    }

    public function saveKey(string $key, ?int $id = null, ?string $label = null): int
    {
        $key = trim($key);
        $encrypted = $this->encryptKey($key);
        $label = trim((string)$label);
        if ($label === '') {
            $label = 'OCR key ' . date('Y-m-d H:i');
        }
        if (mb_strlen($label) > 100) {
            throw new InvalidArgumentException('Label must not exceed 100 characters.');
        }

        if ($id === null) {
            $stmt = $this->pdo->prepare('INSERT INTO ocr_api_keys (label, encrypted_key, enabled) VALUES (?, ?, 1)');
            $stmt->execute([$label, $encrypted]);
            return (int)$this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare('UPDATE ocr_api_keys SET label = ?, encrypted_key = ?, last_tested_at = NULL, last_test_status = NULL, last_error_category = NULL, count_engine1 = NULL, count_engine2 = NULL, count_engine3 = NULL, usage_checked_at = NULL WHERE id = ?');
        $stmt->execute([$label, $encrypted, $id]);
        if ($stmt->rowCount() === 0 && !$this->keyExists($id)) {
            throw new OutOfBoundsException('OCR API key not found.');
        }
        return $id;
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $stmt = $this->pdo->prepare('UPDATE ocr_api_keys SET enabled = ? WHERE id = ?');
        $stmt->execute([$enabled ? 1 : 0, $id]);
        if ($stmt->rowCount() === 0 && !$this->keyExists($id)) {
            throw new OutOfBoundsException('OCR API key not found.');
        }
    }

    public function removeKey(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ocr_api_keys WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            throw new OutOfBoundsException('OCR API key not found.');
        }
    }

    /** Import the current config key once, idempotently, for existing installations. */
    public function importConfiguredKeyOnce(string $key): ?int
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }
        $this->pdo->beginTransaction();
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $this->pdo->exec('INSERT OR IGNORE INTO ocr_api_key_bootstrap (id, legacy_imported) VALUES (1, 0)');
            } else {
                $this->pdo->exec('INSERT IGNORE INTO ocr_api_key_bootstrap (id, legacy_imported) VALUES (1, 0)');
            }
            $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
            $rows = $this->pdo->query('SELECT id, encrypted_key FROM ocr_api_keys ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                try {
                    if (hash_equals($this->decryptKey((string)$row['encrypted_key']), $key)) {
                        $this->pdo->exec('UPDATE ocr_api_key_bootstrap SET legacy_imported = 1 WHERE id = 1');
                        $this->pdo->commit();
                        return (int)$row['id'];
                    }
                } catch (Throwable) {
                    // A corrupt or older record must not prevent importing the configured key.
                }
            }
            $imported = $this->pdo->query('SELECT legacy_imported FROM ocr_api_key_bootstrap WHERE id = 1' . $lock)->fetchColumn();
            if ((int)$imported === 1) {
                $this->pdo->commit();
                return null;
            }
            $id = $this->saveKey($key, null, 'Migrated from OCR config');
            $this->pdo->exec('UPDATE ocr_api_key_bootstrap SET legacy_imported = 1 WHERE id = 1');
            $this->pdo->commit();
            return $id;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Fetch provider usage counts. OCR.Space reports conversions through the end
     * of the preceding day, so remaining values are estimates rather than live counters.
     *
     * @return array<string, mixed>
     */
    public function probeUsage(int $id): array
    {
        $encryptedSnapshot = $this->getEncryptedKey($id);
        $key = $this->decryptKey($encryptedSnapshot);
        $response = $this->request('POST', 'https://myapi.ocr.space/conversions', $key, []);
        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if (($response['error'] ?? '') !== '' || (int)($response['http_code'] ?? 0) !== 200 || !is_array($decoded)) {
            return ['success' => false, 'message' => 'Usage endpoint tidak dapat diverifikasi.'];
        }

        $engine1 = filter_var($decoded['count_engine1'] ?? null, FILTER_VALIDATE_INT);
        $engine2 = filter_var($decoded['count_engine2'] ?? null, FILTER_VALIDATE_INT);
        $engine3 = filter_var($decoded['count_engine3'] ?? null, FILTER_VALIDATE_INT);
        if ($engine1 === false || $engine2 === false || $engine3 === false || min($engine1, $engine2, $engine3) < 0) {
            return ['success' => false, 'message' => 'Format data pemakaian dari provider tidak valid.'];
        }

        $statusStmt = $this->pdo->prepare('SELECT last_test_status FROM ocr_api_keys WHERE id = ?');
        $statusStmt->execute([$id]);
        $lastTestStatus = $statusStmt->fetchColumn();
        if ($lastTestStatus === false) {
            throw new OutOfBoundsException('OCR API key not found.');
        }
        $usageVerified = ($engine1 + $engine2 + $engine3) > 0 || $lastTestStatus === 'success';
        $checkedAt = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('UPDATE ocr_api_keys SET count_engine1 = ?, count_engine2 = ?, count_engine3 = ?, usage_checked_at = ? WHERE id = ? AND encrypted_key = ?');
        $stmt->execute([$engine1, $engine2, $engine3, $checkedAt, $id, $encryptedSnapshot]);

        return [
            'success' => true,
            'count_engine1' => $engine1,
            'count_engine2' => $engine2,
            'count_engine3' => $engine3,
            'engine12_used' => $engine1 + $engine2,
            'engine12_limit' => 25000,
            'engine12_remaining_estimate' => max(0, 25000 - $engine1 - $engine2),
            'engine3_used' => $engine3,
            'engine3_limit' => 1000,
            'engine3_remaining_estimate' => max(0, 1000 - $engine3),
            'usage_verified' => $usageVerified,
            'checked_at' => $checkedAt,
            'freshness_note' => 'Snapshot provider diperbarui ' . $checkedAt . '. Saat pengecekan itu, data provider tersedia sampai akhir hari sebelumnya; sisa kuota adalah perkiraan.',
        ];
    }

    /** @return array<string, mixed> */
    public function testKey(int $id): array
    {
        $encryptedSnapshot = $this->getEncryptedKey($id);
        $key = $this->decryptKey($encryptedSnapshot);
        require_once __DIR__ . '/../config/ocr_config.php';
        $endpoint = preg_replace('#/parse/image$#', '/parse/imageurl', EMS_OCR_API_ENDPOINT) ?: EMS_OCR_API_ENDPOINT;
        $response = $this->request('GET', $endpoint, $key, [
            'url' => EMS_OCR_API_TEST_IMAGE,
            'language' => 'eng',
            'OCREngine' => '2',
        ]);
        $failure = $this->classifyProviderFailure($response);
        $decoded = json_decode((string)($response['body'] ?? ''), true);
        $text = trim((string)($decoded['ParsedResults'][0]['ParsedText'] ?? ''));
        $ok = $failure === null
            && (int)($response['http_code'] ?? 0) >= 200
            && (int)($response['http_code'] ?? 0) < 300
            && empty($decoded['IsErroredOnProcessing'])
            && $text !== '';

        $stmt = $this->pdo->prepare('UPDATE ocr_api_keys SET last_tested_at = ?, last_test_status = ?, last_error_category = ?, last_error_at = CASE WHEN ? IS NULL THEN last_error_at ELSE ? END, last_success_at = CASE WHEN ? = 1 THEN ? ELSE last_success_at END WHERE id = ? AND encrypted_key = ?');
        $now = date('Y-m-d H:i:s');
        $curlError = (string)($response['curl_error'] ?? $response['error'] ?? '');
        $httpCode = (int)($response['http_code'] ?? 0);
        $category = $failure ?? (!$ok ? ($curlError !== '' ? 'transport_error' : (($httpCode < 200 || $httpCode >= 300) ? 'http_error' : 'document_error')) : null);
        $stmt->execute([$now, $ok ? 'success' : 'error', $category, $category, $now, $ok ? 1 : 0, $now, $id, $encryptedSnapshot]);

        return ['success' => $ok, 'category' => $category, 'message' => $ok
            ? 'Key aktif dan berhasil membaca gambar uji.'
            : (in_array($category, ['auth', 'quota', 'rate_limit'], true)
                ? 'Key ditolak atau mengalami pembatasan provider.'
                : ($category === 'transport_error'
                    ? 'Probe gagal terhubung (jaringan/TLS).'
                    : ($category === 'http_error'
                        ? 'Endpoint tes mengembalikan HTTP ' . $httpCode . '.'
                        : 'Gambar uji tidak menghasilkan teks OCR.')))];
    }

    /** @return array<string, mixed> */
    public function testEnabledKeys(): array
    {
        require_once __DIR__ . '/../config/ocr_config.php';
        $endpoint = preg_replace('#/parse/image$#', '/parse/imageurl', EMS_OCR_API_ENDPOINT) ?: EMS_OCR_API_ENDPOINT;
        $result = $this->withKeyFailover(function (string $key) use ($endpoint): array {
            $response = $this->request('GET', $endpoint, $key, [
                'url' => EMS_OCR_API_TEST_IMAGE,
                'language' => 'eng',
                'OCREngine' => '2',
            ]);
            $decoded = json_decode((string)($response['body'] ?? ''), true);
            $text = trim((string)($decoded['ParsedResults'][0]['ParsedText'] ?? ''));
            $apiError = is_array($decoded) && (
                !empty($decoded['IsErroredOnProcessing']) ||
                !empty($decoded['ErrorMessage']) ||
                !empty($decoded['ErrorDetails']) ||
                !empty($decoded['ParsedResults'][0]['ErrorMessage'])
            );
            $response['success'] = ($response['curl_error'] ?? '') === ''
                && (int)($response['http_code'] ?? 0) >= 200
                && (int)($response['http_code'] ?? 0) < 300
                && !$apiError
                && $text !== '';
            $response['sample_text'] = $response['success'] ? $text : '';
            return $response;
        });
        $result['message'] = !empty($result['success'])
            ? 'API key aktif dan gambar uji berhasil dibaca.'
            : ((int)($result['attempts'] ?? 0) === 0
                ? 'Tidak ada API key aktif untuk diuji.'
                : 'Pemeriksaan API gagal. Periksa kategori error dan koneksi provider.');
        return $result;
    }

    /**
     * Returns auth, quota, or rate_limit only for explicit provider signals.
     * Network and document failures intentionally return null.
     */
    public function classifyProviderFailure(array $response): ?string
    {
        if ((string)($response['curl_error'] ?? $response['error'] ?? '') !== '') {
            return null;
        }
        $httpCode = (int)($response['http_code'] ?? 0);
        if ($httpCode === 401) {
            return 'auth';
        }
        if ($httpCode === 429) {
            return 'rate_limit';
        }

        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($decoded)) {
            return null;
        }
        $errors = [];
        foreach ([$decoded['ErrorMessage'] ?? null, $decoded['ErrorDetails'] ?? null, $decoded['ParsedResults'][0]['ErrorMessage'] ?? null] as $error) {
            if (is_string($error)) {
                $errors[] = $error;
            } elseif (is_array($error)) {
                array_push($errors, ...array_map('strval', $error));
            }
        }
        $haystack = strtolower(implode(' ', $errors));
        if ($haystack === '') {
            return null;
        }
        if (preg_match('/invalid\s+(api\s*)?key|api\s*key\s*(is\s*)?(invalid|rejected)|unauthori[sz]ed|api\s*key.{0,30}access denied/', $haystack)) {
            return 'auth';
        }
        if (preg_match('/rate\s*limit|too\s+many\s+requests|request\s+limit\s+exceeded/', $haystack)) {
            return 'rate_limit';
        }
        if (preg_match('/quota.{0,30}(exceeded|exhausted|reached)|monthly\s+(limit|quota)|daily\s+(limit|quota)|maximum.{0,60}(request|conversion|api\s*call).{0,40}(reached|exceeded)|(?:request|conversion).{0,40}(quota|limit).{0,30}(reached|exceeded)/', $haystack)) {
            return 'quota';
        }
        return null;
    }

    /**
     * Try each enabled key once, continuing only after an explicit credential,
     * quota, or rate-limit failure. The callback receives decrypted secrets only
     * in process memory and must never log or return them.
     *
     * @return array<string, mixed>
     */
    public function withKeyFailover(callable $scanWithKey): array
    {
        $rows = $this->pdo->query('SELECT id, encrypted_key FROM ocr_api_keys WHERE enabled = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return ['success' => false, 'attempts' => 0, 'failure_category' => 'no_enabled_keys'];
        }

        $this->pdo->beginTransaction();
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $this->pdo->exec('INSERT OR IGNORE INTO ocr_api_key_rotation (id, next_key_id) VALUES (1, NULL)');
            } else {
                $this->pdo->exec('INSERT IGNORE INTO ocr_api_key_rotation (id, next_key_id) VALUES (1, NULL)');
            }
            $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
            $cursor = $this->pdo->query('SELECT next_key_id FROM ocr_api_key_rotation WHERE id = 1' . $lock)->fetchColumn();
            $startIndex = 0;
            if ($cursor !== false && $cursor !== null) {
                foreach ($rows as $index => $row) {
                    if ((int)$row['id'] >= (int)$cursor) {
                        $startIndex = $index;
                        break;
                    }
                    if ($index === count($rows) - 1) {
                        $startIndex = 0;
                    }
                }
            }
            $nextId = (int)$rows[($startIndex + 1) % count($rows)]['id'];
            $updateCursor = $this->pdo->prepare('UPDATE ocr_api_key_rotation SET next_key_id = ? WHERE id = 1');
            $updateCursor->execute([$nextId]);
            $this->pdo->commit();
            $rows = array_merge(array_slice($rows, $startIndex), array_slice($rows, 0, $startIndex));
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        $attempts = 0;
        $lastResult = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $currentKey = $this->pdo->prepare('SELECT encrypted_key FROM ocr_api_keys WHERE id = ? AND enabled = 1');
            $currentKey->execute([$id]);
            $encryptedKey = $currentKey->fetchColumn();
            if (!is_string($encryptedKey)) {
                continue;
            }
            $key = $this->decryptKey($encryptedKey);
            $stmt = $this->pdo->prepare('UPDATE ocr_api_keys SET last_used_at = ? WHERE id = ?');
            $stamp = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
            $stmt->execute([$stamp->format('Y-m-d H:i:s.u'), $id]);
            $attempts++;
            $response = $scanWithKey($key, $id);
            $response['key_id'] = $id;
            $response['attempts'] = $attempts;
            $lastResult = $response;

            $failure = $this->classifyProviderFailure($response);
            if ($failure !== null) {
                $this->recordAttempt($id, false, $failure, $encryptedKey);
                $lastResult['failure_category'] = $failure;
                continue;
            }

            if (!empty($response['success'])) {
                $this->recordAttempt($id, true, null, $encryptedKey);
                $response['failure_category'] = null;
                return $response;
            }

            $this->recordAttempt($id, false, null, $encryptedKey);
            $response['failure_category'] = (string)($response['curl_error'] ?? '') !== ''
                ? 'transport_error'
                : 'document_error';
            return $response;
        }

        $lastResult['success'] = false;
        return $lastResult;
    }

    private function recordAttempt(int $id, bool $success, ?string $failure, string $encryptedSnapshot): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('UPDATE ocr_api_keys
            SET last_tested_at = ?, last_test_status = ?,
                last_error_category = CASE WHEN ? = 1 THEN NULL WHEN ? IS NULL THEN last_error_category ELSE ? END,
                last_error_at = CASE WHEN ? IS NULL THEN last_error_at ELSE ? END,
                last_success_at = CASE WHEN ? = 1 THEN ? ELSE last_success_at END
            WHERE id = ? AND encrypted_key = ?');
        $successFlag = $success ? 1 : 0;
        $stmt->execute([$now, $success ? 'success' : 'error', $successFlag, $failure, $failure, $failure, $now, $successFlag, $now, $id, $encryptedSnapshot]);
    }

    private function getDecryptedKey(int $id): string
    {
        return $this->decryptKey($this->getEncryptedKey($id));
    }

    private function getEncryptedKey(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT encrypted_key FROM ocr_api_keys WHERE id = ?');
        $stmt->execute([$id]);
        $encrypted = $stmt->fetchColumn();
        if (!is_string($encrypted)) {
            throw new OutOfBoundsException('OCR API key not found.');
        }
        return $encrypted;
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $url, string $key, array $payload): array
    {
        if ($this->httpTransport !== null) {
            return ($this->httpTransport)($method, $url, $key, $payload);
        }

        if (strtoupper($method) === 'GET' && $payload !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($payload);
        }
        $curl = curl_init();
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['apikey: ' . $key, 'Accept: application/json'],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_OPTIONS => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0,
        ];
        if (strtoupper($method) !== 'GET') {
            $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($payload);
        }
        curl_setopt_array($curl, $curlOptions);
        $body = curl_exec($curl);
        $result = [
            'body' => is_string($body) ? $body : '',
            'http_code' => (int)curl_getinfo($curl, CURLINFO_HTTP_CODE),
            'curl_error' => curl_error($curl),
            'error' => curl_error($curl),
        ];
        curl_close($curl);
        return $result;
    }

    private function keyExists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ocr_api_keys WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    }

    private function assertEncryptionSecret(): void
    {
        if (strlen($this->encryptionSecret) < 32 || preg_match('/^(replace|change|your|example)[-_ ]/i', trim($this->encryptionSecret))) {
            throw new RuntimeException('EMS_OCR_KEYS_ENCRYPTION_KEY must be a non-placeholder secret of at least 32 bytes.');
        }
    }
}

function ems_ocr_key_manager(PDO $pdo): OcrKeyManager
{
    require_once __DIR__ . '/../config/env.php';
    return new OcrKeyManager($pdo, (string)ems_env('EMS_OCR_KEYS_ENCRYPTION_KEY', ''));
}

function ems_ocr_key_action_is_allowed(bool $isProgrammerRoxwood, string $method, bool $csrfValid): bool
{
    return $isProgrammerRoxwood && strtoupper($method) === 'POST' && $csrfValid;
}
