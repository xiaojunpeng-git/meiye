<?php
declare(strict_types=1);

/**
 * One-time, 008-only member password reset requested by the product manager.
 * The H5 password contract stores md5(plaintext) in eb_user.pwd. This script
 * never writes a plaintext password to the database or backup. It does not
 * change phone identities, staff accounts, balances, points, or rights.
 *
 * Usage: php customer_008_reset_member_passwords_20261013.php check|apply|verify
 *   --env-file=/www/wwwroot/008.cc3798.com/.env --password-file=/root/<private-file>
 *   [--baseline-sha256=<check output> --backup-dir=/www/backups/008.cc3798.com/<batch>]
 */

const M008_MEMBER_COUNT = 5722;
const M008_TARGET_PASSWORD_SHA256 = '6ca13d52ca70c883e0f0bb101e425a89e8624de51db2d2392593af6a84118090';
const M008_EXPECTED_AFTER_SHA256 = '3354c59e9e40a2188c1df38d29dca5a40386b05f95176ed890333794ae52a5d8';

/** Require explicit CLI options so a wrong target or unreviewed snapshot fails closed. */
function m008Option(array $argv, string $name, bool $required = true): string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) return substr($arg, strlen($prefix));
    }
    if ($required) throw new RuntimeException('Missing --' . $name);
    return '';
}

/** The shared target password stays in a root-only execution file, never Git or logs. */
function m008TargetHash(string $file): string
{
    if (strpos($file, '/root/m008-member-password-reset-20261013.') !== 0
        || !is_file($file) || (fileperms($file) & 0077) !== 0) {
        throw new RuntimeException('Private 008 password file unavailable');
    }
    $plain = rtrim((string)file_get_contents($file), "\r\n");
    if (!hash_equals(M008_TARGET_PASSWORD_SHA256, hash('sha256', $plain))) {
        throw new RuntimeException('Target password differs from approved value');
    }
    return md5($plain);
}

