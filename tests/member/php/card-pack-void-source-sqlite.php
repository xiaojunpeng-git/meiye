<?php
declare(strict_types=1);

/** Exercise the real card-pack DAO against an isolated in-memory source order set. */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
if (!class_exists(\Swoole\Table::class)) {
    eval('namespace Swoole; final class Table { public const TYPE_STRING = 7; public const TYPE_INT = 1; }');
}
$backend = getenv('MEMBER_CARD_VOID_BACKEND') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

$app = new \think\App($backend . '/');
$app->initialize();
$db = new \think\DbManager();
$db->setConfig(['default' => 'member_card_void', 'connections' => ['member_card_void' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'eb_',
    'fields_strict' => true, 'fields_cache' => false,
]]]);
\think\Container::getInstance()->instance(\think\DbManager::class, $db);
if (\think\facade\Db::connect()->getConfig('database') !== ':memory:') {
    throw new RuntimeException('isolation_required');
}

\think\facade\Db::execute('CREATE TABLE eb_user_card_holder (id INTEGER PRIMARY KEY, uid INTEGER, oid INTEGER, is_del INTEGER, product_type INTEGER)');
\think\facade\Db::execute('CREATE TABLE eb_store_order (id INTEGER PRIMARY KEY, terminal_action INTEGER)');
foreach ([[1, 101, null], [2, 102, 0], [3, 103, 2], [4, 104, 2], [5, 105, 0]] as $row) {
    \think\facade\Db::name('store_order')->insert(['id' => $row[1], 'terminal_action' => $row[2]]);
    \think\facade\Db::name('user_card_holder')->insert([
        'id' => $row[0], 'uid' => $row[0] === 4 ? 8 : 7, 'oid' => $row[1],
        'is_del' => $row[0] === 5 ? 1 : 0, 'product_type' => 1,
    ]);
}

$dao = new \app\dao\user\UserCardHolderDao();
$scope = ['uid' => 7, 'is_del' => 0, 'exclude_void_source_order' => 1];
$ids = array_map('intval', array_column($dao->getList($scope, 1, 20), 'id'));
if ($ids !== [2, 1] || $dao->count($scope) !== 2) {
    throw new RuntimeException('member_list_or_total_includes_void_source:' . json_encode([$ids, $dao->count($scope)]));
}
if (array_map('intval', array_column($dao->getList($scope, 2, 1), 'id')) !== [1]) {
    throw new RuntimeException('page_after_void_filter_has_a_gap');
}
if ($dao->count(['uid' => 7, 'is_del' => 0]) !== 3) {
    throw new RuntimeException('unrelated_dao_scope_was_changed');
}
echo "card-pack void-source scope: 3 checks passed\n";
