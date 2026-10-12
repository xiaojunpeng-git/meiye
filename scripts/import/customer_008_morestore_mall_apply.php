<?php
/**
 * Restore the 008 member mall homepage after its imported product catalogue.
 * The old eight-module layout uses the current H5 component schema, while all 16
 * homepage/navigation images are served from 008's own static directory.
 *
 * Usage on the named server: php customer_008_morestore_mall_apply.php check|apply|repair-check|repair|verify
 */
declare(strict_types=1);

const MALL_ENV = '/www/wwwroot/008.cc3798.com/.env';
const MALL_PUBLIC = '/www/wwwroot/008.cc3798.com/public';
const MALL_BACKUP_DIR = '/www/backups/008.cc3798.com';
const MALL_PLAN = __DIR__ . '/customer_008_morestore_mall_homepage_plan.json';
const MALL_ASSETS = __DIR__ . '/customer_008_morestore_mall_assets.json';
const MALL_VERSION = '008-morestore-mall-20261013-r4';
const MALL_PREVIOUS_VERSION = '008-morestore-mall-20261013-r3';
const MALL_PREVIOUS_VALUE_SHA256 = 'e01d19b530667076159c44385c5505db8766d448e800cafefc0f7be4657299dd';

/** Bind every write to the 008 database and its historical MySQL 3307 port. */
function mallDb(): PDO
{
    $env = parse_ini_file(MALL_ENV, true);
    $database = $env['DATABASE'] ?? [];
    if (($database['DATABASE'] ?? '') !== 'mohe_008'
        || (int)($database['HOSTPORT'] ?? 0) !== 3307) {
        throw new RuntimeException('008 database environment identity changed');
    }
    $db = new PDO('mysql:host=' . $database['HOSTNAME']
        . ';port=3307;dbname=mohe_008;charset=utf8mb4',
        $database['USERNAME'], $database['PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    $identity = $db->query('SELECT DATABASE() AS db, @@port AS port')->fetch();
    if (($identity['db'] ?? '') !== 'mohe_008' || (int)($identity['port'] ?? 0) !== 3307) {
        throw new RuntimeException('Connected database is not 008');
    }
    return $db;
}

/** Require one reviewed modern homepage, footer and a closed set of assets. */
function mallPlan(): array
{
    $plan = json_decode((string)file_get_contents(MALL_PLAN), true, 512, JSON_THROW_ON_ERROR);
    $manifest = json_decode((string)file_get_contents(MALL_ASSETS), true, 512, JSON_THROW_ON_ERROR);
    if (($plan['version'] ?? '') !== MALL_VERSION
        || ($manifest['plan_version'] ?? '') !== MALL_VERSION
        || ($manifest['target_instance'] ?? '') !== '008.cc3798.com'
        || count($manifest['assets'] ?? []) !== 16
        || count($plan['homepage']['value'] ?? []) !== 9
        || count($plan['navigation']['menuList'] ?? []) !== 5
        || ($plan['navigation']['name'] ?? '') !== 'pageFoot') {
        throw new RuntimeException('Reviewed 008 mall scope changed');
    }
    $names = array_column(array_values($plan['homepage']['value']), 'name');
    if ($names !== ['headerSerch', 'swiperBg', 'menus', 'newVip', 'coupon',
            'bargain', 'seckill', 'promotionList', 'pageFoot']
        || end($plan['homepage']['value']) !== $plan['navigation']) {
        throw new RuntimeException('Homepage component contract changed');
    }
    $paths = [];
    foreach ($manifest['assets'] as $asset) {
        $path = $asset['target_path'] ?? '';
        if (!preg_match('~^/static/mall/008-20261013/[0-9a-f]{20}\\.(png|jpe?g|webp|gif)$~', $path)
            || isset($paths[$path]) || !preg_match('/^[0-9a-f]{64}$/', (string)($asset['sha256'] ?? ''))
            || (int)($asset['bytes'] ?? 0) <= 0) {
            throw new RuntimeException('Asset manifest contains an invalid path or digest');
        }
        $paths[$path] = true;
    }
    $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    preg_match_all('~/static/mall/008-20261013/[0-9a-f]{20}\\.(?:png|jpe?g|webp|gif)~', $encoded, $matched);
    if (array_diff(array_keys($paths), $matched[0]) || array_diff($matched[0], array_keys($paths))) {
        throw new RuntimeException('Plan images differ from reviewed asset manifest');
    }
    return [$plan, $manifest];
}

/** Verify deployment bytes before making the page reference any local image. */
function mallCheckFiles(array $manifest): void
{
    foreach ($manifest['assets'] as $asset) {
        $path = MALL_PUBLIC . $asset['target_path'];
        if (!is_file($path) || filesize($path) !== (int)$asset['bytes']
            || hash_file('sha256', $path) !== $asset['sha256']) {
            throw new RuntimeException('008 static image missing or changed: ' . $asset['target_path']);
        }
    }
}

/** Require the intended empty DIY state, leaving any other instance untouched. */
function mallPreflight(PDO $db): void
{
    if ((int)$db->query('SELECT COUNT(*) FROM eb_diy')->fetchColumn() !== 0) {
        throw new RuntimeException('008 DIY is no longer empty; a new plan is required');
    }
    foreach ([158, 318, 867] as $id) {
        $query = $db->prepare('SELECT COUNT(*) FROM eb_store_product WHERE id=? AND is_del=0 AND is_show=1');
        $query->execute([$id]);
        if ((int)$query->fetchColumn() !== 1) {
            throw new RuntimeException('Homepage banner product changed: ' . $id);
        }
    }
}

/** Limit the follow-up to the one retired coupon route in the imported 008 page. */
function mallRepairPreflight(PDO $db, array $plan): array
{
    $row = $db->query('SELECT id,version,value FROM eb_diy ORDER BY id')->fetch();
    if (!$row || (int)$db->query('SELECT COUNT(*) FROM eb_diy')->fetchColumn() !== 1
        || (int)$row['id'] !== 1 || $row['version'] !== MALL_PREVIOUS_VERSION
        || hash('sha256', (string)$row['value']) !== MALL_PREVIOUS_VALUE_SHA256) {
        throw new RuntimeException('008 DIY no longer matches the imported r3 page');
    }
    $updated = str_replace('/pages/users/user_get_coupon/index',
        '/pages/activity/coupon/index', (string)$row['value'], $replacements);
    $expected = json_encode($plan['homepage']['value'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if ($replacements !== 1 || $updated !== $expected) {
        throw new RuntimeException('Coupon route is not the sole DIY change');
    }
    return [$row, $expected];
}

/** Keep a private rollback snapshot of the single table modified here. */
function mallBackup(PDO $db): string
{
    if (!is_dir(MALL_BACKUP_DIR) || !is_writable(MALL_BACKUP_DIR)) {
        throw new RuntimeException('008 backup directory is unavailable');
    }
    $rows = $db->query('SELECT * FROM eb_diy ORDER BY id')->fetchAll();
    $path = MALL_BACKUP_DIR . '/mall-diy-before-' . date('Ymd_His') . '.json';
    $previousUmask = umask(0077);
    try {
        $file = fopen($path, 'x');
        if ($file === false) throw new RuntimeException('Cannot create exclusive DIY rollback snapshot');
        $payload = json_encode(['target_database' => 'mohe_008', 'created_at' => date(DATE_ATOM),
            'eb_diy' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (fwrite($file, $payload) !== strlen($payload)) {
            fclose($file);
            throw new RuntimeException('Incomplete DIY rollback snapshot');
        }
        fclose($file);
    } finally {
        umask($previousUmask);
    }
    return $path;
}

/** Invalidate only 008's three DIY public response keys in shared Redis. */
function mallInvalidateCache(): void
{
    $env = parse_ini_file(MALL_ENV, true);
    if (($env['CACHE']['PREFIX'] ?? '') !== '008:'
        || ($env['CACHE']['TAG_PREFIX'] ?? '') !== '008:') {
        throw new RuntimeException('008 Redis namespace changed; cache was not cleared');
    }
    $config = $env['REDIS'] ?? [];
    $redis = new Redis();
    $redis->connect($config['REDIS_HOSTNAME'], (int)$config['PORT']);
    if (($config['REDIS_PASSWORD'] ?? '') !== '') $redis->auth($config['REDIS_PASSWORD']);
    $redis->select((int)$config['SELECT']);
    $redis->del('008:mc:diy:tagindex_diy_default', '008:mc:diy:tagdiy_info_0',
        '008:mc:diy:tagnavigation_');
}

/** Check persisted component bytes and public visibility without touching members. */
function mallVerify(PDO $db, array $plan, array $manifest): array
{
    mallCheckFiles($manifest);
    $row = $db->query('SELECT * FROM eb_diy WHERE status=1 AND type=1 AND is_diy=1')->fetch();
    if (!$row || (int)$db->query('SELECT COUNT(*) FROM eb_diy')->fetchColumn() !== 1
        || $row['version'] !== MALL_VERSION || $row['title'] !== '渼约') {
        throw new RuntimeException('008 active DIY row differs from plan');
    }
    $value = json_decode((string)$row['value'], true, 512, JSON_THROW_ON_ERROR);
    $expected = $plan['homepage']['value'];
    if ($value !== $expected) {
        throw new RuntimeException('008 DIY content or footer differs from plan');
    }
    return ['diy_id' => (int)$row['id'], 'components' => count($plan['homepage']['value']) - 1,
        'footer_entries' => count($plan['navigation']['menuList']),
        'local_images' => count($manifest['assets'])];
}

/** Check modes are read-only; apply and repair write only the named instance. */
function mallMain(array $argv): void
{
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['check', 'apply', 'repair-check', 'repair', 'verify'], true)) {
        throw new RuntimeException('Use check, apply, repair-check, repair or verify');
    }
    [$plan, $manifest] = mallPlan();
    $db = mallDb();
    if ($mode === 'check') {
        mallPreflight($db);
        echo json_encode(['mode' => 'check', 'target' => 'mohe_008',
            'components' => 8, 'footer_entries' => 5, 'images' => 16], JSON_PRETTY_PRINT), "\n";
        return;
    }
    if ($mode === 'verify') {
        echo json_encode(['mode' => 'verify', 'target' => 'mohe_008',
            'result' => mallVerify($db, $plan, $manifest)], JSON_PRETTY_PRINT), "\n";
        return;
    }
    if ($mode === 'repair-check') {
        [$row] = mallRepairPreflight($db, $plan);
        mallCheckFiles($manifest);
        echo json_encode(['mode' => 'repair-check', 'target' => 'mohe_008',
            'diy_id' => (int)$row['id'], 'from' => MALL_PREVIOUS_VERSION,
            'to' => MALL_VERSION], JSON_PRETTY_PRINT), "\n";
        return;
    }
    $lock = $db->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute(['mohe_008_mall_diy_20261013']);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('008 DIY import lock is busy');
    try {
        if ($mode === 'repair') {
            [$previous, $encoded] = mallRepairPreflight($db, $plan);
            mallCheckFiles($manifest);
            $backup = mallBackup($db);
            $db->beginTransaction();
            try {
                mallRepairPreflight($db, $plan);
                $change = $db->prepare('UPDATE eb_diy SET value=?,default_value=?,version=?,update_time=?
                    WHERE id=? AND version=? AND SHA2(value,256)=?');
                $change->execute([$encoded, $encoded, MALL_VERSION, time(),
                    (int)$previous['id'], MALL_PREVIOUS_VERSION, MALL_PREVIOUS_VALUE_SHA256]);
                if ($change->rowCount() !== 1) {
                    throw new RuntimeException('008 DIY changed during coupon route repair');
                }
                $db->commit();
            } catch (Throwable $error) {
                if ($db->inTransaction()) $db->rollBack();
                throw $error;
            }
            mallInvalidateCache();
            echo json_encode(['mode' => 'repair', 'target' => 'mohe_008',
                'backup' => $backup, 'backup_sha256' => hash_file('sha256', $backup),
                'verified' => mallVerify($db, $plan, $manifest)],
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
            return;
        }
        mallPreflight($db);
        mallCheckFiles($manifest);
        $backup = mallBackup($db);
        $value = $plan['homepage']['value'];
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $now = time();
        $db->beginTransaction();
        try {
            mallPreflight($db);
            $insert = $db->prepare('INSERT INTO eb_diy
                (version,name,title,cover_image,template_name,value,default_value,add_time,
                 update_time,status,type,is_show,is_bg_color,is_bg_pic,is_diy,color_picker,
                 bg_pic,bg_tab_val,is_del,order_status,my_banner_status,menu_status,service_status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $insert->execute([MALL_VERSION, '渼约', '渼约', '', 'default', $encoded, $encoded,
                $now, $now, 1, 1, 1, 1, 0, 1, '#FFFFFF', '', 0, 0, 0, 1, 1, 1]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        mallInvalidateCache();
        $verified = mallVerify($db, $plan, $manifest);
        echo json_encode(['mode' => 'apply', 'target' => 'mohe_008', 'backup' => $backup,
            'backup_sha256' => hash_file('sha256', $backup), 'verified' => $verified],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute(['mohe_008_mall_diy_20261013']);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        mallMain($argv);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
