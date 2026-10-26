<?php
declare(strict_types=1);

/**
 * 008 年度积分到期最后启用步骤。必须在会员积分/历史流水对账、守卫表迁移、
 * 同版 PHP 代码部署及队列进程重载完成后运行。check/verify 只读；apply 仅写 008。
 */

use app\services\system\timer\SystemTimerServices;
use mohe\services\SystemConfigService;
use think\facade\Config;
use think\facade\Db;

const M008_EXPIRY_SITE = '/www/wwwroot/008.cc3798.com';
const M008_EXPIRY_DATABASE = 'mohe_008';
const M008_EXPIRY_PORT = 3307;
const M008_EXPIRY_MEMBER_BATCH = '008-morestore-member-points-r1';
const M008_EXPIRY_UPGRADE = '20261013-001-integral-expiry-guard';
const M008_EXPIRY_ENABLE_KEY = '20261013-008-integral-expiry-enable';
const M008_EXPIRY_TIMER_MARK = 'auto_clear_integral';
const M008_EXPIRY_TIMER_CYCLE = '12/31/23/59';

/** Ensure both application configuration and live TCP connection belong to 008. */
function m008ExpiryAssertInstance(): void
{
    if (realpath(getcwd()) !== M008_EXPIRY_SITE) {
        throw new RuntimeException('Must run from the 008 site root');
    }
    $env = parse_ini_file(M008_EXPIRY_SITE . '/.env');
    if (!is_array($env) || ($env['DATABASE'] ?? '') !== M008_EXPIRY_DATABASE
        || (int)($env['HOSTPORT'] ?? 0) !== M008_EXPIRY_PORT
        || (int)($env['SELECT'] ?? -1) !== 0
        || (string)($env['PREFIX'] ?? '') !== '008:') {
        throw new RuntimeException('008 database or Redis environment identity changed');
    }
    $row = Db::query('SELECT DATABASE() db, @@port port');
    if (($row[0]['db'] ?? '') !== M008_EXPIRY_DATABASE
        || (int)($row[0]['port'] ?? 0) !== M008_EXPIRY_PORT
        || (int)Config::get('cache.stores.redis.select') !== 0
        || (string)Config::get('cache.stores.redis.prefix') !== '008:') {
        throw new RuntimeException('008 runtime connection identity changed');
    }
}

