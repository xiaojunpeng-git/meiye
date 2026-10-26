<?php
/**
 * O4 组织工作台写能力冒烟（R1～R5）
 * - 关闸 / 纯函数 / R1·R2·R5 隔离检查：真实 PASS
 * - 完整写套件：未开闸或无 011 时 NOT_RUN（禁止假 PASS）；代码分支可安全执行
 * - 禁止本轮执行 011 / 开闸 / 切 source mode
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\services\organization\OrganizationManageServices;
use app\services\organization\OrganizationScopeService;
use app\services\organization\OrganizationWorkspaceWriteGate;
use app\services\organization\OrganizationWorkspaceWriteServices;
use mohe\services\CacheService;
use think\facade\Db;

$failures = [];
$notRun = [];
$runId = 'o4ws_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 6);
$reportDir = '/tmp/' . $runId;
@mkdir($reportDir, 0777, true);

function pass(string $c, string $x = ''): void
{
    echo 'PASS ' . $c . ($x !== '' ? ' ' . $x : '') . "\n";
}
function fail(string $c, string $m): void
{
    global $failures;
    $failures[] = "{$c}: {$m}";
    echo "FAIL {$c}: {$m}\n";
}
function not_run(string $c, string $m): void
{
    global $notRun;
    $notRun[] = "{$c}: {$m}";
    echo "NOT_RUN {$c}: {$m}\n";
}

/**
 * S6：分批读取全表并哈希；禁止 limit(8000)。
 * 仅 organization_write_idempotency 在 011 未执行时允许 ABSENT_011_NOT_EXECUTED；
 * 其它表查询异常直接抛错（由调用方 FAIL）。
 */
function coreSnapshot(): array
{
    $tables = [
        'organization',
        'organization_store',
        'organization_admin',
        'organization_admin_store_exclude',
        'organization_leader',
        'organization_change_log',
        'employee_change_log',
        'organization_write_idempotency',
        'system_store',
        'system_admin',
        'employee',
    ];
    $counts = [];
    $hashes = [];
    $batch = 500;
    foreach ($tables as $t) {
        if ($t === 'organization_write_idempotency') {
            $idemExists = !empty(Db::query("SHOW TABLES LIKE 'eb_organization_write_idempotency'"));
            if (!$idemExists) {
                $counts[$t] = -1;
                $hashes[$t] = 'ABSENT_011_NOT_EXECUTED';
                continue;
            }
        }
        $counts[$t] = (int)Db::name($t)->count();
        $ctx = hash_init('sha256');
        $lastId = 0;
        while (true) {
            $rows = Db::name($t)->where('id', '>', $lastId)->order('id', 'asc')->limit($batch)->select()->toArray();
            if (!$rows) {
                break;
            }
            foreach ($rows as $r) {
                hash_update($ctx, json_encode($r, JSON_UNESCAPED_UNICODE) . "\n");
                $lastId = (int)$r['id'];
            }
            if (count($rows) < $batch) {
                break;
            }
        }
        $hashes[$t] = hash_final($ctx);
    }
    /** @var OrganizationScopeService $scope */
    $scope = app()->make(OrganizationScopeService::class);
    $redis = CacheService::redisHandler();
    $rt = $redis->get(OrganizationScopeService::SOURCE_MODE_RUNTIME_KEY);
    $idemExists = isset($counts['organization_write_idempotency']) && $counts['organization_write_idempotency'] >= 0;
    if (!$idemExists) {
        try {
            $idemExists = !empty(Db::query("SHOW TABLES LIKE 'eb_organization_write_idempotency'"));
        } catch (\Throwable $e) {
            $idemExists = false;
        }
    }
    return [
        'counts' => $counts,
        'hashes' => $hashes,
        'source_mode' => $scope->getSourceMode(),
        'runtime' => $rt === false || $rt === null ? null : $rt,
        'idem_table' => $idemExists ? 1 : 0,
    ];
}

/** 011 正式升级 SQL 冻结 SHA（仅用于 upgrade_log 对照，不作为 schema ready 依据） */
const O4_011_SQL_CHECKSUM = 'f0e3924bc987796ab1273a9a836aee2662ae1a7e4ed010e1b4cc617e190f4e59';
const O4_011_UPGRADE_KEY = '20260719-011-organization-workspace-write';

/**
 * V1：只读判断 011 完整结构是否就绪（禁止仅凭表存在 / 禁止仅凭 upgrade_log）
 * @return array{ready:bool,checks:array<string,mixed>,blockers:array<int,string>,upgrade_log_count:int,upgrade_log_checksum_match:bool}
 */
function o4Upgrade011Status(): array
{
    $checks = [];
    $blockers = [];
    $mark = static function (string $key, $ok, $detail = null) use (&$checks, &$blockers): void {
        $checks[$key] = [
            'ok' => (bool)$ok,
            'detail' => $detail,
        ];
        if (!$ok) {
            $blockers[] = $key . ($detail !== null && $detail !== '' ? (':' . (is_scalar($detail) ? (string)$detail : json_encode($detail, JSON_UNESCAPED_UNICODE))) : '');
        }
    };

    $db = '';
    try {
        $db = (string)(Db::query('SELECT DATABASE() AS d')[0]['d'] ?? '');
        // W3：mohe-app 实际连接库必须精确为 lin8（fail-closed）
        $dbOk = ($db === 'lin8');
        $mark('database', $dbOk, $db === '' ? 'empty' : $db);
        if (!$dbOk) {
            return [
                'ready' => false,
                'checks' => $checks,
                'blockers' => $blockers,
                'upgrade_log_count' => 0,
                'upgrade_log_checksum_match' => false,
            ];
        }
    } catch (\Throwable $e) {
        $mark('database', false, $e->getMessage());
        return [
            'ready' => false,
            'checks' => $checks,
            'blockers' => $blockers,
            'upgrade_log_count' => 0,
            'upgrade_log_checksum_match' => false,
        ];
    }

    // —— 1) 幂等表 ——
    try {
        $tbl = Db::query(
            'SELECT ENGINE e, TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? LIMIT 1',
            [$db, 'eb_organization_write_idempotency']
        );
        $exists = !empty($tbl);
        $mark('idem_table_exists', $exists, $exists ? 'present' : 'absent');
        if ($exists) {
            $mark('idem_engine_innodb', strtoupper((string)($tbl[0]['e'] ?? '')) === 'INNODB', (string)($tbl[0]['e'] ?? ''));

            $colOk = static function (string $name, string $dataType, ?int $len, string $nullable, ?string $extraLike = null, ?string $columnKey = null) use ($db, $mark): void {
                $sql = 'SELECT COUNT(*) c FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?
                      AND DATA_TYPE=? AND IS_NULLABLE=?';
                $args = [$db, 'eb_organization_write_idempotency', $name, $dataType, $nullable];
                if ($len !== null) {
                    $sql .= ' AND CHARACTER_MAXIMUM_LENGTH=?';
                    $args[] = $len;
                }
                if ($name === 'operator_id' || $name === 'id') {
                    $sql .= " AND COLUMN_TYPE LIKE '%unsigned%'";
                }
                if ($extraLike !== null) {
                    $sql .= ' AND EXTRA LIKE ?';
                    $args[] = $extraLike;
                }
                if ($columnKey !== null) {
                    $sql .= ' AND COLUMN_KEY=?';
                    $args[] = $columnKey;
                }
                $c = (int)(Db::query($sql, $args)[0]['c'] ?? 0);
                $mark('idem_col_' . $name, $c === 1, 'count=' . $c);
            };

            $colOk('id', 'bigint', null, 'NO', '%auto_increment%', 'PRI');
            $colOk('request_token', 'varchar', 64, 'NO');
            $colOk('operator_id', 'int', null, 'NO');
            $colOk('action', 'varchar', 64, 'NO');
            $colOk('scope_key', 'varchar', 128, 'NO');
            $colOk('request_hash', 'char', 64, 'NO');
            $colOk('response_json', 'mediumtext', null, 'YES');
            $colOk('add_time', 'int', null, 'NO');

            // uk_request_token：恰好 request_token 单列 UNIQUE
            $ukCols = (int)(Db::query(
                'SELECT COUNT(*) c FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?',
                [$db, 'eb_organization_write_idempotency', 'uk_request_token']
            )[0]['c'] ?? 0);
            $ukUnique = (int)(Db::query(
                'SELECT COUNT(*) c FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?
                   AND NON_UNIQUE=0 AND SEQ_IN_INDEX=1 AND COLUMN_NAME=?',
                [$db, 'eb_organization_write_idempotency', 'uk_request_token', 'request_token']
            )[0]['c'] ?? 0);
            $mark('idem_uk_request_token', $ukCols === 1 && $ukUnique === 1, 'cols=' . $ukCols . ' unique_token=' . $ukUnique);

            $idxMatch = static function (string $index, array $expected) use ($db, $mark): void {
                $rows = Db::query(
                    'SELECT SEQ_IN_INDEX seq, COLUMN_NAME col FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?
                     ORDER BY SEQ_IN_INDEX ASC',
                    [$db, 'eb_organization_write_idempotency', $index]
                );
                $got = [];
                foreach ($rows as $r) {
                    $got[(int)$r['seq']] = (string)$r['col'];
                }
                $ok = count($got) === count($expected);
                if ($ok) {
                    foreach ($expected as $i => $name) {
                        if (($got[$i + 1] ?? null) !== $name) {
                            $ok = false;
                            break;
                        }
                    }
                }
                $mark('idem_idx_' . $index, $ok, $got);
            };
            $idxMatch('idx_scope_action', ['scope_key', 'action', 'id']);
            $idxMatch('idx_operator_time', ['operator_id', 'add_time']);
        }
    } catch (\Throwable $e) {
        $mark('idem_table_query', false, $e->getMessage());
    }

    // —— 2) change_log 审计列完整结构 + idx_org_log_token 恰好 request_token 单列 ——
    try {
        foreach (['operator_ip', 'request_id', 'request_token'] as $col) {
            $rows = Db::query(
                "SELECT DATA_TYPE dt, CHARACTER_MAXIMUM_LENGTH len, IS_NULLABLE nul, COLUMN_DEFAULT def
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1",
                [$db, 'eb_organization_change_log', $col]
            );
            if (!$rows) {
                $mark('change_log_col_' . $col, false, 'absent');
                continue;
            }
            $dt = (string)($rows[0]['dt'] ?? '');
            $len = (int)($rows[0]['len'] ?? 0);
            $nul = (string)($rows[0]['nul'] ?? '');
            $def = $rows[0]['def'];
            // DEFAULT '' 在 information_schema 中可能为 '' 或 null（视版本）；NOT NULL + varchar(64) 必达
            $defOk = ($def === '' || $def === null || (string)$def === '');
            $ok = ($dt === 'varchar' && $len === 64 && $nul === 'NO' && $defOk);
            $mark('change_log_col_' . $col, $ok, [
                'data_type' => $dt,
                'len' => $len,
                'nullable' => $nul,
                'default' => $def,
            ]);
        }
        $idxRows = Db::query(
            'SELECT SEQ_IN_INDEX seq, COLUMN_NAME col FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?
             ORDER BY SEQ_IN_INDEX ASC',
            [$db, 'eb_organization_change_log', 'idx_org_log_token']
        );
        $got = [];
        foreach ($idxRows as $r) {
            $got[(int)$r['seq']] = (string)$r['col'];
        }
        $idxOk = (count($got) === 1 && ($got[1] ?? null) === 'request_token');
        $mark('change_log_idx_org_log_token', $idxOk, $got ?: 'absent');
    } catch (\Throwable $e) {
        $mark('change_log_query', false, $e->getMessage());
    }

    // —— 3) scope_mode：varchar(16) NOT NULL DEFAULT inherit ——
    try {
        $rows = Db::query(
            "SELECT DATA_TYPE dt, CHARACTER_MAXIMUM_LENGTH len, IS_NULLABLE nul, COLUMN_DEFAULT def
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME='scope_mode' LIMIT 1",
            [$db, 'eb_organization_admin']
        );
        if (!$rows) {
            $mark('scope_mode_col', false, 'absent');
        } else {
            $dt = (string)($rows[0]['dt'] ?? '');
            $len = (int)($rows[0]['len'] ?? 0);
            $nul = (string)($rows[0]['nul'] ?? '');
            $def = (string)($rows[0]['def'] ?? '');
            // MySQL 可能返回 inherit 或 'inherit'
            $defNorm = trim($def, "\"'");
            $ok = ($dt === 'varchar' && $len === 16 && $nul === 'NO' && $defNorm === 'inherit');
            $mark('scope_mode_col', $ok, ['data_type' => $dt, 'len' => $len, 'nullable' => $nul, 'default' => $def]);
        }
    } catch (\Throwable $e) {
        $mark('scope_mode_query', false, $e->getMessage());
    }

    // —— 4/5) 两平台门禁：各恰好一行，value 仅为 JSON "0"|"1" ——
    foreach (['organization_workspace_write_enabled', 'organization_workspace_write_allow_legacy'] as $menu) {
        try {
            $rows = Db::query(
                'SELECT id, value FROM eb_system_config WHERE is_store=0 AND menu_name=?',
                [$menu]
            );
            $cnt = is_array($rows) ? count($rows) : 0;
            $mark('gate_row_' . $menu, $cnt === 1, 'count=' . $cnt);
            if ($cnt === 1) {
                $raw = (string)($rows[0]['value'] ?? '');
                $okVal = ($raw === '"0"' || $raw === '"1"');
                $mark('gate_value_' . $menu, $okVal, $raw === '' ? 'empty' : $raw);
            }
        } catch (\Throwable $e) {
            $mark('gate_query_' . $menu, false, $e->getMessage());
        }
    }

    // —— upgrade_log：仅报告，不参与 ready ——
    $logCount = 0;
    $checksumMatch = false;
    try {
        $logCount = (int)(Db::query(
            'SELECT COUNT(*) c FROM eb_database_upgrade_log WHERE upgrade_key=?',
            [O4_011_UPGRADE_KEY]
        )[0]['c'] ?? 0);
        $checks['upgrade_log_count'] = ['ok' => true, 'detail' => $logCount];
        if ($logCount === 1) {
            $sum = (string)(Db::query(
                'SELECT sql_checksum FROM eb_database_upgrade_log WHERE upgrade_key=? LIMIT 1',
                [O4_011_UPGRADE_KEY]
            )[0]['sql_checksum'] ?? '');
            $checksumMatch = (strtolower($sum) === strtolower(O4_011_SQL_CHECKSUM));
            $checks['upgrade_log_checksum'] = ['ok' => $checksumMatch, 'detail' => $sum];
        } else {
            $checks['upgrade_log_checksum'] = ['ok' => false, 'detail' => 'count=' . $logCount];
        }
    } catch (\Throwable $e) {
        // log 查询失败不影响 schema ready；仅记入 checks
        $checks['upgrade_log_query'] = ['ok' => false, 'detail' => $e->getMessage()];
    }

    // ready 仅看 schema 结构 checks（upgrade_log_* 不参与）
    $ready = ($db !== '');
    foreach ($checks as $k => $c) {
        if (strpos((string)$k, 'upgrade_log') === 0) {
            continue;
        }
        if (empty($c['ok'])) {
            $ready = false;
            break;
        }
    }

    return [
        'ready' => $ready,
        'checks' => $checks,
        'blockers' => $ready ? [] : array_values(array_unique($blockers)),
        'upgrade_log_count' => $logCount,
        'upgrade_log_checksum_match' => $checksumMatch,
    ];
}

