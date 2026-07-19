<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\BaseServices;
use app\services\order\cashier\PaidOrderLockLease;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrValueServices;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 统一库存变更服务（阶段1内核）
 *
 * 所有实物库存增减应逐步收口到本服务，禁止 max(0) 静默截断。
 */
class ProductInventoryChangeServices extends BaseServices
{
    use ServicesTrait;

    public const BIZ_SALE_PAY_SUCCESS = 'sale_pay_success';
    public const BIZ_SALE_REFUND = 'sale_refund';
    public const BIZ_SALE_VOID = 'sale_void';
    public const BIZ_MANUAL = 'manual';
    public const BIZ_COUNT = 'count';
    public const BIZ_TRANSFER = 'transfer';
    public const BIZ_SALON = 'salon';
    public const BIZ_EXTERNAL = 'external_sync';

    /**
     * 原子变更单个 SKU 良品/残次品库存（基本单位）
     *
     * @param array $params
     *  - product_id int
     *  - unique string
     *  - delta_stock string|float 良品增减（正加负减）
     *  - delta_defective string|float 残次品增减
     *  - store_id int 门店ID（>0 时映射门店商品）
     *  - biz_type string
     *  - allow_negative_override bool 销售支付成功并发例外
     *  - change_sales bool 是否同时加减销量（与 delta 同向销量：减库存则加销量）
     *  - sales_num string|float 销量变动绝对值；默认取 abs(delta_stock) 换算前销售数量由调用方传入
     */
    public function changeSkuStock(array $params): array
    {
        $productId = (int)($params['product_id'] ?? 0);
        $unique = (string)($params['unique'] ?? '');
        $deltaStock = (string)($params['delta_stock'] ?? '0');
        $deltaDefective = (string)($params['delta_defective'] ?? '0');
        $storeId = (int)($params['store_id'] ?? 0);
        $bizType = (string)($params['biz_type'] ?? self::BIZ_MANUAL);
        $allowNegativeOverride = (bool)($params['allow_negative_override'] ?? false);
        $changeSales = (bool)($params['change_sales'] ?? false);
        $salesNum = (string)($params['sales_num'] ?? '');

        if ($productId <= 0) {
            throw new ValidateException('商品参数错误');
        }
        if (bccomp($deltaStock, '0', 4) === 0 && bccomp($deltaDefective, '0', 4) === 0 && !$changeSales) {
            return ['product_id' => $productId, 'unique' => $unique, 'skipped' => true];
        }

        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        /** @var StoreProductAttrValueServices $skuServices */
        $skuServices = app()->make(StoreProductAttrValueServices::class);

        // 门店映射：平台 unique -> 门店商品/sku（已映射路径可 skip，禁止回退扣总部）
        $skipStoreMap = (bool)($params['skip_store_map'] ?? false);
        if ($skipStoreMap) {
            $platformPid = (int)($params['platform_pid'] ?? 0);
        } elseif ($storeId > 0) {
            $mapped = $this->mapStoreSku($productServices, $skuServices, $productId, $unique, $storeId);
            $productId = $mapped['product_id'];
            $unique = $mapped['unique'];
            $platformPid = $mapped['platform_pid'];
        } else {
            $platformPid = (int)($params['platform_pid'] ?? 0);
        }

        $product = $productServices->get($productId, ['id', 'pid', 'type', 'relation_id', 'product_type', 'is_inventory', 'allow_negative_stock', 'stock', 'defective_stock']);
        if (!$product) {
            throw new ValidateException('商品不存在');
        }
        $product = is_array($product) ? $product : $product->toArray();

        // 【供应商库存已停用】type=2 一律跳过实物库存变更（不阻断销售支付/退款；销量仍可记）
        // 原逻辑：销售链路跳过；其他业务抛「请在供应商端处理」——已注释，后续大概率不再使用供应商仓
        // if ((int)($product['type'] ?? 0) === 2) {
        //     if (in_array($bizType, [self::BIZ_SALE_PAY_SUCCESS, self::BIZ_SALE_REFUND], true)) { ... }
        //     throw new ValidateException('供应商商品库存请在供应商端处理');
        // }
        if ((int)($product['type'] ?? 0) === 2) {
            if ($changeSales && $salesNum !== '' && bccomp($salesNum, '0', 4) !== 0) {
                $this->applySalesOnly($productServices, $skuServices, $productId, $unique, $salesNum, $platformPid);
            }
            return ['product_id' => $productId, 'unique' => $unique, 'inventory_skipped' => true, 'supplier_skipped' => true];
        }

        $isInventory = (int)($product['is_inventory'] ?? 0);
        // 历史字段未迁移时：仅 product_type=0 视为参与库存
        if (!array_key_exists('is_inventory', $product)) {
            $isInventory = ((int)($product['product_type'] ?? 0) === 0) ? 1 : 0;
        }

        if ($isInventory !== 1 && in_array($bizType, [self::BIZ_SALE_PAY_SUCCESS, self::BIZ_SALE_REFUND, self::BIZ_SALE_VOID], true)) {
            // 未参与库存：销售链路只处理销量
            if ($changeSales && $salesNum !== '' && bccomp($salesNum, '0', 4) !== 0) {
                $this->applySalesOnly($productServices, $skuServices, $productId, $unique, $salesNum, $platformPid);
            }
            return ['product_id' => $productId, 'unique' => $unique, 'inventory_skipped' => true];
        }

        if ($isInventory !== 1 && bccomp($deltaStock, '0', 4) !== 0) {
            throw new ValidateException('该商品未参与库存管理');
        }

        $allowNegative = (int)($product['allow_negative_stock'] ?? 1) === 1;
        if ($allowNegativeOverride) {
            // 仅销售支付成功并发例外：允许良品扣成负，即使开关关闭
            $allowNegative = true;
        }

        $assumeLocked = (bool)($params['assume_locked'] ?? false);

        $apply = function () use (
            $productServices, $skuServices, $productId, $unique, $deltaStock, $deltaDefective,
            $allowNegative, $changeSales, $salesNum, $platformPid, $product, $assumeLocked
        ) {
            // 全局锁序：先 SKU id 升序，再商品 id 升序（assume_locked 时外层已批量加锁）
            if (!$assumeLocked) {
                $this->lockSkuThenProducts([[
                    'product_id' => $productId,
                    'unique' => $unique,
                    'platform_pid' => (int)$platformPid,
                ]]);
            }
            $skuQuery = Db::name('store_product_attr_value')
                ->where('product_id', $productId)
                ->where('unique', $unique)
                ->where('type', 0)
                ->find();
            if (!$skuQuery) {
                throw new ValidateException('商品规格不存在');
            }

            // 业务精度：院装最多 2 位，其它整数；变化量禁止静默四舍五入
            $scale = max(0, min(2, (int)($skuQuery['decimal_scale'] ?? 0)));
            /** @var StockQtyValidateServices $qtyValidate */
            $qtyValidate = app()->make(StockQtyValidateServices::class);
            $goodsLabel = 'ID' . $productId . '/' . $unique;
            try {
                if (bccomp((string)$deltaStock, '0', 4) !== 0) {
                    $abs = bccomp((string)$deltaStock, '0', 4) < 0
                        ? bcmul((string)$deltaStock, '-1', 4)
                        : (string)$deltaStock;
                    $qtyValidate->assertQty($abs, $scale, $goodsLabel);
                }
                if (bccomp((string)$deltaDefective, '0', 4) !== 0) {
                    $absDef = bccomp((string)$deltaDefective, '0', 4) < 0
                        ? bcmul((string)$deltaDefective, '-1', 4)
                        : (string)$deltaDefective;
                    $qtyValidate->assertQty($absDef, $scale, $goodsLabel . '(残次)');
                }
            } catch (\mohe\exceptions\AdminException $e) {
                throw new ValidateException($e->getMessage());
            }
            $newStock = bcadd((string)$skuQuery['stock'], (string)$deltaStock, 4);
            $newDefective = bcadd((string)($skuQuery['defective_stock'] ?? 0), (string)$deltaDefective, 4);

            // 残次品永不为负
            if (bccomp($newDefective, '0', 4) < 0) {
                throw new ValidateException('残次品库存不足');
            }
            // 良品负库存校验
            if (!$allowNegative && bccomp($newStock, '0', 4) < 0) {
                $need = bcsub('0', $deltaStock, 4);
                throw new ValidateException(sprintf(
                    '库存不足：商品ID %d / SKU %s，需要 %s，现有 %s，缺口 %s',
                    $productId,
                    $unique,
                    $this->trimQty($need, $scale),
                    $this->trimQty((string)$skuQuery['stock'], $scale),
                    $this->trimQty(bcsub($need, (string)$skuQuery['stock'], 4), $scale)
                ));
            }

            $newSum = bcadd($newStock, $newDefective, 4);
            $update = [
                'stock' => $newStock,
                'defective_stock' => $newDefective,
                'sum_stock' => $newSum,
            ];
            if ($changeSales && $salesNum !== '' && bccomp($salesNum, '0', 4) !== 0) {
                // 减库存加销量 / 加库存减销量
                if (bccomp($deltaStock, '0', 4) < 0) {
                    $update['sales'] = Db::raw('sales+' . abs((float)$salesNum));
                } elseif (bccomp($deltaStock, '0', 4) > 0) {
                    $update['sales'] = Db::raw('IF(sales>=' . abs((float)$salesNum) . ',sales-' . abs((float)$salesNum) . ',0)');
                }
            }
            Db::name('store_product_attr_value')->where('id', (int)$skuQuery['id'])->update($update);

            // 商品主表按本次变化量原子增减，禁止 SUM 快照后绝对值覆盖
            $deltaGoodSql = $this->sqlDecimal($deltaStock);
            $deltaDefSql = $this->sqlDecimal($deltaDefective);
            $productUpdate = [
                'stock' => Db::raw('`stock`+(' . $deltaGoodSql . ')'),
                'defective_stock' => Db::raw('`defective_stock`+(' . $deltaDefSql . ')'),
                // MySQL 同句 UPDATE 中 stock 已先完成增减，is_sold 只按最终 stock 判断一次，禁止再 +delta
                'is_sold' => Db::raw('IF(`stock`>0,0,1)'),
            ];
            if ($changeSales && $salesNum !== '' && bccomp($salesNum, '0', 4) !== 0) {
                if (bccomp($deltaStock, '0', 4) < 0) {
                    $productUpdate['sales'] = Db::raw('sales+' . abs((float)$salesNum));
                    if ($platformPid > 0) {
                        Db::name('store_product')->where('id', $platformPid)->inc('sales', abs((float)$salesNum))->update();
                    }
                } elseif (bccomp($deltaStock, '0', 4) > 0) {
                    $productUpdate['sales'] = Db::raw('IF(sales>=' . abs((float)$salesNum) . ',sales-' . abs((float)$salesNum) . ',0)');
                    if ($platformPid > 0) {
                        Db::name('store_product')->where('id', $platformPid)->dec('sales', abs((float)$salesNum))->update();
                    }
                }
            }
            Db::name('store_product')->where('id', $productId)->update($productUpdate);

            // 批量加锁支付/院装路径跳过全量 cacheTag 清理，避免高并发 Redis 雪崩
            if (!$assumeLocked) {
                $productServices->cacheTag()->clear();
                $skuServices->cacheTag()->clear();
            }

            return [
                'product_id' => $productId,
                'unique' => $unique,
                'stock' => $newStock,
                'defective_stock' => $newDefective,
            ];
        };

        // 已在支付/院装外层事务且 SKU 已加锁：禁止再开 savepoint，减少嵌套事务开销
        if ($assumeLocked) {
            try {
                $pdo = Db::getPdo();
                if ($pdo && $pdo->inTransaction()) {
                    return $apply();
                }
            } catch (\Throwable $e) {
                // fall through to Db::transaction
            }
        }
        return Db::transaction($apply);
    }

