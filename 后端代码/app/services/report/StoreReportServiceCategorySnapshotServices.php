<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** Freezes the category path used when a project actually completes service. */
final class StoreReportServiceCategorySnapshotServices
{
    /** @return array{project_category_path_snapshot:string} */
    public function resolveInTx(int $categoryId, string $fallbackName): array
    {
        CashierV3TransactionGuard::assertInTransaction('storeReportServiceCategorySnapshot');
        $names = [];
        $seen = [];
        for ($guard = 0; $categoryId > 0 && $guard < 16; $guard++) {
            if (isset($seen[$categoryId])) {
                throw new \LogicException('service_category_snapshot_cycle');
            }
            $seen[$categoryId] = true;
            $category = Db::name('store_product_category')->where('id', $categoryId)
                ->field('id,pid,cate_name')->find();
            if (!$category) {
                break;
            }
            array_unshift($names, trim((string)($category['cate_name'] ?? '')));
            $categoryId = (int)($category['pid'] ?? 0);
        }
        $names = array_values(array_filter($names, static function (string $name): bool {
            return $name !== '';
        }));
        if ($names === []) {
            $fallbackName = trim($fallbackName);
            $names = $fallbackName === '' ? [] : [$fallbackName];
        }
        return ['project_category_path_snapshot' => implode(' / ', $names)];
    }
}
