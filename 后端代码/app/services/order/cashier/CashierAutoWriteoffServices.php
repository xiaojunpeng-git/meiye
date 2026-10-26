<?php
declare(strict_types=1);

namespace app\services\order\cashier;

use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\product\product\StoreProduct;
use app\model\yeji\YejiCommission;
use app\services\BaseServices;
use app\services\order\StoreCartServices;
use app\services\order\store\WriteOffOrderServices;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;

/**
 * 收银台支付后同步自动核销（含院装扣料）
 *
 * 须在支付成功同一事务内调用；失败抛错由外层回滚，避免「已付款无核销」。
 */
class CashierAutoWriteoffServices extends BaseServices
{
    use ServicesTrait;

    /**
     * 支付事务内：对需要自动核销的收银项目行执行核销（失败抛出）
     */
    public function cashierAutoWriteoffAfterPay(array $orderInfo): void
    {
        if (($orderInfo['channel_type'] ?? '') !== 'cashier') {
            return;
        }
        $oid = (int)($orderInfo['id'] ?? 0);
        if ($oid <= 0) {
            return;
        }

        $theOrder = StoreOrder::where('id', $oid)->find();
        if (!$theOrder) {
            throw new ValidateException('订单不存在');
        }
        $theOrder = $theOrder->toArray();
        // 欠款补交单业务禁止核销（WriteOffOrderServices 同口径）；不得进入核销以免支付事务被误回滚
        if (!empty($theOrder['is_debt_repay']) || !empty($orderInfo['is_debt_repay'])) {
            return;
        }
        if (!empty($theOrder['pid']) && (int)$theOrder['pid'] > 0) {
            $parent = StoreOrder::where('id', (int)$theOrder['pid'])->find();
            if ($parent) {
                $theOrder = $parent->toArray();
            }
        }

        $serviceYejiAll = [];
        if (!empty($theOrder['service_yeji'])) {
            $decoded = json_decode((string)$theOrder['service_yeji'], true);
            $serviceYejiAll = is_array($decoded) ? $decoded : [];
        }

        $ids = StoreOrder::where('pid', (int)$theOrder['id'])->column('id');
        if (empty($ids)) {
            $ids = [(int)$theOrder['id']];
        }

        $cartInfos = StoreOrderCartInfo::whereIn('oid', $ids)->select();
        /** @var WriteOffOrderServices $writeOffOrderServices */
        $writeOffOrderServices = app()->make(WriteOffOrderServices::class);

        // 预取商品 pid/product_type，避免核销环内 N+1（禁止走 UI 用 getOrderCartInfo）
        $productIds = [];
        foreach ($cartInfos as $c) {
            $c = is_object($c) ? $c->toArray() : (array)$c;
            $pid = (int)($c['product_id'] ?? 0);
            if ($pid > 0) {
                $productIds[$pid] = $pid;
            }
        }
        $productMeta = [];
        if ($productIds) {
            $rows = StoreProduct::whereIn('id', array_values($productIds))->field('id,pid,product_type')->select()->toArray();
            foreach ($rows as $row) {
                $productMeta[(int)$row['id']] = $row;
            }
            $rootIds = [];
            foreach ($productMeta as $row) {
                $root = (int)($row['pid'] ?? 0) > 0 ? (int)$row['pid'] : (int)$row['id'];
                if (!isset($productMeta[$root])) {
                    $rootIds[$root] = $root;
                }
            }
            if ($rootIds) {
                $roots = StoreProduct::whereIn('id', array_values($rootIds))->field('id,pid,product_type')->select()->toArray();
                foreach ($roots as $row) {
                    $productMeta[(int)$row['id']] = $row;
                }
            }
        }
        $orderByOid = [];
        $orderByOid[(int)$theOrder['id']] = $theOrder;
        $missingOrderIds = [];
        foreach ($ids as $cid) {
            $cid = (int)$cid;
            if ($cid > 0 && !isset($orderByOid[$cid])) {
                $missingOrderIds[$cid] = $cid;
            }
        }
        if ($missingOrderIds) {
            $childRows = StoreOrder::whereIn('id', array_values($missingOrderIds))->select()->toArray();
            foreach ($childRows as $row) {
                $orderByOid[(int)$row['id']] = $row;
            }
        }

        // Yeji：ORDER BY id ASC 每 product_id 取首行，对齐 value() 在唯一行场景；多行禁止无序 column 覆盖
        $yejiPids = [];
        foreach ($cartInfos as $c) {
            $c = is_object($c) ? $c->toArray() : (array)$c;
            $productId = (int)($c['product_id'] ?? 0);
            $meta = $productMeta[$productId] ?? null;
            $pid = $meta && (int)($meta['pid'] ?? 0) > 0 ? (int)$meta['pid'] : $productId;
            if ($pid > 0) {
                $yejiPids[$pid] = $pid;
            }
        }
        $yejiByPid = $this->prefetchYejiCommissionByProductIds(array_values($yejiPids));
        $giftShellCache = [];

        foreach ($cartInfos as $cartOne) {
            $cartOne = is_object($cartOne) ? $cartOne->toArray() : (array)$cartOne;
            if ((int)($cartOne['is_writeoff'] ?? 0) === 1) {
                continue;
            }
            if ((int)($cartOne['write_surplus_times'] ?? 0) <= 0) {
                continue;
            }

            $productId = (int)($cartOne['product_id'] ?? 0);
            $meta = $productMeta[$productId] ?? null;
            $pid = $meta && (int)($meta['pid'] ?? 0) > 0 ? (int)$meta['pid'] : $productId;
            $rootMeta = $productMeta[$pid] ?? $meta;
            $productType = (int)($rootMeta['product_type'] ?? 0);
            $lookOrderArr = $orderByOid[(int)$cartOne['oid']] ?? null;
            if (!$this->shouldCashierAutoWriteoffServiceCart($cartOne, $productType, $lookOrderArr, $giftShellCache)) {
                continue;
            }

            $cartInfoDecoded = is_string($cartOne['cart_info'] ?? null)
                ? (json_decode($cartOne['cart_info'], true) ?: [])
                : (($cartOne['cart_info'] ?? []) ?: []);
            $svcObj = trim((string)($cartInfoDecoded['service_object'] ?? ''));
            $svcObj = $svcObj === '朋友' ? '朋友' : '本人';
            if ($svcObj === '本人') {
                $orderSo = trim((string)($lookOrderArr['service_object'] ?? $theOrder['service_object'] ?? ''));
                if ($orderSo === '朋友') {
                    $svcObj = '朋友';
                }
            }

            $cartIds = [[
                'cart_id' => $cartOne['cart_id'],
                'cart_num' => $cartOne['write_surplus_times'],
                'service_object' => $svcObj,
            ]];

            $onePrice = $yejiByPid[$pid] ?? null;
            $syncAll = [];
            $matchedServiceYeji = $this->findServiceYejiForCart($serviceYejiAll, $cartOne, $productId);
            if ($matchedServiceYeji) {
                $syncOne = $matchedServiceYeji;
                $syncOne['type'] = 3;
                $syncOne['write_times'] = $cartOne['write_times'];
                $syncOne['true_price'] = $cartOne['pay_price'];
                $syncOne['order_id'] = $cartOne['oid'];
                $syncOne['value'] = $cartOne['write_surplus_times'];
                $syncOne['link_id'] = 0;
                $syncOne['once_price'] = $onePrice;
                $syncOne['price'] = bcmul((string)$onePrice, (string)$cartOne['write_surplus_times'], 2);
                $syncOne['staffChoose'] = $matchedServiceYeji['staffChoose'] ?? [];
                $len = count($syncOne['staffChoose']);
                if ($len > 0) {
                    if (bccomp((string)$syncOne['price'], '0', 2) > 0) {
                        $totalAssigned = '0';
                        foreach ($syncOne['staffChoose'] as $nvChoose) {
                            $totalAssigned = bcadd($totalAssigned, (string)($nvChoose['yeji'] ?? 0), 2);
                        }
                        if (bccomp($totalAssigned, (string)$syncOne['price'], 2) !== 0) {
                            $onceYeji = bcdiv((string)$syncOne['price'], (string)$len, 2);
                            $yu = bcsub((string)$syncOne['price'], bcmul($onceYeji, (string)$len, 2), 2);
                            foreach ($syncOne['staffChoose'] as $nkChoose => &$nvChoose) {
                                $nvChoose['yeji'] = $onceYeji;
                                if ($nkChoose === $len - 1 && bccomp($yu, '0', 2) > 0) {
                                    $nvChoose['yeji'] = bcadd($onceYeji, $yu, 2);
                                }
                            }
                            unset($nvChoose);
                        }
                    } else {
                        foreach ($syncOne['staffChoose'] as &$nvChoose) {
                            $nvChoose['yeji'] = $nvChoose['yeji'] ?? 0;
                        }
                        unset($nvChoose);
                    }
                    $syncAll[] = $syncOne;
                }
            }

            // 自动核销禁止走 getOrderCartInfo（含双次 writeoffOrderInfo/一致性同步/缩略图等 UI 重路径）
            $writerOrder = $lookOrderArr ?: $theOrder;
            $writerOrder['shipping_type'] = 2;
            if (!isset($writerOrder['order_type'])) {
                $writerOrder['order_type'] = 'order';
            }
            $staffId = (int)($theOrder['staff_id'] ?? 0);
            $addTime = date('Y-m-d H:i:s', (int)($orderInfo['add_time'] ?? $theOrder['add_time'] ?? time()));
            $writeOffOrderServices->writeoffOrder(
                0,
                $writerOrder,
                $cartIds,
                'cashier',
                $staffId,
                $syncAll,
                $orderInfo['is_budan'] ?? $theOrder['is_budan'] ?? 0,
                $addTime,
                1
            );
        }
    }

