<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/** Read only the Git HEAD metadata; never expose paths, config, or credentials. */
function emsDeploymentStatusGitDirectory(string $repositoryRoot): ?string
{
    $gitPath = $repositoryRoot . DIRECTORY_SEPARATOR . '.git';
    if (is_dir($gitPath)) {
        return $gitPath;
    }

    if (!is_file($gitPath)) {
        return null;
    }

    $gitFile = trim((string) @file_get_contents($gitPath));
    if (preg_match('/^gitdir:\s*(.+)$/i', $gitFile, $matches) !== 1) {
        return null;
    }

    $resolved = trim($matches[1]);
    if ($resolved === '') {
        return null;
    }

    if ($resolved[0] !== '/' && preg_match('/^[A-Za-z]:[\\\\\/]/', $resolved) !== 1) {
        $resolved = $repositoryRoot . DIRECTORY_SEPARATOR . $resolved;
    }

    return is_dir($resolved) ? $resolved : null;
}

function emsDeploymentStatusRespond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$repositoryRoot = dirname(__DIR__);
$gitDirectory = emsDeploymentStatusGitDirectory($repositoryRoot);
if ($gitDirectory === null) {
    emsDeploymentStatusRespond(['ok' => false, 'message' => 'Checkout Git tidak ditemukan.'], 503);
}

$head = trim((string) @file_get_contents($gitDirectory . DIRECTORY_SEPARATOR . 'HEAD'));
$commit = '';
$branch = null;

if (preg_match('/^[a-f0-9]{40,64}$/i', $head) === 1) {
    $commit = strtolower($head);
} elseif (preg_match('/^ref:\s*(refs\/heads\/.+)$/', $head, $matches) === 1) {
    $ref = $matches[1];
    $branch = substr($ref, strlen('refs/heads/'));
    $refPath = $gitDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ref);
    if (is_file($refPath)) {
        $candidate = trim((string) @file_get_contents($refPath));
        if (preg_match('/^[a-f0-9]{40,64}$/i', $candidate) === 1) {
            $commit = strtolower($candidate);
        }
    }

    if ($commit === '') {
        $packedRefs = @file($gitDirectory . DIRECTORY_SEPARATOR . 'packed-refs', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach (is_array($packedRefs) ? $packedRefs : [] as $line) {
            if ($line[0] === '#' || $line[0] === '^') {
                continue;
            }
            if (preg_match('/^([a-f0-9]{40,64})\s+' . preg_quote($ref, '/') . '$/i', trim($line), $packedMatch) === 1) {
                $commit = strtolower($packedMatch[1]);
                break;
            }
        }
    }
}

if ($commit === '') {
    emsDeploymentStatusRespond(['ok' => false, 'message' => 'Commit checkout belum dapat dibaca.'], 503);
}

emsDeploymentStatusRespond([
    'ok' => true,
    'branch' => $branch,
    'commit' => $commit,
    'ai_surgery_planner_action_sha256' => hash_file('sha256', $repositoryRoot . DIRECTORY_SEPARATOR . 'dashboard' . DIRECTORY_SEPARATOR . 'ai_surgery_planner_action.php') ?: null,
]);