    /**
     * 商品订单支付前统一检查入口（会员充值等非商品单跳过）
     */
    public function assertOrderCanPay(array $orderInfo): void
    {
        if (isset($orderInfo['member_type'])) {
            return;
        }
        // 欠款补交：不产生库存业务，支付前不做可售库存校验
        if (!empty($orderInfo['is_debt_repay'])) {
            if ((int)($orderInfo['paid'] ?? 0) === 1) {
                throw new ValidateException('订单已支付!');
            }
            return;
        }
        $orderId = (int)($orderInfo['id'] ?? 0);
        if ($orderId <= 0) {
            return;
        }
        if ((int)($orderInfo['paid'] ?? 0) === 1) {
            throw new ValidateException('订单已支付!');
        }
        $cartInfo = $this->loadOrderCartInfoForInventory($orderId);
        $this->checkOrderPayableStock($cartInfo, (int)($orderInfo['store_id'] ?? 0));
    }

    /**
     * 读取订单购物车并规范化为库存处理结构
     */
    public function loadOrderCartInfoForInventory(int $orderId): array
    {
        $rows = Db::name('store_order_cart_info')
            ->where('oid', $orderId)
            ->whereIn('cart_type', [0, 1])
            ->field('id,product_id,sku_unique,cart_num,cart_info,product_type,cart_type,type,relation_id')
            ->select()
            ->toArray();
        $cartInfo = [];
        foreach ($rows as $row) {
            $info = is_string($row['cart_info'] ?? null) ? json_decode($row['cart_info'], true) : ($row['cart_info'] ?? []);
            if (!is_array($info)) {
                $info = [];
            }
            if (empty($info['productInfo']['id'])) {
                $info['productInfo']['id'] = (int)($row['product_id'] ?? 0);
            }
            if (empty($info['productInfo']['attrInfo']['unique'])) {
                $info['productInfo']['attrInfo']['unique'] = (string)($row['sku_unique'] ?? '');
            }
            $info['cart_num'] = $row['cart_num'] ?? ($info['cart_num'] ?? 0);
            $info['product_type'] = $row['product_type'] ?? ($info['product_type'] ?? ($info['productInfo']['product_type'] ?? 0));
            $info['cart_type'] = $row['cart_type'] ?? ($info['cart_type'] ?? 0);
            $info['product_id'] = (int)($row['product_id'] ?? ($info['product_id'] ?? 0));
            $info['sku_unique'] = (string)($row['sku_unique'] ?? '');
            $info['order_cart_info_id'] = (int)($row['id'] ?? 0);
            // 台账归属（0平台/1门店/2供应商）；禁止覆盖 cart JSON 的活动 type（11/12 等）
            $info['cart_belong_type'] = (int)($row['type'] ?? 0);
            $info['relation_id'] = (int)($row['relation_id'] ?? ($info['relation_id'] ?? 0));
            $cartInfo[] = $this->enrichCartWithInventorySnapshot($info);
        }
        return $cartInfo;
    }

