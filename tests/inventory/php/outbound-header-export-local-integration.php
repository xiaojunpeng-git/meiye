<?php
declare(strict_types=1);

/** Local-only rollback test: synthetic stock never changes a user's physical inventory. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
use think\facade\Db;
use app\services\product\inventory\InventoryManualOutboundServices;
use app\services\product\inventory\InventoryOutboundHeaderServices;
use app\services\query\UnifiedQueryRuntime;

(new \think\App($backend . '/'))->initialize();
$config = config('database.connections.mysql');
if (!is_file('/.dockerenv') || $config['hostname'] !== 'mysql' || $config['database'] !== 'ruihao') throw new RuntimeException('local_database_required');
$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException($name);
    $passed++; echo 'PASS ' . $name . PHP_EOL;
};
$reject = static function (callable $f): bool { try { $f(); return false; } catch (Throwable $e) { return true; } };
$key = 'R52-test-' . bin2hex(random_bytes(8));
Db::startTrans();
try {
    $location = ['tenant_id' => '0', 'organization_id' => '110', 'organization_path' => '/110/', 'location_type' => 'HQ',
        'owner_id' => 110, 'location_code' => $key, 'location_name' => 'R52 TEST', 'store_id' => 0, 'is_default' => 1, 'location_status' => 'ACTIVE'];
    $location['id'] = (int)Db::name('inventory_location')->insertGetId($location);
    $scope = ['tenantId' => '0', 'organizationId' => '110', 'organizationPath' => '/110/', 'storeId' => 0,
        'operatorId' => 1, 'operatorType' => 'PLATFORM_ADMIN', 'operatorName' => 'R52 TEST'];
    $stock = (int)Db::name('inventory_stock')->insertGetId(['tenant_id' => '0', 'organization_id' => '110', 'organization_path' => '/110/',
        'location_id' => $location['id'], 'store_id' => 0, 'consumable_product_id' => 99520001, 'sku_id' => 99520001,
        'product_unique' => $key, 'stock_unit' => '盒', 'quantity_scale' => 0, 'available_quantity_units' => 20]);
    Db::name('inventory_batch')->insert(['stock_id' => $stock, 'batch_no' => $key, 'product_name_snapshot' => 'R52 TEST产品',
        'sku_name_snapshot' => '默认', 'available_quantity_units' => 20, 'unit_cost_cents' => 1255,
        'received_business_date' => date('Y-m-d'), 'expire_date' => '2028-01-01']);
    $command = ['idempotency_key' => $key, 'business_date' => date('Y-m-d'), 'remark' => 'R52 TEST',
        'lines' => [['product_id' => 99520001, 'sku_id' => 99520001, 'sku_unique' => $key, 'quantity' => '2']],
        'outbound_type' => '对内出库', 'outbound_recipient' => '  测试部门  '];
    $writer = new InventoryManualOutboundServices();
    $first = $writer->createForHeadquarters($scope, $location, $command);
    $replay = $writer->createForHeadquarters($scope, $location, $command);
    $check('same request replays once', $replay['lines'][0]['idempotent'] && $first['document_no'] === $replay['document_no']
        && (int)Db::name('inventory_stock')->where('id', $stock)->value('available_quantity_units') === 18);
    $header = Db::name('inventory_manual_outbound_header')->where('source_id', $key)->find();
    $check('recipient and type persisted, trimmed', $header['outbound_recipient'] === '测试部门' && $header['outbound_type'] === '对内出库');
    foreach (['outbound_type' => '对外出库', 'outbound_recipient' => '其他', 'remark' => 'changed'] as $field => $value) {
        $changed = array_replace($command, [$field => $value]);
        $check('changed ' . $field . ' conflicts', $reject(fn() => $writer->createForHeadquarters($scope, $location, $changed)));
    }
    $headers = new InventoryOutboundHeaderServices();
    $check('empty recipient is valid', $headers->normalize(['outbound_recipient' => ''])['outbound_recipient'] === '');
    foreach ([['outbound_type' => '调拨出库'], ['outbound_recipient' => []], ['outbound_recipient' => str_repeat('字', 101)]] as $bad) {
        $check('invalid header rejected', $reject(fn() => $headers->normalize($bad)));
    }
    $second = array_replace($command, ['idempotency_key' => $key . '-2', 'outbound_type' => '对外出库', 'outbound_recipient' => '']);
    $writer->createForHeadquarters($scope, $location, $second);
    $failed = array_replace($command, ['idempotency_key' => $key . '-fail', 'lines' => [array_replace($command['lines'][0], ['quantity' => '999'])]]);
    $check('insufficient stock rolls back header', $reject(fn() => $writer->createForHeadquarters($scope, $location, $failed))
        && !Db::name('inventory_manual_outbound_header')->where('source_id', $key . '-fail')->find());
    $check('foreign store actor cannot write', $reject(fn() => $writer->create(999999, 999999, $command)));

    $runtime = UnifiedQueryRuntime::runtime();
    $context = $runtime['contextFactory']->make(['tenant_id' => '0', 'account_id' => 1, 'operator_id' => 1, 'store_id' => 0,
        'organization_id' => '110', 'visible_store_ids' => null, 'ancestor_organization_ids' => ['110'],
        'shareable_store_ids' => [], 'shareable_organization_ids' => ['110'], 'permission_version' => 'R52-test',
        'granted_features' => ['inventory.batch.view', 'inventory.batch.export', 'inventory.cost.view'],
        'scope_dimensions' => ['location_id' => [(string)$location['id']], 'inventory_actor_type' => ['platform']], 'query_cutoff_date' => date('Y-m-d'), 'data_as_of' => time()], ['pageCode' => 'inventory_outbound']);
    $provider = $runtime['providers']->resolve('inventory_outbound');
    $query = ['pageCode' => 'inventory_outbound', 'page' => 1, 'limit' => 1, 'topFilterConditions' => [
        ['field' => 'order_type_name', 'operator' => 'eq', 'value' => '对内出库'],
        ['field' => 'business_date', 'operator' => 'between', 'value' => [date('Y-m-d'), date('Y-m-d')]],
    ]];
    $list = $provider->query($context, $query);
    $check('type/date query returns only matching document', $list['total'] === 1 && $list['records'][0]['outbound_recipient'] === '测试部门');
    $plan = $runtime['execution']->validatedPlan('inventory_outbound', [], $query, $context);
    $fields = ['order_sn', 'store_name', 'order_type_name', 'outbound_recipient', 'outbound_product', 'outbound_quantity', 'outbound_cost_cents'];
    $export = $provider->executeFrozenPlan($context, $plan, 'query', $fields);
    $check('export matches filtered document and exact batch cost', count($export['exportRows']) === 1
        && $export['exportRows'][0]['order_sn'] === $first['document_no'] && (int)$export['exportRows'][0]['outbound_cost_cents'] === 2510
        && $export['exportRows'][0]['outbound_quantity'] === '2');
    $query['topFilterConditions'] = [];
    $all = $provider->query($context, $query);
    $plan = $runtime['execution']->validatedPlan('inventory_outbound', [], $query, $context);
    $export = $provider->executeFrozenPlan($context, $plan, 'query', $fields);
    $check('export full query not one-row page', count($all['records']) === 1 && $all['total'] === 2 && count($export['exportRows']) === 2);
    $hidden = $context;
    $hidden['permissions'] = array_values(array_diff($context['permissions'], ['inventory.cost.view']));
    $check('no cost permission cannot export cost', $reject(fn() => $provider->executeFrozenPlan($hidden, $plan, 'query', $fields)));
    $foreign = $context;
    $foreign['scope_dimensions']['location_id'] = ['999999999'];
    $check('foreign warehouse returns no rows', $provider->query($foreign, $query)['total'] === 0);
    // Snapshot/fallback changes stay inside this rollback fixture; never rename a real warehouse.
    Db::name('inventory_location')->where('id', $location['id'])->update(['store_name_snapshot' => '历史门店名称']);
    $snapshotList = $provider->query($context, $query);
    $snapshotExport = $provider->executeFrozenPlan($context, $plan, 'query', $fields);
    $check('list and export share store snapshot', $snapshotList['records'][0]['store_name'] === '历史门店名称'
        && $snapshotExport['exportRows'][0]['store_name'] === '历史门店名称'
        && $snapshotList['records'][0]['location_name'] === 'R52 TEST');
    $store = Db::name('system_store')->where('id', '>', 0)->find();
    Db::name('inventory_location')->where('id', $location['id'])->update(['store_name_snapshot' => '', 'store_id' => $store['id']]);
    Db::name('inventory_batch_movement_fact')->where('location_id', $location['id'])->update(['store_id' => $store['id']]);
    $projection = new ReflectionMethod(\app\services\product\inventory\query\InventoryOperationalUnifiedQueryProvider::class, 'documentRows');
    $projection->setAccessible(true);
    $storeRows = $projection->invoke($provider, '0', (int)$store['id'], [$location['id']], ['manual_outbound'], true);
    $check('empty snapshot falls back to owning store', count($storeRows) === 2 && $storeRows[0]['store_name'] === $store['name']);
    $check('store scope still excludes foreign documents', $projection->invoke($provider, '0', 999999999, [$location['id']], ['manual_outbound'], true) === []);
} finally {
    Db::rollback();
}
$check('fixtures fully rolled back', !Db::name('inventory_manual_outbound_header')->whereLike('source_id', $key . '%')->find());
echo "R52_OUTBOUND passed={$passed} failed=0\n";
