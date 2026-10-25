<?php
declare(strict_types=1);

/** Exercise the production visibility predicate against a prefixed isolated database. */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$backend = dirname(__DIR__, 2) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\activity\combination\CombinationConsumptionVisibilityServices;
use think\facade\Db;

new think\App($backend . '/');
$db = new think\DbManager();
$db->setConfig(['default' => 'test', 'connections' => ['test' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'eb_',
]]]);
think\Container::getInstance()->instance(think\DbManager::class, $db);
if (Db::connect()->getConfig('database') !== ':memory:') throw new RuntimeException('isolated_database_required');

Db::execute('CREATE TABLE eb_store_order (id INTEGER PRIMARY KEY, type INTEGER)');
Db::execute('CREATE TABLE eb_combination_order_fulfillment (order_id INTEGER PRIMARY KEY, state TEXT)');
foreach ([[1,0],[2,3],[3,3],[4,3],[5,3],[6,3],[7,3]] as [$id,$type]) {
    Db::name('store_order')->insert(compact('id','type'));
}
foreach ([[2,'pending'],[3,'issued'],[4,'delivering']] as [$orderId,$state]) {
    Db::name('combination_order_fulfillment')->insert(['order_id' => $orderId, 'state' => $state]);
}
$query = Db::name('store_order')->alias('o');
CombinationConsumptionVisibilityServices::apply($query, 'o');
$visible = array_map('intval', $query->order('o.id')->column('o.id'));
if ($visible !== [1,3,4]) throw new RuntimeException('unexpected visible orders: ' . json_encode($visible));

$prefixed = Db::name('store_order');
CombinationConsumptionVisibilityServices::apply($prefixed, $prefixed->getTable());
$ids = array_map('intval', $prefixed->order('id')->column('id'));
if ($ids !== $visible) throw new RuntimeException('prefixed query differs from aliased query');
// A completion/issuance transition reveals exactly the formerly pending row;
// orders without receipts remain outside this new-document flow.
Db::name('combination_order_fulfillment')->where('order_id', 2)->update(['state' => 'issued']);
$after = Db::name('store_order')->alias('o');
CombinationConsumptionVisibilityServices::apply($after, 'o');
if (array_map('intval', $after->order('o.id')->column('o.id')) !== [1,2,3,4]) {
    throw new RuntimeException('issued group did not become visible');
}
echo "group consumption visibility, alias and table prefix: PASS\n";
