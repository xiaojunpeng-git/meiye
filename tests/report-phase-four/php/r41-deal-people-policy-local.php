<?php

declare(strict_types=1);

/** 仅在本机瑞昊开发库验证版本写入；外层事务始终回滚，不留下测试配置。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\report\StoreDealPeopleThresholdPolicyServices;
use think\facade\Db;

(new \think\App(rtrim($backend, '/\\') . '/'))->initialize();
if (getenv('R41_DEAL_PEOPLE_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('需要显式指定本机瑞昊开发库');
}
$policy = new StoreDealPeopleThresholdPolicyServices();
$before = $policy->current();
Db::startTrans();
try {
    $next = $policy->save(1100, 600, $before['version'], 1);
    if ($next['a_yuan'] !== 1100 || $next['other_yuan'] !== 600
        || $next['version'] !== $before['version'] + 1) {
        throw new RuntimeException('门槛版本保存/读取不一致');
    }
    try {
        $policy->save(1200, 700, $before['version'], 1);
        throw new RuntimeException('陈旧版本应拒绝覆盖');
    } catch (\think\exception\ValidateException $expected) {
        // 第二个管理员的旧版本不会覆盖已保存的门槛。
    }
    try {
        $policy->save('12.5', 500, $next['version'], 1);
        throw new RuntimeException('小数门槛应被拒绝');
    } catch (\think\exception\ValidateException $expected) {
        // 保存单位是整数元，内部以整分记录。
    }
    echo "PASS R41 versioned deal-people policy\n";
} finally {
    Db::rollback();
}