/** Read a single target table's presence without touching data or creating it. */
function m008ExpiryTableExists(string $table): bool
{
    $rows = Db::query('SELECT COUNT(*) n FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
    return (int)($rows[0]['n'] ?? 0) === 1;
}

/** Load exactly one platform config row per key and return only nonsecret values. */
function m008ExpiryConfigRows(bool $lock = false): array
{
    $query = Db::name('system_config')->where('is_store', 0)
        ->whereIn('menu_name', ['integral_effective_status', 'integral_effective_time']);
    if ($lock) $query->lock(true);
    $rows = $query->field('id,menu_name,value,status')->select()->toArray();
    $result = [];
    foreach ($rows as $row) {
        $key = (string)$row['menu_name'];
        if (isset($result[$key]) || (int)$row['status'] !== 1) {
            throw new RuntimeException('008 annual expiry config rows are duplicated or inactive');
        }
        $result[$key] = $row;
    }
    if (count($result) !== 2) {
        throw new RuntimeException('008 annual expiry config rows are missing');
    }
    return $result;
}

/** The member import marker binds current balances and historical bills to one fixed source batch. */
function m008ExpiryMemberBatch(): ?array
{
    if (!m008ExpiryTableExists('mig_morestore_20261013_member_batch')) return null;
    $rows = Db::name('mig_morestore_20261013_member_batch')
        ->where('batch_key', M008_EXPIRY_MEMBER_BATCH)->select()->toArray();
    if (count($rows) > 1) throw new RuntimeException('Duplicate 008 member import marker');
    return $rows[0] ?? null;
}

/** Inspect the database, member import, timer cache and bounded 2025 expiry forecast without mutation. */
function m008ExpiryState(?string $expectedMemberSha = null): array
{
    m008ExpiryAssertInstance();
    $config = m008ExpiryConfigRows();
    $timerRows = Db::name('system_timer')->where('mark', M008_EXPIRY_TIMER_MARK)
        ->select()->toArray();
    if (count($timerRows) > 1) throw new RuntimeException('Duplicate 008 annual expiry timer');
    $timer = $timerRows[0] ?? null;
    $markerRows = Db::name('database_upgrade_log')
        ->where('upgrade_key', M008_EXPIRY_ENABLE_KEY)->select()->toArray();
    if (count($markerRows) > 1) throw new RuntimeException('Duplicate 008 expiry enable marker');
    $marker = $markerRows[0] ?? null;
    $batch = m008ExpiryMemberBatch();
    $blockers = [];
    if (!m008ExpiryTableExists('eb_user_integral_expiry')) $blockers[] = 'expiry_guard_missing';
    if ((int)Db::name('database_upgrade_log')->where('upgrade_key', M008_EXPIRY_UPGRADE)->count() !== 1) {
        $blockers[] = 'expiry_guard_upgrade_not_recorded';
    }
    if (!$batch) {
        $blockers[] = 'member_batch_missing';
    } else {
        if (!preg_match('/^[a-f0-9]{64}$/D', (string)$batch['source_sha256'])
            || ($expectedMemberSha !== null
                && !hash_equals($expectedMemberSha, (string)$batch['source_sha256']))) {
            $blockers[] = 'member_source_digest_mismatch';
        }
        if (!$marker && ((int)Db::name('user')->count() !== (int)$batch['target_members']
            || (int)Db::name('user')->sum('integral') !== (int)$batch['current_integral']
            || (int)Db::name('user_bill')->where('category', 'integral')->count()
                !== (int)$batch['integral_bills'])) {
            $blockers[] = 'member_or_integral_reconciliation_drift';
        }
        if ($marker) {
            // 启用记录必须继续绑定同一批来源；不能把后来重导的会员账误认为已经验收的年度配置。
            $note = json_decode((string)$marker['result_note'], true);
            if (!is_array($note)
                || !hash_equals((string)$batch['source_sha256'],
                    (string)($note['member_source_sha256'] ?? ''))
                || (int)($note['redis_db'] ?? -1) !== 0
                || (int)($note['timer_id'] ?? 0) <= 0) {
                $blockers[] = 'enable_marker_member_or_redis_drift';
            }
        }
    }
    if (!$marker && m008ExpiryTableExists('eb_user_integral_expiry')
        && (int)Db::name('user_integral_expiry')->count() !== 0) {
        $blockers[] = 'expiry_guard_already_has_entries';
    }
    $desired = ['integral_effective_status' => '1', 'integral_effective_time' => '3'];
    $current = [
        'integral_effective_status' => (string)$config['integral_effective_status']['value'],
        'integral_effective_time' => (string)$config['integral_effective_time']['value'],
    ];
    $timerMatches = $timer && (int)$timer['is_del'] === 0 && (int)$timer['is_open'] === 1
        && (int)$timer['type'] === 8 && (string)$timer['cycle'] === M008_EXPIRY_TIMER_CYCLE;
    if ($timerMatches && $marker && $batch) {
        $note = json_decode((string)$marker['result_note'], true);
        if ((int)($note['timer_id'] ?? 0) !== (int)$timer['id']) {
            $blockers[] = 'enable_marker_timer_drift';
        }
    }
    $timerCache = app()->make(SystemTimerServices::class)->cacheList();
    $cached = null;
    foreach ($timerCache as $row) {
        if (($row['mark'] ?? '') === M008_EXPIRY_TIMER_MARK) {
            if ($cached !== null) throw new RuntimeException('Duplicate 008 timer cache row');
            $cached = $row;
        }
    }
    $cacheMatches = $timerMatches && $cached
        && (int)($cached['id'] ?? 0) === (int)$timer['id']
        && (int)($cached['is_open'] ?? 0) === 1
        && (int)($cached['type'] ?? 0) === 8
        && (string)($cached['cycle'] ?? '') === M008_EXPIRY_TIMER_CYCLE;
    $mode = 'blocked';
    if (!$blockers && !$marker && !$timer && $current === ['integral_effective_status' => '',
            'integral_effective_time' => '']) {
        $mode = 'ready';
    } elseif (!$blockers && $marker && $timerMatches && $current === $desired) {
        $mode = 'active';
    } elseif (!$blockers) {
        $blockers[] = 'unreviewed_config_timer_or_marker_state';
    }
    // Forecast uses the same annual ledger arithmetic as the service: previous-year
    // clear bills are excluded, and each merged target UID is capped by current points.
    $forecast = ['members' => 0, 'points' => 0];
    if ($batch) {
        $start = strtotime('2025-01-01 00:00:00');
        $end = strtotime('2025-12-31 23:59:59');
        $rows = Db::query("SELECT u.uid,u.integral,
            SUM(CASE WHEN b.pm=1 THEN b.number ELSE 0 END) got,
            SUM(CASE WHEN b.pm=0 AND b.type<>'system_clear' THEN b.number ELSE 0 END) spent
            FROM eb_user_bill b JOIN eb_user u ON u.uid=b.uid
            WHERE b.category='integral' AND b.status=1 AND b.add_time BETWEEN ? AND ?
            GROUP BY u.uid,u.integral", [$start, $end]);
        foreach ($rows as $row) {
            $expiring = min(max(0, (int)$row['got'] - (int)$row['spent']),
                max(0, (int)$row['integral']));
            if ($expiring > 0) {
                $forecast['members']++;
                $forecast['points'] += $expiring;
            }
        }
    }
    return ['mode' => $mode, 'blockers' => $blockers,
        'target' => '008.cc3798.com/mohe_008:3307',
        'redis_db' => 0, 'member_source_sha256' => $batch['source_sha256'] ?? null,
        'member_batch' => $batch ? [
            'source_members' => (int)$batch['source_members'],
            'target_members' => (int)$batch['target_members'],
            'current_integral' => (int)$batch['current_integral'],
            'integral_bills' => (int)$batch['integral_bills'],
        ] : null,
        'config' => $current,
        'timer' => $timer ? ['id' => (int)$timer['id'], 'is_open' => (int)$timer['is_open'],
            'type' => (int)$timer['type'], 'cycle' => (string)$timer['cycle']] : null,
        'timer_cache_synced' => (bool)$cacheMatches,
        'forecast_2026_12_31' => $forecast];
}

/** Apply both config values and timer row atomically; postcommit cache refresh is retryable. */
function m008ExpiryApply(string $scriptSha, string $memberSha, string $codeVersion): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $scriptSha)
        || !hash_equals($scriptSha, (string)hash_file('sha256', __FILE__))
        || !preg_match('/^[a-f0-9]{64}$/D', $memberSha)
        || !preg_match('/^[a-f0-9]{40}$/D', $codeVersion)) {
        throw new RuntimeException('Fixed script, member source and local commit fingerprints required');
    }
    $state = m008ExpiryState($memberSha);
    if (!in_array($state['mode'], ['ready', 'active'], true)) {
        throw new RuntimeException('008 annual expiry preflight is not ready');
    }
    $got = Db::query("SELECT GET_LOCK('008_integral_expiry_enable',15) got");
    if ((int)($got[0]['got'] ?? 0) !== 1) throw new RuntimeException('008 enable lock unavailable');
    try {
        Db::transaction(function () use ($scriptSha, $memberSha, $codeVersion): void {
            m008ExpiryAssertInstance();
            $rows = m008ExpiryConfigRows(true);
            $batch = m008ExpiryMemberBatch();
            if (!$batch || !hash_equals($memberSha, (string)$batch['source_sha256'])) {
                throw new RuntimeException('008 member batch changed before enable');
            }
            $markers = Db::name('database_upgrade_log')
                ->where('upgrade_key', M008_EXPIRY_ENABLE_KEY)->lock(true)->select()->toArray();
            if ($markers) {
                if (count($markers) !== 1
                    || (string)$markers[0]['sql_checksum'] !== $scriptSha
                    || (string)$markers[0]['code_version'] !== $codeVersion) {
                    throw new RuntimeException('008 expiry enable marker drifted');
                }
                return;
            }
            if ((int)Db::name('user')->count() !== (int)$batch['target_members']
                || (int)Db::name('user')->sum('integral') !== (int)$batch['current_integral']
                || (int)Db::name('user_bill')->where('category', 'integral')->count()
                    !== (int)$batch['integral_bills']
                || (int)Db::name('user_integral_expiry')->count() !== 0) {
                throw new RuntimeException('008 member and historical integral reconciliation drifted');
            }
            foreach (['integral_effective_status' => '1', 'integral_effective_time' => '3'] as $key => $value) {
                if ((string)$rows[$key]['value'] !== '') {
                    throw new RuntimeException('008 annual expiry config is no longer blank');
                }
                Db::name('system_config')->where('id', (int)$rows[$key]['id'])
                    ->update(['value' => $value]);
            }
            if ((int)Db::name('system_timer')->where('mark', M008_EXPIRY_TIMER_MARK)->count() !== 0) {
                throw new RuntimeException('008 expiry timer appeared during enable');
            }
            $now = time();
            $id = (int)Db::name('system_timer')->insertGetId([
                'name' => '自动清空用户积分', 'mark' => M008_EXPIRY_TIMER_MARK,
                'type' => 8, 'title' => '年度积分到期', 'is_open' => 1,
                'cycle' => M008_EXPIRY_TIMER_CYCLE, 'last_execution_time' => 0,
                'update_execution_time' => $now, 'is_del' => 0, 'add_time' => $now,
            ]);
            if ($id <= 0) throw new RuntimeException('008 expiry timer insertion failed');
            Db::name('database_upgrade_log')->insert([
                'upgrade_key' => M008_EXPIRY_ENABLE_KEY,
                'title' => '008 年度积分到期启用',
                'task_file' => 'scripts/import/customer_008_integral_expiry_enable.php',
                'sql_checksum' => $scriptSha,
                'code_version' => $codeVersion,
                'executed_at' => date('Y-m-d H:i:s'),
                'executed_by' => 'Codex',
                'result_note' => json_encode(['member_source_sha256' => $memberSha,
                    'timer_id' => $id, 'redis_db' => 0], JSON_THROW_ON_ERROR),
            ]);
        });
        if (SystemConfigService::clear() === false) {
            throw new RuntimeException('008 config committed but cache refresh failed; retry apply');
        }
        // The timer DAO uses raw mc:system_timer keys without CACHE.PREFIX;
        // the verified isolated Redis SELECT 0 prevents cross-instance writes.
        app()->make(SystemTimerServices::class)->setAllTimerCache();
        if ((int)sys_config('integral_effective_status', 0) !== 1
            || (int)sys_config('integral_effective_time', 0) !== 3) {
            throw new RuntimeException('008 config committed but runtime cache disagrees; retry apply');
        }
        $after = m008ExpiryState($memberSha);
        if ($after['mode'] !== 'active' || !$after['timer_cache_synced']) {
            throw new RuntimeException('008 expiry enabled but postcheck failed; inspect then retry apply');
        }
        return $after;
    } finally {
        Db::query("SELECT RELEASE_LOCK('008_integral_expiry_enable') released");
    }
}

