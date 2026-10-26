<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use think\facade\Db;

/** Resolves a count creator from an authenticated session, never from client-supplied row fields. */
final class InventoryStockCountActorServices
{
    public const STORE_STAFF = 'STORE_STAFF';
    public const EMPLOYEE = 'EMPLOYEE';

    /** @return array{type:string,id:int,name:string} The type is part of ownership, preventing numeric ID collisions. */
    public function resolve(int $storeId, int $sessionId, array $sessionInfo = []): array
    {
        if ($storeId <= 0 || $sessionId <= 0 || !empty($sessionInfo['_cashier_v3_delegated'])) {
            throw new \RuntimeException('inventory_stock_count_scope_denied');
        }
        if (!empty($sessionInfo['_cashier_v3_organization'])) {
            // The organization token's id is a replaceable session row; employee_id is the stable creator.
            if ((int)($sessionInfo['id'] ?? 0) !== $sessionId
                || (int)($sessionInfo['store_id'] ?? 0) !== $storeId
                || (int)($sessionInfo['employee_id'] ?? 0) <= 0
                || ($sessionInfo['session_mode'] ?? '') !== 'store_organization'
                || !empty($sessionInfo['read_only'])
                // parseToken rebuilds operation_features from the live job and store scope on every request.
                || !in_array('cashier.v3.inventory.count', (array)($sessionInfo['operation_features'] ?? []), true)) {
                throw new \RuntimeException('inventory_stock_count_scope_denied');
            }
            $name = mb_substr(trim((string)($sessionInfo['real_name'] ?? $sessionInfo['name'] ?? $sessionInfo['account'] ?? '')), 0, 128);
            if ($name === '') {
                $name = mb_substr(trim((string)Db::name('employee')->where('id', (int)$sessionInfo['employee_id'])->value('name')), 0, 128);
            }
            if ($name === '') throw new \RuntimeException('inventory_stock_count_scope_denied');
            return ['type' => self::EMPLOYEE, 'id' => (int)$sessionInfo['employee_id'], 'name' => $name];
        }
        $staff = Db::name('system_store_staff')->where('id', $sessionId)->where('store_id', $storeId)
            ->where('status', 1)->where('is_del', 0)->field('id,staff_name')->find();
        if (!$staff) throw new \RuntimeException('inventory_stock_count_scope_denied');
        return ['type' => self::STORE_STAFF, 'id' => $sessionId, 'name' => mb_substr(trim((string)$staff['staff_name']), 0, 128)];
    }
}
