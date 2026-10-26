<?php
/**
 * Copy the approved operating morestore shop images to their mapped 008 shops.
 *
 * The 22 source-to-target IDs are the reviewed catalog mapping. Only the 008
 * shop photo and door photo change; Qiniu files stay at their existing URLs.
 */
declare(strict_types=1);

const STORE_MEDIA_SOURCE_ENV = '/www/wwwroot/morestore.cc3798.com/.env';
const STORE_MEDIA_TARGET_ENV = '/www/wwwroot/008.cc3798.com/.env';
const STORE_MEDIA_UPGRADE = '20261013-008-morestore-store-media';
const STORE_MEDIA_ACTION = '008_store_images_20261013';
const STORE_MEDIA_MAP = [
    17 => [118, '苏州 国金店', '国金'],
    25 => [120, '苏州 钻石广场店', '钻石'],
    30 => [115, '苏州 和雍锦园店', '和雍锦园'],
    35 => [113, '苏州 东环路店', '东环'],
    36 => [110, '苏州 春天花园店', '春天'],
    38 => [117, '苏州 平江万达店', '万达'],
    41 => [125, '南通 海门店', '海门店'],
    42 => [122, '无锡 安镇店', '安镇'],
    43 => [124, '无锡 韩立美 玉兰店', '玉兰'],
    44 => [127, '苏州 金茂府店', '苏州 金茂府店'],
    45 => [112, '苏州 东沙湖店', '东沙湖'],
    46 => [128, '渼约新港店', '渼约新港店'],
    47 => [119, '苏州 常熟店', '常熟'],
    49 => [108, '广州 海伦堡店', '海伦堡'],
    50 => [129, '广州 雅居乐店', '广州 雅居乐店'],
    54 => [130, '浙江 富阳店', '浙江 富阳店'],
    55 => [107, '山东 潍坊金宸店', '金宸店'],
    57 => [116, '苏州 木渎天虹店', '木渎天虹'],
    60 => [114, '苏州 欧丽薇店', '欧丽薇'],
    62 => [123, '无锡 滨湖万达店', '滨湖'],
    64 => [126, '郑州 建业店', '建业店'],
    68 => [121, '无锡 韩立美 天安店', '天安'],
];

