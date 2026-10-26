<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use think\facade\Db;

/** 总部仓主数据命令：沿用组织所有权，不创建门店副仓，不直接改库存或历史快照。 */
final class InventoryPlatformWarehouseCommandServices
{
    /** 从已授权总部仓取得组织归属；幂等键生成稳定编码，重试不会重复建仓。 */
    public function create(array $adminInfo, array $input): array
    {
        $key = trim((string)($input['idempotency_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9:._-]{8,96}$/D', $key)) throw new \InvalidArgumentException('inventory_platform_warehouse_idempotency_invalid');
        $name = $this->name($input['location_name'] ?? '');
        $policy = new InventoryPlatformAccessPolicy();
        $policy->assertFeature($policy->resolve($adminInfo), 'inventory.location.settings', '当前岗位未配置“仓库设置”权限。');
        $base = (new InventoryHqLocationServices())->readableLocation($adminInfo, (int)($input['base_location_id'] ?? 0));
        return Db::transaction(function () use ($base, $key, $name): array {
            $location = (array)$base['location'];
            $this->lockOwner($location);
            $code = 'HQ-' . $location['owner_id'] . '-' . strtoupper(substr(hash('sha256', $key), 0, 24));
            $existing = Db::name('inventory_location')->where('tenant_id', $location['tenant_id'])->where('location_code', $code)->lock(true)->find();
            if ($existing) {
                if ((int)$existing['owner_id'] !== (int)$location['owner_id'] || (string)$existing['location_name'] !== $name) throw new \RuntimeException('inventory_platform_warehouse_idempotency_conflict');
                return ['location' => $existing, 'idempotent' => true];
            }
            $this->assertNameAvailable($location, $name);
            $now = time();
            $id = (int)Db::name('inventory_location')->insertGetId([
                'tenant_id' => $location['tenant_id'], 'organization_id' => $location['organization_id'],
                'organization_path' => $location['organization_path'], 'organization_name_snapshot' => $location['organization_name_snapshot'],
                'location_type' => 'HQ', 'owner_id' => $location['owner_id'], 'location_code' => $code,
                'location_name' => $name, 'store_id' => 0, 'store_name_snapshot' => '', 'is_default' => 0,
                'location_status' => 'ACTIVE', 'version' => 1, 'created_by_admin_id' => (int)$base['access']['admin_id'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            return ['location' => Db::name('inventory_location')->where('id', $id)->find(), 'idempotent' => false];
        });
    }

    /** 版本保护阻止旧页面覆盖他人设置；改名与停用不覆盖历史单据快照。 */
    public function update(array $adminInfo, int $id, array $input): array
    {
        $policy = new InventoryPlatformAccessPolicy();
        $access = $policy->resolve($adminInfo);
        $policy->assertFeature($access, 'inventory.location.settings', '当前岗位未配置“仓库设置”权限。');
        $resolved = (new InventoryHqLocationServices())->readableLocation($adminInfo, $id);
        $name = $this->name($input['location_name'] ?? '');
        $status = (string)($input['location_status'] ?? '');
        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true) || (int)($input['expected_version'] ?? 0) <= 0) throw new \InvalidArgumentException('inventory_platform_warehouse_input_invalid');
        return Db::transaction(function () use ($resolved, $id, $input, $name, $status): array {
            $this->lockOwner((array)$resolved['location']);
            $location = Db::name('inventory_location')->where('id', $id)->lock(true)->find();
            if (!$location || (int)$location['version'] !== (int)$input['expected_version']) throw new \RuntimeException('仓库设置已变化，请刷新后重试。');
            $this->assertNameAvailable($location, $name, $id);
            if ($status === 'INACTIVE' && (string)$location['location_status'] !== 'INACTIVE') $this->assertCanDisable($location);
            $changed = Db::name('inventory_location')->where('id', $id)->where('version', (int)$input['expected_version'])->update([
                'location_name' => $name, 'location_status' => $status, 'version' => (int)$location['version'] + 1, 'updated_at' => time(),
            ]);
            if ($changed !== 1) throw new \RuntimeException('仓库设置已变化，请刷新后重试。');
            return ['location' => Db::name('inventory_location')->where('id', $id)->find()];
        });
    }

    private function name($value): string
    {
        if (!is_string($value)) throw new \InvalidArgumentException('inventory_platform_warehouse_input_invalid');
        $name = trim($value);
        if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) throw new \InvalidArgumentException('inventory_platform_warehouse_input_invalid');
        return $name;
    }

    /** 同一总部共用组织行锁，保证并发建仓与改名的名称唯一。 */
    private function lockOwner(array $location): void
    {
        if (!Db::name('organization')->where('id', (int)$location['owner_id'])->where('is_del', 0)->lock(true)->find()) throw new \RuntimeException('仓库所属组织已失效。');
    }

    private function assertNameAvailable(array $location, string $name, int $exceptId = 0): void
    {
        if (Db::name('inventory_location')->where('tenant_id', $location['tenant_id'])->where('location_type', 'HQ')
            ->where('owner_id', (int)$location['owner_id'])->where('location_name', $name)->where('id', '<>', $exceptId)->value('id')) throw new \RuntimeException('该总部已存在同名仓库。');
    }

    /** 停用前锁仓核对余额及未完成单据；不删除历史或转移库存。 */
    private function assertCanDisable(array $location): void
    {
        $id = (int)$location['id'];
        if (Db::name('inventory_stock')->where('location_id', $id)->where('available_quantity_units', '<>', 0)->value('id')) throw new \RuntimeException('仓库仍有库存，不能停用。');
        if (Db::name('inventory_stock_count_draft')->where('location_id', $id)->where('document_status', 'DRAFT')->value('id')) throw new \RuntimeException('仓库仍有未完成盘点草稿，不能停用。');
        if (Db::name('inventory_cross_transfer_document')->whereIn('document_status', ['DRAFT', 'DISPATCHED'])
            ->where(function ($q) use ($id): void { $q->where('from_location_id', $id)->whereOr('to_location_id', $id); })->value('id')) throw new \RuntimeException('仓库仍有未完成调拨，不能停用。');
        // 未绑定总部供货仓的旧请货不能因默认仓停用被遗落；先核实绑定，再允许停用。
        if ((int)$location['is_default'] === 1 && Db::name('inventory_stock_request_document')->alias('d')
            ->join('inventory_location l', 'l.id=d.location_id')
            ->where('d.tenant_id', $location['tenant_id'])->where('d.supply_party_type', 'HQ')->where('d.supply_location_id', 0)
            ->whereIn('d.document_status', ['APPLIED', 'PARTIAL'])
            ->where('l.organization_path', 'like', '/' . (int)$location['owner_id'] . '/%')->value('d.id')) {
            throw new \RuntimeException('存在尚未确认供货仓的历史请货，请先绑定仓库。');
        }
        if (Db::name('inventory_stock_request_document')->whereIn('document_status', ['APPLIED', 'PARTIAL'])
            ->where(function ($q) use ($id): void { $q->where('location_id', $id)->whereOr('supply_location_id', $id); })->value('id')) throw new \RuntimeException('仓库仍有未完成请货，不能停用。');
    }
}
