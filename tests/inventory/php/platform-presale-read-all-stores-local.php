<?php
declare(strict_types=1);

/** Local-only platform presale read-scope probe; synthetic account and role are rolled back. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use app\services\cashier\v3\presale\CashierV3PresaleClaimServices;
use app\services\product\inventory\InventoryPlatformAccessPolicy;
use app\services\query\UnifiedQueryException;
use think\facade\Db;

if (!getenv('MOHE_LOCAL_DB_HOSTNAME')
    || config('database.connections.mysql.hostname') !== 'mysql'
    || (string)(Db::query('SELECT @@hostname AS host')[0]['host'] ?? '') !== getenv('MOHE_LOCAL_DB_HOSTNAME')) {
    throw new RuntimeException('Local database guard failed');
}

function checkPresaleRead(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo 'PASS ' . $label . PHP_EOL;
}

$menuId = (int)Db::name('system_menus')->where('unique_auth', 'admin-stock-manage')
    ->where('type', 1)->where('is_del', 0)->value('id');
$sample = Db::name('cashier_v3_presale_claimable_line')->where('source_kind', 'PRESALE')
    ->where('store_id', '>', 0)->field('claimable_line_id,store_id')->find();
if ($menuId <= 0 || !$sample) throw new RuntimeException('Local presale menu and data required');

Db::startTrans();
try {
    $tag = 'presale-read-' . bin2hex(random_bytes(5));
    $roleId = (int)Db::name('system_role')->insertGetId([
        'role_name' => $tag, 'rules' => (string)$menuId, 'status' => 1,
    ]);
    $adminId = (int)Db::name('system_admin')->insertGetId([
        'account' => $tag, 'real_name' => $tag, 'roles' => (string)$roleId,
        'level' => 1, 'status' => 1, 'is_del' => 0, 'admin_type' => 0,
    ]);
    $admin = ['id' => $adminId];
    checkPresaleRead(Db::name('organization_admin')->where('admin_id', $adminId)->count() === 0,
        'page-only account has no organization appointment');
    $policy = new InventoryPlatformAccessPolicy();
    $original = $policy->resolve($admin);
    $read = $policy->forPresaleRead($original);
    checkPresaleRead(!$original['store_ids'] && !empty($read['store_ids'])
        && in_array((int)$sample['store_id'], $read['store_ids'], true),
        'presale read scope includes active stores without widening original write scope');

    $service = new CashierV3PresaleClaimServices();
    $scope = $service->scopeForPlatform($admin);
    checkPresaleRead(!empty($scope['tree']) && $scope['allowed_store_ids'] === $read['store_ids']
        && $scope['permission_version'] === $read['permission_version'],
        'picker uses the same server-authorized presale read scope');
    $list = $service->listForPlatform($admin, ['store_ids' => (string)$sample['store_id'], 'page' => 1, 'limit' => 20]);
    checkPresaleRead((int)$list['count'] > 0 && !array_filter($list['list'], static fn(array $row): bool =>
        (int)$row['store_id'] !== (int)$sample['store_id']),
        'list narrows the shared read scope to the selected store');
    checkPresaleRead(!array_filter($list['list'], static fn(array $row): bool => !array_key_exists('can_operate', $row) || $row['can_operate']),
        'page-only account sees no claim command on readable rows');
    $detail = $service->detailForPlatform($admin, (string)$sample['claimable_line_id']);
    checkPresaleRead((string)$detail['claimable']['claimable_line_id'] === (string)$sample['claimable_line_id']
        && !$detail['can_manage'],
        'detail uses the same read scope');

    try {
        $service->claimForPlatform($admin, ['store_id' => (int)$sample['store_id']]);
        throw new RuntimeException('Read-only page unexpectedly opened the claim command');
    } catch (UnifiedQueryException $expected) {
        checkPresaleRead(true, 'page-only account cannot write a presale claim');
    }
    Db::name('system_role')->where('id', $roleId)->update(['rules' => '']);
    try {
        $service->scopeForPlatform($admin);
        throw new RuntimeException('Revoked page menu unexpectedly reads presale scope');
    } catch (UnifiedQueryException $expected) {
        checkPresaleRead(true, 'revoked page menu closes presale read scope');
    }
} finally {
    Db::rollback();
}
