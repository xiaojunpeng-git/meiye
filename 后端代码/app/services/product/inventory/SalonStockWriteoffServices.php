<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\dao\product\inventory\StoreProjectConsumableRecipeDao;
use app\dao\product\inventory\StoreProjectConsumableRecipeDetailDao;
use app\services\BaseServices;
use app\services\product\branch\StoreBranchProductServices;
use app\services\product\sku\StoreProductAttrValueServices;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 院装·核销同事务扣料/退料（D3/D4）
 *
 * 收口点：核销主事务内同步调用（项目单 product_type=6）。
 * 以「核销记录ID」为幂等锚点（uk_writeoff_action / uk_idempotent）。
 *
 * 关键约束（Codex 复核修订）：
 * 1. 不吞异常：任何解析/映射/库存不足都向上抛出，由核销主事务整体回滚。
 * 2. 先按「映射后的最终门店 SKU」固定顺序预锁全部耗材并汇总不足清单，检查通过后才扣料。
 * 3. 平台配方在门店缺少商品/SKU 副本时明确报错「请先同步」，禁止回退扣平台库存。
 * 4. 退料严格取原扣料流水；原明细非法直接抛错，不静默跳过。
 * 5. 快照固化项目/耗材/规格名称，报表不再实时回查配方。
 */
class SalonStockWriteoffServices extends BaseServices
{
    use ServicesTrait;

    public const STATUS_TAKEN = 1;   // 领用
    public const STATUS_RETURNED = 2; // 已退回（D4）

    /** @var StoreProjectConsumableRecipeDetailDao */
    protected $detailDao;

    public function __construct(StoreProjectConsumableRecipeDao $dao, StoreProjectConsumableRecipeDetailDao $detailDao)
    {
        $this->dao = $dao;
        $this->detailDao = $detailDao;
    }

    /**
     * 核销扣料入口（幂等，失败抛出）。
     *
     * 必须在核销主事务内同步调用；任一步失败将由外层事务整体回滚，
     * 保证「核销权益 + 核销记录 + 扣料 + 院装领用出库」原子一致。
     *
     * @param int $writeoffId       核销记录ID（store_order_writeoff.id）
     * @param int $storeId          实际核销门店ID（0 表示平台/无门店，不扣料）
     * @param int $projectProductId 项目商品ID（订单购物车中的平台商品ID）
     * @param string $projectUnique 项目SKU unique
     * @param string $writeoffCount 本次核销次数（>0）
     */
    public function consumeForWriteoff(int $writeoffId, int $storeId, int $projectProductId, string $projectUnique, string $writeoffCount): void
    {
        $this->doConsume($writeoffId, $storeId, $projectProductId, trim($projectUnique), $writeoffCount);
    }