    /**
     * 支付前库存检查（不扣减）
     * 映射到底层门店+商品+SKU 后先汇总需求，再与现库存比较；应管库存的非法行直接报错。
     * 仅用于 assert；生成的预取结果不得跨支付边界复用为扣库存依据。
     */
    public function checkOrderPayableStock(array $cartInfo, int $storeId = 0): void
    {
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        /** @var StoreProductAttrValueServices $skuServices */
        $skuServices = app()->make(StoreProductAttrValueServices::class);

        $enriched = [];
        $needProductIds = [];
        foreach ($cartInfo as $cart) {
            $cart = $this->enrichCartWithInventorySnapshot($cart);
            $enriched[] = $cart;
            try {
                [$pid] = $this->resolveInventoryTarget($cart);
                if ($pid > 0) {
                    $needProductIds[$pid] = $pid;
                }
            } catch (\Throwable $e) {
                $pid = (int)($cart['productInfo']['id'] ?? $cart['product_id'] ?? 0);
                if ($pid > 0) {
                    $needProductIds[$pid] = $pid;
                }
            }
        }
        $productMap = $this->prefetchProductsByIds(array_values($needProductIds));

        $mapPairs = [];
        $pending = [];
        foreach ($enriched as $cart) {
            if ($this->shouldSkipInventoryCart($cart, $productMap)) {
                continue;
            }
            try {
                [$productId, $unique] = $this->resolveInventoryTarget($cart);
            } catch (\Throwable $e) {
                throw new ValidateException($e->getMessage() ?: '商品规格无法解析，禁止支付');
            }
            $num = (string)($cart['cart_num'] ?? 0);
            if ($productId <= 0 || $unique === '' || bccomp($num, '0', 4) <= 0) {
                throw new ValidateException('订单商品规格或数量异常，禁止支付');
            }
            $product = $productMap[$productId] ?? null;
            if (!$product) {
                throw new ValidateException('商品不存在');
            }
            if ((int)($product['type'] ?? 0) === 2) {
                continue;
            }
            $isInventory = (int)($product['is_inventory'] ?? (((int)($product['product_type'] ?? 0) === 0) ? 1 : 0));
            if ($isInventory !== 1) {
                continue;
            }
            if ((int)($product['allow_negative_stock'] ?? 1) === 1) {
                continue;
            }
            $mapPairs[] = ['product_id' => $productId, 'unique' => $unique];
            $pending[] = [
                'product_id' => $productId,
                'unique' => $unique,
                'num' => $num,
                'name' => (string)($product['store_name'] ?? $productId),
            ];
        }

        $storeMap = $storeId > 0
            ? $this->batchMapStoreSkus($productServices, $skuServices, $mapPairs, $storeId)
            : [];

        $checkKeys = [];
        foreach ($pending as $i => $row) {
            $checkProductId = $row['product_id'];
            $checkUnique = $row['unique'];
            if ($storeId > 0) {
                $mapped = $storeMap[$row['product_id'] . '|' . $row['unique']] ?? null;
                if (!$mapped) {
                    throw new ValidateException('门店商品规格映射失败，禁止支付');
                }
                $checkProductId = (int)$mapped['product_id'];
                $checkUnique = (string)$mapped['unique'];
                if ($checkProductId <= 0 || $checkUnique === '') {
                    throw new ValidateException('门店商品规格映射失败，禁止支付');
                }
            }
            $pending[$i]['check_product_id'] = $checkProductId;
            $pending[$i]['check_unique'] = $checkUnique;
            $checkKeys[$checkProductId . '|' . $checkUnique] = [
                'product_id' => $checkProductId,
                'unique' => $checkUnique,
            ];
        }
        $skuMap = $this->prefetchSkusByProductUnique(array_values($checkKeys));

        $demand = [];
        foreach ($pending as $row) {
            $key = $row['check_product_id'] . '|' . $row['check_unique'];
            $sku = $skuMap[$key] ?? null;
            if (!$sku) {
                throw new ValidateException('商品规格不存在');
            }
            $convert = (string)(($sku['unit_convert'] ?? '1') ?: '1');
            if (bccomp($convert, '0', 4) <= 0) {
                $convert = '1';
            }
            $skuScale = max(0, min(2, (int)($sku['decimal_scale'] ?? 0)));
            $need = bcmul($row['num'], $convert, $skuScale > 0 ? 2 : 0);
            if (!isset($demand[$key])) {
                $demand[$key] = [
                    'product_id' => $row['check_product_id'],
                    'unique' => $row['check_unique'],
                    'need' => '0',
                    'stock' => (string)$sku['stock'],
                    'scale' => (int)($sku['decimal_scale'] ?? 0),
                    'name' => $row['name'],
                ];
            }
            $demand[$key]['need'] = bcadd($demand[$key]['need'], $need, 4);
        }

        foreach ($demand as $row) {
            if (bccomp($row['stock'], $row['need'], 4) < 0) {
                throw new ValidateException(sprintf(
                    '库存不足：%s（SKU %s），需要 %s，现有 %s，缺口 %s',
                    $row['name'],
                    $row['unique'],
                    $this->trimQty($row['need'], $row['scale']),
                    $this->trimQty($row['stock'], $row['scale']),
                    $this->trimQty(bcsub($row['need'], $row['stock'], 4), $row['scale'])
                ));
            }
        }
    }

    /**
     * 支付成功库存/出库处理（幂等：inventory_handled/sales_handled）。
     * 须在外层支付事务内调用；本方法不再单独开事务写订单标记。
     * @param bool $withSales true=同事务加销量（含平台）；收银长事务可传 false，同事务末尾再调 handlePaidOrderSales
     * @param array $opts 内部：ops、return_ops（禁止 HTTP 注入）
     * @return array|null 当 return_ops=true 时返回可复用的销量 ops（不得为空壳导致误标 sales_handled）
     */
    public function handlePaidOrderInventory(array $orderInfo, array $cartInfo, bool $withSales = true, array $opts = [])
    {
        $orderId = (int)($orderInfo['id'] ?? 0);
        if ($orderId <= 0) {
            return null;
        }
        // 欠款补交：禁止库存/出库/销量副作用（支付路径应已跳过；此处为防护）
        if (!empty($orderInfo['is_debt_repay'])) {
            if ((int)($orderInfo['inventory_handled'] ?? 0) !== 1 || (int)($orderInfo['sales_handled'] ?? 0) !== 1) {
                Db::name('store_order')->where('id', $orderId)->update([
                    'inventory_handled' => 1,
                    'sales_handled' => 1,
                ]);
            }
            return !empty($opts['return_ops']) ? ['lock_targets' => [], 'lines' => []] : null;
        }
        $storeId = (int)($orderInfo['store_id'] ?? 0);
        $inventoryHandled = (int)($orderInfo['inventory_handled'] ?? 0);
        $salesHandled = (int)($orderInfo['sales_handled'] ?? 0);

        // 库存已处理：确保出库单；销量未完成时不得返回空 ops（否则后续会误标 sales_handled）
        if ($inventoryHandled === 1 && ($salesHandled === 1 || !$withSales)) {
            $this->createSaleOutOrdersForPaidOrder($orderId, false, $cartInfo);
            if ($withSales && $salesHandled !== 1) {
                $this->handlePaidOrderSales($orderInfo, $cartInfo);
            }
            if (!empty($opts['return_ops'])) {
                if ($salesHandled !== 1) {
                    return $this->buildPaidOrderInventoryOps($cartInfo, $storeId, false, true);
                }
                return null;
            }
            return null;
        }

        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);

        // 同事务销量复用：即使本段不加销量，也构建 do_sales 行与锁目标，便于一次加锁
        $buildDoSales = ($withSales && $salesHandled !== 1) || !empty($opts['return_ops']);
        $ops = (isset($opts['ops']) && is_array($opts['ops']) && !empty($opts['ops']['lines']))
            ? $opts['ops']
            : $this->buildPaidOrderInventoryOps($cartInfo, $storeId, $inventoryHandled !== 1, $buildDoSales);
        if ($ops['lock_targets']) {
            $this->lockSkuThenProducts($ops['lock_targets']);
        }
        foreach ($ops['lines'] as $line) {
            if (!empty($line['do_stock'])) {
                $this->changeSkuStock([
                    'product_id' => (int)$line['mapped_product_id'],
                    'unique' => (string)$line['mapped_unique'],
                    'delta_stock' => bcsub('0', $line['basic_qty'], 4),
                    'store_id' => 0,
                    'platform_pid' => (int)($line['platform_pid'] ?? 0),
                    'skip_store_map' => true,
                    'biz_type' => self::BIZ_SALE_PAY_SUCCESS,
                    'allow_negative_override' => true,
                    'change_sales' => false,
                    'assume_locked' => true,
                ]);
            }
            if (!empty($line['do_sales']) && $withSales) {
                $productServices->incProductSales(
                    (int)ceil((float)$line['num']),
                    (int)$line['product_id'],
                    (string)$line['unique'],
                    $storeId,
                    true,
                    $this->resolvedFromOpsLine($line, $storeId)
                );
            }
        }

        if ($inventoryHandled !== 1) {
            $this->createSaleOutOrdersForPaidOrder($orderId, false, $cartInfo);
        }

