<?php
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/ocr_config.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Status OCR API';
$result = null;
$maskedApiKey = str_repeat('*', strlen(EMS_OCR_API_KEY));
$apiKeyLength = strlen(EMS_OCR_API_KEY);
if ($apiKeyLength > 6) {
    $maskedApiKey = substr(EMS_OCR_API_KEY, 0, 3)
        . str_repeat('*', max(0, $apiKeyLength - 6))
        . substr(EMS_OCR_API_KEY, -3);
}

if (isset($_GET['run']) && $_GET['run'] === '1') {
    $docsProbeUrl = 'https://ocr.space/OCRAPI';
    $testUrl = preg_replace('#/parse/image$#', '/parse/imageurl', EMS_OCR_API_ENDPOINT) . '?' . http_build_query([
        'apikey' => EMS_OCR_API_KEY,
        'url' => EMS_OCR_API_TEST_IMAGE,
        'language' => 'eng',
        'OCREngine' => '2',
    ]);
    $maskedTestUrl = preg_replace('/([?&]apikey=)[^&]+/i', '$1' . rawurlencode($maskedApiKey), $testUrl);
    if (!is_string($maskedTestUrl) || $maskedTestUrl === '') {
        $maskedTestUrl = $testUrl;
    }

    $docsCurl = curl_init();
    curl_setopt_array($docsCurl, [
        CURLOPT_URL => $docsProbeUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json,text/html,*/*',
        ],
    ]);
    $docsBody = curl_exec($docsCurl);
    $docsError = curl_error($docsCurl);
    $docsProbe = [
        'body' => is_string($docsBody) ? $docsBody : '',
        'error' => $docsError,
        'http_code' => (int) curl_getinfo($docsCurl, CURLINFO_HTTP_CODE),
        'primary_ip' => (string) curl_getinfo($docsCurl, CURLINFO_PRIMARY_IP),
        'total_time' => (float) curl_getinfo($docsCurl, CURLINFO_TOTAL_TIME),
    ];
    curl_close($docsCurl);

    $apiCurl = curl_init();
    curl_setopt_array($apiCurl, [
        CURLOPT_URL => $testUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json,text/html,*/*',
        ],
    ]);
    $apiBody = curl_exec($apiCurl);
    $apiProbe = [
        'body' => is_string($apiBody) ? $apiBody : '',
        'error' => curl_error($apiCurl),
        'http_code' => (int) curl_getinfo($apiCurl, CURLINFO_HTTP_CODE),
        'primary_ip' => (string) curl_getinfo($apiCurl, CURLINFO_PRIMARY_IP),
        'total_time' => (float) curl_getinfo($apiCurl, CURLINFO_TOTAL_TIME),
    ];
    curl_close($apiCurl);

    $rawResponse = $apiProbe['body'];
    $curlError = $apiProbe['error'];
    $httpCode = $apiProbe['http_code'];

    $decoded = is_string($rawResponse) ? json_decode($rawResponse, true) : null;
    $parsedText = trim((string)($decoded['ParsedResults'][0]['ParsedText'] ?? ''));
    $apiError = trim((string)($decoded['ParsedResults'][0]['ErrorMessage'] ?? ''));
    $isErroredOnProcessing = !empty($decoded['IsErroredOnProcessing']);

    $result = [
        'http_code' => $httpCode,
        'status' => 'error',
        'title' => 'Pemeriksaan gagal',
        'message' => 'OCR API tidak merespons seperti yang diharapkan.',
        'details' => [
            'Docs probe HTTP' => (string) $docsProbe['http_code'],
            'Endpoint test' => $maskedTestUrl,
            'Docs probe IP' => $docsProbe['primary_ip'] !== '' ? $docsProbe['primary_ip'] : '-',
            'API probe IP' => $apiProbe['primary_ip'] !== '' ? $apiProbe['primary_ip'] : '-',
            'API response time' => number_format($apiProbe['total_time'], 2) . 's',
        ],
        'sample_text' => '',
        'raw_excerpt' => is_string($rawResponse) ? mb_substr($rawResponse, 0, 400) : '',
    ];

    if ($curlError !== '') {
        $curlErrorHaystack = strtolower($curlError);

        if (strpos($curlErrorHaystack, 'timed out') !== false) {
            $result['title'] = 'Endpoint OCR timeout';
            $result['message'] = 'Website OCR.Space bisa diakses, tetapi endpoint API OCR tidak memberi respons tepat waktu. Ini biasanya bukan bukti limit key, melainkan timeout jalur API / firewall / rate-limit jaringan.';
        } elseif (strpos($curlErrorHaystack, 'ssl') !== false || strpos($curlErrorHaystack, 'certificate') !== false) {
            $result['title'] = 'Masalah SSL / sertifikat';
            $result['message'] = 'Koneksi ke OCR API gagal pada tahap SSL/TLS. Ini biasanya terkait sertifikat, antivirus HTTPS scanning, atau konfigurasi cURL/PHP.';
        } elseif (strpos($curlErrorHaystack, 'could not resolve host') !== false || strpos($curlErrorHaystack, 'name resolution') !== false) {
            $result['title'] = 'DNS gagal resolve';
            $result['message'] = 'Hostname OCR API tidak bisa di-resolve. Ini menunjukkan masalah DNS atau jaringan lokal, bukan limit API key.';
        } elseif (strpos($curlErrorHaystack, 'failed to connect') !== false || strpos($curlErrorHaystack, 'connection refused') !== false) {
            $result['title'] = 'Koneksi ke endpoint gagal';
            $result['message'] = 'Server aplikasi tidak berhasil membuka koneksi ke endpoint OCR. Ini biasanya masalah firewall, proxy, atau jalur jaringan.';
        } else {
            $result['title'] = 'Pemeriksaan gagal';
            $result['message'] = 'cURL error: ' . $curlError;
        }

        $result['details']['cURL error'] = $curlError;
    } elseif ($httpCode >= 200 && $httpCode < 300 && !$isErroredOnProcessing && $parsedText !== '') {
        $result['status'] = 'success';
        $result['title'] = 'OCR API aktif';
        $result['message'] = 'API key merespons normal dan berhasil membaca sample image resmi dari OCR.Space.';
        $result['sample_text'] = $parsedText;
    } else {
        $errorMessage = $apiError;

        if ($errorMessage === '' && is_array($decoded['ErrorMessage'] ?? null)) {
            $errorMessage = implode(' | ', array_map('strval', $decoded['ErrorMessage']));
        }

        if ($errorMessage === '' && isset($decoded['OCRExitCode'])) {
            $errorMessage = 'OCRExitCode: ' . (string)$decoded['OCRExitCode'];
        }

        if ($errorMessage === '' && $httpCode === 401) {
            $errorMessage = 'API key tidak valid atau ditolak.';
        }

        if ($errorMessage === '') {
            $errorMessage = 'Respons tidak mengandung hasil OCR yang valid.';
        }

        $errorHaystack = strtolower($errorMessage);
        foreach (['limit', 'rate', 'quota', 'daily', 'too many'] as $needle) {
            if (strpos($errorHaystack, $needle) !== false) {
                $result['status'] = 'warning';
                break;
            }
        }
        $result['title'] = $result['status'] === 'warning'
            ? 'Kemungkinan limit / rate limit tercapai'
            : 'OCR API mengembalikan error';
        $result['message'] = $errorMessage;
    }
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">
        <h1 class="page-title">Status OCR API</h1>
        <p class="page-subtitle">Pemeriksaan cepat API key OCR.Space yang dipakai oleh fitur scan identitas.</p>

        <div class="card card-section">
            <div class="card-header">Konfigurasi</div>
            <div class="card-body">
                <div class="grid gap-3 md:grid-cols-2">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="meta-text-xs">API Key</div>
                        <div class="font-mono text-sm text-slate-900"><?= htmlspecialchars($maskedApiKey) ?></div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="meta-text-xs">Endpoint</div>
                        <div class="text-sm text-slate-900"><?= htmlspecialchars(EMS_OCR_API_ENDPOINT) ?></div>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-3">
                    <a href="?run=1" class="btn-primary"><?= ems_icon('arrow-path', 'h-4 w-4') ?> Cek Status OCR</a>
                    <a href="https://ocr.space/OCRAPI" target="_blank" rel="noopener noreferrer" class="btn-secondary"><?= ems_icon('arrow-right-on-rectangle', 'h-4 w-4') ?> Buka Docs OCR.Space</a>
                </div>
            </div>
        </div>

        <?php if ($result !== null): ?>
            <?php
            $variantClass = $result['status'] === 'success'
                ? 'notice-box notice-info'
                : ($result['status'] === 'warning' ? 'notice-box notice-warning' : 'notice-box notice-danger');
            ?>
            <div class="<?= $variantClass ?>" style="display:block;">
                <strong><?= htmlspecialchars($result['title']) ?></strong><br><br>
                <?= htmlspecialchars($result['message']) ?><br><br>
                <strong>Docs Probe HTTP:</strong> <?= htmlspecialchars((string)($result['details']['Docs probe HTTP'] ?? '-')) ?><br>
                <strong>Docs Probe IP:</strong> <?= htmlspecialchars((string)($result['details']['Docs probe IP'] ?? '-')) ?><br>
                <strong>HTTP Code:</strong> <?= (int)$result['http_code'] ?><br>
                <strong>API Probe IP:</strong> <?= htmlspecialchars((string)($result['details']['API probe IP'] ?? '-')) ?><br>
                <strong>API Response Time:</strong> <?= htmlspecialchars((string)($result['details']['API response time'] ?? '-')) ?><br>
                <strong>Endpoint Test:</strong> <span class="font-mono text-xs"><?= htmlspecialchars($result['details']['Endpoint test']) ?></span>
                <?php if (!empty($result['details']['cURL error'])): ?>
                    <br><strong>cURL Error:</strong> <?= htmlspecialchars((string)$result['details']['cURL error']) ?>
                <?php endif; ?>

                <?php if ($result['sample_text'] !== ''): ?>
                    <br><br>
                    <strong>Sample OCR Result:</strong><br>
                    <pre class="mt-2 rounded-xl bg-slate-950 p-3 text-xs text-slate-100 whitespace-pre-wrap"><?= htmlspecialchars($result['sample_text']) ?></pre>
                <?php elseif ($result['raw_excerpt'] !== ''): ?>
                    <br><br>
                    <strong>Response Excerpt:</strong><br>
                    <pre class="mt-2 rounded-xl bg-slate-950 p-3 text-xs text-slate-100 whitespace-pre-wrap"><?= htmlspecialchars($result['raw_excerpt']) ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card card-section">
            <div class="card-header">Cara Baca Hasil</div>
            <div class="card-body">
                <ul class="list-disc list-inside text-sm text-slate-700 space-y-2">
                    <li><strong>OCR API aktif</strong>: API key masih bisa dipakai normal.</li>
                    <li><strong>Kemungkinan limit / rate limit tercapai</strong>: biasanya free plan sedang kena batas request harian atau pembatasan IP.</li>
                    <li><strong>OCR API mengembalikan error</strong>: bisa karena API key salah, jaringan bermasalah, atau provider sedang gangguan.</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
