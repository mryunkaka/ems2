<?php

date_default_timezone_set('Asia/Jakarta');

$repo = '/home/hark8423/public_html/rh-ems2';
$log = '/home/hark8423/git-deploy.log';
$git = is_executable('/usr/bin/git') ? '/usr/bin/git' : 'git';

if (!is_dir($repo . '/.git')) {
    exit(1);
}

chdir($repo);

$old = trim((string) shell_exec($git . ' rev-parse HEAD 2>&1'));
if ($old === '' || preg_match('/fatal:|not found|unable to/i', $old)) {
    exit(1);
}

$pullOutput = trim((string) shell_exec($git . ' pull origin main 2>&1'));
$new = trim((string) shell_exec($git . ' rev-parse HEAD 2>&1'));

if (
    $new === '' ||
    preg_match('/fatal:|not found|unable to/i', $new) ||
    $old === $new
) {
    exit(0);
}

$commits = trim((string) shell_exec(
    $git . ' log ' . escapeshellarg($old . '..' . $new) . " --pretty=format:'%h | %an | %s' 2>&1"
));

if ($commits === '' || preg_match('/fatal:|not found|unable to/i', $commits)) {
    $singleCommit = trim((string) shell_exec(
        $git . " log -1 --pretty=format:'%h | %an | %s' " . escapeshellarg($new) . ' 2>&1'
    ));

    if ($singleCommit === '' || preg_match('/fatal:|not found|unable to/i', $singleCommit)) {
        exit(0);
    }

    $commits = $singleCommit;
}

$date = date('Y-m-d H:i:s');

foreach (preg_split('/\r\n|\r|\n/', $commits) as $commit) {
    $commit = trim((string) $commit);
    if ($commit === '') {
        continue;
    }

    file_put_contents($log, $date . ' - Deploy ' . $commit . PHP_EOL, FILE_APPEND);
}
