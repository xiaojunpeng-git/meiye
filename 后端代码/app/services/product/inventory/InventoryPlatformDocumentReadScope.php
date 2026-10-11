<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\product\inventory\query\InventoryPlatformUnifiedQueryContextFactory;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryRuntime;
use think\facade\Db;

/** 单据展开与平台列表共用服务端数据范围；门店选择不能冒充门店登录或取得写权限。 */
final class InventoryPlatformDocumentReadScope
{
    /** 浏览器只能缩小到已授权的单一仓库，租户、成本可见性始终由后台身份解析。 */
    public function resolve(array $adminInfo, int $hqLocationId, string $pageCode, array $selection = []): array
    {
        $subject = $selection['subject'] ?? 'HQ';
        if (!is_string($subject) || !in_array($subject, ['HQ', 'STORE'], true)) {
            throw new \InvalidArgumentException('inventory_document_read_subject_invalid');
        }
        if ($subject === 'HQ') {
            return (new InventoryHqLocationServices())->readableLocation($adminInfo, $hqLocationId);
        }
        $storeId = $this->positiveId($selection['storeId'] ?? null);
        $locationId = $this->positiveId($selection['locationId'] ?? null);
        $runtime = UnifiedQueryRuntime::runtime();
        $context = (new InventoryPlatformUnifiedQueryContextFactory($runtime['contextFactory']))->make(
            (int)($adminInfo['id'] ?? 0), $adminInfo, date('Y-m-d'), $storeId, 'STORE', $pageCode
        );
        $allowedLocations = array_map('intval', (array)($context['scope_dimensions']['location_id'] ?? []));
        if (!in_array($locationId, $allowedLocations, true)) {
            throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '所选仓库不在当前平台库存范围内。', []);
        }
        $location = Db::name('inventory_location')->where('tenant_id', (string)$context['tenant_id'])
            ->where('store_id', $storeId)->where('id', $locationId)
            ->where('location_type', 'STORE')->where('location_status', 'ACTIVE')->find();
        if (!$location) throw new \InvalidArgumentException('inventory_document_read_location_missing');
        // 此适配器只提供读取能力；现有总部作废判断不得延伸到门店单据。
        return ['location' => (array)$location, 'access' => ['features' => array_values(array_intersect(
            ['inventory.cost.view'], (array)($context['permissions'] ?? [])
        ))]];
    }

    private function positiveId($value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value)) {
            throw new \InvalidArgumentException('inventory_document_read_selection_invalid');
        }
        return (int)$value;
    }
}
