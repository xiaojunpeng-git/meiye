<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\BaseServices;
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

        // 门店映射：平台 unique -> 门店商品/sku
        if ($storeId > 0) {
            $mapped = $this->mapStoreSku($productServices, $skuServices, $productId, $unique, $storeId);
            $productId = $mapped['product_id'];
            $unique = $mapped['unique'];
            $platformPid = $mapped['platform_pid'];
        } else {
            $platformPid = 0;
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

        if ($isInventory !== 1 && in_array($bizType, [self::BIZ_SALE_PAY_SUCCESS, self::BIZ_SALE_REFUND], true)) {
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

        return Db::transaction(function () use (
            $productServices, $skuServices, $productId, $unique, $deltaStock, $deltaDefective,
            $allowNegative, $changeSales, $salesNum, $platformPid, $product
        ) {
            // 全局实物库存锁顺序：先锁 SKU（store_product_attr_value），再更新商品表；禁止先锁商品再锁 SKU
            $skuQuery = Db::name('store_product_attr_value')
                ->where('product_id', $productId)
                ->where('unique', $unique)
                ->where('type', 0)
                ->lock(true)
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

            $productServices->cacheTag()->clear();
            $skuServices->cacheTag()->clear();

            return [
                'product_id' => $productId,
                'unique' => $unique,
                'stock' => $newStock,
                'defective_stock' => $newDefective,
            ];
        });
    }

    /**
     * 商品订单支付前统一检查入口（会员充值等非商品单跳过）
     */
    public function assertOrderCanPay(array $orderInfo): void
    {
        if (isset($orderInfo['member_type'])) {
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
            ->field('id,product_id,sku_unique,cart_num,cart_info,product_type,cart_type,type')
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
            $cartInfo[] = $this->enrichCartWithInventorySnapshot($info);
        }
        return $cartInfo;
    }

    /**
     * 支付前库存检查（不扣减）
     * 映射到底层门店+商品+SKU 后先汇总需求，再与现库存比较；应管库存的非法行直接报错。
     */
    public function checkOrderPayableStock(array $cartInfo, int $storeId = 0): void
    {
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        /** @var StoreProductAttrValueServices $skuServices */
        $skuServices = app()->make(StoreProductAttrValueServices::class);

        // key = storeProductId|unique => 汇总后的基本单位需求
        $demand = [];
        foreach ($cartInfo as $cart) {
            $cart = $this->enrichCartWithInventorySnapshot($cart);
            if ($this->shouldSkipInventoryCart($cart)) {
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
            $product = $productServices->get($productId, ['id', 'type', 'is_inventory', 'allow_negative_stock', 'product_type', 'store_name']);
            if (!$product) {
                throw new ValidateException('商品不存在');
            }
            $product = is_array($product) ? $product : $product->toArray();
            // 【供应商库存已停用】不参与支付前库存拦截
            if ((int)($product['type'] ?? 0) === 2) {
                continue;
            }
            $isInventory = (int)($product['is_inventory'] ?? (((int)($product['product_type'] ?? 0) === 0) ? 1 : 0));
            if ($isInventory !== 1) {
                continue;
            }
            if ((int)($product['allow_negative_stock'] ?? 1) === 1) {
                continue; // 允许负库存：支付前不拦截
            }
            $checkProductId = $productId;
            $checkUnique = $unique;
            if ($storeId > 0) {
                $mapped = $this->mapStoreSku($productServices, $skuServices, $productId, $unique, $storeId);
                $checkProductId = $mapped['product_id'];
                $checkUnique = $mapped['unique'];
            }
            $sku = Db::name('store_product_attr_value')->where([
                'product_id' => $checkProductId,
                'unique' => $checkUnique,
                'type' => 0,
            ])->field('stock,unit_convert,decimal_scale')->find();
            if (!$sku) {
                throw new ValidateException('商品规格不存在');
            }
            $convert = (string)(($sku['unit_convert'] ?? '1') ?: '1');
            if (bccomp($convert, '0', 4) <= 0) {
                $convert = '1';
            }
            $need = bcmul($num, $convert, 4);
            $key = $checkProductId . '|' . $checkUnique;
            if (!isset($demand[$key])) {
                $demand[$key] = [
                    'product_id' => $checkProductId,
                    'unique' => $checkUnique,
                    'need' => '0',
                    'stock' => (string)$sku['stock'],
                    'scale' => (int)($sku['decimal_scale'] ?? 0),
                    'name' => (string)($product['store_name'] ?? $productId),
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
     * 支付成功：扣实物库存 + 加销量 + 同步销售出库（幂等依赖订单 inventory_handled/sales_handled）
     * 须在外层支付事务内调用；本方法不再单独开事务写订单标记。
     */
    public function handlePaidOrderInventory(array $orderInfo, array $cartInfo): void
    {
        $orderId = (int)($orderInfo['id'] ?? 0);
        if ($orderId <= 0) {
            return;
        }
        $storeId = (int)($orderInfo['store_id'] ?? 0);
        $inventoryHandled = (int)($orderInfo['inventory_handled'] ?? 0);
        $salesHandled = (int)($orderInfo['sales_handled'] ?? 0);
        if ($inventoryHandled === 1 && $salesHandled === 1) {
            // 已处理过库存/销量时，仍确保销售出库单存在（幂等靠 store_order_id）
            $this->createSaleOutOrdersForPaidOrder($orderId, false);
            return;
        }

        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);

        foreach ($cartInfo as $cart) {
            $cart = $this->enrichCartWithInventorySnapshot($cart);
            $skipInv = $this->shouldSkipInventoryCart($cart);
            try {
                [$productId, $unique] = $this->resolveInventoryTarget($cart);
            } catch (\Throwable $e) {
                if (!$skipInv) {
                    throw new ValidateException($e->getMessage() ?: '商品规格无法解析，无法扣库存');
                }
                continue;
            }
            $num = (string)($cart['cart_num'] ?? 0);
            if ($productId <= 0 || bccomp($num, '0', 4) <= 0 || $unique === '') {
                if (!$skipInv) {
                    throw new ValidateException('订单商品规格或数量异常，无法扣库存');
                }
                continue;
            }

            $basicQty = $this->calcBasicQty($productId, $unique, $num);

            if (!$skipInv && $inventoryHandled !== 1) {
                $this->changeSkuStock([
                    'product_id' => $productId,
                    'unique' => $unique,
                    'delta_stock' => bcsub('0', $basicQty, 4),
                    'store_id' => $storeId,
                    'biz_type' => self::BIZ_SALE_PAY_SUCCESS,
                    'allow_negative_override' => true,
                    'change_sales' => false,
                ]);
            }

            if ($salesHandled !== 1) {
                // 销量按销售数量；活动订单销量记到底层实物商品
                $productServices->incProductSales((int)ceil((float)$num), $productId, $unique, $storeId);
            }
        }

        // 销售出库台账：库存已扣，isStock=false 只写单据
        if ($inventoryHandled !== 1) {
            $this->createSaleOutOrdersForPaidOrder($orderId, false);
        }

        $update = [];
        if ($inventoryHandled !== 1) {
            $update['inventory_handled'] = 1;
        }
        if ($salesHandled !== 1) {
            $update['sales_handled'] = 1;
        }
        if ($update) {
            Db::name('store_order')->where('id', $orderId)->update($update);
        }
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
     */
    public function createSaleOutOrdersForPaidOrder(int $orderId, bool $isStock = false): void
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
        $cartInfo = Db::name('store_order_cart_info')
            ->where('oid', $orderId)
            ->field('id,type,relation_id,product_id,sku_unique,cart_num,cart_info')
            ->select()
            ->toArray();
        if (!$cartInfo) {
            return;
        }
        $stockAdminData = $stockStoreData = [];
        // $stockSupplierData = []; // 【供应商库存已停用】不再写供应商销售出库台账
        foreach ($cartInfo as $cart) {
            $info = is_string($cart['cart_info'] ?? null) ? json_decode($cart['cart_info'], true) : [];
            if (!is_array($info)) {
                $info = [];
            }
            $info['product_id'] = (int)($cart['product_id'] ?? 0);
            $info['sku_unique'] = (string)($cart['sku_unique'] ?? '');
            $info['cart_num'] = $cart['cart_num'] ?? 0;
            $info = $this->enrichCartWithInventorySnapshot($info);
            if ($this->shouldSkipInventoryCart($info)) {
                continue;
            }
            // 【供应商库存已停用】cart.type=2 跳过出库台账
            if ((int)($cart['type'] ?? 0) === 2) {
                continue;
            }
            try {
                [$productId, $unique] = $this->resolveInventoryTarget($info);
            } catch (\Throwable $e) {
                throw new ValidateException($e->getMessage() ?: '销售出库无法解析商品规格');
            }
            $num = (string)($cart['cart_num'] ?? 0);
            if ($productId <= 0 || $unique === '' || bccomp($num, '0', 4) <= 0) {
                throw new ValidateException('销售出库商品规格或数量异常');
            }
            $basicQty = $this->calcBasicQty($productId, $unique, $num);
            $attrInfo = [
                'product_id' => $productId,
                'unique' => $unique,
                'stock' => $basicQty,
                'sale_qty' => $num,
            ];
            switch ((int)($cart['type'] ?? 0)) {
                case 0:
                    $stockAdminData[] = $attrInfo;
                    break;
                case 1:
                    $stockStoreData[(int)$cart['relation_id']][] = $attrInfo;
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

    protected function shouldSkipInventoryCart(array $cart): bool
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
            $row = Db::name('store_product')->where('id', $productId)->field('is_inventory,type,product_type')->find();
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

    protected function applySalesOnly(StoreProductServices $productServices, StoreProductAttrValueServices $skuServices, int $productId, string $unique, string $salesNum, int $platformPid): void
    {
        $n = abs((float)$salesNum);
        if ($n <= 0) {
            return;
        }
        if ($unique !== '' && $unique !== '0') {
            Db::name('store_product_attr_value')->where([
                'product_id' => $productId,
                'unique' => $unique,
                'type' => 0,
            ])->inc('sales', $n)->update();
        }
        Db::name('store_product')->where('id', $productId)->inc('sales', $n)->update();
        if ($platformPid > 0) {
            Db::name('store_product')->where('id', $platformPid)->inc('sales', $n)->update();
        }
        $productServices->cacheTag()->clear();
    }

    /**
     * 销售数量 × 单位换算 → 基本单位数量
     */
    protected function calcBasicQty(int $productId, string $unique, string $saleNum): string
    {
        $convert = '1';
        if ($productId > 0 && $unique !== '') {
            $unitConvert = Db::name('store_product_attr_value')->where([
                'product_id' => $productId,
                'unique' => $unique,
                'type' => 0,
            ])->value('unit_convert');
            if ($unitConvert !== null && $unitConvert !== '' && bccomp((string)$unitConvert, '0', 4) > 0) {
                $convert = (string)$unitConvert;
            }
        }
        return bcmul($saleNum, $convert, 4);
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