/** Confirm both database names and ports before permitting a read or write. */
function storeMediaConnect(string $envPath, string $database, int $port): PDO
{
    $env = parse_ini_file($envPath);
    if (!is_array($env) || ($env['DATABASE'] ?? '') !== $database
        || (int)($env['HOSTPORT'] ?? 0) !== $port) {
        throw new RuntimeException('Store media database configuration changed');
    }
    $pdo = new PDO('mysql:host=' . $env['HOSTNAME'] . ';port=' . $env['HOSTPORT']
        . ';dbname=' . $database . ';charset=utf8mb4', $env['USERNAME'], $env['PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $identity = $pdo->query('SELECT DATABASE() db,@@port port')->fetch();
    if (($identity['db'] ?? '') !== $database || (int)($identity['port'] ?? 0) !== $port) {
        throw new RuntimeException('Store media connection reached an unexpected database');
    }
    return $pdo;
}

/** Read only the approved old shops and reject changed status or unknown media. */
function storeMediaSource(PDO $pdo): array
{
    $ids = implode(',', array_keys(STORE_MEDIA_MAP));
    $rows = $pdo->query('SELECT id,name,is_show,is_del,type,image,oblong_image,banner '
        . 'FROM eb_system_store WHERE id IN (' . $ids . ') ORDER BY id')->fetchAll();
    if (count($rows) !== count(STORE_MEDIA_MAP)) {
        throw new RuntimeException('Reviewed source shop set changed');
    }
    $media = [];
    $bannerCount = 0;
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        if (!isset(STORE_MEDIA_MAP[$id]) || $row['name'] !== STORE_MEDIA_MAP[$id][1]
            || (int)$row['is_show'] !== 1 || (int)$row['is_del'] !== 0
            || !in_array((int)$row['type'], [1, 2], true)
            || trim((string)$row['oblong_image']) !== '') {
            throw new RuntimeException('Reviewed old shop status or media fields changed: ' . $id);
        }
        $banner = json_decode((string)$row['banner'], true);
        if (!is_array($banner) || count($banner) > 1) {
            throw new RuntimeException('Old shop banner cannot fit the 008 door photo: ' . $id);
        }
        $image = (string)$row['image'];
        $door = (string)($banner[0] ?? $image);
        foreach ([$image, $door] as $url) {
            if (strlen($url) > 255 || !preg_match('~^https://myqiniu\.cc3798\.com/attach/[A-Za-z0-9_./-]+\.(?:jpg|jpeg|png|webp)$~i', $url)) {
                throw new RuntimeException('Old shop image URL invalid: ' . $id);
            }
        }
        $bannerCount += count($banner);
        $media[$id] = ['image' => $image, 'background_image' => $door];
    }
    if ($bannerCount !== 4) {
        throw new RuntimeException('Reviewed old shop banner count changed');
    }
    return ['media' => $media, 'source_sha256' => hash('sha256', (string)json_encode($rows,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 'banner_count' => $bannerCount];
}

/** Prove a mapped target shop belongs below 渼约 and has no previous photos. */
function storeMediaAssertTargets(PDO $pdo, bool $lock): array
{
    $ids = implode(',', array_map(static function ($entry) { return $entry[0]; }, STORE_MEDIA_MAP));
    $sql = 'SELECT id,name,is_show,is_del,image,background_image FROM eb_system_store '
        . 'WHERE id IN (' . $ids . ') ORDER BY id' . ($lock ? ' FOR UPDATE' : '');
    $rows = $pdo->query($sql)->fetchAll();
    if (count($rows) !== count(STORE_MEDIA_MAP)) {
        throw new RuntimeException('008 mapped shop count changed');
    }
    $byTarget = [];
    foreach (STORE_MEDIA_MAP as $entry) {
        $byTarget[$entry[0]] = $entry[2];
    }
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        if (($byTarget[$id] ?? null) !== $row['name'] || (int)$row['is_show'] !== 1
            || (int)$row['is_del'] !== 0 || (string)$row['image'] !== ''
            || (string)$row['background_image'] !== '') {
            throw new RuntimeException('008 shop changed or already has images: ' . $id);
        }
        $binding = $pdo->prepare('SELECT org_id FROM eb_organization_store WHERE store_id=?');
        $binding->execute([$id]);
        $orgs = $binding->fetchAll();
        if (count($orgs) !== 1) {
            throw new RuntimeException('008 shop organization binding changed: ' . $id);
        }
        $orgId = (int)$orgs[0]['org_id'];
        $seen = [];
        while ($orgId > 0 && $orgId !== 3 && !isset($seen[$orgId])) {
            $seen[$orgId] = true;
            $org = $pdo->prepare('SELECT pid,is_del FROM eb_organization WHERE id=?');
            $org->execute([$orgId]);
            $node = $org->fetch();
            if (!$node || (int)$node['is_del'] !== 0) {
                throw new RuntimeException('008 shop organization missing: ' . $id);
            }
            $orgId = (int)$node['pid'];
        }
        if ($orgId !== 3) {
            throw new RuntimeException('008 mapped shop is outside 渼约: ' . $id);
        }
    }
    return $rows;
}

/** Verify the final values and one audit row per mapped shop. */
function storeMediaVerify(PDO $pdo): array
{
    $marker = $pdo->prepare('SELECT sql_checksum,code_version,result_note FROM eb_database_upgrade_log WHERE upgrade_key=?');
    $marker->execute([STORE_MEDIA_UPGRADE]);
    $markers = $marker->fetchAll();
    $audit = $pdo->prepare('SELECT target_id,after_data FROM eb_organization_change_log '
        . 'WHERE action=? AND request_id=? AND target_type=? ORDER BY target_id');
    $audit->execute([STORE_MEDIA_ACTION, STORE_MEDIA_UPGRADE, 'store_media']);
    $audits = $audit->fetchAll();
    if (count($markers) !== 1 || count($audits) !== count(STORE_MEDIA_MAP)
        || $markers[0]['sql_checksum'] !== hash_file('sha256', __FILE__)) {
        throw new RuntimeException('008 store media upgrade or audit incomplete');
    }
    $lookup = [];
    foreach ($audits as $row) {
        $id = (int)$row['target_id'];
        if (isset($lookup[$id])) {
            throw new RuntimeException('Duplicate 008 store media audit');
        }
        $lookup[$id] = json_decode((string)$row['after_data'], true);
    }
    foreach (STORE_MEDIA_MAP as $sourceId => $entry) {
        $id = $entry[0];
        if (($lookup[$id]['source_id'] ?? 0) !== $sourceId) {
            throw new RuntimeException('008 store media audit mapping changed: ' . $id);
        }
        $stmt = $pdo->prepare('SELECT name,image,background_image FROM eb_system_store WHERE id=?');
        $stmt->execute([$id]);
        $store = $stmt->fetch();
        if (!$store || $store['name'] !== $entry[2]
            || $store['image'] !== ($lookup[$id]['image'] ?? null)
            || $store['background_image'] !== ($lookup[$id]['background_image'] ?? null)) {
            throw new RuntimeException('008 shop photo differs from audit: ' . $id);
        }
    }
    return $markers[0];
}

/** A single 008 transaction writes photos, per-shop audit, and upgrade marker. */
function storeMediaApply(PDO $pdo, array $source, string $expectedSourceSha,
    string $scriptSha, string $commit): void
{
    if (!hash_equals($expectedSourceSha, $source['source_sha256'])
        || !hash_equals($scriptSha, (string)hash_file('sha256', __FILE__))
        || !preg_match('/^[a-f0-9]{40}$/', $commit)) {
        throw new RuntimeException('Reviewed source, script SHA, or local commit mismatch');
    }
    $lock = (int)$pdo->query("SELECT GET_LOCK('008_store_media_import',15)")->fetchColumn();
    if ($lock !== 1) {
        throw new RuntimeException('008 store media migration lock unavailable');
    }
    try {
        $pdo->beginTransaction();
        try {
            $marker = $pdo->prepare('SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key=?');
            $marker->execute([STORE_MEDIA_UPGRADE]);
            if ((int)$marker->fetchColumn() !== 0) {
                throw new RuntimeException('008 store media upgrade already applied');
            }
            storeMediaAssertTargets($pdo, true);
            $update = $pdo->prepare('UPDATE eb_system_store SET image=?,background_image=? '
                . "WHERE id=? AND image='' AND background_image=''");
            $audit = $pdo->prepare('INSERT INTO eb_organization_change_log '
                . '(org_id,action,target_type,target_id,before_data,after_data,remark,operator_id,operator_name,operator_ip,request_id,request_token,add_time) '
                . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $now = time();
            foreach (STORE_MEDIA_MAP as $sourceId => $entry) {
                $id = $entry[0];
                $media = $source['media'][$sourceId];
                $update->execute([$media['image'], $media['background_image'], $id]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('008 shop media update count mismatch: ' . $id);
                }
                $after = json_encode(['source_id' => $sourceId, 'image' => $media['image'],
                    'background_image' => $media['background_image']], JSON_UNESCAPED_UNICODE);
                $audit->execute([3, STORE_MEDIA_ACTION, 'store_media', $id,
                    '{"image":"","background_image":"}', $after, '008 渼约门店图片补导',
                    0, 'Codex', '', STORE_MEDIA_UPGRADE, '', $now]);
            }
            $note = json_encode(['stores' => count(STORE_MEDIA_MAP), 'source_sha256' => $expectedSourceSha,
                'distinct_banners' => $source['banner_count'], 'backup' => 'none_by_product_manager_request']);
            $upgrade = $pdo->prepare('INSERT INTO eb_database_upgrade_log '
                . '(upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note,created_at) '
                . 'VALUES (?,?,?,?,?,?,?,?,?)');
            $upgrade->execute([STORE_MEDIA_UPGRADE, '008 渼约门店图片补导',
                '后端代码/database/upgrades/2026-10-13-008渼约门店图片补导/01-执行.php',
                $scriptSha, $commit, date('Y-m-d H:i:s', $now), 'Codex', $note,
                date('Y-m-d H:i:s', $now)]);
            storeMediaVerify($pdo);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('008_store_media_import')");
    }
}

try {
    $command = (string)($argv[1] ?? '');
    if (!in_array($command, ['check', 'apply', 'verify'], true)) {
        throw new RuntimeException('Usage: php 01-执行.php check|apply|verify [source-sha script-sha commit]');
    }
    $target = storeMediaConnect(STORE_MEDIA_TARGET_ENV, 'mohe_008', 3307);
    if ($command === 'verify') {
        $marker = storeMediaVerify($target);
        echo json_encode(['ok' => true, 'mode' => 'verify', 'stores' => count(STORE_MEDIA_MAP),
            'commit' => $marker['code_version']]) . PHP_EOL;
        exit(0);
    }
    $source = storeMediaSource(storeMediaConnect(STORE_MEDIA_SOURCE_ENV, 'morestore', 3306));
    $marker = $target->prepare('SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key=?');
    $marker->execute([STORE_MEDIA_UPGRADE]);
    if ((int)$marker->fetchColumn() !== 0) {
        throw new RuntimeException('008 store media upgrade already applied');
    }
    storeMediaAssertTargets($target, false);
    if ($command === 'check') {
        echo json_encode(['ok' => true, 'mode' => 'check', 'stores' => count(STORE_MEDIA_MAP),
            'banner_photos' => $source['banner_count'], 'source_sha256' => $source['source_sha256']]) . PHP_EOL;
    } else {
        storeMediaApply($target, $source, (string)($argv[2] ?? ''),
            (string)($argv[3] ?? ''), (string)($argv[4] ?? ''));
        echo json_encode(['ok' => true, 'mode' => 'apply', 'stores' => count(STORE_MEDIA_MAP)]) . PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, '008 store media migration stopped: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
