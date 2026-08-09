<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$scanner = $root . '/tests/mobile-auth/scripts/scan-identity-writers.php';
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scanner);
$output = [];
$exit = 0;
exec($command, $output, $exit);
$report = json_decode(implode("\n", $output), true);

if ($exit === 0) {
    fwrite(STDERR, "FAIL MA-CI-SCAN scanner_must_block_unmigrated_writers\n");
    exit(1);
}
if (!is_array($report) || ($report['failureCount'] ?? 0) <= 0) {
    fwrite(STDERR, "FAIL MA-CI-SCAN scanner_report_invalid\n");
    exit(1);
}

$symbols = array_unique(array_map(static function (array $candidate): string {
    return $candidate['sourcePath'] . '::' . $candidate['symbol'];
}, $report['candidates'] ?? []));
foreach ([
    '后端代码/app/services/user/UserServices.php::updateInfo',
] as $expected) {
    if (!in_array($expected, $symbols, true)) {
        fwrite(STDERR, "FAIL MA-CI-SCAN missing={$expected}\n");
        exit(1);
    }
}

$failures = array_map(static function (array $candidate): string {
    return $candidate['sourcePath'] . '::' . $candidate['symbol'];
}, $report['failures'] ?? []);
if (in_array('后端代码/app/services/user/UserServices.php::setUserInfo', $failures, true)) {
    fwrite(STDERR, "FAIL MA-CI-SCAN canonical_set_user_info_still_blocked\n");
    exit(1);
}

echo "PASS MA-CI-SCAN expected unmigrated writers are blocking\n";