/** CLI output contains only counts, nonsecret configuration and digests. */
function m008ExpiryMain(array $argv): void
{
    $mode = (string)($argv[1] ?? '');
    if (!in_array($mode, ['check', 'verify', 'apply'], true)) {
        throw new RuntimeException('Usage: check|verify|apply <script_sha256> <member_source_sha256> <commit_sha>');
    }
    if (realpath(getcwd()) !== M008_EXPIRY_SITE) {
        throw new RuntimeException('Must run from the 008 site root');
    }
    require getcwd() . '/vendor/autoload.php';
    (new think\App(getcwd() . '/'))->initialize();
    if ($mode === 'apply') {
        $result = m008ExpiryApply((string)($argv[2] ?? ''), (string)($argv[3] ?? ''),
            (string)($argv[4] ?? ''));
    } else {
        $result = m008ExpiryState();
        if ($mode === 'verify' && ($result['mode'] !== 'active'
                || !$result['timer_cache_synced'])) {
            throw new RuntimeException('008 annual expiry is not active in database and timer cache');
        }
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        m008ExpiryMain($argv);
    } catch (Throwable $error) {
        $message = $error instanceof RuntimeException ? $error->getMessage() : get_class($error);
        fwrite(STDERR, '008 expiry enable stopped: ' . $message . PHP_EOL);
        exit(1);
    }
}
