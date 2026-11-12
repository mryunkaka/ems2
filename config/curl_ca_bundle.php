<?php

// Keep TLS verification enabled while supplying a local CA bundle when PHP has none configured.
if (!function_exists('emsFindCaBundlePath')) {
    function emsFindCaBundlePath(): ?string
    {
        static $resolved = false;
        static $path = null;
        if ($resolved) return $path;
        $resolved = true;

        $localAppData = getenv('LOCALAPPDATA');
        $candidates = [
            ini_get('curl.cainfo') ?: null,
            ini_get('openssl.cafile') ?: null,
            $localAppData ? rtrim((string) $localAppData, '\\/') . DIRECTORY_SEPARATOR . 'Programs' . DIRECTORY_SEPARATOR . 'Git' . DIRECTORY_SEPARATOR . 'usr' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'ca-bundle.crt' : null,
            'C:\\Program Files\\Git\\usr\\ssl\\certs\\ca-bundle.crt',
            'C:\\Program Files\\Git\\mingw64\\ssl\\certs\\ca-bundle.crt',
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate && is_file($candidate)) {
                $path = $candidate;
                break;
            }
        }
        return $path;
    }
}
