<?php

namespace app\services\cashier\v3\checkout;

use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * 在结账事务内从服务项目的权威商品分类冻结快照。
 * 卡的外层分类不能替代卡内实际护理项目；已失效的历史分类不能猜进现行分类。
 */
final class CashierV3ServiceProjectCategorySnapshotServices
{
    /**
     * 最终落账优先沿用服务器准备时冻结的分类；旧草稿缺失时按项目补冻。
     * 输入必须是服务器持久化的准备快照，不能直接接收浏览器字段。
     *
     * @return array{id:int,name:string}
     */
    public function resolvePreparedLineInTx(array $line, int $storeId): array
    {
        CashierV3TransactionGuard::assertInTransaction('cashierPreparedServiceCategorySnapshot');
        $id = max(0, (int)($line['category_id_snapshot'] ?? 0));
        $name = trim((string)($line['category_name_snapshot'] ?? ''));
        if ($id > 0 && $name !== '') return ['id' => $id, 'name' => $name];
        return $this->resolveInTx((int)($line['project_id'] ?? 0), $storeId);
    }

    /** @return array{id:int,name:string} */
    public function resolveInTx(int $projectId, int $storeId): array
    {
        CashierV3TransactionGuard::assertInTransaction('cashierServiceProjectCategorySnapshot');
        if ($projectId <= 0 || $storeId <= 0) return ['id' => 0, 'name' => '未分类'];

        $project = Db::name('store_product')->where('id', $projectId)
            ->field('id,pid,type,relation_id,cate_id')->find();
        if (!$project || ((int)$project['type'] === 1 && (int)$project['relation_id'] !== $storeId)) {
            return ['id' => 0, 'name' => '未分类'];
        }
        $candidates = [$project];
        if ((int)($project['pid'] ?? 0) > 0) {
            $parent = Db::name('store_product')->where('id', (int)$project['pid'])
                ->field('id,pid,type,relation_id,cate_id')->find();
            if ($parent && (int)$parent['type'] === 0) $candidates[] = $parent;
        }
        foreach ($candidates as $candidate) {
            foreach (explode(',', (string)($candidate['cate_id'] ?? '')) as $rawId) {
                $rawId = trim($rawId);
                if (preg_match('/^[1-9][0-9]*$/D', $rawId) !== 1) continue;
                $category = Db::name('store_product_category')->where('id', (int)$rawId)
                    ->where('type', 0)->where('relation_id', 0)
                    ->field('id,cate_name')->find();
                $name = trim((string)($category['cate_name'] ?? ''));
                if ($name !== '') return ['id' => (int)$category['id'], 'name' => $name];
            }
        }
        // 删除过的分类没有可信路径，必须显式留在未分类，不按项目名称或卡名猜测。
        return ['id' => 0, 'name' => '未分类'];
    }
}