    protected function doConsume(int $writeoffId, int $storeId, int $projectProductId, string $projectUnique, string $writeoffCount): void
    {
        // a2：关键字段缺失不得静默 return，改抛错——项目行核销必须可解析并落到具体门店
        if ($writeoffId <= 0) {
            throw new ValidateException('院装扣料缺少核销记录ID，无法扣减耗材');
        }
        if ($projectProductId <= 0 || $projectUnique === '') {
            throw new ValidateException(sprintf('院装扣料缺少项目商品/规格信息（商品ID %d / SKU %s）', $projectProductId, $projectUnique));
        }
        if ($storeId <= 0) {
            throw new ValidateException('院装扣料缺少实际核销门店，无法扣减耗材');
        }
        if (!is_numeric($writeoffCount) || bccomp($writeoffCount, '0', 4) <= 0) {
            throw new ValidateException('院装扣料核销数量非法');
        }

        // 幂等前置：已生成领用记录直接返回（uk_idempotent 兜底并发）
        $idempotentKey = 'salon_out_' . $writeoffId;
        if (Db::name('store_salon_stock_usage')->where('idempotent_key', $idempotentKey)->value('id')) {
            return;
        }

        $resolved = $this->resolveEnabledRecipe($projectProductId, $projectUnique, $storeId);
        if (!$resolved) {
            return; // 无启用配方：该项目本身不需扣料（非错误）
        }
        $ownerType = (int)$resolved['owner_type'];
        $recipe = $resolved['recipe'];
        $details = $resolved['details'];

        // ===== 预映射（不加锁）：解析每条配方明细的最终门店 SKU + 用量 =====
        // - snapshotLines：保留每条配方行（含固化名称），供报表按快照回溯
        // - aggregated：按最终门店 SKU 聚合用量，供统一锁序 / 扣料 / 台账 / 明细
        $snapshotLines = [];
        $aggregated = [];
        /** @var StockQtyValidateServices $qtyValidate */
        $qtyValidate = app()->make(StockQtyValidateServices::class);
        foreach ($details as $d) {
            $recipePid = (int)$d['consumable_product_id'];
            $recipeUnique = (string)$d['consumable_unique'];
            // 院装耗材：单次用量与合计扣料均最多 2 位小数，禁止静默截断
            try {
                $per = $qtyValidate->assertQty($d['qty_per_writeoff'] ?? 0, 2, '院装配方用量');
                $qty = $qtyValidate->assertQty(bcmul($per, (string)$writeoffCount, 4), 2, '院装扣料数量');
            } catch (\mohe\exceptions\AdminException $e) {
                throw new ValidateException($e->getMessage());
            }

            if ($ownerType === 1) {
                $finalPid = $recipePid;
                $finalUnique = $recipeUnique;
            } else {
                // 平台配方：映射门店副本，缺副本抛错「请先同步」，禁止回退扣平台库存
                [$finalPid, $finalUnique] = $this->mapPlatformConsumableToStore($recipePid, $recipeUnique, $storeId);
            }

            $snapshotLines[] = [
                'recipe_product_id' => $recipePid,
                'recipe_unique' => $recipeUnique,
                'consumable_product_id' => $finalPid,
                'consumable_unique' => $finalUnique,
                'consumable_name' => '', // 阶段2锁定后补固化名
                'sku_name' => '',
                'qty_per_writeoff' => $per,
                'qty' => $qty,
                'stock_unit' => (string)($d['stock_unit'] ?? ''),
            ];
            if (bccomp($qty, '0', 4) <= 0) {
                continue;
            }
            $key = $finalPid . '|' . $finalUnique;
            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'final_pid' => $finalPid,
                    'final_unique' => $finalUnique,
                    'qty' => '0',
                    'stock_unit' => (string)($d['stock_unit'] ?? ''),
                ];
            }
            $aggregated[$key]['qty'] = bcadd($aggregated[$key]['qty'], $qty, 2);
        }
        if (!$aggregated) {
            return; // 无有效扣料明细：不占用幂等键
        }

        // 统一锁序：先查各最终门店 SKU 行ID（不加锁）→ 按 (SKU行ID 升序, 商品ID 升序, unique 升序) 排序
        // 扣料与退料共用同一锁序，规避跨事务交叉加锁死锁
        $orderedKeys = $this->orderTargetsBySkuId($aggregated);

        /** @var ProductInventoryChangeServices $changeServices */
        $changeServices = app()->make(ProductInventoryChangeServices::class);
        /** @var StoreProductStockOrderServices $stockOrderServices */
        $stockOrderServices = app()->make(StoreProductStockOrderServices::class);

        $this->transaction(function () use (
            $writeoffId, $storeId, $projectProductId, $projectUnique, $writeoffCount,
            $ownerType, $recipe, $aggregated, $orderedKeys, $snapshotLines, $idempotentKey, $changeServices, $stockOrderServices
        ) {
            $time = time();

            // ===== 阶段1：按统一锁序预锁全部最终门店 SKU + 汇总不足清单 =====
            $plan = [];       // key => 待扣料计划（最终门店 SKU + 聚合数量 + 固化名称）
            $shortages = [];  // 不足清单
            foreach ($orderedKeys as $key) {
                $finalPid = (int)$aggregated[$key]['final_pid'];
                $finalUnique = (string)$aggregated[$key]['final_unique'];
                $qty = (string)$aggregated[$key]['qty'];

                $sku = Db::name('store_product_attr_value')
                    ->where('product_id', $finalPid)
                    ->where('unique', $finalUnique)
                    ->where('type', 0)
                    ->lock(true)
                    ->find();
                if (!$sku) {
                    throw new ValidateException(sprintf('门店缺少耗材规格副本（商品ID %d / SKU %s），请先同步后再核销', $finalPid, $finalUnique));
                }
                $product = Db::name('store_product')->where('id', $finalPid)->field('store_name,allow_negative_stock,is_inventory')->find();
                if (!$product || (int)($product['is_inventory'] ?? 0) !== 1) {
                    throw new ValidateException(sprintf('耗材未参与库存管理（商品ID %d），请先同步后再核销', $finalPid));
                }
                $scale = max(0, min(2, (int)($sku['decimal_scale'] ?? 0)));
                $available = (string)($sku['stock'] ?? '0');
                $allowNegative = (int)($product['allow_negative_stock'] ?? 1) === 1;
                if (!$allowNegative && bccomp($available, $qty, 4) < 0) {
                    $shortages[] = sprintf(
                        '%s（商品ID %d/SKU %s）需 %s 现 %s 缺 %s',
                        (string)($product['store_name'] ?? ''),
                        $finalPid,
                        $finalUnique,
                        $this->trimQty($qty, $scale),
                        $this->trimQty($available, $scale),
                        $this->trimQty(bcsub($qty, $available, 4), $scale)
                    );
                }

                $plan[$key] = [
                    'final_pid' => $finalPid,
                    'final_unique' => $finalUnique,
                    'qty' => $qty,
                    'stock_unit' => (string)($aggregated[$key]['stock_unit'] ?? ($sku['stock_unit'] ?? '')),
                    'sku_name' => (string)($sku['suk'] ?? ''),
                    'product_name' => (string)($product['store_name'] ?? ''),
                ];
            }

            if (!$plan) {
                return;
            }
            if ($shortages) {
                throw new ValidateException('以下院装耗材库存不足，本次核销失败：' . implode('；', $shortages));
            }

            // ===== 幂等占位：先插主记录抢占唯一键，并发/重试第二个直接失败回滚 =====
            try {
                $usageId = (int)Db::name('store_salon_stock_usage')->insertGetId([
                    'writeoff_id' => $writeoffId,
                    'store_id' => $storeId,
                    'status' => self::STATUS_TAKEN,
                    'recipe_snapshot' => '',
                    'idempotent_key' => $idempotentKey,
                    'out_stock_order_id' => 0,
                    'in_stock_order_id' => 0,
                    'add_time' => $time,
                    'update_time' => $time,
                ]);
            } catch (\Throwable $e) {
                if ($this->isDuplicate($e)) {
                    return; // 已被其它进程处理
                }
                throw $e;
            }

            // ===== 阶段2：按统一锁序扣料 + 台账 + 固化名称明细/快照 =====
            $projectName = (string)Db::name('store_product')->where('id', $projectProductId)->value('store_name');
            $projectSkuName = (string)Db::name('store_product_attr_value')
                ->where('product_id', $projectProductId)->where('unique', $projectUnique)->where('type', 0)->value('suk');

            $outDetail = [];
            $usageDetailRows = [];
            foreach ($orderedKeys as $key) {
                $p = $plan[$key];
                // 已在阶段1锁定并映射到门店最终 SKU：store_id=0 直扣，禁止再次门店映射回退平台库存
                $res = $changeServices->changeSkuStock([
                    'product_id' => $p['final_pid'],
                    'unique' => $p['final_unique'],
                    'delta_stock' => bcsub('0', $p['qty'], 4),
                    'store_id' => 0,
                    'biz_type' => ProductInventoryChangeServices::BIZ_SALON,
                    'change_sales' => false,
                ]);
                $balance = (string)($res['stock'] ?? '0');

                $outDetail[] = [
                    'product_id' => $p['final_pid'],
                    'unique' => $p['final_unique'],
                    'stock' => $p['qty'], // 基本单位正数；出库单 saveData 内部取负
                ];
                $usageDetailRows[$key] = [
                    'consumable_product_id' => $p['final_pid'],
                    'consumable_unique' => $p['final_unique'],
                    'consumable_name' => $p['product_name'], // 固化名（报表禁查当前商品覆盖）
                    'sku_name' => $p['sku_name'],
                    'qty' => $p['qty'],
                    'stock_unit' => $p['stock_unit'],
                    'balance_stock' => $balance,
                    'stock_detail_id' => 0, // 出库台账落库后回填
                    'add_time' => $time,
                ];
            }

            // 院装领用出库台账（order_type=8；库存已由 changeSkuStock 扣完，isStock=false）
            $outOrderId = $stockOrderServices->saveData(2, [
                'store_order_id' => 0,
                'order_type' => 8,
                'stock_time' => date('Y-m-d', $time),
                'remark' => '院装领用·核销#' . $writeoffId,
                'out_product_detail' => $outDetail,
            ], 1, $storeId, 0, false, false);
            $outOrderId = is_numeric($outOrderId) ? (int)$outOrderId : 0;

            // a7：回填库存明细追踪ID（store_product_stock_detail），按 (product_id,unique) 定位
            $stockDetailMap = $this->stockDetailIdMap($outOrderId);
            $usageDetailInsert = [];
            foreach ($usageDetailRows as $key => $row) {
                $row['usage_id'] = $usageId;
                $mapKey = (int)$row['consumable_product_id'] . '|' . (string)$row['consumable_unique'];
                $row['stock_detail_id'] = $stockDetailMap[$mapKey] ?? 0;
                $usageDetailInsert[] = $row;
            }

            // 快照 lines 回填固化名称（按最终 SKU 匹配 plan）
            foreach ($snapshotLines as &$line) {
                $lineKey = (int)$line['consumable_product_id'] . '|' . (string)$line['consumable_unique'];
                if (isset($plan[$lineKey])) {
                    $line['consumable_name'] = $plan[$lineKey]['product_name'];
                    $line['sku_name'] = $plan[$lineKey]['sku_name'];
                }
            }
            unset($line);

            $snapshot = json_encode([
                'recipe_id' => (int)$recipe['id'],
                'recipe_type' => (int)$recipe['type'],
                'recipe_relation_id' => (int)$recipe['relation_id'],
                'recipe_version' => (int)$recipe['version'],
                'owner_type' => $ownerType,
                'project_product_id' => $projectProductId,
                'project_unique' => $projectUnique,
                'project_name' => $projectName,
                'project_sku_name' => $projectSkuName,
                'writeoff_count' => $writeoffCount,
                'lines' => array_values($snapshotLines),
            ], JSON_UNESCAPED_UNICODE);

            Db::name('store_salon_stock_usage')->where('id', $usageId)->update([
                'recipe_snapshot' => $snapshot,
                'out_stock_order_id' => $outOrderId,
                'update_time' => $time,
            ]);
            Db::name('store_salon_stock_usage_detail')->insertAll($usageDetailInsert);
        });
    }

    /**
     * 撤销核销退料（D4）：按原扣料流水原量退回，幂等。
     *
     * 严格取原领用明细的数量与商品，不按当前配方重算；无原扣料流水则不凭空加库存。
     * 由共享撤销服务在同一事务内调用；本方法不吞异常，失败将回滚整个撤销。
     * 原明细非法（商品/SKU/数量异常）直接抛错，不静默跳过。
     *
     * @param int $writeoffId 核销记录ID（store_order_writeoff.id）
     */
    public function returnForWriteoff(int $writeoffId): void
    {
        if ($writeoffId <= 0) {
            return;
        }
        // 原领用流水（status=1）
        $out = Db::name('store_salon_stock_usage')
            ->where('writeoff_id', $writeoffId)
            ->where('status', self::STATUS_TAKEN)
            ->find();
        if (!$out) {
            return; // 无原扣料，不退料
        }
        // 幂等：已退回则跳过
        $returnKey = 'salon_in_' . $writeoffId;
        if (Db::name('store_salon_stock_usage')->where('idempotent_key', $returnKey)->value('id')) {
            return;
        }
        if (Db::name('store_salon_stock_usage')->where('writeoff_id', $writeoffId)->where('status', self::STATUS_RETURNED)->value('id')) {
            return;
        }

        $storeId = (int)$out['store_id'];
        $outUsageId = (int)$out['id'];
        $detailRows = Db::name('store_salon_stock_usage_detail')->where('usage_id', $outUsageId)->select()->toArray();
        if (!$detailRows) {
            throw new ValidateException('原院装领用明细缺失，无法退料（核销#' . $writeoffId . '）');
        }
        // 统一锁序：与扣料一致，按 (SKU行ID 升序, 商品ID 升序, unique 升序) 处理，规避跨事务死锁
        // 批量查 SKU 行ID（单次 whereIn），避免逐条查询
        $skuIdMap = $this->skuRowIdMap($detailRows);
        usort($detailRows, function ($a, $b) use ($skuIdMap) {
            $ka = (int)$a['consumable_product_id'] . '|' . (string)$a['consumable_unique'];
            $kb = (int)$b['consumable_product_id'] . '|' . (string)$b['consumable_unique'];
            return [(int)($skuIdMap[$ka] ?? 0), (int)$a['consumable_product_id'], (string)$a['consumable_unique']]
                <=> [(int)($skuIdMap[$kb] ?? 0), (int)$b['consumable_product_id'], (string)$b['consumable_unique']];
        });

        /** @var ProductInventoryChangeServices $changeServices */
        $changeServices = app()->make(ProductInventoryChangeServices::class);
        /** @var StoreProductStockOrderServices $stockOrderServices */
        $stockOrderServices = app()->make(StoreProductStockOrderServices::class);

        $this->transaction(function () use ($writeoffId, $storeId, $out, $detailRows, $returnKey, $changeServices, $stockOrderServices) {
            $time = time();
            try {
                $usageId = (int)Db::name('store_salon_stock_usage')->insertGetId([
                    'writeoff_id' => $writeoffId,
                    'store_id' => $storeId,
                    'status' => self::STATUS_RETURNED,
                    'recipe_snapshot' => (string)($out['recipe_snapshot'] ?? ''),
                    'idempotent_key' => $returnKey,
                    'out_stock_order_id' => 0,
                    'in_stock_order_id' => 0,
                    'add_time' => $time,
                    'update_time' => $time,
                ]);
            } catch (\Throwable $e) {
                if ($this->isDuplicate($e)) {
                    return; // 并发/重试：已退回
                }
                throw $e;
            }

            $inDetail = [];
            $usageDetailRows = [];
            foreach ($detailRows as $d) {
                $pid = (int)$d['consumable_product_id'];
                $unique = (string)$d['consumable_unique'];
                $qty = (string)$d['qty'];
                // 原明细非法直接抛错，不静默跳过，避免账实不一致
                if ($pid <= 0 || $unique === '' || !is_numeric($qty) || bccomp($qty, '0', 4) <= 0) {
                    throw new ValidateException('原院装领用明细非法（商品ID ' . $pid . '/SKU ' . $unique . '），退料终止');
                }
                // 原扣料商品/SKU 已是扣减时的门店商品，store_id=0 直接回加良品库存
                $res = $changeServices->changeSkuStock([
                    'product_id' => $pid,
                    'unique' => $unique,
                    'delta_stock' => $qty,
                    'store_id' => 0,
                    'biz_type' => ProductInventoryChangeServices::BIZ_SALON,
                    'change_sales' => false,
                ]);
                $balance = (string)($res['stock'] ?? '0');
                $inDetail[] = [
                    'product_id' => $pid,
                    'unique' => $unique,
                    'stock' => $qty,
                ];
                $usageDetailRows[] = [
                    'consumable_product_id' => $pid,
                    'consumable_unique' => $unique,
                    'consumable_name' => (string)($d['consumable_name'] ?? ''), // 沿用原领用固化名
                    'sku_name' => (string)($d['sku_name'] ?? ''),
                    'qty' => $qty,
                    'stock_unit' => (string)($d['stock_unit'] ?? ''),
                    'balance_stock' => $balance,
                    'stock_detail_id' => 0,
                    'add_time' => $time,
                ];
            }

            if (!$inDetail) {
                throw new ValidateException('原院装领用明细为空，退料终止（核销#' . $writeoffId . '）');
            }

            // 院装退回入库台账（order_type=7；库存已由 changeSkuStock 回加，isStock=false）
            $inOrderId = $stockOrderServices->saveData(1, [
                'store_order_id' => 0,
                'order_type' => 7,
                'stock_time' => date('Y-m-d', $time),
                'remark' => '院装退回·撤销核销#' . $writeoffId,
                'in_product_detail' => $inDetail,
            ], 1, $storeId, 0, false, false);
            $inOrderId = is_numeric($inOrderId) ? (int)$inOrderId : 0;

            $stockDetailMap = $this->stockDetailIdMap($inOrderId);
            foreach ($usageDetailRows as &$row) {
                $row['usage_id'] = $usageId;
                $key = (int)$row['consumable_product_id'] . '|' . (string)$row['consumable_unique'];
                $row['stock_detail_id'] = $stockDetailMap[$key] ?? 0;
            }
            unset($row);

            Db::name('store_salon_stock_usage')->where('id', $usageId)->update([
                'in_stock_order_id' => $inOrderId,
                'update_time' => $time,
            ]);
            Db::name('store_salon_stock_usage_detail')->insertAll($usageDetailRows);
        });
    }

    /**
     * 解析启用中的项目耗材配方。
     * P0 口径：仅读取总部启用配方（type=0,relation_id=0）；忽略门店配方。
     * $storeId 保留入参兼容，不参与选方（扣料仍只扣核销门店库存，见扣料主流程）。
     *
     * @return array{recipe:array,details:array,owner_type:int}|array
     */
    public function resolveEnabledRecipe(int $platformProjectId, string $platformUnique, int $storeId): array
    {
        $platformUnique = trim($platformUnique);
        if ($platformProjectId <= 0 || $platformUnique === '') {
            return [];
        }

        // 口径：配方仅总部维护；门店配方忽略，核销统一执行总部启用配方（$storeId 不参与选方）
        $recipe = $this->dao->findByOwnerProject(0, 0, $platformProjectId, $platformUnique);
        if ($recipe && (int)$recipe['status'] === StoreProjectConsumableRecipeServices::STATUS_ENABLED) {
            $detailRows = $this->detailDao->getByRecipeId((int)$recipe['id']);
            if ($detailRows) {
                return ['recipe' => $recipe, 'details' => $detailRows, 'owner_type' => 0];
            }
        }
        return [];
    }

    /**
     * 平台项目商品 → 门店项目商品 SKU 映射（缺副本返回 [0,'']）
     * @return array{0:int,1:string} [storeProductId, storeUnique]
     */
    protected function mapPlatformProjectToStore(int $platformProjectId, string $platformUnique, int $storeId): array
    {
        /** @var StoreBranchProductServices $branchProductServices */
        $branchProductServices = app()->make(StoreBranchProductServices::class);
        /** @var StoreProductAttrValueServices $skuServices */
        $skuServices = app()->make(StoreProductAttrValueServices::class);

        $info = $branchProductServices->isValidStoreProduct($platformProjectId, $storeId);
        $storePid = (int)($info['id'] ?? 0);

        // 入参本身就是本门店商品ID（订单以门店商品下单的情况）
        if ($storePid <= 0) {
            $selfStore = Db::name('store_product')
                ->where('id', $platformProjectId)
                ->where('type', 1)
                ->where('relation_id', $storeId)
                ->where('is_del', 0)
                ->value('id');
            if ($selfStore) {
                return [$platformProjectId, $platformUnique];
            }
            return [0, ''];
        }
        if ($storePid === $platformProjectId) {
            return [$storePid, $platformUnique];
        }
        $suk = $skuServices->value(['unique' => $platformUnique, 'product_id' => $platformProjectId, 'type' => 0], 'suk');
        if ($suk === null || $suk === '') {
            return [0, ''];
        }
        $storeUnique = (string)$skuServices->value(['suk' => $suk, 'product_id' => $storePid, 'type' => 0], 'unique');
        return [$storePid, $storeUnique];
    }

    /**
     * 平台耗材商品 → 门店耗材商品 SKU 映射（缺副本抛错「请先同步」，禁止回退扣平台库存）
     * @return array{0:int,1:string} [storeProductId, storeUnique]
     */
    protected function mapPlatformConsumableToStore(int $platformPid, string $platformUnique, int $storeId): array
    {
        [$storePid, $storeUnique] = $this->mapPlatformProjectToStore($platformPid, $platformUnique, $storeId);
        if ($storePid <= 0 || $storeUnique === '') {
            throw new ValidateException(sprintf('门店缺少院装耗材商品/规格副本（平台商品ID %d / SKU %s），请先同步后再核销', $platformPid, $platformUnique));
        }
        return [$storePid, $storeUnique];
    }

    /**
     * 统一锁序：查各最终门店 SKU 行ID（不加锁），按 (SKU行ID 升序, 商品ID 升序, unique 升序) 排序返回 key 列表。
     * 缺门店副本立即抛错「请先同步」，禁止回退扣平台库存。
     *
     * @param array $aggregated key => ['final_pid'=>int,'final_unique'=>string,...]
     * @return array<int,string> 排序后的 aggregated key 列表
     */
    protected function orderTargetsBySkuId(array $aggregated): array
    {
        // 批量查 SKU 行ID（单次 whereIn），避免逐条查询
        $idMap = $this->skuRowIdMap($aggregated);
        $skuIds = [];
        foreach ($aggregated as $key => $a) {
            $pid = (int)$a['final_pid'];
            $unique = (string)$a['final_unique'];
            $id = (int)($idMap[$pid . '|' . $unique] ?? 0);
            if ($id <= 0) {
                throw new ValidateException(sprintf('门店缺少耗材规格副本（商品ID %d / SKU %s），请先同步后再核销', $pid, $unique));
            }
            $skuIds[$key] = $id;
        }
        $keys = array_keys($aggregated);
        usort($keys, function ($a, $b) use ($skuIds, $aggregated) {
            return [(int)$skuIds[$a], (int)$aggregated[$a]['final_pid'], (string)$aggregated[$a]['final_unique']]
                <=> [(int)$skuIds[$b], (int)$aggregated[$b]['final_pid'], (string)$aggregated[$b]['final_unique']];
        });
        return $keys;
    }

    /**
     * 批量查 SKU 行ID：入参含 final_pid/final_unique 的数组 → [ "product_id|unique" => sku_id ]
     * 单次 whereIn(product_id) + whereIn(unique) 查询，限定到本次实际用到的 unique，避免逐条查询也避免拉回整商品全部 SKU。
     */
    protected function skuRowIdMap(array $rows): array
    {
        $pids = [];
        $uniques = [];
        foreach ($rows as $r) {
            $pid = (int)($r['final_pid'] ?? $r['consumable_product_id'] ?? 0);
            $unique = (string)($r['final_unique'] ?? $r['consumable_unique'] ?? '');
            if ($pid > 0) {
                $pids[$pid] = $pid;
            }
            if ($unique !== '') {
                $uniques[$unique] = $unique;
            }
        }
        if (!$pids) {
            return [];
        }
        $query = Db::name('store_product_attr_value')
            ->whereIn('product_id', array_values($pids))
            ->where('type', 0);
        // 限定到本次实际用到的 unique（组合键仍以 product_id|unique 精确匹配）
        if ($uniques) {
            $query->whereIn('unique', array_values($uniques));
        }
        $list = $query
            ->field('id,product_id,unique')
            ->select()
            ->toArray();
        $map = [];
        foreach ($list as $row) {
            $map[(int)$row['product_id'] . '|' . (string)$row['unique']] = (int)$row['id'];
        }
        return $map;
    }

    /**
     * 出入库台账明细ID映射：order_id -> [ "product_id|unique" => stock_detail_id ]
     */
    protected function stockDetailIdMap(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }
        $rows = Db::name('store_product_stock_detail')
            ->where('order_id', $orderId)
            ->field('id,product_id,unique')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['product_id'] . '|' . (string)$row['unique']] = (int)$row['id'];
        }
        return $map;
    }

    protected function isDuplicate(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return stripos($msg, 'Duplicate') !== false || stripos($msg, '1062') !== false;
    }

    protected function trimQty(string $qty, int $scale = 2): string
    {
        $scale = max(0, min(2, $scale));
        $formatted = bcadd($qty, '0', $scale);
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }
        return $formatted === '' ? '0' : $formatted;
    }
}
