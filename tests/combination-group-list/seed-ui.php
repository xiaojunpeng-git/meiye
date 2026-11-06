<?php
declare(strict_types=1);
/** Local-only display fixtures. No gateway, refund command, entitlement or production data is touched. */
require (getenv('BACKEND_ROOT') ?: '/var/www/html') . '/vendor/autoload.php';
use think\facade\Db;
$app = new think\App((getenv('BACKEND_ROOT') ?: '/var/www/html') . '/');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Only local Docker ruihao is allowed');
}
function uiSeed(string $table, array $data): int
{
    // Required legacy columns receive neutral fixture values, never values copied from real business rows.
    foreach (Db::query('SHOW COLUMNS FROM eb_' . $table) as $field) {
        $key = $field['Field'];
        if (array_key_exists($key, $data) || $field['Default'] !== null || $field['Null'] === 'YES' || strpos($field['Extra'], 'auto_increment') !== false) continue;
        $data[$key] = preg_match('/int|decimal|float|double/', $field['Type']) ? 0 : '';
    }
    return (int)Db::name($table)->insertGetId($data);
}
$tag = 'R16-UI-' . bin2hex(random_bytes(4));
Db::startTrans();
try {
    $store = (int)Db::name('system_store')->where('is_del', 0)->order('id')->value('id');
    if (!$store) throw new RuntimeException('A local store is required');
    $uid = uiSeed('user', ['nickname' => $tag, 'real_name' => $tag]);
    $cid = uiSeed('store_combination', ['title' => $tag, 'people' => 2, 'is_show' => 0]);
    $groups = [];
    for ($i = 1; $i <= 22; $i++) {
        $state = $i === 22 ? 'success' : ($i === 21 ? 'pending' : 'none');
        $label = $tag . '-' . ($state === 'success' ? '已全退' : ($state === 'pending' ? '退款处理中' : sprintf('未退款%02d', $i)));
        $oid = uiSeed('store_order', ['order_id' => $tag . '-' . $i, 'unique' => $tag . '-' . $i, 'uid' => $uid, 'store_id' => $store,
            'paid' => 1, 'pay_price' => 100, 'refund_price' => 0, 'refund_status' => $state === 'success' ? 2 : ($state === 'pending' ? 1 : 0),
            'real_name' => $label, 'add_time' => time(), 'type' => 3, 'activity_id' => $cid]);
        $gid = uiSeed('store_pink', ['uid' => $uid, 'nickname' => $label, 'order_id_key' => $oid,
            'order_id' => $tag . '-' . $i, 'cid' => $cid, 'status' => 2, 'people' => 2,
            'price' => 100, 'add_time' => time(), 'stop_time' => time() + 3600, 'is_refund' => $state === 'success' ? 1 : 0]);
        Db::name('store_order')->where('id', $oid)->update(['pink_id' => $gid]);
        if ($state !== 'none') uiSeed('combination_order_refund', ['order_id' => $oid, 'refund_no' => $tag . '-' . $i,
            'state' => $state, 'amount_cents' => 10000]);
        $groups[] = ['group_id' => $gid, 'order_id' => $oid, 'state' => $state];
    }
    Db::commit();
    echo json_encode(compact('tag', 'uid', 'cid', 'store', 'groups'), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
} catch (\Throwable $error) {
    Db::rollback();
    throw $error;
}