    /**
     * 收银台支付后自动核销：
     * - 仅独立购买的项目单 product_type=6
     * - 预约单 type=12（项目预约消耗）
     * 卡项 product_type=5 购卡时不自动核销
     */
    /**
     * @param array|null $giftShellCache 按 oid 缓存赠送壳判断（同请求复用）
     */
    public function shouldCashierAutoWriteoffServiceCart($cartOne, int $productType, $lookOrder, ?array &$giftShellCache = null): bool
    {
        if ($this->isGiftProjectShellOrder($lookOrder, $giftShellCache)) {
            return false;
        }
        if ($productType !== 6 || (int)($cartOne['is_gift'] ?? 0) === 1) {
            return false;
        }
        if (!$lookOrder || ($lookOrder['channel_type'] ?? '') !== 'cashier') {
            return false;
        }
        $orderProductType = (int)($lookOrder['product_type'] ?? 0);
        $orderType = (int)($lookOrder['type'] ?? 0);
        if ($orderProductType === 6) {
            return true;
        }
        return $orderType === 12;
    }

    /**
     * @param array|null $giftShellCache
     */
    protected function isGiftProjectShellOrder($lookOrder, ?array &$giftShellCache = null): bool
    {
        if (!$lookOrder) {
            return false;
        }
        $oid = (int)($lookOrder['id'] ?? 0);
        if ($oid <= 0) {
            return false;
        }
        if (is_array($giftShellCache) && array_key_exists($oid, $giftShellCache)) {
            return (bool)$giftShellCache[$oid];
        }
        /** @var StoreCartServices $storeCartServices */
        $storeCartServices = app()->make(StoreCartServices::class);
        $hit = $storeCartServices->isGiftProjectShellOrder($oid);
        if (is_array($giftShellCache)) {
            $giftShellCache[$oid] = $hit;
        }
        return $hit;
    }

