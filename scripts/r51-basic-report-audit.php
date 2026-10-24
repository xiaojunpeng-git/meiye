<?php
/**
 * R51 只读配置/导入核对。可从标准输入执行；不上传源码、不执行 SQL 配置、不输出客户明细。
 * source-local 仅访问已知本地007副本；target-010 只核对010及其已保存的007来源快照。
 * 没有 apply/修复模式：已导入数据出现差异必须另外备份、映射、验收，禁止重复覆盖。
 */
$options = getopt('', ['root:', 'mode:']);
$root = $options['root'] ?? getenv('R51_BACKEND_ROOT') ?: dirname(__DIR__) . '/后端代码';
$mode = $options['mode'] ?? getenv('R51_AUDIT_MODE') ?: 'source-local';
chdir($root);
require 'vendor/autoload.php';
(new \think\App())->initialize();

use think\facade\Db;

/** 白名单同时约束执行模式、连接目标和只读事务，不能由浏览器传入任意库名。 */
function r51AuditGuard(string $mode, string $host, string $database): void
{
    $local = $mode === 'source-local'
        && in_array($host, ['007-local-mysql', '007-snapshot-mysql'], true)
        && in_array($database, ['linlin_007_local', 'linlin_007_snapshot'], true);
    $target = $mode === 'target-010' && $database === 'mohe_010_app'
        && in_array($host, ['127.0.0.1', 'localhost'], true)
        && realpath(getcwd()) === '/www/wwwroot/010.cc3798.com';
    if (!$local && !$target) throw new RuntimeException('R51 audit target is not allowlisted');
}

/** 语义名称只做来源配置定位，必须唯一；不会把旧表 ID 当作跨客户通用业务 code。 */
function r51BrushTableId(string $schema): int
{
    $rows = Db::query("SELECT id,type FROM `{$schema}`.eb_salary_table WHERE name=?", ['刷单登记']);
    if (count($rows) !== 1 || (int)$rows[0]['type'] !== 4) {
        throw new RuntimeException('Brush configuration missing, ambiguous or not a registration form');
    }
    return (int)$rows[0]['id'];
}

$connection = config('database.connections.mysql');
$database = (string)($connection['database'] ?? '');
r51AuditGuard($mode, (string)($connection['hostname'] ?? ''), $database);
Db::execute('START TRANSACTION READ ONLY');
try {
    $actual = Db::query('SELECT DATABASE() AS database_name, VERSION() AS server_version')[0];
    if ($actual['database_name'] !== $database) throw new RuntimeException('Connected database mismatch');
    $tables = Db::name('salary_table')->order('id')->select()->toArray();
    $fields = Db::name('salary_field')->order('sort')->order('id')->select()->toArray();
    $reports = [];
    foreach ($tables as $table) {
        $columns = []; $sql = [];
        foreach ($fields as $field) {
            if (!in_array((string)$table['id'], explode(',', (string)$field['table_ids']), true)) continue;
            if ((int)$field['type'] === 5) {
                $sql[] = ['key' => $field['key'], 'sha256' => hash('sha256', (string)$field['info'])];
            } else {
                $columns[] = ['key' => $field['key'], 'name' => $field['name'],
                    'input_type' => (int)$field['type'], 'is_count' => (int)$field['is_count'],
                    'formula' => (int)$field['type'] === 2 ? $field['info'] : null];
            }
        }
        $reports[] = ['legacy_id' => (int)$table['id'], 'name' => $table['name'],
            'type' => (int)$table['type'], 'enabled' => (int)$table['status'],
            'columns' => $columns, 'sql_sources' => $sql];
    }
    $brushId = r51BrushTableId($database);
    $brush = Db::query('SELECT COUNT(*) AS row_count,COUNT(DISTINCT store_id) AS store_count,
        SUM(date IS NULL OR date=0) AS missing_date_count FROM eb_salary_store WHERE FIND_IN_SET(?,table_ids)', [$brushId])[0];
    $result = ['mode' => $mode, 'database' => $actual, 'reports' => $reports, 'brush' => $brush,
        'read_only' => true, 'writes_performed' => false];
    if ($mode === 'target-010') {
        $source = 'mohe_010';
        $sourceId = r51BrushTableId($source);
        // 比较原始内容而非合计：同金额的错行、日期变化、跨店归属变化均不能被合计掩盖。
        $result['import_comparison'] = Db::query('SELECT COUNT(*) AS source_rows,
            COALESCE(SUM(t.id IS NULL),0) AS missing_rows,
            COALESCE(SUM(t.id IS NOT NULL AND (
                NOT(s.date <=> t.date) OR NOT(BINARY s.salary_info <=> BINARY t.salary_info)
                OR NOT(s.store_id <=> t.store_id) OR NOT(s.staff_id <=> t.staff_id)
                OR NOT(BINARY s.table_ids <=> BINARY t.table_ids))),0) AS changed_rows
            FROM mohe_010.eb_salary_store s LEFT JOIN mohe_010_app.eb_salary_store t ON t.id=s.id
            WHERE FIND_IN_SET(?,s.table_ids)', [$sourceId])[0];
        $result['target_only_rows'] = Db::query('SELECT COUNT(*) AS row_count
            FROM mohe_010_app.eb_salary_store t LEFT JOIN mohe_010.eb_salary_store s
            ON s.id=t.id AND FIND_IN_SET(?,s.table_ids)
            WHERE FIND_IN_SET(?,t.table_ids) AND s.id IS NULL', [$sourceId, $brushId])[0];
        $result['orphan_stores'] = Db::query('SELECT COUNT(*) AS row_count
            FROM eb_salary_store s LEFT JOIN eb_system_store t ON t.id=s.store_id
            WHERE FIND_IN_SET(?,s.table_ids) AND t.id IS NULL', [$brushId])[0];
        $comparison = $result['import_comparison'];
        $result['import_status'] = (int)$comparison['source_rows'] > 0
            && (int)$comparison['missing_rows'] === 0 && (int)$comparison['changed_rows'] === 0
            && (int)$result['orphan_stores']['row_count'] === 0
                ? 'source_snapshot_already_imported_preserve_target_additions' : 'requires_mapping_review';
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    Db::execute('ROLLBACK');
}
