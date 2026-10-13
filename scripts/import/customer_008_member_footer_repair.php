<?php
/**
 * Repair the two retired footer routes in the imported 008 member homepage.
 * Only the reviewed DIY row and its private 008 cache keys may change.
 * Usage on the named server: php customer_008_member_footer_repair.php check|apply|verify
 */
declare(strict_types=1);

require_once __DIR__ . '/customer_008_morestore_mall_apply.php';

const FOOTER_FROM_VERSION = '008-morestore-mall-20261013-r4';
const FOOTER_TO_VERSION = '008-morestore-mall-20261013-r5';
const FOOTER_FROM_SHA256 = '6ed38d50208a62ce036b6ceede75466e5b4005deafee54174ef53b8fabc2c203';

/** Build exactly the two requested route corrections from the reviewed r4 page. */
function footerExpected(): array
{
    [$plan] = mallPlan();
    $value = $plan['homepage']['value'];
    $footer = &$value[array_key_last($value)];
    $routes = [
        1 => ['美疗师', '/pages/technician/index?source=home', '/pages/activity/therapist_list/index'],
        3 => ['项目', '/pages/goods/item_work/index?status=0', '/pages/users/user_card_list/index'],
    ];
    foreach ($routes as $index => [$name, $old, $new]) {
        if (($footer['menuList'][$index]['name'] ?? '') !== $name
            || ($footer['menuList'][$index]['link'] ?? '') !== $old) {
            throw new RuntimeException('Reviewed footer route changed: ' . $name);
        }
        $footer['menuList'][$index]['link'] = $new;
    }
    unset($footer);
    return $value;
}

/** Refuse a write unless the live row is byte-for-byte the reviewed r4 page. */
function footerPreflight(PDO $db): array
{
    $rows = $db->query('SELECT id,version,value,default_value FROM eb_diy ORDER BY id')->fetchAll();
    if (count($rows) !== 1 || (int)$rows[0]['id'] !== 1
        || $rows[0]['version'] !== FOOTER_FROM_VERSION
        || hash('sha256', (string)$rows[0]['value']) !== FOOTER_FROM_SHA256
        || $rows[0]['default_value'] !== $rows[0]['value']) {
        throw new RuntimeException('008 DIY does not match the reviewed r4 page');
    }
    $expected = json_encode(footerExpected(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return [$rows[0], $expected];
}

/** Re-read 008 after the write so stale cache or a partial update cannot count as success. */
function footerVerify(PDO $db): array
{
    $rows = $db->query('SELECT id,version,value,default_value FROM eb_diy ORDER BY id')->fetchAll();
    $expected = json_encode(footerExpected(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (count($rows) !== 1 || (int)$rows[0]['id'] !== 1
        || $rows[0]['version'] !== FOOTER_TO_VERSION
        || $rows[0]['value'] !== $expected || $rows[0]['default_value'] !== $expected) {
        throw new RuntimeException('008 DIY footer verification failed');
    }
    $decoded = json_decode($expected, true, 512, JSON_THROW_ON_ERROR);
    $footer = end($decoded);
    return ['diy_id' => 1, 'version' => FOOTER_TO_VERSION,
        'sha256' => hash('sha256', $expected),
        'links' => array_map(static fn(array $item): array => [$item['name'], $item['link']], $footer['menuList'])];
}

/** Keep preflight read-only and serialize the 008-only update with the mall lock. */
function footerMain(array $argv): void
{
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['check', 'apply', 'verify'], true)) {
        throw new RuntimeException('Use check, apply or verify');
    }
    $db = mallDb();
    if ($mode === 'verify') {
        echo json_encode(footerVerify($db), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
        return;
    }
    [$row, $expected] = footerPreflight($db);
    if ($mode === 'check') {
        echo json_encode(['target' => 'mohe_008', 'from' => FOOTER_FROM_VERSION,
            'to' => FOOTER_TO_VERSION, 'diy_id' => (int)$row['id'],
            'new_sha256' => hash('sha256', $expected)], JSON_PRETTY_PRINT), "\n";
        return;
    }
    $lock = $db->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute(['mohe_008_mall_diy_20261013']);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('008 DIY import lock is busy');
    try {
        footerPreflight($db);
        $backup = mallBackup($db);
        $db->beginTransaction();
        try {
            footerPreflight($db);
            $update = $db->prepare('UPDATE eb_diy SET value=?,default_value=?,version=?,update_time=?
                WHERE id=1 AND version=? AND SHA2(value,256)=?');
            $update->execute([$expected, $expected, FOOTER_TO_VERSION, time(),
                FOOTER_FROM_VERSION, FOOTER_FROM_SHA256]);
            if ($update->rowCount() !== 1) throw new RuntimeException('008 DIY changed during footer update');
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        mallInvalidateCache();
        echo json_encode(['target' => 'mohe_008', 'backup' => $backup,
            'verified' => footerVerify($db)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute(['mohe_008_mall_diy_20261013']);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        footerMain($argv);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
