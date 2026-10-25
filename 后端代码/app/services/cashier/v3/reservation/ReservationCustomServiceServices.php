<?php
declare(strict_types=1);
namespace app\services\cashier\v3\reservation;

use app\services\store\ServiceNameSettingsServices;
use think\exception\ValidateException;
use think\facade\Db;

/** Optional display-only booking lines. No price, stock, duration, rights or
 * performance fields are accepted. Parent reservation owns lifecycle/access.
 */
class ReservationCustomServiceServices
{
    /** Canonicalize only identity/positive integer count. Client names, prices,
     * duplicate rows and decimal counts cannot become persisted facts.
     */
    public function normalize($input): array
    {
        if (!is_array($input) || array_values($input) !== $input) throw new ValidateException('定制服务格式无效。');
        $items = [];
        foreach ($input as $item) {
            if (!is_array($item)) throw new ValidateException('定制服务格式无效。');
            $id = $this->positiveInteger($item['id'] ?? null);
            $quantity = $this->positiveInteger($item['quantity'] ?? null);
            if ($quantity > 2147483647 || isset($items[$id])) throw new ValidateException('定制服务数量或重复项目无效。');
            $items[$id] = ['id' => $id, 'quantity' => $quantity];
        }
        ksort($items, SORT_NUMERIC);
        return array_values($items);
    }

    /** Names are exclusively resolved from this store's locked live menu. */
    public function resolve(int $storeId, array $items): array
    {
        if (!$items) return [];
        if (!Db::query("SHOW TABLES LIKE 'eb_cashier_v3_reservation_custom_service'")) throw new ValidateException('定制服务功能尚未升级，请联系门店。');
        $catalog = (new ServiceNameSettingsServices())->reservationCatalog($storeId, true);
        if (!$catalog['enabled']) throw new ValidateException('门店已关闭定制服务，请刷新预约信息后重试。');
        return $this->resolveFromCatalog($items, $catalog['records']);
    }

    /** Pure resolver for the already store-scoped catalog, also used by tests. */
    public function resolveFromCatalog(array $items, array $records): array
    {
        $names = array_column($records, 'name', 'id'); $result = [];
        foreach ($this->normalize($items) as $item) {
            if (!isset($names[$item['id']])) throw new ValidateException('所选定制服务已停用或不属于预约门店，请重新选择。');
            $result[] = $item + ['name' => $names[$item['id']]];
        }
        return $result;
    }

    /** Caller holds the parent creation transaction. Never replace history on
     * retry: member reservation idempotency returns before this insertion.
     */
    public function saveInTx(string $tenantId, int $storeId, int $reservationId, array $snapshot, int $at): void
    {
        if (!$snapshot) return;
        // Never attach lines to a parent from another store/tenant, even if a
        // future caller passes the wrong ID after its own authorization check.
        if (!Db::name('cashier_v3_reservation')->where('id', $reservationId)->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)->lock(true)->find()) throw new ValidateException('预约门店范围无效。');
        foreach ($snapshot as $item) Db::name('cashier_v3_reservation_custom_service')->insert([
            'tenant_id' => $tenantId, 'store_id' => $storeId, 'reservation_id' => $reservationId,
            'service_name_id' => $item['id'], 'name_snapshot' => $item['name'], 'quantity' => $item['quantity'], 'created_at' => $at,
        ]);
    }

    /** Batched, scope-bound reads retain names even after catalog edits/off.
     * Legacy reservations and not-yet-migrated instances project empty lines.
     */
    public function forReservations(string $tenantId, int $storeId, array $ids): array
    {
        if (!$ids || !Db::query("SHOW TABLES LIKE 'eb_cashier_v3_reservation_custom_service'")) return [];
        $rows = Db::name('cashier_v3_reservation_custom_service')->alias('c')
            ->join('cashier_v3_reservation r', 'r.id=c.reservation_id AND r.tenant_id=c.tenant_id AND r.store_id=c.store_id')
            ->where('r.tenant_id', $tenantId)->where('r.store_id', $storeId)->whereIn('r.id', $ids)
            ->field('c.reservation_id,c.service_name_id,c.name_snapshot,c.quantity')->order('c.id asc')->select();
        $result = [];
        foreach ($rows as $row) $result[(int)$row['reservation_id']][] = ['id' => (int)$row['service_name_id'], 'name' => (string)$row['name_snapshot'], 'quantity' => (int)$row['quantity']];
        return $result;
    }

    /** Member listing already authorizes the header IDs; enforce the same
     * authenticated member again and fetch all stores in one batched query.
     */
    public function forMemberReservations(int $memberId, array $ids): array
    {
        if ($memberId <= 0 || !$ids || !Db::query("SHOW TABLES LIKE 'eb_cashier_v3_reservation_custom_service'")) return [];
        $rows = Db::name('cashier_v3_reservation_custom_service')->alias('c')
            ->join('cashier_v3_reservation r', 'r.id=c.reservation_id AND r.tenant_id=c.tenant_id AND r.store_id=c.store_id')
            ->where('r.member_id', $memberId)->whereIn('r.id', $ids)
            ->field('c.reservation_id,c.service_name_id,c.name_snapshot,c.quantity')->order('c.id asc')->select();
        $result = [];
        foreach ($rows as $row) $result[(int)$row['reservation_id']][] = ['id' => (int)$row['service_name_id'], 'name' => (string)$row['name_snapshot'], 'quantity' => (int)$row['quantity']];
        return $result;
    }

    private function positiveInteger($value): int
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)))
            || (int)$value <= 0 || (string)(int)$value !== (string)$value) throw new ValidateException('定制服务数量必须为正整数。');
        return (int)$value;
    }
}
