<?php
/** Real service + local MySQL fixtures; every fixture is rolled back. No
 * customer instance, payment, stock, rights or real booking is modified.
 */
require '/var/www/html/vendor/autoload.php';
(new \think\App('/var/www/html/'))->initialize();
set_exception_handler(static function (Throwable $error): void { file_put_contents('php://stderr', $error->getMessage() . PHP_EOL); exit(1); });
use app\services\cashier\v3\reservation\ReservationCustomServiceServices;
use app\services\store\ServiceNameSettingsServices;
use think\facade\Db;
use think\exception\ValidateException;
$cfg = config('database.connections.mysql');
if (!in_array($cfg['hostname'], ['mysql', 'mohe-mysql'], true) || $cfg['database'] !== 'ruihao') throw new RuntimeException('Local only');
$checks = 0;
function r58check($condition, $label) { global $checks; if (!$condition) throw new RuntimeException($label); $checks++; }
$service = new ReservationCustomServiceServices();
r58check($service->normalize([]) === [], 'Optional empty');
r58check($service->normalize([['id' => '2', 'quantity' => '3'], ['id' => 1, 'quantity' => 1]]) === [['id' => 1, 'quantity' => 1], ['id' => 2, 'quantity' => 3]], 'Stable identity normalization');
foreach ([null, 'x', [['id' => 1, 'quantity' => 0]], [['id' => 1, 'quantity' => 1.1]], [['id' => 1, 'quantity' => '1.0']], [['id' => 1, 'quantity' => -1]], [['id' => 1, 'quantity' => '01']], [['id' => 1, 'quantity' => 2147483648]], [['id' => 1, 'quantity' => true]], [['id' => 1, 'quantity' => 1], ['id' => 1, 'quantity' => 2]]] as $bad) {
    try { $service->normalize($bad); throw new RuntimeException('Invalid integer accepted'); } catch (ValidateException $e) { $checks++; }
}
$snapshot = $service->resolveFromCatalog([['id' => 1, 'quantity' => 2, 'name' => 'forged', 'price' => 999]], [['id' => 1, 'name' => '馄饨']]);
r58check($snapshot === [['id' => 1, 'quantity' => 2, 'name' => '馄饨']], 'Names and fields are authoritative');
try { $service->resolveFromCatalog([['id' => 2, 'quantity' => 1]], [['id' => 1, 'name' => '馄饨']]); throw new RuntimeException('Foreign item accepted'); } catch (ValidateException $e) { $checks++; }
$store = 2147000001; $otherStore = 2147000002; $member = 2147000001;
if (Db::name('cashier_v3_service_name')->whereIn('store_id', [$store, $otherStore])->count()) throw new RuntimeException('Fixture collision');
Db::startTrans();
try {
    $settings = new ServiceNameSettingsServices(); $key = bin2hex(random_bytes(12));
    $name = $settings->create($store, ['id' => 0], '馄饨', 0, 'r58-a-' . $key);
    $other = $settings->create($otherStore, ['id' => 0], '花茶', 0, 'r58-b-' . $key);
    $items = [['id' => $name['id'], 'quantity' => 2]];
    $resolved = $service->resolve($store, $items);
    r58check($resolved[0]['name'] === '馄饨', 'Store menu resolved');
    try { $service->resolve($store, [['id' => $other['id'], 'quantity' => 1]]); throw new RuntimeException('Cross-store selection accepted'); } catch (ValidateException $e) { $checks++; }
    $header = (array)Db::name('cashier_v3_reservation')->order('id asc')->find();
    if (!$header) throw new RuntimeException('Local reservation schema fixture required');
    unset($header['id']); $header['tenant_id'] = 'R58_FIXTURE'; $header['store_id'] = $store; $header['member_id'] = $member;
    $header['reservation_id'] = 'RSV-R58-' . $key; $header['reservation_no'] = 'R58-' . $key; $header['member_name_snapshot'] = 'R58 fixture'; $header['member_phone_snapshot'] = '';
    $id = (int)Db::name('cashier_v3_reservation')->insertGetId($header);
    try { $service->saveInTx('R58_FIXTURE', $otherStore, $id, $resolved, time()); throw new RuntimeException('Foreign parent accepted'); } catch (ValidateException $e) { $checks++; }
    $service->saveInTx('R58_FIXTURE', $store, $id, $resolved, time());
    r58check($service->forReservations('R58_FIXTURE', $store, [$id])[$id] == $resolved, 'Reload exact saved snapshot');
    r58check($service->forReservations('R58_FIXTURE', $otherStore, [$id]) === [], 'Store read isolation');
    r58check($service->forReservations('another-tenant', $store, [$id]) === [], 'Tenant read isolation');
    r58check($service->forMemberReservations($member, [$id])[$id] == $resolved, 'Own member read');
    r58check($service->forMemberReservations($member + 1, [$id]) === [], 'Other member cannot read');
    $disabled = $settings->create($store, ['id' => 0], '拌面', 0, 'r58-disabled-' . $key);
    $settings->setEnabled($store, ['id' => 0], (int)$disabled['id'], false, (int)$disabled['version'], 'r58-disable-' . $key);
    try { $service->resolve($store, [['id' => $disabled['id'], 'quantity' => 1]]); throw new RuntimeException('Disabled item accepted'); } catch (ValidateException $e) { $checks++; }
    $settings->update($store, ['id' => 0], (int)$name['id'], '已改名', (int)$name['version'], 'r58-rename-' . $key);
    r58check($service->forReservations('R58_FIXTURE', $store, [$id])[$id][0]['name'] === '馄饨', 'Rename never rewrites history');
    $settings->setSwitch($store, ['id' => 0], false, 0, 'r58-off-' . $key);
    r58check($settings->reservationCatalog($store)['enabled'] === false, 'Closed menu hidden');
    try { $service->resolve($store, $items); throw new RuntimeException('Disabled menu accepted'); } catch (ValidateException $e) { $checks++; }
    r58check($service->forReservations('R58_FIXTURE', $store, [$id])[$id] == $resolved, 'Closed switch retains detail snapshot');
    r58check($service->resolve($store, []) === [], 'No selection remains valid when closed');
} finally { Db::rollback(); }
r58check((int)Db::name('cashier_v3_service_name')->whereIn('store_id', [$store, $otherStore])->count() === 0, 'Fixtures rolled back');
echo 'R58_CUSTOM_SERVICE_PASS checks=' . $checks . PHP_EOL;
