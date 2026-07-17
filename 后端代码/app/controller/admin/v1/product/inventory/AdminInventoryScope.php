<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\services\product\inventory\InventoryScopeServices;

/**
 * Admin 库存监管范围参数读取
 */
trait AdminInventoryScope
{
    protected function inventoryScope(): array
    {
        $params = $this->request->getMore([
            ['scope', 'hq'],
            ['store_id', ''],
        ]);
        /** @var InventoryScopeServices $scopeServices */
        $scopeServices = app()->make(InventoryScopeServices::class);
        return $scopeServices->resolveFromRequest($params);
    }

    /**
     * 写入/导入：仅总部仓；监管范围请求必须拒绝，禁止静默写总部仓
     */
    protected function assertInventoryHqWrite(): void
    {
        $scope = strtolower(trim((string)$this->request->param('scope', 'hq')));
        /** @var InventoryScopeServices $scopeServices */
        $scopeServices = app()->make(InventoryScopeServices::class);
        $scopeServices->assertHqWriteOnly([
            'scope' => $scope === '' ? 'hq' : $scope,
            'store_id' => $this->request->param('store_id', ''),
        ]);
    }
}