/** Read only the dedicated 008 credential file and prove the connected DB identity. */
function m008Connect(string $envFile): PDO
{
    if ($envFile !== '/www/wwwroot/008.cc3798.com/.env') {
        throw new RuntimeException('Refusing a non-008 environment path');
    }
    $env = parse_ini_file($envFile);
    if (!is_array($env) || ($env['DATABASE'] ?? '') !== 'mohe_008'
        || (int)($env['HOSTPORT'] ?? 0) !== 3307) {
        throw new RuntimeException('Refusing a non-008 database configuration');
    }
    $db = new PDO(
        'mysql:host=' . $env['HOSTNAME'] . ';port=3307;dbname=mohe_008;charset=utf8mb4',
        $env['USERNAME'], $env['PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $identity = $db->query('SELECT DATABASE() db, @@port port')->fetch();
    if (($identity['db'] ?? '') !== 'mohe_008' || (int)($identity['port'] ?? 0) !== 3307) {
        throw new RuntimeException('Connected database identity differs');
    }
    return $db;
}

/** Lock member rows before calculating the fingerprint used by the write guard. */
function m008Rows(PDO $db, bool $forUpdate): array
{
    $sql = 'SELECT uid,pwd,status FROM eb_user ORDER BY uid' . ($forUpdate ? ' FOR UPDATE' : '');
    $rows = $db->query($sql)->fetchAll();
    if (count($rows) !== M008_MEMBER_COUNT) {
        throw new RuntimeException('Unexpected member count');
    }
    $mapped = (int)$db->query('SELECT COUNT(DISTINCT target_uid) FROM mig_morestore_20261013_member_map')
        ->fetchColumn();
    $unmapped = (int)$db->query('SELECT COUNT(*) FROM eb_user u WHERE NOT EXISTS
        (SELECT 1 FROM mig_morestore_20261013_member_map m WHERE m.target_uid=u.uid)')
        ->fetchColumn();
    if ($mapped !== M008_MEMBER_COUNT || $unmapped !== 0) {
        throw new RuntimeException('Member set differs from the approved import');
    }
    foreach ($rows as $row) {
        if ((int)$row['uid'] <= 0 || preg_match('/^[a-f0-9]{32}$/D', (string)$row['pwd']) !== 1
            || (int)$row['status'] !== 1) {
            throw new RuntimeException('Member status or password format differs');
        }
    }
    return $rows;
}

/** Fingerprint UID plus existing hash, without printing individual credential hashes. */
function m008Summary(array $rows, string $target): array
{
    $ctx = hash_init('sha256');
    $already = 0;
    foreach ($rows as $row) {
        hash_update($ctx, $row['uid'] . ':' . $row['pwd'] . "\n");
        if (hash_equals($target, (string)$row['pwd'])) $already++;
    }
    return [
        'members' => count($rows),
        'already_target' => $already,
        'change_needed' => count($rows) - $already,
        'baseline_sha256' => hash_final($ctx),
    ];
}

/** Keep original hashes in a private, unique 008 rollback point before any update. */
function m008Backup(array $rows, string $dir, string $baseline): array
{
    $prefix = '/www/backups/008.cc3798.com/';
    if (strpos($dir, $prefix) !== 0 || strpos(substr($dir, strlen($prefix)), '/') !== false
        || file_exists($dir) || !mkdir($dir, 0700) || !chmod($dir, 0700)) {
        throw new RuntimeException('Backup directory is unsafe or already exists');
    }
    $file = $dir . '/member-passwords-before.tsv';
    $handle = fopen($file, 'x');
    if ($handle === false || !chmod($file, 0600)) throw new RuntimeException('Private backup unavailable');
    try {
        foreach ($rows as $row) {
            $line = $row['uid'] . "\t" . $row['pwd'] . "\n";
            if (fwrite($handle, $line) !== strlen($line)) {
                throw new RuntimeException('Backup write failed');
            }
        }
        if (!fflush($handle)) throw new RuntimeException('Backup flush failed');
    } finally {
        fclose($handle);
    }
    $backupSha = hash_file('sha256', $file);
    if (!is_string($backupSha)) throw new RuntimeException('Backup checksum unavailable');
    $meta = [
        'target' => 'mohe_008:3307',
        'member_count' => count($rows),
        'baseline_sha256' => $baseline,
        'backup_sha256' => $backupSha,
        'created_at' => date(DATE_ATOM),
    ];
    $metaFile = $dir . '/manifest.json';
    if (file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false
        || !chmod($metaFile, 0600)) {
        throw new RuntimeException('Backup manifest unavailable');
    }
    return $meta;
}

/** Apply the single-column update inside one transaction with exact row counts. */
function m008Apply(PDO $db, string $target, string $expected, string $backupDir): array
{
    if (preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1) {
        throw new RuntimeException('Invalid approved baseline fingerprint');
    }
    if ((int)$db->query("SELECT GET_LOCK('mohe_008_member_password_reset_20261013', 10)")->fetchColumn() !== 1) {
        throw new RuntimeException('Reset lock unavailable');
    }
    try {
        $db->beginTransaction();
        $rows = m008Rows($db, true);
        $before = m008Summary($rows, $target);
        if (!hash_equals($expected, $before['baseline_sha256'])) {
            throw new RuntimeException('Approved member password baseline changed');
        }
        $backup = m008Backup($rows, $backupDir, $expected);
        $update = $db->prepare('UPDATE eb_user SET pwd=? WHERE pwd<>?');
        $update->execute([$target, $target]);
        if ($update->rowCount() !== $before['change_needed']) {
            throw new RuntimeException('Changed password row count differs');
        }
        $after = m008Summary(m008Rows($db, false), $target);
        if ($after['already_target'] !== M008_MEMBER_COUNT || $after['change_needed'] !== 0
            || !hash_equals(M008_EXPECTED_AFTER_SHA256, $after['baseline_sha256'])) {
            throw new RuntimeException('Post-update member password check failed');
        }
        $db->commit();
        return ['result' => 'APPLIED', 'before' => $before, 'after' => $after,
            'backup_sha256' => $backup['backup_sha256'], 'backup_dir' => $backupDir];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    } finally {
        $db->query("SELECT RELEASE_LOCK('mohe_008_member_password_reset_20261013')");
    }
}

try {
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['check', 'apply', 'verify'], true)) {
        throw new RuntimeException('Mode must be check, apply, or verify');
    }
    $target = m008TargetHash(m008Option($argv, 'password-file'));
    $db = m008Connect(m008Option($argv, 'env-file'));
    if ($mode === 'apply') {
        $result = m008Apply($db, $target, m008Option($argv, 'baseline-sha256'),
            m008Option($argv, 'backup-dir'));
    } else {
        $summary = m008Summary(m008Rows($db, false), $target);
        if ($mode === 'verify' && ($summary['already_target'] !== M008_MEMBER_COUNT
            || !hash_equals(M008_EXPECTED_AFTER_SHA256, $summary['baseline_sha256']))) {
            throw new RuntimeException('Some member passwords are not the approved target');
        }
        $result = ['result' => strtoupper($mode), 'summary' => $summary];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, '008 member password reset failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
