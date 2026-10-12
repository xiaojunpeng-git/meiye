<?php
declare(strict_types=1);

/**
 * 幂等新增补交奖励列，兼容 MySQL 5.6；默认只检查，--apply --database=库名 才执行。
 * 不回填旧奖励、不更新本金；发布须先迁移后代码。回滚代码时保留新列及已记录奖励，禁止自动删列。
 */
require dirname(__DIR__, 3) . '/vendor/autoload.php';
$root = dirname(__DIR__, 3) . '/';
$app = new think\App($root);
$app->env->load($root . '.env');
$app->initialize();
$args = getopt('', ['apply', 'database:']);
$database = (string)think\facade\Db::query('SELECT DATABASE() db')[0]['db'];
if (!isset($args['database']) || (string)$args['database'] !== $database) {
    throw new RuntimeException('必须使用 --database=当前库名 明确目标；本脚本不连接其他实例。');
}
$prefix = (string)think\facade\Db::getConfig('connections.mysql.prefix');
$tables = [$prefix . 'cashier_v3_debt_repayment', $prefix . 'cashier_v3_recharge_debt_repayment'];
$checks = [];
foreach ($tables as $table) {
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('非法表名');
    $columns = think\facade\Db::query('SHOW COLUMNS FROM `' . $table . '`');
    $reward = array_values(array_filter($columns, static function (array $row): bool { return $row['Field'] === 'reward_amount_cents'; }));
    if ($reward && (stripos($reward[0]['Type'], 'bigint') !== 0 || stripos($reward[0]['Type'], 'unsigned') === false || $reward[0]['Null'] !== 'NO' || (string)$reward[0]['Default'] !== '0')) {
        throw new RuntimeException($table . ' 的既有奖励列与约定不符，停止执行。');
    }
    $checks[$table] = ['exists' => (bool)$reward, 'rows' => (int)think\facade\Db::query('SELECT COUNT(*) n FROM `' . $table . '`')[0]['n']];
}
foreach ($checks as $table => &$check) {
    if (isset($args['apply']) && !$check['exists']) {
        think\facade\Db::execute('ALTER TABLE `' . $table . '` ADD COLUMN `reward_amount_cents` bigint unsigned NOT NULL DEFAULT 0 COMMENT \'本次补交异业收客奖励（分），不抵扣本金\'');
        $check['added'] = true;
    }
}
unset($check);
echo json_encode(['migration' => '20261012-repayment-cross-industry-reward', 'database' => $database, 'apply' => isset($args['apply']), 'tables' => $checks], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