    /**
     * 批量取 yeji：每个 product_id 取 id 最小的一行（ORDER BY id ASC），与 value() 在单行时一致。
     * 若发现同 product_id 多行，打日志告警，不以无序 column 覆盖。
     * @param list<int> $productIds
     * @return array<int, mixed> product_id => yeji
     */
    protected function prefetchYejiCommissionByProductIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) {
            return [];
        }
        $rows = YejiCommission::whereIn('product_id', $productIds)
            ->order('id', 'asc')
            ->field('id,product_id,yeji')
            ->select()
            ->toArray();
        $map = [];
        $dup = [];
        foreach ($rows as $row) {
            $pid = (int)($row['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            if (!array_key_exists($pid, $map)) {
                $map[$pid] = $row['yeji'] ?? null;
            } else {
                $dup[$pid] = true;
            }
        }
        if ($dup) {
            \think\facade\Log::warning('yeji_commission duplicate product_id; using ORDER BY id ASC first row', [
                'product_ids' => array_keys($dup),
            ]);
        }
        return $map;
    }

    /**
     * 匹配手艺人业绩：优先 cart_id / old_cart_id，拆单后兜底 goods_id
     */
    protected function findServiceYejiForCart(array $serviceYejiAll, array $cartRow, int $productId): ?array
    {
        if (!$serviceYejiAll) {
            return null;
        }
        $cartId = (string)($cartRow['cart_id'] ?? '');
        $oldCartId = (string)($cartRow['old_cart_id'] ?? '');
        foreach ($serviceYejiAll as $item) {
            $yejiCartId = (string)($item['cart_id'] ?? '');
            if ($yejiCartId !== '' && ($yejiCartId === $cartId || ($oldCartId !== '' && $yejiCartId === $oldCartId))) {
                return $item;
            }
        }
        if ($productId > 0) {
            foreach ($serviceYejiAll as $item) {
                if ((int)($item['goods_id'] ?? 0) === $productId) {
                    return $item;
                }
            }
        }
        return null;
    }

}