        $update = [];
        if ($inventoryHandled !== 1) {
            $update['inventory_handled'] = 1;
        }
        // 仅当本方法内已完成门店+平台销量后才标 sales_handled
        if ($withSales && $salesHandled !== 1) {
            $update['sales_handled'] = 1;
        }
        if ($update) {
            Db::name('store_order')->where('id', $orderId)->update($update);
        }
        return !empty($opts['return_ops']) ? $ops : null;
    }

    /**
     * 支付成功后加销量（幂等：sales_handled）。门店+平台销量均在同事务内完成后再写 sales_handled=1。
     * @param PaidOrderLockLease|null $lease FOR UPDATE 后颁发的租约；无效则重新加锁
     * @param array|null $ops 同事务库存段构建的只读 ops；空/无 lines 时重建
     * @param bool $skuAlreadyLocked 仅当 lease 有效且库存段已锁过同一批 lock_targets 时为 true
     */
    public function handlePaidOrderSales(
        array $orderInfo,
        array $cartInfo = [],
        ?PaidOrderLockLease $lease = null,
        ?array $ops = null,
        bool $skuAlreadyLocked = false
    ): void {
        $orderId = (int)($orderInfo['id'] ?? 0);
        if ($orderId <= 0) {
            return;
        }
        // 欠款补交：禁止加销量（支付路径应已跳过；此处为防护）
        if (!empty($orderInfo['is_debt_repay'])) {
            if ((int)($orderInfo['sales_handled'] ?? 0) !== 1) {
                Db::name('store_order')->where('id', $orderId)->where('sales_handled', 0)->update(['sales_handled' => 1]);
            }
            return;
        }
        $canSkipOrderLock = $this->canUsePaidOrderLockLease($lease, $orderId);
        if ($canSkipOrderLock) {
            $locked = $orderInfo;
            if ((int)($locked['paid'] ?? 0) !== 1) {
                return;
            }
            if ((int)($locked['sales_handled'] ?? 0) === 1) {
                return;
            }
        } else {
            $locked = Db::name('store_order')->where('id', $orderId)->lock(true)->find();
            if (!$locked || (int)($locked['paid'] ?? 0) !== 1) {
                return;
            }
            if ((int)($locked['sales_handled'] ?? 0) === 1) {
                return;
            }
            $skuAlreadyLocked = false;
        }
        $storeId = (int)($locked['store_id'] ?? 0);
        if (!$cartInfo) {
            $cartInfo = $this->loadOrderCartInfoForInventory($orderId);
        }
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        if (!is_array($ops) || empty($ops['lines'])) {
            $ops = $this->buildPaidOrderInventoryOps($cartInfo, $storeId, false, true);
            $skuAlreadyLocked = false;
        }
        if ($ops['lock_targets'] && !$skuAlreadyLocked) {
            $this->lockSkuThenProducts($ops['lock_targets']);
        }
        foreach ($ops['lines'] as $line) {
            if (!empty($line['do_sales'])) {
                $productServices->incProductSales(
                    (int)ceil((float)$line['num']),
                    (int)$line['product_id'],
                    (string)$line['unique'],
                    $storeId,
                    true,
                    $this->resolvedFromOpsLine($line, $storeId)
                );
            }
        }
        // 门店+平台销量均已在上环完成；任一步抛错则不会执行到此
        Db::name('store_order')->where('id', $orderId)->where('sales_handled', 0)->update(['sales_handled' => 1]);
    }

    /**
     * 锁租约 + 当前事务：证明订单行已由颁发方 FOR UPDATE。
     */
    protected function canUsePaidOrderLockLease(?PaidOrderLockLease $lease, int $orderId): bool
    {
        if (!$lease || !$lease->isValidFor($orderId)) {
            return false;
        }
        try {
            $pdo = Db::getPdo();
            if (!$pdo || !$pdo->inTransaction()) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }
        return true;
    }

    /**
     * @param array $line ops.lines 行
     * @return array|null resolved 映射（校验失败返回 null，由 incProductSales 自行映射）
     */
    protected function resolvedFromOpsLine(array $line, int $storeId): ?array
    {
        if (!isset($line['mapped_product_id'], $line['mapped_unique'], $line['product_id'], $line['unique'])) {
            return null;
        }
        return [
            'product_id' => (int)$line['mapped_product_id'],
            'unique' => (string)$line['mapped_unique'],
            'platform_pid' => (int)($line['platform_pid'] ?? 0),
            'source_product_id' => (int)$line['product_id'],
            'source_unique' => (string)$line['unique'],
            'store_id' => $storeId,
        ];
    }

    /**
     * 退款回退库存/销量（按退款单商品行；幂等写在退款单 inventory_refunded/sales_refunded）
     */
    public function handleRefundInventory(array $orderInfo, array $refundCartLines, int $refundId): void
    {
        if ($refundId <= 0) {
            throw new ValidateException('退款单参数错误');
        }
        $refund = Db::name('store_order_refund')->where('id', $refundId)->lock(true)->find();
        if (!$refund) {
            throw new ValidateException('退款单不存在');
        }
        $inventoryRefunded = (int)($refund['inventory_refunded'] ?? 0);
        $salesRefunded = (int)($refund['sales_refunded'] ?? 0);
        if ($inventoryRefunded === 1 && $salesRefunded === 1) {
            return;
        }

        $paid = (int)($orderInfo['paid'] ?? 0);
        $orderInventoryHandled = (int)($orderInfo['inventory_handled'] ?? 0);
        $orderSalesHandled = (int)($orderInfo['sales_handled'] ?? 0);
        $restorePhysical = ($paid === 1 && $orderInventoryHandled === 1 && $inventoryRefunded !== 1);
        $restoreSales = ($paid === 1 && $orderSalesHandled === 1 && $salesRefunded !== 1);
        if (!$restorePhysical && !$restoreSales) {
            return;
        }

        $storeId = (int)($orderInfo['store_id'] ?? 0);
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        $inboundDetail = [];

        foreach ($refundCartLines as $cart) {
            $cart = $this->enrichCartWithInventorySnapshot(is_array($cart) ? $cart : []);
            $skipInv = $this->shouldSkipInventoryCart($cart);
            try {
                [$productId, $unique] = $this->resolveInventoryTarget($cart);
            } catch (\Throwable $e) {
                if ($restorePhysical && !$skipInv) {
                    throw new ValidateException($e->getMessage() ?: '退款商品规格无法解析');
                }
                continue;
            }
            $num = (string)($cart['cart_num'] ?? 0);
            if ($productId <= 0 || bccomp($num, '0', 4) <= 0 || $unique === '') {
                if ($restorePhysical && !$skipInv) {
                    throw new ValidateException('退款商品规格或数量异常');
                }
                continue;
            }
            $basicQty = $this->calcBasicQty($productId, $unique, $num);

            if ($restorePhysical && !$skipInv) {
                $this->changeSkuStock([
                    'product_id' => $productId,
                    'unique' => $unique,
                    'delta_stock' => $basicQty,
                    'store_id' => $storeId,
                    'biz_type' => self::BIZ_SALE_REFUND,
                    'change_sales' => false,
                ]);
                $inboundDetail[] = [
                    'product_id' => $productId,
                    'unique' => $unique,
                    'stock' => $basicQty, // 台账按基本单位
                    'sale_qty' => $num,   // 销售单位快照（明细表无此列时仅随数组传入，saveData 会忽略未知键）
                ];
            }
            if ($restoreSales) {
                $productServices->decProductSales((int)ceil((float)$num), $productId, $unique, $storeId);
            }
        }

        if ($restorePhysical && $inboundDetail) {
            $this->createRefundInOrderForDetails((int)$orderInfo['id'], $refundId, $inboundDetail, false);
        }

        $update = [];
        if ($restorePhysical) {
            $update['inventory_refunded'] = 1;
        }
        if ($restoreSales) {
            $update['sales_refunded'] = 1;
        }
        if ($update) {
            Db::name('store_order_refund')->where('id', $refundId)->update($update);
        }
    }

    /**
     * 整单作废回库存（不写退款申请表；幂等键=operation_no）
     * stockInType: 0=默认良品；1=良品入库；2=残次品入库（已发货场景）
     */
    public function handleVoidInventory(array $orderInfo, array $cartLines, string $operationNo, int $stockInType = 0): void
    {
        $operationNo = trim($operationNo);
        if ($operationNo === '') {
            throw new ValidateException('作废操作号缺失，无法回库存');
        }
        $orderId = (int)($orderInfo['id'] ?? 0);
        if ($orderId <= 0) {
            return;
        }
        $remarkKey = '订单作废入库:' . $operationNo;
        $exists = (int)Db::name('store_product_stock_order')
            ->where('store_order_id', $orderId)
            ->where('remark', $remarkKey)
            ->where('stock_type', 1)
            ->value('id');
        if ($exists > 0) {
            return;
        }

        $paid = (int)($orderInfo['paid'] ?? 0);
        $orderInventoryHandled = (int)($orderInfo['inventory_handled'] ?? 0);
        $orderSalesHandled = (int)($orderInfo['sales_handled'] ?? 0);
        $restorePhysical = ($paid === 1 && $orderInventoryHandled === 1);
        $restoreSales = ($paid === 1 && $orderSalesHandled === 1);
        if (!$restorePhysical && !$restoreSales) {
            return;
        }

        $storeId = (int)($orderInfo['store_id'] ?? 0);
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        $inboundDetail = [];
        $useDefective = ($stockInType === 2);

        foreach ($cartLines as $cart) {
            $cart = $this->enrichCartWithInventorySnapshot(is_array($cart) ? $cart : []);
            $skipInv = $this->shouldSkipInventoryCart($cart);
            try {
                [$productId, $unique] = $this->resolveInventoryTarget($cart);
            } catch (\Throwable $e) {
                if ($restorePhysical && !$skipInv) {
                    throw new ValidateException($e->getMessage() ?: '作废商品规格无法解析');
                }
                continue;
            }
            $num = (string)($cart['cart_num'] ?? 0);
            if ($productId <= 0 || bccomp($num, '0', 4) <= 0 || $unique === '') {
                if ($restorePhysical && !$skipInv) {
                    throw new ValidateException('作废商品规格或数量异常');
                }
                continue;
            }
            $basicQty = $this->calcBasicQty($productId, $unique, $num);

            if ($restorePhysical && !$skipInv) {
                $this->changeSkuStock([
                    'product_id' => $productId,
                    'unique' => $unique,
                    'delta_stock' => $useDefective ? '0' : $basicQty,
                    'delta_defective' => $useDefective ? $basicQty : '0',
                    'store_id' => $storeId,
                    'biz_type' => self::BIZ_SALE_VOID,
                    'change_sales' => false,
                ]);
                $inboundDetail[] = [
                    'product_id' => $productId,
                    'unique' => $unique,
                    'stock' => $basicQty,
                    'sale_qty' => $num,
                ];
            }
            if ($restoreSales) {
                $productServices->decProductSales((int)ceil((float)$num), $productId, $unique, $storeId);
            }
        }

        if ($restorePhysical && $inboundDetail) {
            /** @var StoreProductStockOrderServices $stockOrderServices */
            $stockOrderServices = app()->make(StoreProductStockOrderServices::class);
            $type = 0;
            $relationId = 0;
            if ($storeId > 0) {
                $type = 1;
                $relationId = $storeId;
            }
            $stockOrderServices->saveData(1, [
                'refund_order_id' => 0,
                'store_order_id' => $orderId,
                'order_type' => 3,
                'stock_time' => date('Y-m-d'),
                'remark' => $remarkKey,
                'in_product_detail' => $inboundDetail,
            ], $type, $relationId, (int)($orderInfo['uid'] ?? 0), false);
        }
    }

    /**
     * 已发货退货入库：必须按退款单 ID 幂等处理（禁止按销售订单整单加库存）
     * 锁退款单 → 回库存 → 写台账 → 标 inventory_refunded，整段同一事务（同步主路径与 Job 共用）
     * @param int $refundId 退款单ID
     * @param bool $isGood true 良品入库；false 残次品入库
     * @param bool $isStock true 同步回库存（库存已由销售支付扣过时）；false 禁止用于「标已退库存」路径
     */
    public function handleShippedRefundInbound(int $refundId, bool $isGood = true, bool $isStock = true): void
    {
        if ($refundId <= 0) {
            throw new ValidateException('退款单参数错误');
        }
        // 禁止：不加库存却把 inventory_refunded 标完成（Job 危险默认值防护）
        if (!$isStock) {
            throw new ValidateException('已发货退货入库必须同步改库存，禁止空标幂等标志');
        }

        Db::transaction(function () use ($refundId, $isGood, $isStock) {
            $refund = Db::name('store_order_refund')->where('id', $refundId)->lock(true)->find();
            if (!$refund) {
                throw new ValidateException('退款单不存在');
            }
            if ((int)($refund['inventory_refunded'] ?? 0) === 1) {
                return;
            }
            $orderId = (int)($refund['store_order_id'] ?? 0);
            $order = Db::name('store_order')->where('id', $orderId)->find();
            if (!$order) {
                throw new ValidateException('原订单不存在');
            }
            $orderInventoryHandled = (int)($order['inventory_handled'] ?? 0);
            $storeId = (int)($order['store_id'] ?? 0);

            $raw = $refund['cart_info'] ?? [];
            if (is_string($raw)) {
                $raw = json_decode($raw, true) ?: [];
            }
            $lines = [];
            foreach ((array)$raw as $line) {
                if (isset($line['cart_info']) && is_array($line['cart_info'])) {
                    $line = $line['cart_info'];
                }
                $lines[] = is_array($line) ? $line : [];
            }
            if (!$lines) {
                throw new ValidateException('退款单无商品明细，禁止入库');
            }

            $inboundDetail = [];
            foreach ($lines as $cart) {
                $cart = $this->enrichCartWithInventorySnapshot($cart);
                $skipInv = $this->shouldSkipInventoryCart($cart);
                try {
                    [$productId, $unique] = $this->resolveInventoryTarget($cart);
                } catch (\Throwable $e) {
                    if (!$skipInv) {
                        throw new ValidateException($e->getMessage() ?: '退款商品规格无法解析，禁止入库');
                    }
                    continue;
                }
                $num = (string)($cart['cart_num'] ?? 0);
                if ($productId <= 0 || $unique === '' || bccomp($num, '0', 4) <= 0) {
                    if (!$skipInv) {
                        throw new ValidateException('退款商品规格或数量异常，禁止入库');
                    }
                    continue;
                }
                if ($skipInv) {
                    continue;
                }
                $basicQty = $this->calcBasicQty($productId, $unique, $num);

                // 仅当原单已扣过实物库存时才回加并写台账；未扣过则无需入库
                if (!($isStock && $orderInventoryHandled === 1)) {
                    continue;
                }
                $params = [
                    'product_id' => $productId,
                    'unique' => $unique,
                    'store_id' => $storeId,
                    'biz_type' => self::BIZ_SALE_REFUND,
                    'change_sales' => false,
                ];
                if ($isGood) {
                    $params['delta_stock'] = $basicQty;
                } else {
                    $params['delta_defective'] = $basicQty;
                }
                $this->changeSkuStock($params);

                $inboundDetail[] = [
                    'product_id' => $productId,
                    'unique' => $unique,
                    'stock' => $isGood ? $basicQty : '0',
                    'defective_stock' => $isGood ? '0' : $basicQty,
                    'sale_qty' => $num,
                ];
            }

            if ($inboundDetail) {
                // 库存已由 changeSkuStock 处理，台账 isStock=false，避免二次改库
                $this->createRefundInOrderForDetails($orderId, $refundId, $inboundDetail, false);
            }
            Db::name('store_order_refund')->where('id', $refundId)->update(['inventory_refunded' => 1]);
        });
    }

    /**
     * 同步写销售出库单（不改库存）；同一订单重复调用时跳过已有单据
     * 明细数量统一为基本单位（cart_num × unit_convert）
     * @param array|null $cartInfo 可选：已加载购物车（须含 type/relation_id）；null 时按原逻辑查库，保持历史调用兼容
     */
    public function createSaleOutOrdersForPaidOrder(int $orderId, bool $isStock = false, ?array $cartInfo = null): void
    {
        if ($orderId <= 0) {
            return;
        }
        $exists = Db::name('store_product_stock_order')
            ->where('store_order_id', $orderId)
            ->where('stock_type', 2)
            ->where('order_type', 1)
            ->count();
        if ($exists > 0) {
            return;
        }
        $order = Db::name('store_order')->where('id', $orderId)->find();
        if (!$order) {
            return;
        }
        // 欠款补交：禁止生成销售出库
        if (!empty($order['is_debt_repay'])) {
            return;
        }
        if ($cartInfo === null) {
            $cartInfo = Db::name('store_order_cart_info')
                ->where('oid', $orderId)
                ->field('id,type,relation_id,product_id,sku_unique,cart_num,cart_info')
                ->select()
                ->toArray();
        }
        if (!$cartInfo) {
            return;
        }
        $stockAdminData = $stockStoreData = [];
        // $stockSupplierData = []; // 【供应商库存已停用】不再写供应商销售出库台账
        foreach ($cartInfo as $cart) {
            // DB 行用 type；预加载购物车用 cart_belong_type（避免与活动 type 冲突）
            $rawType = (int)($cart['cart_belong_type'] ?? $cart['type'] ?? 0);
            $rawRelationId = (int)($cart['relation_id'] ?? 0);
            if (is_string($cart['cart_info'] ?? null)) {
                $info = json_decode($cart['cart_info'], true);
                if (!is_array($info)) {
                    $info = [];
                }
                $info['product_id'] = (int)($cart['product_id'] ?? 0);
                $info['sku_unique'] = (string)($cart['sku_unique'] ?? '');
                $info['cart_num'] = $cart['cart_num'] ?? 0;
            } else {
                // loadOrderCartInfoForInventory 已 enrich 的结构
                $info = is_array($cart) ? $cart : [];
            }
            $info = $this->enrichCartWithInventorySnapshot($info);
            if ($this->shouldSkipInventoryCart($info)) {
                continue;
            }
            // 【供应商库存已停用】cart.type=2 跳过出库台账
            if ($rawType === 2) {
                continue;
            }
            try {
                [$productId, $unique] = $this->resolveInventoryTarget($info);
            } catch (\Throwable $e) {
                throw new ValidateException($e->getMessage() ?: '销售出库无法解析商品规格');
            }
            $num = (string)($info['cart_num'] ?? $cart['cart_num'] ?? 0);
            if ($productId <= 0 || $unique === '' || bccomp($num, '0', 4) <= 0) {
                throw new ValidateException('销售出库商品规格或数量异常');
            }
            // 基本单位换算必须用原始购物车商品/SKU，不得用门店映射 SKU
            $basicQty = $this->calcBasicQty($productId, $unique, $num);
            $attrInfo = [
                'product_id' => $productId,
                'unique' => $unique,
                'stock' => $basicQty,
                'sale_qty' => $num,
            ];
            switch ($rawType) {
                case 0:
                    $stockAdminData[] = $attrInfo;
                    break;
                case 1:
                    $stockStoreData[$rawRelationId][] = $attrInfo;
                    break;
                // case 2: // 【供应商】原写出库台账逻辑已停用
                //     $stockSupplierData[(int)$cart['relation_id']][] = $attrInfo;
                //     break;
            }
        }
        /** @var StoreProductStockOrderServices $stockOrderServices */
        $stockOrderServices = app()->make(StoreProductStockOrderServices::class);
        $uid = (int)($order['uid'] ?? 0);
        $stockTime = date('Y-m-d', (int)($order['add_time'] ?? time()));
        if ($stockAdminData) {
            $stockOrderServices->saveData(2, [
                'store_order_id' => $orderId,
                'order_type' => 1,
                'stock_time' => $stockTime,
                'remark' => '',
                'out_product_detail' => $stockAdminData,
            ], 0, 0, $uid, $isStock);
        }
        foreach ($stockStoreData as $sid => $data) {
            $stockOrderServices->saveData(2, [
                'store_order_id' => $orderId,
                'order_type' => 1,
                'stock_time' => $stockTime,
                'remark' => '',
                'out_product_detail' => $data,
            ], 1, (int)$sid, $uid, $isStock);
        }
        // 【供应商库存已停用】
        // foreach ($stockSupplierData as $supplierId => $data) {
        //     $stockOrderServices->saveData(2, [...], 2, (int)$supplierId, $uid, false);
        // }
    }

    /**
     * 退货入库台账（默认不改库存，库存已由统一服务回退）
     */
    public function createRefundInOrderForDetails(int $orderId, int $refundId, array $detail, bool $isStock = false): void
    {
        if ($orderId <= 0 || !$detail) {
            return;
        }
        $order = Db::name('store_order')->where('id', $orderId)->find();
        if (!$order) {
            return;
        }
        /** @var StoreProductStockOrderServices $stockOrderServices */
        $stockOrderServices = app()->make(StoreProductStockOrderServices::class);
        $type = 0;
        $relationId = 0;
        if ((int)($order['store_id'] ?? 0) > 0) {
            $type = 1;
            $relationId = (int)$order['store_id'];
        }
        $stockOrderServices->saveData(1, [
            'refund_order_id' => $refundId,
            'store_order_id' => $orderId,
            'order_type' => 3,
            'stock_time' => date('Y-m-d'),
            'remark' => '',
            'in_product_detail' => $detail,
        ], $type, $relationId, (int)($order['uid'] ?? 0), $isStock);
    }

    /**
     * 解析应扣/应退的底层实物商品与 SKU
     * @return array{0:int,1:string}
     */
    public function resolveInventoryTarget(array $cart): array
    {
        if (!empty($cart['inventory_product_id']) && (string)($cart['inventory_unique'] ?? '') !== '') {
            return [(int)$cart['inventory_product_id'], (string)$cart['inventory_unique']];
        }
        $info = $cart['productInfo'] ?? [];
        if (!is_array($info)) {
            $info = [];
        }
        $attr = $info['attrInfo'] ?? [];
        if (!is_array($attr)) {
            $attr = [];
        }
        $displayId = (int)($info['id'] ?? $cart['product_id'] ?? 0);
        $baseProductId = (int)($info['product_id'] ?? 0);
        $unique = (string)($attr['unique'] ?? $cart['product_attr_unique'] ?? $cart['sku_unique'] ?? '');
        $suk = (string)($attr['suk'] ?? '');

        // 活动商品：productInfo.product_id 为底层商品，id 为活动商品
        if ($baseProductId > 0 && $baseProductId !== $displayId) {
            $baseUnique = '';
            if ($suk !== '') {
                $baseUnique = (string)Db::name('store_product_attr_value')->where([
                    'product_id' => $baseProductId,
                    'suk' => $suk,
                    'type' => 0,
                ])->value('unique');
            }
            if ($baseUnique === '' && $unique !== '') {
                $activityType = (int)($attr['type'] ?? 0);
                if ($activityType > 0) {
                    $suk = (string)Db::name('store_product_attr_value')->where([
                        'product_id' => $displayId,
                        'unique' => $unique,
                        'type' => $activityType,
                    ])->value('suk');
                    if ($suk !== '') {
                        $baseUnique = (string)Db::name('store_product_attr_value')->where([
                            'product_id' => $baseProductId,
                            'suk' => $suk,
                            'type' => 0,
                        ])->value('unique');
                    }
                }
            }
            if ($baseUnique === '') {
                throw new ValidateException('活动商品无法映射到底层规格，禁止继续扣库存');
            }
            return [$baseProductId, $baseUnique];
        }
        return [$displayId, $unique];
    }

    /**
     * 写入库存快照字段，便于支付/退款统一使用
     */
    public function enrichCartWithInventorySnapshot(array $cart): array
    {
        try {
            [$pid, $unique] = $this->resolveInventoryTarget($cart);
            $cart['inventory_product_id'] = $pid;
            $cart['inventory_unique'] = $unique;
        } catch (\Throwable $e) {
            // 解析失败时保留原结构，调用方再决定是否抛错
        }
        return $cart;
    }

    /**
     * @param array|null $productMap id => product row（批量预取时传入，避免 N+1）
     */
    protected function shouldSkipInventoryCart(array $cart, ?array $productMap = null): bool
    {
        $productType = (int)($cart['product_type'] ?? ($cart['productInfo']['product_type'] ?? 0));
        // cart JSON 的 type 为活动类型（1秒杀/2砍价…）；11/12 为卡项核销类
        $activityType = (int)($cart['type'] ?? 0);
        if (in_array($productType, [4, 5, 6], true) || in_array($activityType, [11, 12], true)) {
            return true;
        }
        // cart_type: 0普通 1赠品 2卡项权益等
        // 实物赠品（cart_type=1）仍须参与库存；仅跳过卡项/项目内部权益行（cart_type>=2）
        $cartType = (int)($cart['cart_type'] ?? 0);
        if ($cartType >= 2) {
            return true;
        }
        // 【供应商库存已停用】商品归属 type=2 跳过新库存（看商品归属，不是活动 type）
        if ((int)($cart['productInfo']['type'] ?? -1) === 2) {
            return true;
        }
        try {
            [$productId] = $this->resolveInventoryTarget($cart);
        } catch (\Throwable $e) {
            $productId = (int)($cart['productInfo']['id'] ?? $cart['product_id'] ?? 0);
        }
        if ($productId > 0) {
            $row = $productMap[$productId] ?? null;
            if ($row === null && $productMap === null) {
                $row = Db::name('store_product')->where('id', $productId)->field('is_inventory,type,product_type')->find();
            }
            if ($row) {
                if ((int)($row['type'] ?? 0) === 2) {
                    return true;
                }
                if ((int)($row['is_inventory'] ?? 0) === 0) {
                    return true;
                }
            } elseif ($productType !== 0) {
                return true;
            }
        }
        return false;
    }

    protected function mapStoreSku(StoreProductServices $productServices, StoreProductAttrValueServices $skuServices, int $productId, string $unique, int $storeId): array
    {
        /** @var \app\services\product\branch\StoreBranchProductServices $branchProductServices */
        $branchProductServices = app()->make(\app\services\product\branch\StoreBranchProductServices::class);
        $info = $branchProductServices->isValidStoreProduct($productId, $storeId);
        $storeProductId = (int)($info['id'] ?? 0);
        $platformPid = 0;
        if ($productId && $storeProductId && $storeProductId != $productId) {
            $suk = $skuServices->value(['unique' => $unique, 'product_id' => $productId, 'type' => 0], 'suk');
            $productId = $storeProductId;
            $unique = (string)$skuServices->value(['suk' => $suk, 'product_id' => $productId, 'type' => 0], 'unique');
            $platformPid = (int)($info['pid'] ?? 0);
        }
        return ['product_id' => $productId, 'unique' => $unique, 'platform_pid' => $platformPid];
    }

    /**
     * 全局锁序：先批量无锁查 SKU id 并校验全集，再按 SKU id 升序逐条 FOR UPDATE，再按商品 id 升序逐条 FOR UPDATE。
     * 禁止 whereIn(...)->lock(true)。平台 platform_pid 不参与 FOR UPDATE。
     * @param array<int, array{product_id:int,unique:string,platform_pid?:int}> $targets
     */
    public function lockSkuThenProducts(array $targets): void
    {
        if (!$targets) {
            return;
        }
        $productIds = [];
        $needSku = [];
        foreach ($targets as $t) {
            $pid = (int)($t['product_id'] ?? 0);
            $unique = (string)($t['unique'] ?? '');
            $requireSku = !empty($t['require_sku']);
            if ($pid > 0) {
                $productIds[] = $pid;
            }
            if ($pid > 0 && $unique !== '') {
                $key = $pid . '|' . $unique;
                if (!isset($needSku[$key])) {
                    $needSku[$key] = [
                        'product_id' => $pid,
                        'unique' => $unique,
                        'require_sku' => $requireSku,
                    ];
                } elseif ($requireSku) {
                    $needSku[$key]['require_sku'] = true;
                }
            } elseif ($requireSku) {
                throw new ValidateException('商品规格不存在，禁止漏锁');
            }
        }
        $skuIds = [];
        if ($needSku) {
            $skuMap = $this->prefetchSkusByProductUnique(array_values($needSku), ['id', 'product_id', 'unique']);
            foreach ($needSku as $key => $pair) {
                $row = $skuMap[$key] ?? null;
                if (!$row || (int)($row['id'] ?? 0) <= 0) {
                    // 应管库存路径 require_sku=true 必须找到；销量-only/项目行保持原「无 SKU 则只锁商品」
                    if (!empty($pair['require_sku'])) {
                        throw new ValidateException('商品规格不存在，禁止漏锁');
                    }
                    continue;
                }
                $skuIds[] = (int)$row['id'];
            }
        }
        $skuIds = array_values(array_unique($skuIds));
        sort($skuIds, SORT_NUMERIC);
        foreach ($skuIds as $sid) {
            Db::name('store_product_attr_value')->where('id', $sid)->lock(true)->field('id')->find();
        }
        $productIds = array_values(array_unique(array_filter($productIds)));
        sort($productIds, SORT_NUMERIC);
        foreach ($productIds as $pid) {
            Db::name('store_product')->where('id', $pid)->lock(true)->field('id')->find();
        }
    }

    /**
     * 汇总支付单库存/销量操作，供批量加锁后执行（多 SKU 必须先汇总排序再锁）。
     * basic_qty 始终按原始购物车 product_id+unique 换算；门店映射仅用于扣库/加锁目标。
     * @return array{lines:array,lock_targets:array}
     */
    protected function buildPaidOrderInventoryOps(array $cartInfo, int $storeId, bool $doStock, bool $doSales): array
    {
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        /** @var StoreProductAttrValueServices $skuServices */
        $skuServices = app()->make(StoreProductAttrValueServices::class);

        $enriched = [];
        $needProductIds = [];
        foreach ($cartInfo as $cart) {
            $cart = $this->enrichCartWithInventorySnapshot($cart);
            $enriched[] = $cart;
            try {
                [$pid] = $this->resolveInventoryTarget($cart);
                if ($pid > 0) {
                    $needProductIds[$pid] = $pid;
                }
            } catch (\Throwable $e) {
                $pid = (int)($cart['productInfo']['id'] ?? $cart['product_id'] ?? 0);
                if ($pid > 0) {
                    $needProductIds[$pid] = $pid;
                }
            }
        }
        $productMap = $this->prefetchProductsByIds(array_values($needProductIds));

        $mapPairs = [];
        $prepared = [];
        foreach ($enriched as $cart) {
            $skipInv = $this->shouldSkipInventoryCart($cart, $productMap);
            try {
                [$productId, $unique] = $this->resolveInventoryTarget($cart);
            } catch (\Throwable $e) {
                if (!$skipInv) {
                    throw new ValidateException($e->getMessage() ?: '商品规格无法解析');
                }
                continue;
            }
            $num = (string)($cart['cart_num'] ?? 0);
            if ($productId <= 0 || bccomp($num, '0', 4) <= 0 || $unique === '') {
                if (!$skipInv) {
                    throw new ValidateException('订单商品规格或数量异常');
                }
                continue;
            }
            $mapPairs[] = ['product_id' => $productId, 'unique' => $unique];
            $prepared[] = [
                'product_id' => $productId,
                'unique' => $unique,
                'num' => $num,
                'skip_inv' => $skipInv,
            ];
        }

        $storeMap = $storeId > 0
            ? $this->batchMapStoreSkus($productServices, $skuServices, $mapPairs, $storeId)
            : [];
        $origSkuPairs = [];
        foreach ($prepared as $row) {
            $origSkuPairs[$row['product_id'] . '|' . $row['unique']] = [
                'product_id' => $row['product_id'],
                'unique' => $row['unique'],
            ];
        }
        $origSkuMap = $this->prefetchSkusByProductUnique(array_values($origSkuPairs), ['id', 'product_id', 'unique', 'unit_convert', 'decimal_scale']);

        $lines = [];
        $lockTargets = [];
        foreach ($prepared as $row) {
            $productId = $row['product_id'];
            $unique = $row['unique'];
            $mappedPid = $productId;
            $mappedUnique = $unique;
            $platformPid = 0;
            if ($storeId > 0) {
                $mapped = $storeMap[$productId . '|' . $unique] ?? null;
                if (!$mapped) {
                    if (!$row['skip_inv']) {
                        throw new ValidateException('门店商品规格映射失败');
                    }
                } else {
                    $mappedPid = (int)$mapped['product_id'];
                    $mappedUnique = (string)$mapped['unique'];
                    $platformPid = (int)$mapped['platform_pid'];
                    if (!$row['skip_inv'] && ($mappedPid <= 0 || $mappedUnique === '')) {
                        throw new ValidateException('门店商品规格映射失败');
                    }
                }
            }
            $skuRow = $origSkuMap[$productId . '|' . $unique] ?? null;
            $willStock = $doStock && !$row['skip_inv'];
            if ($willStock && !$skuRow) {
                throw new ValidateException('商品规格不存在');
            }
            $basicQty = $this->calcBasicQtyFromSkuRow($skuRow, $row['num']);
            $lines[] = [
                'product_id' => $productId,
                'unique' => $unique,
                'mapped_product_id' => $mappedPid,
                'mapped_unique' => $mappedUnique,
                'platform_pid' => $platformPid,
                'num' => $row['num'],
                'basic_qty' => $basicQty,
                'do_stock' => $willStock,
                'do_sales' => $doSales,
            ];
            if ($willStock || $doSales) {
                $lockTargets[] = [
                    'product_id' => $mappedPid,
                    'unique' => $mappedUnique,
                    'platform_pid' => $platformPid,
                    // 仅应管库存扣减要求 SKU 全集；销量-only 允许无 type=0 SKU（项目等）
                    'require_sku' => $willStock,
                ];
            }
        }
        return ['lines' => $lines, 'lock_targets' => $lockTargets];
    }

    /**
     * @param list<int> $ids
     * @return array<int, array>
     */
    protected function prefetchProductsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $rows = Db::name('store_product')
            ->whereIn('id', $ids)
            ->field('id,pid,type,is_inventory,allow_negative_stock,product_type,store_name')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['id']] = $row;
        }
        return $map;
    }

    /**
     * @param list<array{product_id:int,unique:string}> $pairs
     * @param list<string> $fields
     * @return array<string, array> key = product_id|unique
     */
    protected function prefetchSkusByProductUnique(array $pairs, array $fields = ['id', 'product_id', 'unique', 'stock', 'unit_convert', 'decimal_scale']): array
    {
        if (!$pairs) {
            return [];
        }
        $productIds = [];
        $want = [];
        foreach ($pairs as $p) {
            $pid = (int)($p['product_id'] ?? 0);
            $unique = (string)($p['unique'] ?? '');
            if ($pid <= 0 || $unique === '') {
                continue;
            }
            $productIds[$pid] = $pid;
            $want[$pid . '|' . $unique] = true;
        }
        if (!$productIds) {
            return [];
        }
        $fieldSql = implode(',', array_unique(array_merge($fields, ['product_id', 'unique'])));
        $rows = Db::name('store_product_attr_value')
            ->whereIn('product_id', array_values($productIds))
            ->where('type', 0)
            ->field($fieldSql)
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $key = (int)$row['product_id'] . '|' . (string)$row['unique'];
            if (!isset($want[$key])) {
                continue;
            }
            $map[$key] = $row;
        }
        return $map;
    }

    /**
     * 批量门店 SKU 映射，语义对齐逐条 mapStoreSku（含「已是门店商品则原样返回」）。
     * 门店归属与 isValidStoreProduct 一致；suk→unique 批量查询；映射失败不回退扣总部（由调用方抛错）。
     * @param list<array{product_id:int,unique:string}> $pairs
     * @return array<string, array{product_id:int,unique:string,platform_pid:int}>
     */
    protected function batchMapStoreSkus(
        StoreProductServices $productServices,
        StoreProductAttrValueServices $skuServices,
        array $pairs,
        int $storeId
    ): array {
        unset($productServices); // 签名保留与调用方一致；归属查询直接走表以批量
        $result = [];
        $uniq = [];
        foreach ($pairs as $p) {
            $pid = (int)$p['product_id'];
            $unique = (string)$p['unique'];
            $uniq[$pid . '|' . $unique] = ['product_id' => $pid, 'unique' => $unique];
        }
        if ($storeId <= 0 || !$uniq) {
            foreach ($uniq as $key => $p) {
                $result[$key] = [
                    'product_id' => $p['product_id'],
                    'unique' => $p['unique'],
                    'platform_pid' => 0,
                ];
            }
            return $result;
        }

        $srcProductIds = [];
        foreach ($uniq as $p) {
            $srcProductIds[$p['product_id']] = $p['product_id'];
        }
        $srcProductIds = array_values($srcProductIds);
        if (!$srcProductIds) {
            return $result;
        }
        // 对齐 isValidStoreProduct：先 id 命中门店商品，再 pid 命中（分两次查，避免 whereOr 方言差异）
        $baseStoreQuery = function () use ($storeId) {
            return Db::name('store_product')
                ->where('type', 1)
                ->where('relation_id', $storeId)
                ->where('is_del', 0)
                ->where('is_show', 1)
                ->field('id,pid');
        };
        $storeRows = array_merge(
            $baseStoreQuery()->whereIn('id', $srcProductIds)->select()->toArray(),
            $baseStoreQuery()->whereIn('pid', $srcProductIds)->select()->toArray()
        );
        $byId = [];
        $byPid = [];
        foreach ($storeRows as $row) {
            $id = (int)$row['id'];
            $pid = (int)($row['pid'] ?? 0);
            if (!isset($byId[$id])) {
                $byId[$id] = $row;
            }
            if ($pid > 0 && !isset($byPid[$pid])) {
                $byPid[$pid] = $row;
            }
        }

        $needSuk = []; // key => [srcPid, unique, storePid]
        foreach ($uniq as $key => $p) {
            $productId = $p['product_id'];
            $unique = $p['unique'];
            $info = $byId[$productId] ?? $byPid[$productId] ?? null;
            $storeProductId = (int)($info['id'] ?? 0);
            // platform_pid：与 incProductSales 一致，门店商品命中时取 info.pid（销量用）
            $platformPid = $info ? (int)($info['pid'] ?? 0) : 0;
            if ($productId && $storeProductId && $storeProductId != $productId) {
                $needSuk[$key] = [
                    'product_id' => $productId,
                    'unique' => $unique,
                    'store_product_id' => $storeProductId,
                    'platform_pid' => $platformPid,
                ];
            } else {
                $result[$key] = [
                    'product_id' => $productId,
                    'unique' => $unique,
                    'platform_pid' => $platformPid,
                ];
            }
        }

        if ($needSuk) {
            $srcPairs = [];
            foreach ($needSuk as $row) {
                $srcPairs[] = ['product_id' => $row['product_id'], 'unique' => $row['unique']];
            }
            $srcSkuMap = $this->prefetchSkusByProductUnique($srcPairs, ['id', 'product_id', 'unique', 'suk']);
            $storePids = [];
            $suks = [];
            foreach ($needSuk as $key => $row) {
                $srcSku = $srcSkuMap[$row['product_id'] . '|' . $row['unique']] ?? null;
                $suk = (string)($srcSku['suk'] ?? '');
                $needSuk[$key]['suk'] = $suk;
                if ($suk !== '') {
                    $storePids[$row['store_product_id']] = $row['store_product_id'];
                    $suks[$suk] = $suk;
                }
            }
            $storeUniqueByPidSuk = [];
            if ($storePids && $suks) {
                $storeSkuRows = Db::name('store_product_attr_value')
                    ->whereIn('product_id', array_values($storePids))
                    ->whereIn('suk', array_values($suks))
                    ->where('type', 0)
                    ->field('product_id,suk,unique')
                    ->select()
                    ->toArray();
                foreach ($storeSkuRows as $r) {
                    $storeUniqueByPidSuk[(int)$r['product_id'] . '|' . (string)$r['suk']] = (string)$r['unique'];
                }
            }
            foreach ($needSuk as $key => $row) {
                $storeUnique = '';
                if ($row['suk'] !== '') {
                    $storeUnique = (string)($storeUniqueByPidSuk[$row['store_product_id'] . '|' . $row['suk']] ?? '');
                }
                // 与 mapStoreSku 一致：查不到则为空串，由上层应管库存路径抛错
                $result[$key] = [
                    'product_id' => (int)$row['store_product_id'],
                    'unique' => $storeUnique,
                    'platform_pid' => (int)$row['platform_pid'],
                ];
            }
        }
        unset($skuServices);
        return $result;
    }

    protected function applySalesOnly(StoreProductServices $productServices, StoreProductAttrValueServices $skuServices, int $productId, string $unique, string $salesNum, int $platformPid): void
    {
        $n = abs((float)$salesNum);
        if ($n <= 0) {
            return;
        }
        $this->lockSkuThenProducts([[
            'product_id' => $productId,
            'unique' => $unique,
            'platform_pid' => $platformPid,
        ]]);
        if ($unique !== '' && $unique !== '0') {
            Db::name('store_product_attr_value')->where([
                'product_id' => $productId,
                'unique' => $unique,
                'type' => 0,
            ])->inc('sales', $n)->update();
        }
        // 门店行可能已在外层 FOR UPDATE；平台行仅原子 INC
        $productIds = array_values(array_unique(array_filter([(int)$productId, (int)$platformPid])));
        sort($productIds, SORT_NUMERIC);
        foreach ($productIds as $pid) {
            Db::name('store_product')->where('id', $pid)->inc('sales', $n)->update();
        }
        // applySalesOnly 仅在已持锁路径调用；跳过全量缓存清扫
    }

    /**
     * 销售数量 × 单位换算 → 基本单位数量（原始购物车商品 SKU，非门店映射 SKU）
     */
    protected function calcBasicQty(int $productId, string $unique, string $saleNum): string
    {
        $skuRow = null;
        if ($productId > 0 && $unique !== '') {
            $skuRow = Db::name('store_product_attr_value')->where([
                'product_id' => $productId,
                'unique' => $unique,
                'type' => 0,
            ])->field('unit_convert,decimal_scale')->find();
        }
        return $this->calcBasicQtyFromSkuRow($skuRow ?: null, $saleNum);
    }

    /**
     * @param array|null $skuRow 原始 SKU 行（unit_convert/decimal_scale）
     */
    protected function calcBasicQtyFromSkuRow(?array $skuRow, string $saleNum): string
    {
        $convert = '1';
        $skuScale = 0;
        if ($skuRow) {
            $unitConvert = $skuRow['unit_convert'] ?? null;
            if ($unitConvert !== null && $unitConvert !== '' && bccomp((string)$unitConvert, '0', 4) > 0) {
                $convert = (string)$unitConvert;
            }
            $skuScale = max(0, min(2, (int)($skuRow['decimal_scale'] ?? 0)));
        }
        return bcmul($saleNum, $convert, $skuScale > 0 ? 2 : 0);
    }

    protected function trimQty(string $qty, int $scale): string
    {
        $scale = max(0, min(4, $scale));
        $formatted = number_format((float)$qty, $scale, '.', '');
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }
        return $formatted === '' ? '0' : $formatted;
    }

    /** 供 Db::raw 拼接的安全小数（禁止注入） */
    protected function sqlDecimal(string $num): string
    {
        $num = bcadd($num, '0', 4);
        if (!preg_match('/^-?\d+(\.\d{1,4})?$/', $num)) {
            throw new ValidateException('库存数量格式错误');
        }
        return $num;
    }
}
