<?php
declare(strict_types=1);

$aiQueueTests = [];

function aiQueueTest(string $name, callable $test): void
{
    global $aiQueueTests;
    $aiQueueTests[$name] = $test;
}

function aiQueueAssertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected === $actual) return;
    $detail = $message !== '' ? $message . ': ' : '';
    throw new RuntimeException($detail . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function aiQueueAssertTrue(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

foreach (glob(__DIR__ . '/*_test.php') ?: [] as $testFile) {
    require_once $testFile;
}

$suite = strtolower(trim((string)($argv[1] ?? 'all')));
$selected = array_filter($aiQueueTests, static function (string $name) use ($suite): bool {
    return $suite === 'all' || str_starts_with($name, $suite . '.');
}, ARRAY_FILTER_USE_KEY);

if ($selected === []) {
    fwrite(STDERR, "Tidak ada test untuk suite '{$suite}'.\n");
    exit(2);
}

$failures = 0;
foreach ($selected as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n" . $error->getTraceAsString() . "\n");
    }
}

$total = count($selected);
fwrite($failures === 0 ? STDOUT : STDERR, sprintf("%d/%d test lulus.\n", $total - $failures, $total));
exit($failures === 0 ? 0 : 1);