function uuidV4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
}

/**
 * R1：模拟「列已存在」时重跑升级逻辑，不得覆盖 custom+空排除
 */
function o4R1ScopeModeRerunSemantics(string $runId): void
{
    $suffix = preg_replace('/[^a-zA-Z0-9_]/', '_', $runId);
    $adminT = 'tmp_o4_r1_admin_' . $suffix;
    $exT = 'tmp_o4_r1_ex_' . $suffix;
    try {
        Db::execute("DROP TABLE IF EXISTS `{$adminT}`");
        Db::execute("DROP TABLE IF EXISTS `{$exT}`");
        Db::execute("CREATE TABLE `{$adminT}` (
            `id` int unsigned NOT NULL,
            `is_del` tinyint(1) NOT NULL DEFAULT 0,
            `scope_mode` varchar(16) NOT NULL DEFAULT 'inherit',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        Db::execute("CREATE TABLE `{$exT}` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `org_admin_id` int unsigned NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        Db::execute("INSERT INTO `{$adminT}` (`id`,`is_del`,`scope_mode`) VALUES (1,0,'custom')");
        // 排除为空；列执行前已存在 → 固定方案禁止 UPDATE
        $scopeModeExistedBefore = 1;
        if ($scopeModeExistedBefore === 0) {
            Db::execute("UPDATE `{$adminT}` a
                SET a.scope_mode = IF(
                  EXISTS(SELECT 1 FROM `{$exT}` ex WHERE ex.org_admin_id = a.id),
                  'custom',
                  'inherit'
                )
                WHERE a.is_del = 0 OR a.is_del = 1");
        }
        $modeAfterSafe = (string)(Db::query("SELECT scope_mode AS m FROM `{$adminT}` WHERE id=1")[0]['m'] ?? '');
        if ($modeAfterSafe !== 'custom') {
            throw new \RuntimeException('safe rerun overwrote custom+empty-exclude to ' . $modeAfterSafe);
        }

        // 对照：旧无条件 UPDATE 会破坏语义
        Db::execute("UPDATE `{$adminT}` a
            SET a.scope_mode = IF(
              EXISTS(SELECT 1 FROM `{$exT}` ex WHERE ex.org_admin_id = a.id),
              'custom',
              'inherit'
            )
            WHERE a.is_del = 0 OR a.is_del = 1");
        $modeAfterBuggy = (string)(Db::query("SELECT scope_mode AS m FROM `{$adminT}` WHERE id=1")[0]['m'] ?? '');
        if ($modeAfterBuggy !== 'inherit') {
            throw new \RuntimeException('buggy path control unexpected: ' . $modeAfterBuggy);
        }
    } finally {
        try {
            Db::execute("DROP TABLE IF EXISTS `{$adminT}`");
        } catch (\Throwable $e) {
        }
        try {
            Db::execute("DROP TABLE IF EXISTS `{$exT}`");
        } catch (\Throwable $e) {
        }
    }
}

/**
 * R2：错误表结构必须被校验拒绝（对照 02/03 条件）
 * @return array{bad_verify:string,good_verify:string}
 */
function o4R2IdempotencyStructureHardCheck(string $runId): array
{
    $suffix = preg_replace('/[^a-zA-Z0-9_]/', '_', $runId);
    $badT = 'tmp_o4_r2_bad_' . $suffix;
    $goodT = 'tmp_o4_r2_good_' . $suffix;
    $db = (string)(Db::query('SELECT DATABASE() AS d')[0]['d'] ?? '');
    try {
        Db::execute("DROP TABLE IF EXISTS `{$badT}`");
        Db::execute("DROP TABLE IF EXISTS `{$goodT}`");
        // 错误：request_token 长度/可空错误；同名 uk 但列错误；非 InnoDB
        Db::execute("CREATE TABLE `{$badT}` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `request_token` varchar(32) NULL,
            `operator_id` int NOT NULL DEFAULT 0,
            `action` varchar(64) NOT NULL DEFAULT '',
            `scope_key` varchar(128) NOT NULL DEFAULT '',
            `request_hash` char(64) NOT NULL DEFAULT '',
            `response_json` text,
            `add_time` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_request_token` (`operator_id`),
            KEY `idx_scope_action` (`action`,`scope_key`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");

        Db::execute("CREATE TABLE `{$goodT}` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `request_token` varchar(64) NOT NULL,
            `operator_id` int unsigned NOT NULL DEFAULT 0,
            `action` varchar(64) NOT NULL DEFAULT '',
            `scope_key` varchar(128) NOT NULL DEFAULT '',
            `request_hash` char(64) NOT NULL DEFAULT '',
            `response_json` mediumtext,
            `add_time` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_request_token` (`request_token`),
            KEY `idx_scope_action` (`scope_key`,`action`,`id`),
            KEY `idx_operator_time` (`operator_id`,`add_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $verify = static function (string $table) use ($db): string {
            $ok = (
                (int)Db::query("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?", [$db, $table])[0]['c'] === 1
                && (string)(Db::query("SELECT ENGINE e FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? LIMIT 1", [$db, $table])[0]['e'] ?? '') === 'InnoDB'
                && (int)Db::query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME='id' AND DATA_TYPE='bigint' AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE='NO' AND EXTRA LIKE '%auto_increment%' AND COLUMN_KEY='PRI'", [$db, $table])[0]['c'] === 1
                && (int)Db::query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME='request_token' AND DATA_TYPE='varchar' AND CHARACTER_MAXIMUM_LENGTH=64 AND IS_NULLABLE='NO'", [$db, $table])[0]['c'] === 1
                && (int)Db::query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME='operator_id' AND DATA_TYPE='int' AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE='NO'", [$db, $table])[0]['c'] === 1
                && (int)Db::query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME='action' AND DATA_TYPE='varchar' AND CHARACTER_MAXIMUM_LENGTH=64 AND IS_NULLABLE='NO'", [$db, $table])[0]['c'] === 1
                && (int)Db::query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME='scope_key' AND DATA_TYPE='varchar' AND CHARACTER_MAXIMUM_LENGTH=128 AND IS_NULLABLE='NO'", [$db, $table])[0]['c'] === 1
                && (int)Db::query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME='request_hash' AND DATA_TYPE='char' AND CHARACTER_MAXIMUM_LENGTH=64 AND IS_NULLABLE='NO'", [$db, $table])[0]['c'] === 1
                && (int)Db::query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME='response_json' AND DATA_TYPE='mediumtext' AND IS_NULLABLE='YES'", [$db, $table])[0]['c'] === 1
                && (int)Db::query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME='add_time' AND DATA_TYPE='int' AND IS_NULLABLE='NO'", [$db, $table])[0]['c'] === 1
                && (int)Db::query("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME='uk_request_token'", [$db, $table])[0]['c'] === 1
                && (int)Db::query("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME='uk_request_token' AND NON_UNIQUE=0 AND SEQ_IN_INDEX=1 AND COLUMN_NAME='request_token'", [$db, $table])[0]['c'] === 1
                && (int)Db::query("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME='idx_scope_action'", [$db, $table])[0]['c'] === 3
                && (int)Db::query("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME='idx_scope_action' AND SEQ_IN_INDEX=1 AND COLUMN_NAME='scope_key'", [$db, $table])[0]['c'] === 1
                && (int)Db::query("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME='idx_scope_action' AND SEQ_IN_INDEX=2 AND COLUMN_NAME='action'", [$db, $table])[0]['c'] === 1
                && (int)Db::query("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME='idx_scope_action' AND SEQ_IN_INDEX=3 AND COLUMN_NAME='id'", [$db, $table])[0]['c'] === 1
                && (int)Db::query("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME='idx_operator_time'", [$db, $table])[0]['c'] === 2
                && (int)Db::query("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME='idx_operator_time' AND SEQ_IN_INDEX=1 AND COLUMN_NAME='operator_id'", [$db, $table])[0]['c'] === 1
                && (int)Db::query("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME='idx_operator_time' AND SEQ_IN_INDEX=2 AND COLUMN_NAME='add_time'", [$db, $table])[0]['c'] === 1
            );
            return $ok ? 'VERIFY_OK' : 'VERIFY_FAIL';
        };

        $bad = $verify($badT);
        $good = $verify($goodT);
        if ($bad !== 'VERIFY_FAIL') {
            throw new \RuntimeException('bad table unexpectedly VERIFY_OK');
        }
        if ($good !== 'VERIFY_OK') {
            throw new \RuntimeException('good table unexpectedly VERIFY_FAIL');
        }
        return ['bad_verify' => $bad, 'good_verify' => $good];
    } finally {
        try {
            Db::execute("DROP TABLE IF EXISTS `{$badT}`");
        } catch (\Throwable $e) {
        }
        try {
            Db::execute("DROP TABLE IF EXISTS `{$goodT}`");
        } catch (\Throwable $e) {
        }
    }
}

/**
 * R5：真正孤儿排除 → 删除可删临时组织必须 fail-closed
 */
function o4R5OrphanExcludeReject(OrganizationManageServices $manage, string $runId): void
{
    $marker = 'o4r5_' . $runId;
    $orgId = 0;
    $orphanAdminId = 0;
    $excludeId = 0;
    try {
        $root = Db::name('organization')->where('pid', 0)->where('is_del', 0)->order('id', 'asc')->find();
        if (!$root) {
            throw new \RuntimeException('no root org');
        }
        $parentId = (int)$root['id'];
        $orgId = $manage->saveOrganization(0, [
            'pid' => $parentId,
            'name' => $marker,
            'sort' => 0,
        ], 1, 'o4r5');

        // 制造真实孤儿：org_admin_id 不存在
        do {
            $orphanAdminId = 910000000 + random_int(1, 999999);
        } while ((int)Db::name('organization_admin')->where('id', $orphanAdminId)->count() > 0);

        $excludeId = (int)Db::name('organization_admin_store_exclude')->insertGetId([
            'org_admin_id' => $orphanAdminId,
            'store_id' => 1,
            'add_time' => time(),
        ]);

        $rejected = false;
        $msg = '';
        try {
            $manage->deleteOrganization($orgId, 1, 'o4r5');
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $rejected = strpos($msg, '异常排除门店数据') !== false;
        }
        if (!$rejected) {
            throw new \RuntimeException('orphan exclude did not block delete: ' . $msg);
        }
        $still = Db::name('organization')->where('id', $orgId)->where('is_del', 0)->find();
        if (!$still) {
            throw new \RuntimeException('org was deleted despite orphan exclude');
        }
    } finally {
        if ($excludeId > 0) {
            Db::name('organization_admin_store_exclude')->where('id', $excludeId)->delete();
        } elseif ($orphanAdminId > 0) {
            Db::name('organization_admin_store_exclude')->where('org_admin_id', $orphanAdminId)->delete();
        }
        if ($orgId > 0) {
            Db::name('organization_change_log')->where('org_id', $orgId)->delete();
            Db::name('organization_leader')->where('org_id', $orgId)->delete();
            Db::name('organization')->where('id', $orgId)->delete();
        }
        // 确认无孤儿残留
        $left = (int)(Db::query(
            'SELECT COUNT(*) AS c FROM `eb_organization_admin_store_exclude` ex
             LEFT JOIN `eb_organization_admin` a ON a.id = ex.org_admin_id
             WHERE a.id IS NULL'
        )[0]['c'] ?? 0);
        if ($left > 0) {
            // 仅清理本轮 orphanAdminId
            if ($orphanAdminId > 0) {
                Db::name('organization_admin_store_exclude')->where('org_admin_id', $orphanAdminId)->delete();
            }
        }
    }
}

/**
 * F1：只读选择 employee_id 精确匹配的有效账号（不改密码/roles/employee）
 * @return array{admin_id:int,employee_id:int,account:string}|null
 */
function o4PickMatchedAdminAccount(): ?array
{
    $rows = Db::query(
        'SELECT a.id AS admin_id, a.employee_id, a.account
         FROM eb_system_admin a
         INNER JOIN eb_employee e ON e.id = a.employee_id
         WHERE a.status = 1 AND a.is_del = 0 AND a.employee_id > 0
           AND e.status = 1 AND e.is_del = 0
         ORDER BY a.id ASC
         LIMIT 1'
    );
    if (!$rows) {
        return null;
    }
    return [
        'admin_id' => (int)$rows[0]['admin_id'],
        'employee_id' => (int)$rows[0]['employee_id'],
        'account' => (string)$rows[0]['account'],
    ];
}

/**
 * F2/S6：绑定前快照该 store_id 的全部 organization_store 行 + 全部 exclude。
 * 行数>1 时修改前 fail-closed，禁止只取一行后删全部。
 */
function o4SnapshotStoreBinding(int $storeId): array
{
    $rows = Db::name('organization_store')->where('store_id', $storeId)->order('id', 'asc')->select()->toArray();
    $cnt = count($rows);
    if ($cnt > 1) {
        throw new \RuntimeException(
            "organization_store store_id={$storeId} 存在 {$cnt} 行绑定，修改前 fail-closed"
        );
    }
    $excludes = Db::name('organization_admin_store_exclude')->where('store_id', $storeId)->order('id', 'asc')->select()->toArray();
    $first = $cnt === 1 ? (is_array($rows[0]) ? $rows[0] : (array)$rows[0]) : null;
    return [
        'store_id' => $storeId,
        'had_binding' => $cnt === 1,
        'row_count' => $cnt,
        'organization_store_rows' => $rows ?: [],
        'exclude_rows' => $excludes ?: [],
        'old_org_id' => $first ? (int)($first['org_id'] ?? 0) : 0,
    ];
}

/**
 * @return array{scope_mode:string,allowed:array<int>,excluded:array<int>}
 */
function o4ReadPermissionSets(int $orgAdminId, int $orgId): array
{
    $mode = strtolower(trim((string)Db::name('organization_admin')->where('id', $orgAdminId)->value('scope_mode')));
    $excluded = array_map('intval', Db::name('organization_admin_store_exclude')->where('org_admin_id', $orgAdminId)->column('store_id') ?: []);
    sort($excluded);
    $orgStores = array_map('intval', Db::name('organization_store')->where('org_id', $orgId)->column('store_id') ?: []);
    sort($orgStores);
    if ($mode === 'inherit') {
        return ['scope_mode' => 'inherit', 'allowed' => [], 'excluded' => $excluded];
    }
    $allowed = array_values(array_diff($orgStores, $excluded));
    sort($allowed);
    return ['scope_mode' => 'custom', 'allowed' => $allowed, 'excluded' => $excluded];
}

function o4AssertSetsEqual(array $a, array $b, string $label): void
{
    $aa = array_values(array_map('intval', $a));
    $bb = array_values(array_map('intval', $b));
    sort($aa);
    sort($bb);
    if ($aa !== $bb) {
        throw new \RuntimeException($label . ' mismatch expect=' . json_encode($bb) . ' got=' . json_encode($aa));
    }
}

/**
 * S1/S2/S3：F5 双进程并发绑定（A_HOLD 后才启 B；PHP7.4 exitcode；独立 finally）
 * @return array{timeline:array,a_hold_at:float,b_start_at:float,exit_a:int,exit_b:int,out_a:string,err_a:string,out_b:string,err_b:string}
 */
function o4F5ConcurrentBind(string $runId, int $storeId, int $orgA, int $orgB, string $reportDir): array
{
    $holdFile = '/tmp/' . $runId . '_hold';
    $releaseFile = '/tmp/' . $runId . '_release';
    @unlink($holdFile);
    @unlink($releaseFile);
    $workerA = '/tmp/smoke-o4-bind-hold-worker-a.php';
    $workerB = '/tmp/smoke-o4-bind-hold-worker-b.php';
    if (!is_file($workerA) || !is_file($workerB)) {
        throw new \RuntimeException('F5 worker scripts missing in /tmp');
    }

    $pA = null;
    $pB = null;
    $pipesA = [];
    $pipesB = [];
    $outA = '';
    $errA = '';
    $outB = '';
    $errB = '';
    $lastExitA = null;
    $lastExitB = null;
    $closeExitA = null;
    $closeExitB = null;
    $aHoldAt = null;
    $bStartAt = null;
    $timeline = [];
    $assertError = null;

    $drain = static function ($pipes, &$out, &$err): void {
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false && $chunk !== '') {
                $out .= $chunk;
            }
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $chunk = stream_get_contents($pipes[2]);
            if ($chunk !== false && $chunk !== '') {
                $err .= $chunk;
            }
        }
    };
    $closePipes = static function (&$pipes): void {
        foreach ([1, 2] as $fd) {
            if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
                fclose($pipes[$fd]);
                $pipes[$fd] = null;
            }
        }
    };
    $noteExit = static function ($proc, &$lastExit) {
        if (!is_resource($proc)) {
            return ['running' => false, 'exitcode' => -1];
        }
        $st = proc_get_status($proc);
        if (!$st['running'] && isset($st['exitcode']) && (int)$st['exitcode'] >= 0) {
            $lastExit = (int)$st['exitcode'];
        }
        return $st;
    };

    try {
        $cmdA = 'php ' . escapeshellarg($workerA) . ' ' . $storeId . ' ' . $orgA . ' '
            . escapeshellarg($holdFile) . ' ' . escapeshellarg($releaseFile);
        $pA = proc_open($cmdA, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesA);
        if (!is_resource($pA)) {
            throw new \RuntimeException('F5 Worker A failed to start');
        }
        stream_set_blocking($pipesA[1], false);
        stream_set_blocking($pipesA[2], false);
        $timeline[] = ['event' => 'A_PROC_OPEN', 't' => microtime(true)];

        // S1：确认 A_HOLD 后才能启动 B（禁止 usleep 推断）
        $deadlineHold = time() + 25;
        while (time() < $deadlineHold) {
            $drain($pipesA, $outA, $errA);
            $stA = $noteExit($pA, $lastExitA);
            if (is_file($holdFile) && strpos($outA, 'A_HOLD') !== false && strpos($outA, 'A_BOUND') !== false) {
                $aHoldAt = microtime(true);
                $timeline[] = ['event' => 'A_HOLD_CONFIRMED', 't' => $aHoldAt];
                break;
            }
            if (!$stA['running'] && !is_file($holdFile)) {
                throw new \RuntimeException('F5 Worker A exited before hold; out=' . $outA . ' err=' . $errA);
            }
            usleep(30000);
        }
        if ($aHoldAt === null || !is_file($holdFile) || strpos($outA, 'A_HOLD') === false) {
            throw new \RuntimeException('F5 A_HOLD not confirmed before starting B; out=' . $outA . ' err=' . $errA);
        }
        $curOrg = (int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
        if ($curOrg !== $orgA) {
            throw new \RuntimeException('F5 after A_HOLD org_id expected ' . $orgA . ' got ' . $curOrg);
        }

        $cmdB = 'php ' . escapeshellarg($workerB) . ' ' . $storeId . ' ' . $orgB;
        $pB = proc_open($cmdB, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesB);
        if (!is_resource($pB)) {
            throw new \RuntimeException('F5 Worker B failed to start');
        }
        stream_set_blocking($pipesB[1], false);
        stream_set_blocking($pipesB[2], false);
        $bProcOpenAt = microtime(true);
        $timeline[] = ['event' => 'B_PROC_OPEN', 't' => $bProcOpenAt];
        if (!($bProcOpenAt > $aHoldAt)) {
            throw new \RuntimeException('F5 timeline invariant failed: B_PROC_OPEN must be after A_HOLD');
        }

        // T2：条件等待 B_START（截止 5s），禁止固定短 sleep 推断已启动
        $bStartMarkerAt = null;
        $deadlineBStart = microtime(true) + 5.0;
        while (microtime(true) < $deadlineBStart) {
            $drain($pipesA, $outA, $errA);
            $drain($pipesB, $outB, $errB);
            $noteExit($pA, $lastExitA);
            $stB = $noteExit($pB, $lastExitB);
            if (strpos($outB, 'B_DONE') !== false) {
                throw new \RuntimeException('F5 B_DONE before B_START confirmed');
            }
            if (strpos($outB, 'B_ERR') !== false) {
                throw new \RuntimeException('F5 Worker B error while waiting B_START: out=' . $outB . ' err=' . $errB);
            }
            if (strpos($outB, 'B_START') !== false) {
                $bStartMarkerAt = microtime(true);
                $timeline[] = ['event' => 'B_START_SEEN', 't' => $bStartMarkerAt];
                break;
            }
            if (!$stB['running']) {
                throw new \RuntimeException(
                    'F5 Worker B exited before B_START; exit=' . json_encode($lastExitB)
                    . ' out=' . $outB . ' err=' . $errB
                );
            }
            usleep(30000);
        }
        if ($bStartMarkerAt === null || strpos($outB, 'B_START') === false) {
            throw new \RuntimeException(
                'F5 B_START timeout(5s); status_exit=' . json_encode($lastExitB)
                . ' outB=' . $outB . ' errB=' . $errB
                . ' timeline=' . json_encode($timeline)
            );
        }
        $bStartAt = $bStartMarkerAt;

        // 见到 B_START 后再观察一段时间：无 B_DONE、B 仍阻塞、归属仍为 A
        $observeUntil = microtime(true) + 0.8;
        while (microtime(true) < $observeUntil) {
            $drain($pipesA, $outA, $errA);
            $drain($pipesB, $outB, $errB);
            $stA = $noteExit($pA, $lastExitA);
            $stB = $noteExit($pB, $lastExitB);
            if (strpos($outB, 'B_DONE') !== false) {
                throw new \RuntimeException('F5 B_DONE while A still holding lock');
            }
            if (!$stB['running']) {
                throw new \RuntimeException(
                    'F5 Worker B not waiting on lock during observe; exit=' . json_encode($lastExitB)
                    . ' out=' . $outB . ' err=' . $errB
                );
            }
            if (!$stA['running'] && strpos($outA, 'A_UNLOCK') !== false) {
                throw new \RuntimeException('F5 Worker A unlocked during observe unexpectedly');
            }
            $curOrg = (int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
            if ($curOrg !== $orgA) {
                throw new \RuntimeException('F5 during hold org_id expected ' . $orgA . ' got ' . $curOrg);
            }
            usleep(40000);
        }
        $timeline[] = ['event' => 'HOLD_OBSERVE_OK', 't' => microtime(true)];

        file_put_contents($releaseFile, 'release|' . sprintf('%.6f', microtime(true)));
        $timeline[] = ['event' => 'RELEASE_WRITTEN', 't' => microtime(true)];

        $deadlineDone = time() + 25;
        while (time() < $deadlineDone) {
            $drain($pipesA, $outA, $errA);
            $drain($pipesB, $outB, $errB);
            $stA = $noteExit($pA, $lastExitA);
            $stB = $noteExit($pB, $lastExitB);
            if (!$stA['running'] && !$stB['running']) {
                break;
            }
            usleep(40000);
        }
        $drain($pipesA, $outA, $errA);
        $drain($pipesB, $outB, $errB);
        if (strpos($outB, 'B_DONE') === false) {
            throw new \RuntimeException('F5 B_DONE missing after release; outB=' . $outB . ' errB=' . $errB);
        }
        if (strpos($outA, 'A_UNLOCK') === false) {
            throw new \RuntimeException('F5 A_UNLOCK missing after release; outA=' . $outA);
        }
        $rowCnt = (int)Db::name('organization_store')->where('store_id', $storeId)->count();
        if ($rowCnt !== 1) {
            throw new \RuntimeException('F5 store must have exactly one organization_store row, got ' . $rowCnt);
        }
        $finalOrg = (int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
        if ($finalOrg !== $orgB) {
            throw new \RuntimeException('F5 after release expect B org ' . $orgB . ' got ' . $finalOrg);
        }
    } catch (\Throwable $e) {
        $assertError = $e;
    } finally {
        // S3：先 release / 终止 / 排空 / 关闭 / 删信号，再交给外层 DB 恢复
        try {
            if (!is_file($releaseFile)) {
                file_put_contents($releaseFile, 'release|finally|' . sprintf('%.6f', microtime(true)));
            }
        } catch (\Throwable $e) {
        }
        $waitUntil = time() + 8;
        while (time() < $waitUntil) {
            $alive = false;
            if (is_resource($pA)) {
                $drain($pipesA, $outA, $errA);
                $st = $noteExit($pA, $lastExitA);
                if ($st['running']) {
                    $alive = true;
                }
            }
            if (is_resource($pB)) {
                $drain($pipesB, $outB, $errB);
                $st = $noteExit($pB, $lastExitB);
                if ($st['running']) {
                    $alive = true;
                }
            }
            if (!$alive) {
                break;
            }
            usleep(50000);
        }
        if (is_resource($pA)) {
            $st = proc_get_status($pA);
            if ($st['running']) {
                @proc_terminate($pA);
                usleep(120000);
                $noteExit($pA, $lastExitA);
            }
            $drain($pipesA, $outA, $errA);
            $closePipes($pipesA);
            $pc = proc_close($pA);
            $closeExitA = (int)$pc;
            if ($pc === -1 && $lastExitA !== null) {
                // S2：PHP 7.4 成功子进程 proc_close 可能 -1
            } elseif ($pc !== -1) {
                $lastExitA = (int)$pc;
            }
            $pA = null;
        }
        if (is_resource($pB)) {
            $st = proc_get_status($pB);
            if ($st['running']) {
                @proc_terminate($pB);
                usleep(120000);
                $noteExit($pB, $lastExitB);
            }
            $drain($pipesB, $outB, $errB);
            $closePipes($pipesB);
            $pc = proc_close($pB);
            $closeExitB = (int)$pc;
            if ($pc === -1 && $lastExitB !== null) {
                // keep lastExitB
            } elseif ($pc !== -1) {
                $lastExitB = (int)$pc;
            }
            $pB = null;
        }
        @unlink($holdFile);
        @unlink($releaseFile);
        $effectiveA = $lastExitA !== null ? (int)$lastExitA : (($closeExitA !== null && $closeExitA !== -1) ? (int)$closeExitA : -1);
        $effectiveB = $lastExitB !== null ? (int)$lastExitB : (($closeExitB !== null && $closeExitB !== -1) ? (int)$closeExitB : -1);
        $evidence = [
            'timeline' => $timeline,
            'a_hold_at' => $aHoldAt,
            'b_start_at' => $bStartAt,
            'a_hold_before_b_start' => ($aHoldAt !== null && $bStartAt !== null) ? ($aHoldAt < $bStartAt) : null,
            'exitcode_status_a' => $lastExitA,
            'exitcode_status_b' => $lastExitB,
            'proc_close_a' => $closeExitA,
            'proc_close_b' => $closeExitB,
            'effective_exit_a' => $effectiveA,
            'effective_exit_b' => $effectiveB,
            'out_a' => $outA,
            'err_a' => $errA,
            'out_b' => $outB,
            'err_b' => $errB,
            'hold_left' => is_file($holdFile),
            'release_left' => is_file($releaseFile),
            'assert_error' => $assertError ? $assertError->getMessage() : null,
        ];
        @file_put_contents($reportDir . '/f5_concurrent.json', json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    if ($assertError !== null) {
        throw $assertError;
    }
    $effectiveA = $lastExitA !== null ? (int)$lastExitA : -1;
    $effectiveB = $lastExitB !== null ? (int)$lastExitB : -1;
    if ($effectiveA !== 0 || $effectiveB !== 0) {
        throw new \RuntimeException(
            "F5 worker effective exit not 0: A={$effectiveA} (close=" . json_encode($closeExitA)
            . ") B={$effectiveB} (close=" . json_encode($closeExitB) . ')'
        );
    }
    if ($aHoldAt === null || $bStartAt === null || !($aHoldAt < $bStartAt)) {
        throw new \RuntimeException('F5 A_HOLD must be strictly before B_START');
    }
    return [
        'timeline' => $timeline,
        'a_hold_at' => $aHoldAt,
        'b_start_at' => $bStartAt,
        'exit_a' => $effectiveA,
        'exit_b' => $effectiveB,
        'out_a' => $outA,
        'err_a' => $errA,
        'out_b' => $outB,
        'err_b' => $errB,
    ];
}

/**
 * 完整写套件异常：携带 entered/branches/cleanup/residue，供主流程不中断收口
 */
class O4FullWriteSuiteException extends \RuntimeException
{
    /** @var array */
    public $payload;

    public function __construct(string $message, array $payload = [])
    {
        parent::__construct($message);
        $this->payload = $payload;
    }
}

/**
 * 完整写套件：F1～F7；关闸/无表时由调用方 NOT_RUN
 * @return array{ran:bool,entered:bool,note:string,branches?:array,cleanup_errors?:array,residue?:array}
 */
function o4FullWriteSuite(string $runId, OrganizationWorkspaceWriteServices $write, OrganizationManageServices $manage, OrganizationWorkspaceWriteGate $gate, string $reportDir = '/tmp'): array
{
    if (!$gate->isWriteEnabled() || !$gate->isLegacyWriteAllowed()) {
        return ['ran' => false, 'entered' => false, 'note' => '写门禁未开启（禁止本轮自行开闸）'];
    }
    // W2：完整结构门禁——任何测试数据创建/借用/修改之前必须 ready=true
    $upgrade011 = o4Upgrade011Status();
    if (empty($upgrade011['ready'])) {
        $blockers = is_array($upgrade011['blockers'] ?? null) ? $upgrade011['blockers'] : [];
        return [
            'ran' => false,
            'entered' => false,
            'note' => '011 完整结构未就绪: ' . implode('; ', array_slice($blockers, 0, 12)),
            'branches' => null,
            'cleanup_errors' => [],
            'residue' => [],
            'upgrade_011_blockers' => $blockers,
        ];
    }

    $super = ['id' => 1, 'level' => 0, 'admin_type' => 0, 'account' => 'admin'];
    $marker = 'o4smoke_' . $runId;
    $allCreatedOrgIds = [];
    $allCreatedOrgAdminIds = [];
    $allTokens = [];
    $borrowedStoreSnapshots = [];
    $cleanupErrors = [];
    $residue = [];
    $entered = true;
    $businessError = null;
    $branches = [
        'leader_second' => null,
        'leader_left_reject' => null,
        'permission_matched_account' => null,
        'permission_custom_empty_partial_all' => null,
        'store_second' => null,
        'store_new_join_custom' => null,
        'bind_store_concurrent_f5' => null,
        'idempotency_conflict_branch' => null,
        'f6_audit_txn_rollback' => null,
    ];

    $trackToken = static function (string $token) use (&$allTokens): string {
        $allTokens[] = $token;
        return $token;
    };
    $ctx = static function (string $token): array {
        return ['header_token' => $token, 'body_token' => '', 'operator_ip' => '127.0.0.1'];
    };
    $borrowStore = static function (int $storeId) use (&$borrowedStoreSnapshots): void {
        foreach ($borrowedStoreSnapshots as $s) {
            if ((int)$s['store_id'] === $storeId) {
                return;
            }
        }
        $borrowedStoreSnapshots[] = o4SnapshotStoreBinding($storeId);
    };
    $payloadOf = static function () use (&$entered, &$branches, &$cleanupErrors, &$residue): array {
        return [
            'entered' => (bool)$entered,
            'ran' => (bool)$entered,
            'branches' => $branches,
            'cleanup_errors' => $cleanupErrors,
            'residue' => $residue,
        ];
    };

    try {
        $root = Db::name('organization')->where('pid', 0)->where('is_del', 0)->order('id', 'asc')->find();
        if (!$root) {
            throw new \RuntimeException('no root org');
        }
        $parentId = (int)$root['id'];

        $tokenCreate = $trackToken(uuidV4());
        $ret = $write->saveOrganization(0, ['pid' => $parentId, 'name' => $marker . '_A', 'sort' => 9], $super, $ctx($tokenCreate));
        $orgA = (int)($ret['data']['id'] ?? 0);
        if ($orgA <= 0) {
            throw new \RuntimeException('create org failed');
        }
        $allCreatedOrgIds[] = $orgA;

        $ret2 = $write->saveOrganization(0, ['pid' => $parentId, 'name' => $marker . '_A', 'sort' => 9], $super, $ctx($tokenCreate));
        if (empty($ret2['replay']) || (int)($ret2['data']['id'] ?? 0) !== $orgA) {
            throw new \RuntimeException('idempotent replay failed');
        }

        $conflict = false;
        try {
            $write->saveOrganization(0, ['pid' => $parentId, 'name' => $marker . '_B', 'sort' => 1], $super, $ctx($tokenCreate));
        } catch (\Throwable $e) {
            $conflict = strpos($e->getMessage(), OrganizationWorkspaceWriteServices::ERR_IDEMPOTENCY_CONFLICT) !== false;
        }
        if (!$conflict) {
            throw new \RuntimeException('expected IDEMPOTENCY_TOKEN_CONFLICT');
        }
        $branches['idempotency_conflict_branch'] = [
            'status' => 'PASS',
            'note' => 'same token different body rejected',
        ];

        $tokenEdit = $trackToken(uuidV4());
        $write->saveOrganization($orgA, ['pid' => $parentId, 'name' => $marker . '_A2', 'sort' => 8], $super, $ctx($tokenEdit));

        $badSelf = false;
        try {
            $write->saveOrganization($orgA, ['pid' => $orgA, 'name' => $marker . '_A2', 'sort' => 8], $super, $ctx($trackToken(uuidV4())));
        } catch (\Throwable $e) {
            $badSelf = true;
        }
        if (!$badSelf) {
            throw new \RuntimeException('self parent should fail');
        }

        $badRoot = false;
        try {
            $write->saveOrganization(0, ['pid' => 0, 'name' => $marker . '_ROOT', 'sort' => 0], $super, $ctx($trackToken(uuidV4())));
        } catch (\Throwable $e) {
            $badRoot = true;
        }
        if (!$badRoot) {
            throw new \RuntimeException('second root should fail');
        }

        $tokenChild = $trackToken(uuidV4());
        $retChild = $write->saveOrganization(0, ['pid' => $orgA, 'name' => $marker . '_C', 'sort' => 1], $super, $ctx($tokenChild));
        $orgC = (int)($retChild['data']['id'] ?? 0);
        $allCreatedOrgIds[] = $orgC;
        $badCycle = false;
        try {
            $write->saveOrganization($orgA, ['pid' => $orgC, 'name' => $marker . '_A2', 'sort' => 8], $super, $ctx($trackToken(uuidV4())));
        } catch (\Throwable $e) {
            $badCycle = true;
        }
        if (!$badCycle) {
            throw new \RuntimeException('descendant cycle should fail');
        }

        $emp = Db::name('employee')->where('is_del', 0)->where('status', 1)->order('id', 'asc')->find();
        if (!$emp) {
            throw new \RuntimeException('no employee for leader tests');
        }
        $empId = (int)$emp['id'];

        $tokenL1 = $trackToken(uuidV4());
        $write->saveLeaders($orgA, [['employee_id' => $empId, 'sort' => 1]], $super, $ctx($tokenL1));
        $leaderRow = Db::name('organization_leader')->where('org_id', $orgA)->where('employee_id', $empId)->find();
        if (!$leaderRow || (int)$leaderRow['is_del'] !== 0) {
            throw new \RuntimeException('leader add failed');
        }
        $emp2 = Db::name('employee')->where('is_del', 0)->where('status', 1)->where('id', '<>', $empId)->order('id', 'asc')->find();
        if ($emp2) {
            $write->saveLeaders($orgA, [
                ['employee_id' => $empId, 'sort' => 1],
                ['employee_id' => (int)$emp2['id'], 'sort' => 2],
            ], $super, $ctx($trackToken(uuidV4())));
            $branches['leader_second'] = ['status' => 'PASS', 'note' => 'second leader ok'];
        } else {
            $branches['leader_second'] = ['status' => 'NOT_RUN', 'note' => '缺少第二在职员工样本'];
        }
        $write->saveLeaders($orgA, [], $super, $ctx($trackToken(uuidV4())));
        if ((int)Db::name('organization_leader')->where('id', (int)$leaderRow['id'])->value('is_del') !== 1) {
            throw new \RuntimeException('leader soft delete failed');
        }
        // 复活：业务层会将 sort 重排为稳定序号（单人时为 1），不得要求入参 sort 原样保留
        $write->saveLeaders($orgA, [['employee_id' => $empId, 'sort' => 2]], $super, $ctx($trackToken(uuidV4())));
        $rev = Db::name('organization_leader')->where('id', (int)$leaderRow['id'])->find();
        if (!$rev || (int)$rev['is_del'] !== 0 || (int)$rev['employee_id'] !== $empId || (int)$rev['sort'] !== 1) {
            throw new \RuntimeException(
                'leader revive failed: ' . json_encode($rev, JSON_UNESCAPED_UNICODE)
            );
        }
        $leftEmp = Db::name('employee')->where('is_del', 0)->where('status', '<>', 1)->order('id', 'asc')->find();
        if ($leftEmp) {
            $leftReject = false;
            try {
                $write->saveLeaders($orgA, [['employee_id' => (int)$leftEmp['id'], 'sort' => 1]], $super, $ctx($trackToken(uuidV4())));
            } catch (\Throwable $e) {
                $leftReject = true;
            }
            if (!$leftReject) {
                throw new \RuntimeException('left employee leader should reject');
            }
            $branches['leader_left_reject'] = ['status' => 'PASS', 'note' => 'left employee rejected'];
        } else {
            $branches['leader_left_reject'] = ['status' => 'NOT_RUN', 'note' => '缺少离职/非在职员工样本'];
        }

        // —— 门店借用（F2/S6）：先至少两店，再测 custom 空/部分/全部 ——
        $storeRows = Db::name('system_store')->where('is_del', 0)->order('id', 'asc')->limit(3)->select()->toArray();
        if (count($storeRows) < 1) {
            throw new \RuntimeException('no system_store for bind tests');
        }
        $sid1 = (int)$storeRows[0]['id'];
        $borrowStore($sid1);
        $bindRet = $write->bindStore($sid1, $orgA, $super, $ctx($trackToken(uuidV4())));
        if (empty($bindRet['data']['changed']) && (int)($bindRet['data']['org_id'] ?? 0) !== $orgA) {
            throw new \RuntimeException('bind store failed');
        }
        $storeIds = [$sid1];
        if (count($storeRows) >= 2) {
            $sid2 = (int)$storeRows[1]['id'];
            $borrowStore($sid2);
            $write->bindStore($sid2, $orgA, $super, $ctx($trackToken(uuidV4())));
            $storeIds[] = $sid2;
            sort($storeIds);
            $branches['store_second'] = ['status' => 'PASS', 'note' => 'bound two stores'];
        } else {
            $branches['store_second'] = ['status' => 'NOT_RUN', 'note' => '有效门店不足 2 家'];
        }

        // F1 权限人员
        $matched = o4PickMatchedAdminAccount();
        $oaId = 0;
        if (!$matched) {
            $branches['permission_matched_account'] = [
                'status' => 'NOT_RUN',
                'note' => '本地无 status=1/is_del=0 且 employee_id>0 精确匹配的 system_admin+employee',
            ];
            $branches['permission_custom_empty_partial_all'] = [
                'status' => 'NOT_RUN',
                'note' => '无匹配账号，跳过权限写',
            ];
            $branches['store_new_join_custom'] = [
                'status' => 'NOT_RUN',
                'note' => '无匹配账号，跳过 custom 新店不扩权',
            ];
        } else {
            $branches['permission_matched_account'] = [
                'status' => 'PASS',
                'note' => 'admin_id=' . $matched['admin_id'] . ' employee_id=' . $matched['employee_id'],
            ];
            $now = time();
            $oaId = (int)Db::name('organization_admin')->insertGetId([
                'org_id' => $orgA,
                'employee_id' => $matched['employee_id'],
                'name' => $marker . '_oa',
                'phone' => '',
                'admin_id' => $matched['admin_id'],
                'uid' => 0,
                'legacy_agent_id' => 0,
                'is_del' => 0,
                'scope_mode' => 'inherit',
                'add_time' => $now,
                'update_time' => $now,
            ]);
            $allCreatedOrgAdminIds[] = $oaId;

            $write->saveAdminPermission($oaId, 'inherit', [], $super, $ctx($trackToken(uuidV4())));
            $st = o4ReadPermissionSets($oaId, $orgA);
            if ($st['scope_mode'] !== 'inherit' || $st['allowed'] !== []) {
                throw new \RuntimeException('inherit assert failed');
            }

            if (count($storeIds) < 2) {
                $branches['permission_custom_empty_partial_all'] = [
                    'status' => 'NOT_RUN',
                    'note' => '测试组织有效门店不足 2 家，无法真实覆盖空/部分/全部',
                ];
            } else {
                // custom 空集
                $write->saveAdminPermission($oaId, 'custom', [], $super, $ctx($trackToken(uuidV4())));
                $st = o4ReadPermissionSets($oaId, $orgA);
                if ($st['scope_mode'] !== 'custom' || $st['allowed'] !== []) {
                    throw new \RuntimeException('custom empty allowed assert failed');
                }
                o4AssertSetsEqual($st['excluded'], $storeIds, 'custom empty excluded');

                // custom 部分（真子集）
                $partial = [$storeIds[0]];
                $complement = array_values(array_diff($storeIds, $partial));
                $write->saveAdminPermission($oaId, 'custom', $partial, $super, $ctx($trackToken(uuidV4())));
                $st = o4ReadPermissionSets($oaId, $orgA);
                if ($st['scope_mode'] !== 'custom') {
                    throw new \RuntimeException('custom partial scope_mode');
                }
                o4AssertSetsEqual($st['allowed'], $partial, 'custom partial allowed');
                o4AssertSetsEqual($st['excluded'], $complement, 'custom partial excluded');

                // custom 全部
                $write->saveAdminPermission($oaId, 'custom', $storeIds, $super, $ctx($trackToken(uuidV4())));
                $st = o4ReadPermissionSets($oaId, $orgA);
                o4AssertSetsEqual($st['allowed'], $storeIds, 'custom all allowed');
                o4AssertSetsEqual($st['excluded'], [], 'custom all excluded');
                $branches['permission_custom_empty_partial_all'] = [
                    'status' => 'PASS',
                    'note' => 'empty/partial/all verified on ' . count($storeIds) . ' stores',
                ];
            }

            // 越界：前后不变（有 oa 即可）
            $beforeOob = o4ReadPermissionSets($oaId, $orgA);
            $logBeforeOob = (int)Db::name('organization_change_log')->where('org_id', $orgA)->where('action', 'save_admin_permission')->count();
            $oob = false;
            try {
                $write->saveAdminPermission($oaId, 'custom', [999999991], $super, $ctx($trackToken(uuidV4())));
            } catch (\Throwable $e) {
                $oob = true;
            }
            if (!$oob) {
                throw new \RuntimeException('oob allowed should fail');
            }
            $afterOob = o4ReadPermissionSets($oaId, $orgA);
            $logAfterOob = (int)Db::name('organization_change_log')->where('org_id', $orgA)->where('action', 'save_admin_permission')->count();
            if ($beforeOob !== $afterOob || $logBeforeOob !== $logAfterOob) {
                throw new \RuntimeException('oob must leave scope/logs unchanged');
            }

            // custom 新门店不扩权
            if (count($storeIds) < 1) {
                $branches['store_new_join_custom'] = ['status' => 'NOT_RUN', 'note' => '无门店'];
            } else {
                $write->saveAdminPermission($oaId, 'custom', [$storeIds[0]], $super, $ctx($trackToken(uuidV4())));
                $beforeAllowed = o4ReadPermissionSets($oaId, $orgA)['allowed'];
                $newStore = Db::name('system_store')->where('is_del', 0)->whereNotIn('id', $storeIds)->order('id', 'asc')->find();
                if ($newStore) {
                    $newSid = (int)$newStore['id'];
                    $borrowStore($newSid);
                    $write->bindStore($newSid, $orgA, $super, $ctx($trackToken(uuidV4())));
                    $storeIds[] = $newSid;
                    $afterJoin = o4ReadPermissionSets($oaId, $orgA);
                    o4AssertSetsEqual($afterJoin['allowed'], $beforeAllowed, 'custom new store allowed unchanged');
                    if (!in_array($newSid, $afterJoin['excluded'], true)) {
                        throw new \RuntimeException('custom new store must be excluded');
                    }
                    $branches['store_new_join_custom'] = ['status' => 'PASS', 'note' => 'new store excluded, allowed unchanged'];
                } else {
                    $branches['store_new_join_custom'] = ['status' => 'NOT_RUN', 'note' => '无第三家可用门店样本'];
                }
            }
        }

        // F4：同组织重复绑定
        $logBefore = (int)Db::name('organization_change_log')->where('org_id', $orgA)->where('action', 'bind_store')->count();
        $rebind = $write->bindStore($sid1, $orgA, $super, $ctx($trackToken(uuidV4())));
        if (!empty($rebind['data']['changed'])) {
            throw new \RuntimeException('repeat bind changed must be false');
        }
        $logAfter = (int)Db::name('organization_change_log')->where('org_id', $orgA)->where('action', 'bind_store')->count();
        if ($logAfter !== $logBefore) {
            throw new \RuntimeException('repeat bind log count must be strictly equal');
        }

        // 失败后同 token 可重试
        $tokenRetry = $trackToken(uuidV4());
        $failOnce = false;
        try {
            $write->saveOrganization(0, ['pid' => 0, 'name' => $marker . '_retry', 'sort' => 0], $super, $ctx($tokenRetry));
        } catch (\Throwable $e) {
            $failOnce = true;
        }
        if (!$failOnce) {
            throw new \RuntimeException('expected first retry attempt to fail');
        }
        if ((int)Db::name('organization_write_idempotency')->where('request_token', $tokenRetry)->count() !== 0) {
            throw new \RuntimeException('failed attempt should not leave idempotency row');
        }
        $okRetry = $write->saveOrganization(0, ['pid' => $parentId, 'name' => $marker . '_retry_ok', 'sort' => 0], $super, $ctx($tokenRetry));
        $orgRetry = (int)($okRetry['data']['id'] ?? 0);
        $allCreatedOrgIds[] = $orgRetry;

        // F6：审计故障整单回滚 + 同 token 重试
        $tokenFault = $trackToken(uuidV4());
        $faultName = $marker . '_fault_org';
        OrganizationWorkspaceWriteServices::armCliAuditFaultOnce();
        $faulted = false;
        try {
            $write->saveOrganization(0, ['pid' => $parentId, 'name' => $faultName, 'sort' => 0], $super, $ctx($tokenFault));
        } catch (\Throwable $e) {
            $faulted = strpos($e->getMessage(), OrganizationWorkspaceWriteServices::ERR_CLI_AUDIT_FAULT) !== false;
        }
        if (!$faulted) {
            throw new \RuntimeException('CLI audit fault not triggered');
        }
        if ((int)Db::name('organization')->where('name', $faultName)->count() !== 0) {
            throw new \RuntimeException('fault: business row should not exist');
        }
        $auditLeft = (int)Db::query(
            "SELECT COUNT(*) c FROM eb_organization_change_log WHERE after_data LIKE ?",
            ['%' . $faultName . '%']
        )[0]['c'];
        if ($auditLeft !== 0) {
            throw new \RuntimeException('fault: audit row should not exist');
        }
        if ((int)Db::name('organization_write_idempotency')->where('request_token', $tokenFault)->count() !== 0) {
            throw new \RuntimeException('fault: idempotency placeholder should not exist');
        }
        if (OrganizationWorkspaceWriteServices::isCliAuditFaultArmed()) {
            throw new \RuntimeException('fault flag should be consumed');
        }
        $retryFault = $write->saveOrganization(0, ['pid' => $parentId, 'name' => $faultName, 'sort' => 0], $super, $ctx($tokenFault));
        $orgFault = (int)($retryFault['data']['id'] ?? 0);
        if ($orgFault <= 0) {
            throw new \RuntimeException('same token retry after fault failed');
        }
        $allCreatedOrgIds[] = $orgFault;
        $branches['f6_audit_txn_rollback'] = [
            'status' => 'PASS',
            'note' => 'audit fault rolled back; business/audit/idem zero; same token retry ok',
        ];

        // F5：双进程确定性持锁（S1～S3）
        $orgBtoken = $trackToken(uuidV4());
        $retB = $write->saveOrganization(0, ['pid' => $parentId, 'name' => $marker . '_BORG', 'sort' => 1], $super, $ctx($orgBtoken));
        $orgB = (int)$retB['data']['id'];
        $allCreatedOrgIds[] = $orgB;
        $f5 = o4F5ConcurrentBind($runId, $sid1, $orgA, $orgB, $reportDir);
        $branches['bind_store_concurrent_f5'] = [
            'status' => 'PASS',
            'note' => 'A_HOLD=' . $f5['a_hold_at'] . ' B_START=' . $f5['b_start_at'],
        ];

        // 清理业务侧可删组织（finally 仍全量清理）
        $write->saveLeaders($orgA, [], $super, $ctx($trackToken(uuidV4())));
        if ($oaId > 0) {
            Db::name('organization_admin_store_exclude')->where('org_admin_id', $oaId)->delete();
            Db::name('organization_admin')->where('id', $oaId)->update(['is_del' => 1, 'update_time' => time()]);
        }
        if ($orgC > 0) {
            $write->deleteOrganization($orgC, $super, $ctx($trackToken(uuidV4())));
        }
    } catch (\Throwable $e) {
        $businessError = $e;
    } finally {
        // F2/F3：同一把结构锁内清理+恢复（禁止在 finally 内抛错，以免绕过主流程快照收口）
        try {
            $manage->withOrganizationStructureLock(function () use (
                &$cleanupErrors,
                $allCreatedOrgIds,
                $allCreatedOrgAdminIds,
                $allTokens,
                $borrowedStoreSnapshots
            ) {
                // 1) 按店恢复 organization_store + exclude
                foreach ($borrowedStoreSnapshots as $snap) {
                    $sid = (int)$snap['store_id'];
                    try {
                        Db::name('organization_store')->where('store_id', $sid)->delete();
                        if (!empty($snap['had_binding']) && !empty($snap['organization_store_rows'])) {
                            foreach ($snap['organization_store_rows'] as $row) {
                                $row = is_array($row) ? $row : (array)$row;
                                Db::name('organization_store')->insert([
                                    'id' => (int)$row['id'],
                                    'org_id' => (int)$row['org_id'],
                                    'store_id' => (int)$row['store_id'],
                                    'add_time' => (int)$row['add_time'],
                                ]);
                            }
                        }
                        Db::name('organization_admin_store_exclude')->where('store_id', $sid)->delete();
                        foreach ($snap['exclude_rows'] as $ex) {
                            Db::name('organization_admin_store_exclude')->insert([
                                'id' => (int)$ex['id'],
                                'org_admin_id' => (int)$ex['org_admin_id'],
                                'store_id' => (int)$ex['store_id'],
                                'add_time' => (int)$ex['add_time'],
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $cleanupErrors[] = 'restore_store_' . $sid . ':' . $e->getMessage();
                    }
                }

                $orgIds = array_values(array_unique(array_filter(array_map('intval', $allCreatedOrgIds))));
                $adminIds = array_values(array_unique(array_filter(array_map('intval', $allCreatedOrgAdminIds))));

                try {
                    if ($allTokens) {
                        Db::name('organization_write_idempotency')->whereIn('request_token', $allTokens)->delete();
                    }
                } catch (\Throwable $e) {
                    $cleanupErrors[] = 'idempotency:' . $e->getMessage();
                }

                try {
                    if ($adminIds) {
                        Db::name('organization_admin_store_exclude')->whereIn('org_admin_id', $adminIds)->delete();
                        Db::name('organization_admin')->whereIn('id', $adminIds)->delete();
                    }
                } catch (\Throwable $e) {
                    $cleanupErrors[] = 'org_admin:' . $e->getMessage();
                }

                try {
                    if ($orgIds) {
                        Db::name('organization_leader')->whereIn('org_id', $orgIds)->delete();
                        Db::name('organization_change_log')->whereIn('org_id', $orgIds)->delete();
                        Db::name('organization_store')->whereIn('org_id', $orgIds)->delete();
                        Db::name('organization')->whereIn('id', $orgIds)->delete();
                    }
                } catch (\Throwable $e) {
                    $cleanupErrors[] = 'org:' . $e->getMessage();
                }

                try {
                    if ($orgIds) {
                        Db::name('employee_change_log')->where('target_type', 'organization')->whereIn('target_id', $orgIds)->delete();
                    }
                    if ($adminIds) {
                        Db::name('employee_change_log')->where('target_type', 'org_admin')->whereIn('target_id', $adminIds)->delete();
                    }
                } catch (\Throwable $e) {
                    $cleanupErrors[] = 'employee_change_log:' . $e->getMessage();
                }
            });
        } catch (\Throwable $e) {
            $cleanupErrors[] = 'structure_lock_cleanup:' . $e->getMessage();
        }

        // F3 residue=0
        $residue = [
            'org' => $allCreatedOrgIds ? (int)Db::name('organization')->whereIn('id', $allCreatedOrgIds)->count() : 0,
            'org_admin' => $allCreatedOrgAdminIds ? (int)Db::name('organization_admin')->whereIn('id', $allCreatedOrgAdminIds)->count() : 0,
            'idem' => 0,
            'org_log' => $allCreatedOrgIds ? (int)Db::name('organization_change_log')->whereIn('org_id', $allCreatedOrgIds)->count() : 0,
            'emp_log_org' => $allCreatedOrgIds ? (int)Db::name('employee_change_log')->where('target_type', 'organization')->whereIn('target_id', $allCreatedOrgIds)->count() : 0,
            'emp_log_oa' => $allCreatedOrgAdminIds ? (int)Db::name('employee_change_log')->where('target_type', 'org_admin')->whereIn('target_id', $allCreatedOrgAdminIds)->count() : 0,
            'leader' => $allCreatedOrgIds ? (int)Db::name('organization_leader')->whereIn('org_id', $allCreatedOrgIds)->count() : 0,
        ];
        if ($allTokens) {
            try {
                $residue['idem'] = (int)Db::name('organization_write_idempotency')->whereIn('request_token', $allTokens)->count();
            } catch (\Throwable $e) {
                $residue['idem'] = -1;
                $cleanupErrors[] = 'residue_idem:' . $e->getMessage();
            }
        }
        $residueSum = 0;
        foreach ($residue as $v) {
            $residueSum += max(0, (int)$v);
        }
        if ($cleanupErrors || $residueSum !== 0) {
            // 记录到 cleanup_errors，由 try/finally 之后统一抛 O4FullWriteSuiteException
            if ($residueSum !== 0 && !$cleanupErrors) {
                $cleanupErrors[] = 'residue_nonzero';
            }
        }
    }

    $base = $payloadOf();
    if ($businessError) {
        $base['note'] = 'full write suite FAIL: ' . $businessError->getMessage();
        throw new O4FullWriteSuiteException($businessError->getMessage(), $base);
    }
    if ($cleanupErrors) {
        $base['note'] = 'cleanup_failed: ' . implode('; ', array_slice($cleanupErrors, 0, 5))
            . ' residue=' . json_encode($residue, JSON_UNESCAPED_UNICODE);
        throw new O4FullWriteSuiteException($base['note'], $base);
    }
    $base['note'] = 'full write suite completed';
    return $base;
}

/**
 * 故障注入（不依赖 011）
 */
function o4FaultAndConcurrentBranches(OrganizationManageServices $manage): array
{
    $threw = false;
    try {
        $manage->writeLog(1, 'smoke_fault', 'organization', 1, ['bad' => NAN], [], 'fault', 1, 'admin', [
            'request_token' => uuidV4(),
            'request_id' => 'fault',
            'operator_ip' => '127.0.0.1',
        ]);
    } catch (\Throwable $e) {
        $threw = strpos($e->getMessage(), '序列化失败') !== false;
    }
    if (!$threw) {
        $threw2 = false;
        try {
            $manage->writeLog(1, 'smoke_fault', 'organization', 1, ['r' => fopen('php://memory', 'r')], [], 'fault', 1, 'admin', [
                'request_token' => uuidV4(),
                'request_id' => 'fault2',
                'operator_ip' => '127.0.0.1',
            ]);
        } catch (\Throwable $e) {
            $threw2 = true;
        }
        if (!$threw2) {
            throw new \RuntimeException('audit serialize fault not triggered');
        }
    }
    return ['ok' => true];
}

try {
    $before = coreSnapshot();
} catch (\Throwable $e) {
    fail('snapshot_before', $e->getMessage());
    echo "RESULT=FAIL\n";
    exit(1);
}
file_put_contents($reportDir . '/snapshot_before.json', json_encode($before, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

/** @var OrganizationWorkspaceWriteGate $gate */
$gate = app()->make(OrganizationWorkspaceWriteGate::class);
/** @var OrganizationWorkspaceWriteServices $write */
$write = app()->make(OrganizationWorkspaceWriteServices::class);
/** @var OrganizationManageServices $manage */
$manage = app()->make(OrganizationManageServices::class);

echo "=== P1-1 identity matrix ===\n";
$cases = [
    'empty' => [[], false, OrganizationWorkspaceWriteGate::REASON_IDENTITY_UNKNOWN],
    'missing_id' => [['level' => 0, 'admin_type' => 0], false, OrganizationWorkspaceWriteGate::REASON_IDENTITY_UNKNOWN],
    'id_zero' => [['id' => 0, 'level' => 0, 'admin_type' => 0], false, OrganizationWorkspaceWriteGate::REASON_IDENTITY_UNKNOWN],
    'missing_level' => [['id' => 1, 'admin_type' => 0], false, OrganizationWorkspaceWriteGate::REASON_IDENTITY_UNKNOWN],
    'missing_admin_type' => [['id' => 1, 'level' => 0], false, OrganizationWorkspaceWriteGate::REASON_IDENTITY_UNKNOWN],
    'level_nonzero' => [['id' => 1, 'level' => 1, 'admin_type' => 0], false, OrganizationWorkspaceWriteGate::REASON_NOT_SUPER_ADMIN],
    'admin_type_3' => [['id' => 1, 'level' => 0, 'admin_type' => 3], false, OrganizationWorkspaceWriteGate::REASON_NOT_SUPER_ADMIN],
    'ok_super' => [['id' => 1, 'level' => 0, 'admin_type' => 0, 'account' => 'admin'], true, OrganizationWorkspaceWriteGate::REASON_OK],
];
foreach ($cases as $name => $tuple) {
    [$info, $expectOk, $expectCode] = $tuple;
    $r = $gate->assertSuperAdmin($info);
    $st = $write->getWriteStatus($info);
    if ((bool)$r['ok'] !== $expectOk || ($r['reason_code'] ?? '') !== $expectCode) {
        fail('identity_' . $name, json_encode($r, JSON_UNESCAPED_UNICODE));
    } elseif ((bool)$st['is_super_admin'] !== $expectOk) {
        fail('identity_status_' . $name, json_encode($st, JSON_UNESCAPED_UNICODE));
    } else {
        pass('identity_' . $name, $r['reason_code']);
    }
}

echo "=== gate / write_status / UUID / by_agent pre-gate ===\n";
$eval = $gate->evaluateWriteGate();
pass('gate_shape', ($eval['reason_code'] ?? '') . ' source=' . ($eval['source_mode'] ?? ''));
if (!$eval['ok']) {
    pass('gate_closed', $eval['reason_code']);
} else {
    pass('gate_open_note', '本地若已开闸，验收后须关回 0');
}

$flag = $gate->readConfigFlag(OrganizationWorkspaceWriteGate::CFG_WRITE_ENABLED);
if (($flag['count'] ?? 0) > 1) {
    fail('cfg_duplicate', 'platform config count=' . $flag['count']);
} else {
    pass('cfg_platform_read', json_encode($flag, JSON_UNESCAPED_UNICODE));
}

if (!$write->isValidUuid('not-a-uuid')) {
    pass('uuid_reject_invalid');
} else {
    fail('uuid_reject_invalid', 'accepted bad token');
}
if ($write->isValidUuid(uuidV4())) {
    pass('uuid_accept_v4');
} else {
    fail('uuid_accept_v4', 'rejected valid');
}

$super = ['id' => 1, 'level' => 0, 'admin_type' => 0, 'account' => 'admin'];
$nonSuper = ['id' => 2, 'level' => 1, 'admin_type' => 0, 'account' => 'staff'];

if (!$eval['ok']) {
    $msgMissing = '';
    $msgExist = '';
    try {
        $write->saveAdminExcludesByAgent(999999991, [], $super, [
            'header_token' => uuidV4(), 'body_token' => '', 'operator_ip' => '127.0.0.1',
        ]);
    } catch (\Throwable $e) {
        $msgMissing = $e->getMessage();
    }
    $legacyId = (int)(Db::name('organization_admin')->where('is_del', 0)->where('legacy_agent_id', '>', 0)->value('legacy_agent_id') ?: 0);
    try {
        $write->saveAdminExcludesByAgent($legacyId > 0 ? $legacyId : 1, [], $super, [
            'header_token' => uuidV4(), 'body_token' => '', 'operator_ip' => '127.0.0.1',
        ]);
    } catch (\Throwable $e) {
        $msgExist = $e->getMessage();
    }
    if ($msgMissing === '' || $msgExist === '' || $msgMissing !== $msgExist) {
        fail('by_agent_pre_gate_uniform', "missing={$msgMissing}; exist={$msgExist}");
    } elseif (strpos($msgMissing, '迁移') !== false || strpos($msgMissing, '不存在') !== false) {
        fail('by_agent_pre_gate_leaked', $msgMissing);
    } else {
        pass('by_agent_pre_gate_uniform', $msgMissing);
    }
} else {
    not_run('by_agent_pre_gate_uniform', '门禁开启，跳过关闸一致性用例');
}

$stNon = $write->getWriteStatus($nonSuper);
if ($stNon['can_write'] || $stNon['is_super_admin']) {
    fail('nonsuper_status', json_encode($stNon, JSON_UNESCAPED_UNICODE));
} else {
    pass('nonsuper_status', $stNon['reason_code']);
}

try {
    $manage->assertOrganizationSaveAllowed(0, 1, 'o4-smoke-name-check', false);
    pass('manage_no_page_gate');
} catch (\Throwable $e) {
    if (strpos($e->getMessage(), '只读阶段') !== false || strpos($e->getMessage(), '写入未开放') !== false) {
        fail('manage_no_page_gate', $e->getMessage());
    } else {
        pass('manage_no_page_gate_biz', $e->getMessage());
    }
}

echo "=== HTTP mapping no-bypass (closed gate) ===\n";
if (!$eval['ok']) {
    $endpoints = [
        'save' => function () use ($write, $super) {
            $write->saveOrganization(0, ['pid' => 1, 'name' => 'x', 'sort' => 0], $super, ['header_token' => uuidV4()]);
        },
        'delete' => function () use ($write, $super) {
            $write->deleteOrganization(1, $super, ['header_token' => uuidV4()]);
        },
        'bind' => function () use ($write, $super) {
            $write->bindStore(1, 1, $super, ['header_token' => uuidV4()]);
        },
        'exclude' => function () use ($write, $super) {
            $write->saveAdminExcludes(1, [], $super, ['header_token' => uuidV4()]);
        },
        'exclude_by_agent' => function () use ($write, $super) {
            $write->saveAdminExcludesByAgent(1, [], $super, ['header_token' => uuidV4()]);
        },
        'leaders' => function () use ($write, $super) {
            $write->saveLeaders(1, [], $super, ['header_token' => uuidV4()]);
        },
        'permission' => function () use ($write, $super) {
            $write->saveAdminPermission(1, 'inherit', [], $super, ['header_token' => uuidV4()]);
        },
    ];
    foreach ($endpoints as $name => $fn) {
        try {
            $fn();
            fail('http_deny_' . $name, 'unexpected success');
        } catch (\Throwable $e) {
            pass('http_deny_' . $name, $e->getMessage());
        }
    }
} else {
    not_run('http_deny_all', '门禁开启，跳过关闸拒绝矩阵');
}

echo "=== R1 scope_mode rerun semantics ===\n";
try {
    o4R1ScopeModeRerunSemantics($runId);
    pass('r1_scope_mode_rerun_keeps_custom_empty_exclude');
} catch (\Throwable $e) {
    fail('r1_scope_mode_rerun_keeps_custom_empty_exclude', $e->getMessage());
}

echo "=== R2 idempotency structure hard check ===\n";
try {
    $r2 = o4R2IdempotencyStructureHardCheck($runId);
    pass('r2_bad_structure_verify_fail', 'bad=' . $r2['bad_verify'] . ' good=' . $r2['good_verify']);
    file_put_contents($reportDir . '/r2_structure.json', json_encode($r2, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
} catch (\Throwable $e) {
    fail('r2_bad_structure_verify_fail', $e->getMessage());
}

echo "=== R5 orphan exclude reject ===\n";
try {
    o4R5OrphanExcludeReject($manage, $runId);
    pass('r5_orphan_exclude_blocks_delete');
} catch (\Throwable $e) {
    fail('r5_orphan_exclude_blocks_delete', $e->getMessage());
}

echo "=== fault inject (no DDL) / F6 CLI isolation ===\n";
try {
    o4FaultAndConcurrentBranches($manage);
    pass('fault_inject_audit_serialize');
} catch (\Throwable $e) {
    fail('fault_inject_audit_serialize', $e->getMessage());
}
if (PHP_SAPI === 'cli' && OrganizationWorkspaceWriteServices::isCliAuditFaultArmed() === false) {
    pass('f6_cli_fault_default_off', 'PHP_SAPI=cli armed=false');
} else {
    fail('f6_cli_fault_default_off', 'unexpected armed state');
}
// 武装后消费一次，证明一次性；非 CLI 路径在类内硬返回 false
OrganizationWorkspaceWriteServices::armCliAuditFaultOnce();
if (!OrganizationWorkspaceWriteServices::isCliAuditFaultArmed()) {
    fail('f6_cli_fault_arm', 'arm failed');
} else {
    $consumed = OrganizationWorkspaceWriteServices::consumeCliAuditFaultOnce();
    $again = OrganizationWorkspaceWriteServices::consumeCliAuditFaultOnce();
    if ($consumed && !$again && !OrganizationWorkspaceWriteServices::isCliAuditFaultArmed()) {
        pass('f6_cli_fault_oneshot_consume');
    } else {
        fail('f6_cli_fault_oneshot_consume', 'consumed=' . json_encode($consumed) . ' again=' . json_encode($again));
    }
}

echo "=== full write suite branch ===\n";
// T1：安全默认值 + try/catch，禁止套件异常绕过 snapshot_after / summary / RESULT
$fullSuiteError = '';
$suite = [
    'ran' => false,
    'entered' => false,
    'note' => 'not_started',
    'branches' => null,
    'cleanup_errors' => [],
    'residue' => [],
];
$branchKeys = [
    'leader_second',
    'leader_left_reject',
    'permission_matched_account',
    'permission_custom_empty_partial_all',
    'store_second',
    'store_new_join_custom',
    'bind_store_concurrent_f5',
    'idempotency_conflict_branch',
    'f6_audit_txn_rollback',
];
$reportSuiteBranches = static function (array $suiteBranches) use ($branchKeys): void {
    foreach ($branchKeys as $bk) {
        // U2：null/缺失只能 NOT_RUN 或 FAIL，绝不能因“覆盖在套件内”而假 PASS
        if (array_key_exists($bk, $suiteBranches) && is_array($suiteBranches[$bk])) {
            $st = (string)($suiteBranches[$bk]['status'] ?? '');
            $note = (string)($suiteBranches[$bk]['note'] ?? '');
            if ($st === 'PASS') {
                pass('branch_' . $bk, $note);
            } elseif ($st === 'NOT_RUN') {
                not_run('branch_' . $bk, $note);
            } elseif ($st === '') {
                fail('branch_' . $bk, 'branch status empty after suite entered');
            } else {
                fail('branch_' . $bk, 'status=' . $st . ' note=' . $note);
            }
        } else {
            not_run('branch_' . $bk, 'suite entered but branch not completed');
        }
    }
};
try {
    $suite = o4FullWriteSuite($runId, $write, $manage, $gate, $reportDir);
    if (!isset($suite['entered'])) {
        $suite['entered'] = (bool)($suite['ran'] ?? false);
    }
    if (!isset($suite['cleanup_errors']) || !is_array($suite['cleanup_errors'])) {
        $suite['cleanup_errors'] = [];
    }
    if (!isset($suite['residue']) || !is_array($suite['residue'])) {
        $suite['residue'] = [];
    }
    if ($suite['ran']) {
        pass('full_write_suite', $suite['note'] ?? 'ok');
        $reportSuiteBranches(is_array($suite['branches'] ?? null) ? $suite['branches'] : []);
    } else {
        // U1：关闸/未就绪 NOT_RUN 时强制空错误与空清理结果
        $suite['entered'] = false;
        $suite['ran'] = false;
        $fullSuiteError = '';
        $suite['cleanup_errors'] = [];
        $suite['residue'] = [];
        not_run('full_write_suite', ($suite['note'] ?? 'skipped') . '；S1～S6 已实现，需开闸+011 才执行');
        not_run('branch_leader_second', '包含在 o4FullWriteSuite（需开闸+011）');
        not_run('branch_leader_left_reject', '包含在 o4FullWriteSuite（需开闸+011）');
        not_run('branch_permission_matched_account', '包含在 o4FullWriteSuite（需开闸+011）');
        not_run('branch_permission_custom_empty_partial_all', '包含在 o4FullWriteSuite（需开闸+011；不足两店则套件内 NOT_RUN）');
        not_run('branch_store_second', '包含在 o4FullWriteSuite（需开闸+011）');
        not_run('branch_store_new_join_custom', '包含在 o4FullWriteSuite（需开闸+011）');
        not_run('branch_bind_store_concurrent_f5', '包含在 o4FullWriteSuite（需开闸+011；A_HOLD 后启 B）');
        not_run('branch_idempotency_conflict_branch', '包含在 o4FullWriteSuite（需开闸+011）');
        not_run('branch_f6_audit_txn_rollback', '包含在 o4FullWriteSuite（需开闸+011；CLI-only）');
    }
} catch (O4FullWriteSuiteException $e) {
    $payload = is_array($e->payload) ? $e->payload : [];
    $suite = array_merge($suite, $payload);
    $suite['note'] = $e->getMessage();
    $suite['entered'] = (bool)($suite['entered'] ?? true);
    $suite['ran'] = (bool)($suite['ran'] ?? $suite['entered']);
    if (!isset($suite['cleanup_errors']) || !is_array($suite['cleanup_errors'])) {
        $suite['cleanup_errors'] = [];
    }
    if (!isset($suite['residue']) || !is_array($suite['residue'])) {
        $suite['residue'] = [];
    }
    $fullSuiteError = $e->getMessage();
    fail('full_write_suite', $e->getMessage());
    if (!empty($suite['cleanup_errors'])) {
        fail('full_write_suite_cleanup', json_encode($suite['cleanup_errors'], JSON_UNESCAPED_UNICODE));
    }
    if ($suite['entered']) {
        $reportSuiteBranches(is_array($suite['branches'] ?? null) ? $suite['branches'] : []);
    }
    // T1：禁止 rethrow，继续 snapshot_after / summary / RESULT
} catch (\Throwable $e) {
    $suite['note'] = $e->getMessage();
    $fullSuiteError = $e->getMessage();
    fail('full_write_suite', $e->getMessage());
    // T1：禁止 rethrow
}

try {
    $after = coreSnapshot();
} catch (\Throwable $e) {
    fail('snapshot_after', $e->getMessage());
    $after = null;
}
if ($after !== null) {
    file_put_contents($reportDir . '/snapshot_after.json', json_encode($after, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $snapOk = ($before['source_mode'] === $after['source_mode'])
        && (($before['runtime'] ?? null) === ($after['runtime'] ?? null))
        && ($before['hashes'] === $after['hashes'])
        && ($before['counts'] === $after['counts']);
    // F7/S6：无论 full suite 是否运行，快照不一致一律 FAIL
    if ($snapOk) {
        pass('snapshot_unchanged', 'source_mode=' . $after['source_mode'] . ' runtime=' . json_encode($after['runtime']));
    } else {
        fail('snapshot_unchanged', 'counts/hashes/source_mode/runtime 不一致: ' . json_encode([
            'suite_ran' => (bool)$suite['ran'],
            'before_counts' => $before['counts'],
            'after_counts' => $after['counts'],
            'source' => [$before['source_mode'], $after['source_mode']],
            'runtime' => [$before['runtime'], $after['runtime']],
            'hash_diff_tables' => array_keys(array_diff_assoc($before['hashes'], $after['hashes'])),
        ], JSON_UNESCAPED_UNICODE));
    }
}

$resultCode = 'PASS';
if ($failures) {
    $resultCode = 'FAIL';
} elseif ($notRun) {
    $resultCode = 'PASS_WITH_NOT_RUN';
}

// V1：011 完整结构状态（只读；与门禁开关无关；不凭 upgrade_log 判 ready）
$upgrade011 = o4Upgrade011Status();

$summary = [
    'run_id' => $runId,
    'failures' => $failures,
    'not_run' => $notRun,
    'result' => $resultCode,
    'before_source_mode' => $before['source_mode'],
    'after_source_mode' => $after['source_mode'] ?? null,
    'before_runtime' => $before['runtime'],
    'after_runtime' => $after['runtime'] ?? null,
    'idem_table' => $before['idem_table'],
    'sql_executed_011' => (bool)$upgrade011['ready'],
    'upgrade_011_checks' => $upgrade011['checks'],
    'upgrade_011_blockers' => $upgrade011['blockers'],
    'upgrade_log_011_count' => (int)$upgrade011['upgrade_log_count'],
    'upgrade_log_011_checksum_match' => (bool)$upgrade011['upgrade_log_checksum_match'],
    'full_suite_implemented' => true,
    // U1：套件状态必须完整落盘 summary.json
    'full_suite_entered' => (bool)($suite['entered'] ?? false),
    'full_suite_ran' => (bool)($suite['ran'] ?? false),
    'full_suite_error' => (string)$fullSuiteError,
    'full_suite_note' => (string)($suite['note'] ?? ''),
    'branches' => $suite['branches'] ?? null,
    'cleanup_errors' => is_array($suite['cleanup_errors'] ?? null) ? $suite['cleanup_errors'] : [],
    'residue' => is_array($suite['residue'] ?? null) ? $suite['residue'] : [],
];
file_put_contents($reportDir . '/summary.json', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "REPORT {$reportDir}\n";
echo 'RESULT=' . $summary['result'] . "\n";
exit($failures ? 1 : 0);
