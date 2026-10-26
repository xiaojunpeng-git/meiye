<?php
declare(strict_types=1);

/**
 * Conservative identity-writer scanner.
 *
 * This scanner intentionally reports an uncertain writer rather than treating
 * an unrecognised payload as harmless. A candidate is only released after its
 * manifest entry names the source method and the source calls the canonical
 * identity service.
 */
const REQUIRED_MANIFEST_FIELDS = [
    'id', 'sourcePath', 'symbol', 'operation', 'canonicalMethod', 'testEvidence', 'legacyDisposition'
];

$repo = dirname(__DIR__, 3);
$appRoot = $repo . '/后端代码/app';
$manifestPath = $repo . '/tests/mobile-auth/identity-writer-manifest.json';
$manifest = json_decode((string)file_get_contents($manifestPath), true);
if (!is_array($manifest) || !is_array($manifest['entries'] ?? null)) {
    fwrite(STDERR, "IDENTITY_WRITER_MANIFEST_INVALID\n");
    exit(2);
}

$manifestByMethod = [];
$manifestFailures = [];
foreach ($manifest['entries'] as $entry) {
    if (!is_array($entry) || array_keys($entry) !== REQUIRED_MANIFEST_FIELDS) {
        $manifestFailures[] = ['rule' => 'MANIFEST_FIELDS', 'entry' => $entry];
        continue;
    }
    $manifestByMethod[$entry['sourcePath'] . '::' . $entry['symbol']] = $entry;
}

function identityWriterSymbol(array $lines, int $lineNumber): string
{
    $symbol = '<file-scope>';
    for ($index = 0; $index < $lineNumber; $index++) {
        if (preg_match('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $lines[$index], $matches)) {
            $symbol = $matches[1];
        }
    }
    return $symbol;
}

function identityWriterFunctionSlice(array $lines, string $symbol): string
{
    $started = false;
    $braceDepth = 0;
    $slice = [];
    foreach ($lines as $line) {
        if (!$started && preg_match('/function\s+' . preg_quote($symbol, '/') . '\s*\(/', $line)) {
            $started = true;
        }
        if (!$started) {
            continue;
        }
        $slice[] = $line;
        $braceDepth += substr_count($line, '{') - substr_count($line, '}');
        if ($braceDepth === 0 && count($slice) > 1) {
            break;
        }
    }
    return implode("\n", $slice);
}

$candidates = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || substr($file->getFilename(), -4) !== '.php') {
        continue;
    }
    $sourcePath = substr($file->getPathname(), strlen($repo . '/'));
    if ($sourcePath === '后端代码/app/services/user/CanonicalUserIdentityServices.php') {
        continue;
    }
    $content = (string)file_get_contents($file->getPathname());
    $lines = preg_split('/\R/', $content);
    $isCoreUserService = in_array($sourcePath, [
        '后端代码/app/services/user/UserServices.php',
        '后端代码/app/services/user/LoginServices.php'
    ], true);

    foreach ($lines as $offset => $line) {
        $lineNumber = $offset + 1;
        $rule = null;
        if ($isCoreUserService && preg_match('/\[\s*[\'\"]phone[\'\"]\s*\]\s*=/', $line)) {
            $rule = 'CORE_USER_SERVICE_PHONE_WRITE';
        }
        if ($rule === null && preg_match('/Db::(?:name\(\s*[\'\"]user[\'\"]\s*\)|table\(\s*[\'\"]eb_user[\'\"]\s*\))/', $line)) {
            $window = implode("\n", array_slice($lines, $offset, 12));
            if (preg_match('/[\'\"]phone[\'\"]/', $window)) {
                $rule = 'DIRECT_EB_USER_PHONE_WRITE';
            }
        }
        if ($rule === null) {
            continue;
        }
        $symbol = identityWriterSymbol($lines, $offset);
        $candidates[] = [
            'sourcePath' => $sourcePath,
            'symbol' => $symbol,
            'line' => $lineNumber,
            'rule' => $rule,
        ];
    }

    if ($isCoreUserService) {
        foreach ($lines as $offset => $line) {
            if (!preg_match('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $matches)) {
                continue;
            }
            $symbol = $matches[1];
            $functionBody = identityWriterFunctionSlice($lines, $symbol);
            if (!preg_match('/[\'\"]phone[\'\"]\s*=>/', $functionBody)
                || !preg_match('/->(?:save|update|create|insert|insertGetId|insertAll)\s*\(/', $functionBody)) {
                continue;
            }
            $candidates[] = [
                'sourcePath' => $sourcePath,
                'symbol' => $symbol,
                'line' => $offset + 1,
                'rule' => 'CORE_USER_SERVICE_PHONE_WRITE',
            ];
        }
    }
}

$deduplicated = [];
foreach ($candidates as $candidate) {
    $key = $candidate['sourcePath'] . '::' . $candidate['symbol'] . '::' . $candidate['rule'];
    $deduplicated[$key] = $candidate;
}
$candidates = array_values($deduplicated);

$failures = $manifestFailures;
foreach ($candidates as $candidate) {
    $key = $candidate['sourcePath'] . '::' . $candidate['symbol'];
    $entry = $manifestByMethod[$key] ?? null;
    if (!$entry || $entry['legacyDisposition'] !== 'CANONICAL_ADAPTED') {
        $candidate['rule'] = 'UNREGISTERED_IDENTITY_WRITER';
        $failures[] = $candidate;
        continue;
    }
    $source = (string)file_get_contents($repo . '/' . $candidate['sourcePath']);
    $lines = preg_split('/\R/', $source);
    $functionBody = identityWriterFunctionSlice($lines, $candidate['symbol']);
    if (strpos($functionBody, 'CanonicalUserIdentityServices') === false) {
        $candidate['rule'] = 'CANONICAL_CALL_MISSING';
        $failures[] = $candidate;
    }
}

echo json_encode([
    'contractVersion' => 'mobile-auth-identity-writer-scan-v1',
    'candidateCount' => count($candidates),
    'candidates' => $candidates,
    'failureCount' => count($failures),
    'failures' => $failures,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 1);
