<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\order;

use app\dao\order\StoreOrderCartInfoDao;
use app\model\order\StoreOrder;
use app\model\product\product\StoreProduct;
use app\model\yeji\StaffYeji;
use app\services\BaseServices;
use app\services\order\StoreCartServices;
use app\services\product\product\StoreCardRelatedServices;
use app\services\product\product\StoreProductReplyServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrValueServices;
use mohe\services\CacheService;
use mohe\services\SystemConfigService;
use mohe\traits\OptionTrait;
use mohe\traits\ServicesTrait;
use mohe\utils\Arr;
use think\exception\ValidateException;

/**
 * Class StoreOrderCartInfoServices
 * @package app\services\order
 * @mixin StoreOrderCartInfoDao
 */
class StoreOrderCartInfoServices extends BaseServices
{
    use OptionTrait;
    use ServicesTrait;

    /**
     * StorePinkServices constructor.
     * @param StoreOrderCartInfoDao $dao
     */
    public function __construct(StoreOrderCartInfoDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 清空订单商品缓存
     * @param int $oid
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function clearOrderCartInfo(int $oid)
    {
		$this->dao->cacheTag()->clear();
        return CacheService::delete(md5('store_order_cart_info_' . $oid));
    }

    /**
     * 获取指定订单下的商品详情
     * @param int $oid
     * @return array|mixed
     */
    public function getOrderCartInfoCache(int $oid)
    {
        $key = md5('store_order_cart_info_' . $oid);
        return $this->dao->cacheTag()->remember($key, function () use ($oid) {
            $cart_info = $this->dao->getCartColunm(['oid' => $oid], 'cart_info', 'cart_id');
            $info = [];
            foreach ($cart_info as $k => $v) {
                $_info = is_string($v) ? json_decode($v, true) : $v;
                if (!isset($_info['productInfo'])) $_info['productInfo'] = [];
				if (!isset($_info['settle_price'])) {
					$_info['settle_price'] = bcmul((string)($_info['productInfo']['attrInfo']['settle_price'] ?? 0), (string)$_info['cart_num'], 2);
				}
                //缩略图处理
                if (isset($_info['productInfo']['attrInfo'])) {
                    $_info['productInfo']['attrInfo'] = get_thumb_water($_info['productInfo']['attrInfo']);
                }
                $_info['product_type'] = $_info['productInfo']['product_type'] ?? 0;
                $_info['supplier_id'] = (($_info['productInfo']['type'] ?? 0) == 2) ? ($_info['productInfo']['relation_id'] ?? 0) : 0;
				$_info['store_id'] = (($_info['productInfo']['type'] ?? 0) == 1) ? ($_info['productInfo']['relation_id'] ?? 0) : 0;
                $_info['is_support_refund'] = $_info['productInfo']['is_support_refund'] ?? 1;
                $_info['productInfo'] = get_thumb_water($_info['productInfo']);
                $_info['refund_num'] = $this->dao->sum(['cart_id' => $_info['id']], 'refund_num');

                $info[$k]['cart_info'] = $_info;
                unset($_info);
            }
            return $info;
        }, 7 * 24 * 3600);
    }

    /**
     * 获取卡项权益
     * @param int $oid
     * @param int $uid
     * @return array
     */
    /**
     * 定制卡相关商品 ID（含 8154 及子商品）
     */
    protected function getCustomCardProductIds(): array
    {
        $mainId = 8154;
        $productIds = StoreProduct::where('pid', $mainId)->column('id');
        $productIds[] = $mainId;
        return array_values(array_unique(array_map('intval', $productIds)));
    }

    /**
     * 定制卡内项行应付（单价×数量；快照里若只有单价则补乘数量）
     */
    protected function resolveCustomCardBundleLineTotal(array $line, array $info, string $lineAmount = ''): string
    {
        $cartNum = max((int)($line['cart_num'] ?? $info['cart_num'] ?? 1), 1);
        $unit = (string)($info['truePrice'] ?? $info['sum_price'] ?? $info['price'] ?? 0);
        if ($lineAmount === '') {
            $lineAmount = (string)($line['pay_price'] ?? $info['pay_price'] ?? 0);
        }
        if (bccomp($lineAmount, '0', 2) <= 0) {
            return bccomp($unit, '0', 2) > 0 ? bcmul($unit, (string)$cartNum, 2) : '0.00';
        }
        if ($cartNum > 1 && bccomp($unit, '0', 2) > 0) {
            $expected = bcmul($unit, (string)$cartNum, 2);
            if (bccomp($lineAmount, $expected, 2) >= 0) {
                return $lineAmount;
            }
            if (bccomp($lineAmount, $unit, 2) === 0) {
                return $expected;
            }
        }
        return $lineAmount;
    }

    /**
     * 定制卡内部项目券后分摊权重（改价行金额 - 行优惠券）
     */
    protected function getCustomCardBundleLinePayWeight(array $line): string
    {
        $info = is_string($line['cart_info'] ?? null) ? json_decode($line['cart_info'], true) : ($line['cart_info'] ?? []);
        if (!is_array($info)) {
            $info = [];
        }
        $coupon = (string)($line['coupon_price'] ?? $info['coupon_price'] ?? 0);

        foreach (['sum_true_price', 'pay_price'] as $field) {
            $val = (string)($info[$field] ?? 0);
            if (bccomp($val, '0', 2) <= 0) {
                continue;
            }
            $val = $this->resolveCustomCardBundleLineTotal($line, $info, $val);
            if (bccomp($coupon, '0', 2) > 0 && $field === 'pay_price') {
                $changeLine = (string)($info['change_price'] ?? 0);
                if (bccomp($changeLine, '0', 2) > 0 && bccomp($val, $changeLine, 2) === 0) {
                    $val = bcsub($val, $coupon, 2);
                }
            }
            if (bccomp($val, '0', 2) > 0) {
                return $val;
            }
        }

        $stored = (string)($line['pay_price'] ?? 0);
        if (bccomp($stored, '0', 2) > 0) {
            $stored = $this->resolveCustomCardBundleLineTotal($line, $info, $stored);
            $changeLine = (string)($info['change_price'] ?? 0);
            if (bccomp($coupon, '0', 2) > 0 && bccomp($changeLine, '0', 2) > 0 && bccomp($stored, $changeLine, 2) === 0) {
                $stored = bcsub($stored, $coupon, 2);
            }
            if (bccomp($stored, '0', 2) > 0) {
                return $stored;
            }
        }

        $cartNum = max((int)($line['cart_num'] ?? $info['cart_num'] ?? 1), 1);
        $writeTimes = max((int)($line['write_times'] ?? $info['write_times'] ?? 0), 0);
        $qty = $writeTimes > 0 ? max($writeTimes, $cartNum) : $cartNum;
        $lineTotal = (string)($info['change_price'] ?? 0);
        if (bccomp($lineTotal, '0', 2) <= 0) {
            $unit = (string)($info['truePrice'] ?? $info['sum_price'] ?? $info['price'] ?? 0);
            $lineTotal = bcmul($unit, (string)$qty, 2);
        } else {
            $lineTotal = $this->resolveCustomCardBundleLineTotal($line, $info, $lineTotal);
        }
        if (bccomp($coupon, '0', 2) > 0) {
            $lineTotal = bcsub($lineTotal, $coupon, 2);
        }
        return bccomp($lineTotal, '0', 2) > 0 ? $lineTotal : '0.00';
    }

    /**
     * 按券后权重将订单实付分摊到定制卡内部各项目
     */
    protected function allocateCustomCardBundlePayPrices(array $bundleLines, string $orderPay): array
    {
        $result = [];
        if (!$bundleLines || bccomp($orderPay, '0', 2) <= 0) {
            return $result;
        }

        $storedSum = '0';
        $allStoredPositive = true;
        foreach ($bundleLines as $line) {
            $sp = (string)($line['pay_price'] ?? 0);
            if (bccomp($sp, '0', 2) <= 0) {
                $allStoredPositive = false;
                break;
            }
            $storedSum = bcadd($storedSum, $sp, 2);
        }
        if ($allStoredPositive && bccomp($storedSum, $orderPay, 2) === 0) {
            foreach ($bundleLines as $line) {
                $result[(int)$line['id']] = (string)($line['pay_price'] ?? 0);
            }
            return $result;
        }

        $count = count($bundleLines);
        $weights = [];
        $weightSum = '0';
        foreach ($bundleLines as $line) {
            $w = $this->getCustomCardBundleLinePayWeight($line);
            $weights[] = $w;
            $weightSum = bcadd($weightSum, $w, 2);
        }

        $allocated = '0';
        foreach ($bundleLines as $k => $line) {
            $lineId = (int)$line['id'];
            if ($k >= $count - 1) {
                $linePay = bcsub($orderPay, $allocated, 2);
            } elseif (bccomp($weightSum, '0', 2) > 0) {
                $linePay = bcmul($orderPay, bcdiv($weights[$k], $weightSum, 4), 2);
            } else {
                $linePay = bcdiv($orderPay, (string)$count, 2);
            }
            if (bccomp($linePay, '0', 2) < 0) {
                $linePay = '0.00';
            }
            $result[$lineId] = $linePay;
            if ($k < $count - 1) {
                $allocated = bcadd($allocated, $linePay, 2);
            }
        }
        return $result;
    }

    /**
     * 定制卡内部同 product 去重：优先保留含改价/用券信息的拆单行
     */
    protected function dedupeCustomCardBundleLines(array $lines): array
    {
        $byProduct = [];
        foreach ($lines as $line) {
            $pid = (int)($line['product_id'] ?? 0);
            if (!$pid) {
                continue;
            }
            $info = is_string($line['cart_info'] ?? null) ? json_decode($line['cart_info'], true) : ($line['cart_info'] ?? []);
            if (!is_array($info)) {
                $info = [];
            }
            $score = 0;
            if (!empty($info['change_price']) || !empty($info['coupon_price']) || !empty($info['coupon_id'])) {
                $score += 10;
            }
            if (!empty($line['coupon_price'])) {
                $score += 5;
            }
            if (empty($line['old_cart_id'])) {
                $score -= 1;
            }
            if (!isset($byProduct[$pid]) || $score > $byProduct[$pid]['score']) {
                $byProduct[$pid] = ['score' => $score, 'line' => $line];
            }
        }
        if (!$byProduct) {
            return $lines;
        }
        return array_values(array_map(function ($item) {
            return $item['line'];
        }, $byProduct));
    }

    /**
     * 是否定制卡壳订单行（8154 本体）
     */
    protected function isCustomCardShellLine(array $row): bool
    {
        if ((int)($row['product_id'] ?? 0) === 8154) {
            return true;
        }
        $info = is_string($row['cart_info'] ?? null) ? json_decode($row['cart_info'], true) : ($row['cart_info'] ?? []);
        if (!is_array($info)) {
            $info = [];
        }
        $pInfo = $info['productInfo'] ?? [];
        if ((int)($pInfo['id'] ?? 0) === 8154) {
            return true;
        }
        $name = (string)($pInfo['store_name'] ?? '');
        if ((int)($pInfo['pid'] ?? 0) === 8154 && strpos($name, '定制卡') !== false) {
            return true;
        }
        return false;
    }

    /**
     * 定制卡订单落库后同步价格：壳单价=订单实付；内部项目实付之和=订单实付
     */
    public function syncCustomCardOrderPayPrice(int $oid): void
    {
        if ($oid <= 0) {
            return;
        }
        $allCartLines = $this->dao->getCartColunm(['oid' => $oid, 'cart_type' => 0], '*', 'id');
        $shell = null;
        foreach ($allCartLines as $row) {
            if ($this->isCustomCardShellLine($row)) {
                $shell = $row;
                break;
            }
        }
        if (!$shell) {
            return;
        }

        $orderPay = (string)StoreOrder::where('id', $oid)->value('pay_price');
        if (bccomp($orderPay, '0', 2) <= 0) {
            return;
        }

        $this->updateOrderCartLinePayPrice((int)$shell['id'], $orderPay, true);

        $bundleLines = $this->dao->getCartColunm(['oid' => $oid, 'cart_type' => 2], '*', 'id');
        if (!$bundleLines) {
            $bundleLines = array_values(array_filter($allCartLines, function ($row) {
                return !$this->isCustomCardShellLine($row);
            }));
        }
        if (!$bundleLines) {
            $this->clearOrderCartInfo($oid);
            return;
        }

        $bundleLines = $this->dedupeCustomCardBundleLines($bundleLines);
        $payPriceMap = $this->allocateCustomCardBundlePayPrices($bundleLines, $orderPay);
        foreach ($bundleLines as $line) {
            $lineId = (int)$line['id'];
            if (!isset($payPriceMap[$lineId])) {
                continue;
            }
            $this->updateOrderCartLinePayPrice($lineId, $payPriceMap[$lineId], false);
        }

        $this->clearOrderCartInfo($oid);
    }

    /**
     * 更新订单行实付并同步 cart_info 快照
     */
    protected function updateOrderCartLinePayPrice(int $id, string $payPrice, bool $isShell): void
    {
        $row = $this->dao->get($id);
        if (!$row) {
            return;
        }
        $info = is_string($row['cart_info']) ? json_decode($row['cart_info'], true) : ($row['cart_info'] ?? []);
        if (!is_array($info)) {
            $info = [];
        }
        $cartNum = max((int)($row['cart_num'] ?? 1), 1);
        $unit = bcdiv($payPrice, (string)$cartNum, 2);
        $info['pay_price'] = $payPrice;
        $info['truePrice'] = $unit;
        $info['sum_price'] = $unit;
        $info['sum_true_price'] = $payPrice;
        if ($isShell) {
            $info['total_price'] = $payPrice;
        }
        $update = [
            'pay_price' => $payPrice,
            'cart_info' => json_encode($info, JSON_UNESCAPED_UNICODE),
        ];
        $yuePay = (string)($row['yue_pay_amount'] ?? 0);
        $cardUpgrade = (string)($row['card_upgrade_amount'] ?? 0);
        if (bccomp($yuePay, $payPrice, 2) > 0) {
            $yuePay = $payPrice;
            $info['yue_pay_amount'] = $payPrice;
            $update['yue_pay_amount'] = $payPrice;
        }
        $cash = bcsub(bcsub($payPrice, $yuePay, 2), $cardUpgrade, 2);
        if (bccomp($cash, '0', 2) < 0) {
            $cash = '0.00';
        }
        $update['cash_pay_amount'] = $cash;
        $info['cash_pay_amount'] = $cash;
        $update['cart_info'] = json_encode($info, JSON_UNESCAPED_UNICODE);
        if ($isShell) {
            $update['total_price'] = $payPrice;
        }
        $this->dao->update($id, $update);
    }

    public function getOrderCardRelatedCartInfo(int $oid = 0, int $uid = 0)
    {
        $cart_info = $this->dao->getCartColunm(['oid' => $oid, 'cart_type' => 2], '*');
        $cart = $this->dao->getOne(['oid' => $oid, 'cart_type' => 0]);
        /** @var StoreReservationOrderServices $reservationOrderServices */
        $reservationOrderServices = app()->make(StoreReservationOrderServices::class);
        /** @var StoreProductReplyServices $replyServices */
        $replyServices = app()->make(StoreProductReplyServices::class);
        $pay_price = 0;
        $productIds = $this->getCustomCardProductIds();
        $isDingzhi = 0;
        $one = $this->dao->getOne(['oid' => $oid, 'cart_type' => 0]);
        if ($one && in_array((int)$one['product_id'], $productIds, true)) {
            $isDingzhi = 1;
        }
        if ($isDingzhi) {
            $cart_info = $this->dedupeCustomCardBundleLines($cart_info);
        }
        $count = count($cart_info);
        $orderPayPrice = (string)StoreOrder::where('id', $oid)->value('pay_price');
        $shellPay = (string)($cart['pay_price'] ?? 0);
        $allocPrice = bccomp($shellPay, '0', 2) > 0 ? $shellPay : $orderPayPrice;
        $dingzhiPayMap = $isDingzhi ? $this->allocateCustomCardBundlePayPrices($cart_info, $orderPayPrice) : [];
        foreach ($cart_info as $k => &$v) {
            $staffs = StaffYeji::where("type", 2)
                ->where("goods_id", $v['product_id'])
                ->where("cart_id", $v['cart_id'])
                ->where("link_id", $oid)
                ->column("staff_name");
            $_info = is_string($v['cart_info']) ? json_decode($v['cart_info'], true) : $v['cart_info'];
            $_info['yeji_staff'] = implode(",", $staffs);
            $_info['is_dingzhi'] = $isDingzhi;
            $lineId = (int)($v['id'] ?? 0);
            if ($isDingzhi && isset($dingzhiPayMap[$lineId])) {
                $v['payPrice'] = $dingzhiPayMap[$lineId];
            } elseif ($k >= $count - 1) {
                $v['payPrice'] = bcsub($allocPrice, (string)$pay_price, 2);
            } else {
                $v['payPrice'] = $this->setActualPaymentPrice($cart_info, $v['product_id'], $v['sku_unique'], $allocPrice);
                $pay_price = bcadd((string)$pay_price, (string)$v['payPrice'], 2);
            }
            $v['price'] = $v['payPrice'];
            $_info['pay_price'] = $v['payPrice'];
            $lineQty = max((int)($v['cart_num'] ?? 1), 1);
            $_info['truePrice'] = bcdiv((string)$v['payPrice'], (string)$lineQty, 2);
            $v['cart_info'] = $_info;
            $v['waiting_service'] = 0;
            //新增是否评价字段
            $v['is_reply'] = $replyServices->count(['unique' => $v['unique']]);
            if ($v['product_type'] == 6) {
                if (!$uid) $uid = $v['uid'];
                $v['waiting_service'] = $reservationOrderServices->count(['uid' => $uid, 'oid' => $oid, 'cart_info_id' => $v['id'], 'status' => 0, 'is_del' => 0]);
            }
        }
        unset($v);
        return $cart_info;
    }

    /**
     * 获取该商品实付价格
     * @param $cart_info
     * @param $product_id
     * @param $price
     * @return string
     */
    public function setActualPaymentPrice($cart_info, $product_id, $sku_unique, $price = 0.00)
    {
        $sumPrice = 0;
        $product = [];
        $totalWriteTimes = 0;
        foreach ($cart_info as $k => $v) {
            $_info = is_string($v['cart_info']) ? json_decode($v['cart_info'], true) : $v['cart_info'];
            $v['cart_info'] = $_info;
            if (!is_array($_info)) {
                $_info = [];
            }
            $linePrice = bcmul((string)($_info['price'] ?? 0), (string)($_info['write_times'] ?? 1), 2);
            $sumPrice = bcadd((string)$sumPrice, $linePrice, 2);
            $totalWriteTimes += max((int)($_info['write_times'] ?? 1), 1);
            if ($product_id == ($_info['product_id'] ?? 0) && $sku_unique == ($_info['product_attr_unique'] ?? '')) {
                $product = $_info;
            }
        }

        if (empty($product) || bccomp((string)$price, '0', 2) <= 0) {
            return '0.00';
        }
        if (bccomp($sumPrice, '0', 2) <= 0) {
            $count = count($cart_info);
            if ($count <= 0) {
                return '0.00';
            }
            if ($totalWriteTimes <= 0) {
                return bcdiv((string)$price, (string)$count, 2);
            }
            $productTimes = max((int)($product['write_times'] ?? 1), 1);
            return bcmul(bcdiv((string)$price, (string)$totalWriteTimes, 4), (string)$productTimes, 2);
        }

        return bcmul(bcmul((string)$price, bcdiv((string)($product['price'] ?? 0), (string)$sumPrice, 4), 4), (string)($product['write_times'] ?? 1), 2);
    }

    /** 获取指定订单下的商品详情 供应商
     * @param int $oid
     * @return array
     */
    public function getOrderCartInfoSettlePrice(int $oid)
    {
        $cart_info = $this->dao->getCartColunm(['oid' => $oid], 'cart_num,refund_num,cart_info', 'id');
        $settlePrice = 0;
        $refundSettlePrice = 0;
        foreach ($cart_info as $k => &$v) {
            $_info = is_string($v['cart_info']) ? json_decode($v['cart_info'], true) : $v['cart_info'];
            $v['cart_info'] = $_info;
            if (!isset($_info['productInfo'])) $_info['productInfo'] = [];
            $settle_price = $_info['productInfo']['attrInfo']['settle_price'] ?? 0;
            $_info['settlePrice'] = bcmul((string)$settle_price, (string)$v['cart_num'], 2); //购买结算价格
            $settlePrice = bcadd($_info['settlePrice'], $settlePrice, 2);
            $_info['refundSettlePrice'] = bcmul((string)$settle_price, (string)$v['cart_num'], 2);//退款结算价格
            $refundSettlePrice = bcadd($_info['refundSettlePrice'], $refundSettlePrice, 2);
        }
        return ['settlePrice' => $settlePrice, 'refundSettlePrice' => $refundSettlePrice, 'info' => $cart_info];
    }

    /**
     * 查找购物车里的所有商品标题
     * @param int $oid
     * @param false $goodsNum
     * @return bool|mixed|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCarIdByProductTitle(int $oid, bool $goodsNum = false)
    {
        $key = md5('store_order_cart_product_title_' . $oid);
        $title = CacheService::get($key);
        if (!$title) {
            $orderCart = $this->dao->getCartInfoList(['oid' => $oid], ['cart_info']);
            foreach ($orderCart as $item) {
				$cartInfo = is_string($item['cart_info']) ? json_decode($item['cart_info'], true) : $item['cart_info'];
                if (isset($cartInfo['productInfo']['store_name'])) {
                    if ($goodsNum && isset($cartInfo['cart_num'])) {
                        $title .= $cartInfo['productInfo']['store_name'] . ' * ' . $cartInfo['cart_num'] . ' | ';
                    } else {
                        $title .= $cartInfo['productInfo']['store_name'] . '|';
                    }
                }
            }
            if ($title) {
                $title = substr($title, 0, strlen($title) - 1);
            }
            CacheService::set($key, $title, 7 * 24 * 3600);
        }
        return $title ? $title : '';
    }

    /**
     * 获取购物车商品重量
     * @param int $oid
     * @param bool $goodsNum
     * @return string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCarIdByProductCargoWeight(int $oid)
    {
        $cargo_weight = 0;
        $orderCart = $this->dao->getCartInfoList(['oid' => $oid], ['cart_info']);
        foreach ($orderCart as $item) {
            if (isset($item['cart_info']['productInfo']['attrInfo']['weight']) && $item['cart_info']['productInfo']['attrInfo']['weight']) {
                $cargo_weight += $item['cart_info']['productInfo']['attrInfo']['weight'] * $item['cart_info']['cart_num'];
            }
        }
        return $cargo_weight;
    }

    /**
     * 获取打印订单的商品信息
     * @param int $oid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCartInfoPrintProduct(int $oid)
    {
        $cartInfo = $this->dao->getCartInfoList(['oid' => $oid], ['cart_type', 'cart_info']);
        $product = [];
        foreach ($cartInfo as $item) {
            $value = is_string($item['cart_info']) ? json_decode($item['cart_info'], true) : $item['cart_info'];
            $value['productInfo']['store_name'] = $value['productInfo']['store_name'] ?? '';
            $value['productInfo']['store_name'] = substrUTf8($value['productInfo']['store_name'], 100, 'UTF-8', '');
            $value['cart_type'] = $item['cart_type'];
			$value['is_gift'] = $item['cart_type'] == 1 ? 1 : 0;
            $product[] = $value;
        }
        return $product;
    }

    /**
     * 保存购物车info
     * @param $oid
     * @param array $cartInfo
     * @param $uid
     * @param array $promotions
     * @return int
     */
    public function setCartInfo($oid, array $cartInfo, $uid, array $promotions = [],$giveIds=[])
    {
        $group = [];
        foreach ($cartInfo as $cart) {
            if (isset($cart['cash_pay_amount'])) {
                $cashPayAmount = (float)$cart['cash_pay_amount'];
            } else {
                $pp = (string)($cart['pay_price'] ?? 0);
                $yue = (string)($cart['yue_pay_amount'] ?? 0);
                $cu = (string)($cart['card_upgrade_amount'] ?? 0);
                $debt = (string)($cart['debt_pay_amount'] ?? 0);
                $cash = bcsub(bcsub(bcsub($pp, $yue, 2), $cu, 2), $debt, 2);
                $cashPayAmount = (float)(bccomp($cash, '0', 2) < 0 ? '0.00' : $cash);
            }
            $result=[
                'oid' => $oid,
                'uid' => $uid,
                'cart_id' => $cart['id'],
                'type' => $cart['productInfo']['type'] ?? 0,
                'relation_id' => $cart['productInfo']['relation_id'] ?? 0,
                'product_id' => $cart['product_id'] ?? $cart['productInfo']['id'],//原商品ID
                'product_type' => $cart['productInfo']['product_type'] ?? 0,
                'sku_unique' => $cart['product_attr_unique'] ?? '',
                'promotions_id' => implode(',', $cart['promotions_id'] ?? []),
				'cart_type' => $cart['cart_type'] ?? 0,
                'is_card' => ($cart['cart_type'] ?? 0) == 2 ? 1 : 0,
                'is_gift' => ($cart['cart_type'] ?? 0) == 1 ? 1 : 0,
                'is_support_refund' => ($cart['cart_type'] ?? 0) > 0 ? 0 : ($cart['productInfo']['is_support_refund'] ?? 1),
                'cart_info' => json_encode($cart),
                'cart_num' => $cart['cart_num'],
                'total_price' => $cart['total_price'] ?? 0,//商品总价
				'settle_price' => $cart['settle_price'] ?? 0,//商品结算总价
                'pay_price' => $cart['pay_price'] ?? 0,//商品支付金额
                'yue_pay_amount' => isset($cart['yue_pay_amount']) ? (float)$cart['yue_pay_amount'] : 0.00,//商品余额支付金额
                'card_upgrade_amount' => isset($cart['card_upgrade_amount']) ? (float)$cart['card_upgrade_amount'] : 0.00,//商品卡升级抵扣金额
                'debt_amount' => isset($cart['debt_pay_amount']) ? (float)$cart['debt_pay_amount'] : 0.00,//商品欠款金额
                'repaid_debt_amount' => 0.00,
                'cash_pay_amount' => $cashPayAmount,//商品现金/第三方等支付金额
                'pay_postage' => $cart['postage_price'] ?? 0,//商品支付邮费
                'member_price' => $cart['member_price'] ?? 0,//用户等级、svip优惠金额
                'coupon_price' => $cart['coupon_price'] ?? 0,//优惠券优惠金额
                'promotions_price' => $cart['sum_promotions_price'] ?? 0,//活动优惠金额
                'first_order_price' => $cart['first_order_price'] ?? 0,//首单优惠金额
                'surplus_num' => $cart['cart_num'],
                'split_surplus_num' => $cart['cart_num'],
                'write_times' => bcmul((string)$cart['cart_num'], (string)(max($cart['productInfo']['attrInfo']['write_times'] ?? 1, 1)), 0),
                'write_surplus_times' => bcmul((string)$cart['cart_num'], (string)(max($cart['productInfo']['attrInfo']['write_times'] ?? 1, 1)), 0),
                'unique' => md5($cart['id'] . '' . $oid)
            ];
            if(!empty($giveIds)){
                 if(in_array($result['cart_id'],$giveIds)){
                       $result['is_gift']=1;
                 }
            }
            $group[] = $result;
        }
        if ($promotions) {
            /** @var StoreOrderPromotionsServices $services */
            $services = app()->make(StoreOrderPromotionsServices::class);
            $services->setPromotionsDetail((int)$uid, (int)$oid, $cartInfo, $promotions);
        }
        return $this->dao->saveAll($group);
    }

    /**
     * 卡项订单写入关联商品数据
     * @param $oid
     * @param $uid
     * @param $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function setCartCartInfo($oid, $uid, $id,$selectedProduct=[])
    {
        if (!$id) return false;
        /** @var StoreCardRelatedServices $relatedService */
        $relatedService = app()->make(StoreCardRelatedServices::class);
        $selected_product=StoreOrder::where("id",$oid)->value("selected_product");
        $selected_product=explode(",",(string)$selected_product);
        $selected_product=array_values(array_filter(array_map('intval', $selected_product)));
        $related = $relatedService->getCardRelatedProduct($id, false, $selected_product);
        /** @var StoreCartServices $storeCartServices */
        $storeCartServices = app()->make(StoreCartServices::class);
        $isGiftProjectShell = $storeCartServices->isGiftProjectShellByProductId((int)$id);
        $giftProjectQtyMap = $isGiftProjectShell ? $this->parseGiftProjectQtyMap((int)$oid) : [];
        if ($isGiftProjectShell && $selected_product) {
            if (empty($related)) {
                $related = $this->buildGiftProjectRelatedRows($selected_product, $giftProjectQtyMap);
            } else {
                $existingIds = [];
                foreach ($related as $row) {
                    $existingIds[] = (int)($row['product_id'] ?? ($row['productInfo']['id'] ?? 0));
                }
                $missingIds = array_values(array_diff($selected_product, $existingIds));
                if ($missingIds) {
                    $related = array_merge($related, $this->buildGiftProjectRelatedRows($missingIds, $giftProjectQtyMap));
                }
                if ($giftProjectQtyMap) {
                    foreach ($related as &$relRow) {
                        $relPid = (int)($relRow['product_id'] ?? ($relRow['productInfo']['id'] ?? 0));
                        if ($relPid > 0 && isset($giftProjectQtyMap[$relPid])) {
                            $relRow = $this->applyGiftProjectQtyToRelatedRow($relRow, (int)$giftProjectQtyMap[$relPid]);
                        }
                    }
                    unset($relRow);
                }
            }
        }
        $group = [];
        $orderServiceObject = trim((string)(StoreOrder::where('id', (int)$oid)->value('service_object') ?: ''));
        foreach ($related as $cart) {
            $cart = array_merge($cart, ['cart_num' => $cart['write_times'],
                'is_card' => 1,
				'cart_type' => 2,
                'is_support_refund' => 0,
                'is_gift' => $isGiftProjectShell ? 1 : 0,
                'pay_price' => 0,
                'pay_postage' => 0,
                'coupon_price' => 0,
                'promotions_price' => 0,
                'first_order_price' => 0,
                'truePrice' => $cart['price'] ?? 0,
                'total_price' => bcmul((string)($cart['price'] ?? 0), (string)$cart['write_times'], 2)
            ]);
            $productId=$cart['product_id'] ?? $cart['productInfo']['id'];
            if(!empty($selected_product) && !in_array((int)$productId, $selected_product, true)){
                continue;
            }
            $ptRel = (int)($cart['productInfo']['product_type'] ?? 0);
            if ($ptRel === 6) {
                $cart['service_object'] = $orderServiceObject === '朋友' ? '朋友' : '本人';
            }
            $group[] = [
                'oid' => $oid,
                'uid' => $uid,
                'cart_id' => $this->getUniqueId(''),
                'type' => $cart['productInfo']['type'] ?? 0,
                'relation_id' => $cart['productInfo']['relation_id'] ?? 0,
                'product_id' => $cart['product_id'] ?? $cart['productInfo']['id'],//原商品ID
                'product_type' => $cart['productInfo']['product_type'] ?? 0,
                'sku_unique' => $cart['product_attr_unique'] ?? '',
                'promotions_id' => '',
                'is_gift' => $isGiftProjectShell ? 1 : 0,
                'is_card' => 1,
				'cart_type' => 2,
                'is_support_refund' => 0,
                'cart_info' => json_encode($cart),
                'cart_num' => $cart['write_times'] ?? 1,
                'total_price' => bcmul((string)($cart['price'] ?? 0), (string)($cart['write_times'] ?? 1), 2),
                'pay_price' => 0,
                'pay_postage' => 0,
                'coupon_price' => 0,
                'promotions_price' => 0,
                'first_order_price' => 0,
                'surplus_num' => $cart['write_times'],
                'split_surplus_num' => $cart['write_times'],
                'write_times' => $cart['write_times'],
                'write_surplus_times' => $cart['write_times'],
                'unique' => md5((string)($cart['id'] ?? $productId) . '_' . $oid)
            ];
        }
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        $cart = $cartServices->getOne(['oid' => $oid, 'cart_type' => 0]);
        if (!$cart || empty($group)) {
            return false;
        }
        $shellPayPrice = (string)($cart['pay_price'] ?? '0');
        if (bccomp($shellPayPrice, '0', 2) <= 0) {
            foreach ($group as &$v) {
                $v['pay_price'] = '0.00';
                $v['cash_pay_amount'] = '0.00';
            }
            unset($v);
            return $this->dao->saveAll($group);
        }
        $pay_price = 0;
        $count = count($group);
        foreach ($group as $k => &$v) {
            if ($k > $count - 2) {
                $v['pay_price'] = bcsub((string)$cart['pay_price'], (string)$pay_price, 2);
            } else {
                $v['pay_price'] = $cartServices->setActualPaymentPrice($group, $v['product_id'],$v['sku_unique'], $cart['pay_price']);
                $pay_price = bcadd((string)$pay_price, (string)$v['pay_price'], 2);
            }
            $yue = (string)($v['yue_pay_amount'] ?? 0);
            $cu = (string)($v['card_upgrade_amount'] ?? 0);
            $pp = (string)($v['pay_price'] ?? 0);
            $cash = bcsub(bcsub($pp, $yue, 2), $cu, 2);
            $v['cash_pay_amount'] = bccomp($cash, '0', 2) < 0 ? '0.00' : $cash;
        }
        unset($v);
        return $this->dao->saveAll($group);
    }

    /**
     * 从订单 send_all 解析赠送项目各品项数量
     */
    protected function parseGiftProjectQtyMap(int $oid): array
    {
        if ($oid <= 0) {
            return [];
        }
        $orderRow = StoreOrder::where('id', $oid)->field('send_all,selected_product')->find();
        if (!$orderRow) {
            return [];
        }
        $qtyMap = [];
        if (!empty($orderRow['send_all'])) {
            $sendAll = json_decode((string)$orderRow['send_all'], true);
            if (is_array($sendAll) && !empty($sendAll['product'])) {
                foreach ($sendAll['product'] as $row) {
                    $pid = (int)($row['id'] ?? 0);
                    if ($pid > 0) {
                        $qtyMap[$pid] = max((int)($row['num'] ?? 1), 1);
                    }
                }
            }
        }
        if (!$qtyMap) {
            foreach (array_filter(array_map('intval', explode(',', (string)$orderRow['selected_product']))) as $pid) {
                if ($pid > 0 && !isset($qtyMap[$pid])) {
                    $qtyMap[$pid] = 1;
                }
            }
        }
        return $qtyMap;
    }

    /**
     * 按赠送数量重算卡内子项核销次数
     */
    protected function applyGiftProjectQtyToRelatedRow(array $row, int $cartNum): array
    {
        $cartNum = max($cartNum, 1);
        $attrInfo = $row['productInfo']['attrInfo'] ?? [];
        $attrWriteTimes = max((int)($attrInfo['write_times'] ?? 1), 1);
        $writeTimes = (int)bcmul((string)$cartNum, (string)$attrWriteTimes, 0);
        $row['write_times'] = $writeTimes;
        if (isset($row['productInfo']['attrInfo']) && is_array($row['productInfo']['attrInfo'])) {
            $row['productInfo']['attrInfo']['write_times'] = $attrWriteTimes;
        }
        return $row;
    }

    /**
     * 赠送项目：按所选商品 ID 直接生成卡内子项（不依赖卡项关联表）
     */
    protected function buildGiftProjectRelatedRows(array $productIds, array $qtyMap = []): array
    {
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        /** @var StoreProductAttrValueServices $attrServices */
        $attrServices = app()->make(StoreProductAttrValueServices::class);
        $rows = [];
        foreach ($productIds as $productId) {
            $productId = (int)$productId;
            if ($productId <= 0) {
                continue;
            }
            $productInfo = $productServices->getProductInfo($productId);
            if (!$productInfo) {
                $productInfo = StoreProduct::where('id', $productId)->where('is_del', 0)->find();
            }
            if (!$productInfo) {
                continue;
            }
            $productInfo = is_array($productInfo) ? $productInfo : $productInfo->toArray();
            $attrInfo = $attrServices->getOne(['product_id' => $productId, 'type' => 0]);
            $attrInfo = $attrInfo ? (is_array($attrInfo) ? $attrInfo : $attrInfo->toArray()) : [];
            if (!$attrInfo) {
                $attrInfo = [
                    'suk' => '默认',
                    'price' => 0,
                    'image' => $productInfo['image'] ?? '',
                    'unique' => '',
                    'write_times' => 1,
                ];
            }
            $cartNum = max((int)($qtyMap[$productId] ?? 1), 1);
            $attrWriteTimes = max((int)($attrInfo['write_times'] ?? 1), 1);
            $writeTimes = (int)bcmul((string)$cartNum, (string)$attrWriteTimes, 0);
            $rows[] = [
                'product_id' => $productId,
                'write_times' => $writeTimes,
                'price' => 0,
                'product_attr_unique' => (string)($attrInfo['unique'] ?? ''),
                'productInfo' => array_merge($productInfo, [
                    'attrInfo' => $attrInfo,
                ]),
            ];
        }
        return $rows;
    }

    /**
     * 赠送项目壳订单：同步落库为卡项单(product_type=5)并展开所选子项目
     */
    public function expandGiftProjectShellOrder(int $oid, int $uid, array $selectedIds = []): bool
    {
        if ($oid <= 0) {
            return false;
        }
        /** @var StoreCartServices $storeCartServices */
        $storeCartServices = app()->make(StoreCartServices::class);
        if (!$storeCartServices->isGiftProjectShellOrder($oid)) {
            return false;
        }
        $shellProductId = (int)$this->value(['oid' => $oid, 'cart_type' => 0], 'product_id');
        if ($shellProductId <= 0) {
            return false;
        }
        if (!$selectedIds) {
            $orderRow = StoreOrder::where('id', $oid)->field('selected_product,send_all')->find();
            if ($orderRow) {
                $qtyMap = $this->parseGiftProjectQtyMap($oid);
                $selectedIds = array_keys($qtyMap);
                if (!$selectedIds) {
                    $selectedIds = array_values(array_filter(array_map('intval', explode(',', (string)$orderRow['selected_product']))));
                }
                if (!$selectedIds && !empty($orderRow['send_all'])) {
                    $sendAll = json_decode((string)$orderRow['send_all'], true);
                    if (is_array($sendAll) && !empty($sendAll['product'])) {
                        foreach ($sendAll['product'] as $row) {
                            $pid = (int)($row['id'] ?? 0);
                            if ($pid > 0) {
                                $selectedIds[] = $pid;
                            }
                        }
                    }
                }
                $selectedIds = array_values(array_unique($selectedIds));
            }
        }
        if (!$selectedIds) {
            return false;
        }
        /** @var StoreOrderCreateServices $orderCreateServices */
        $orderCreateServices = app()->make(StoreOrderCreateServices::class);
        $verifyCode = (string)StoreOrder::where('id', $oid)->value('verify_code');
        if ($verifyCode === '') {
            $verifyCode = $orderCreateServices->getStoreCode();
        }
        StoreOrder::where('id', $oid)->update([
            'type' => 11,
            'product_type' => 5,
            'shipping_type' => 2,
            'verify_code' => $verifyCode,
            'selected_product' => implode(',', $selectedIds),
        ]);
        $this->dao->update(['oid' => $oid, 'cart_type' => 0], ['product_type' => 5]);
        $this->dao->delete(['oid' => $oid, 'cart_type' => 2]);
        $result = $this->setCartCartInfo($oid, $uid, $shellProductId);
        $this->clearOrderCartInfo($oid);
        return (bool)$result;
    }

    /**
     * 修改卡项下商品价格
     * @param int $oid
     * @param array $cartInfo
     * @return void
     */
    public function updateCardHolderInfo(int $oid, array $cartInfo)
    {
        $cart_info = $this->dao->getCartColunm(['oid' => $oid, 'cart_type' => 2], '*');
        $cart = $cartInfo[0];
        $pay_price = 0;
        $count = count($cart_info);
        foreach ($cart_info as $k => $v) {
            if ($k > $count - 2) {
                $v['payPrice'] = bcsub((string)$cart['pay_price'], (string)$pay_price, 2);
            } else {
                $v['payPrice'] = $this->setActualPaymentPrice($cart_info, $v['product_id'],$v['sku_unique'], $cart['pay_price']);
                $pay_price = bcadd((string)$pay_price, (string)$v['payPrice'], 2);
            }
            $this->dao->update(['oid' => $oid, 'id' => $v['id']], ['pay_price' => $v['payPrice']]);
        }
    }

    /**
     * 订单创建成功之后计算订单（实际优惠、积分、佣金、上级、上上级）
     * @param $oid
     * @param array $cartInfo
     * @return bool
     */
    public function updateCartInfo($oid, array $cartInfo)
    {
        foreach ($cartInfo as $cart) {
            $group = [
                'cart_info' => json_encode($cart),
                'pay_price' => $cart['pay_price'] ?? 0,
                'deduction_price' => $cart['integral_price'] ?? 0,
                'change_price' => $cart['change_price'] ?? 0
            ];
            $this->dao->update(['oid' => $oid, 'cart_id' => $cart['id']], $group);
        }
        $this->dao->cacheTag()->clear();
        return true;
    }

    /**
     * 商品编号
     * @param int $oid
     * @return array
     */
    public function getCartIdsProduct(int $oid)
    {
        $where = [
            'oid' => $oid
        ];
        return $this->dao->getColumn($where, 'product_id', 'oid', true);
    }

    /**
     * 检测这些商品是否还可以拆分
     * @param int $oid
     * @param array $cart_data
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function checkCartIdsIsSplit(int $oid, array $cart_data)
    {
        if (!$cart_data) return false;
        $ids = array_unique(array_column($cart_data, 'cart_id'));
        if ($this->dao->getCartInfoList(['oid' => $oid, 'cart_id' => $ids, 'split_status' => 2], ['cart_id'])) {
            throw new ValidateException('您选择的商品已经拆分完成，请刷新或稍后重新选择');
        }
        $cartInfo = $this->getSplitCartList($oid, 'surplus_num,split_surplus_num,cart_info,cart_num', 'cart_id');
        if (!$cartInfo) {
            throw new ValidateException('该订单已发货完成');
        }
        foreach ($cart_data as $cart) {
            $surplus_num = $cartInfo[$cart['cart_id']]['surplus_num'] ?? 0;
            if (!$surplus_num) {//兼容之前老数据
                $_info = $cartInfo[$cart['cart_id']]['cart_info'] ?? [];
                $surplus_num = $_info['cart_num'] ?? 0;
            }
            if ($cart['cart_num'] > $surplus_num) {
                throw new ValidateException('您选择商品拆分数量大于购买数量');
            }
        }
        return true;
    }

    /**
     * 获取可退款商品
     * @param int $oid
     * @param string $field
     * @param string $key
     * @return array
     */
    public function getRefundCartList(int $oid, string $field = '*', string $key = '')
    {
        $cartInfo = $this->dao->getColumn([['oid', '=', $oid]], $field, $key);
        foreach ($cartInfo as $key => &$item) {
            if ($field == 'cart_info') {
                $item = is_string($item) ? json_decode($item, true) : $item;
            } else {
                if (isset($item['cart_info'])) $item['cart_info'] = is_string($item['cart_info']) ? json_decode($item['cart_info'], true) : $item['cart_info'];
                if (isset($item['cart_num']) && !$item['cart_num']) {//兼容之前老数据
                    $item['cart_num'] = $item['cart_info']['cart_num'] ?? 0;
                }
            }
            $surplus = (int)bcsub((string)$item['cart_num'], (string)$item['refund_num'], 0);
            if ($surplus > 0) {
                $item['surplus_num'] = $surplus;
            } else {
                unset($cartInfo[$key]);
            }
        }
        return array_merge($cartInfo);
    }

    /**
     * 获取某个订单还可以拆分商品 split_status 0：未拆分1：部分拆分2：拆分完成
     * @param int $oid
     * @param string $field
     * @param string $key
     * @return array
     */
    public function getSplitCartList(int $oid, string $field = '*', string $key = '')
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $order = $orderServices->get($oid);
        if (!$order) {
            throw new ValidateException('订单不存在');
        }
        $store_id = $this->getItem('store_id', 0);
        $supplier_id = $this->getItem('supplier_id', 0);

        //拆分完整主订单查询未发货子订单
        if ($order['pid'] == -1) {
            $oid = $orderServices->value(['pid' => $oid, 'status' => 0, 'supplier_id' => $supplier_id, 'store_id' => $store_id, 'refund_type' => [0, 3]], 'id');
        }
        $cartInfo = $this->dao->getColumn([['oid', '=', $oid], ['split_status', 'IN', [0, 1]]], $field, $key);
        foreach ($cartInfo as &$item) {
            if ($field == 'cart_info') {
                $item = is_string($item) ? json_decode($item, true) : $item;
            } else {
                if (isset($item['cart_info'])) $item['cart_info'] = is_string($item['cart_info']) ? json_decode($item['cart_info'], true) : $item['cart_info'];
                if (isset($item['cart_num']) && !$item['cart_num']) {//兼容之前老数据
                    $item['cart_num'] = $item['cart_info']['cart_num'] ?? 0;
                }
                $item['surplus_num'] = $item['split_surplus_num'];
            }
        }
        return $cartInfo;
    }

    /** 次卡商品未核销短信提醒
     * @return bool
     */
    public function reminderUnverifiedRemind()
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        // 临期
        //系统预设取消订单时间段
        $keyValue = ['reminder_deadline_second_card_time'];
        //获取配置
        $systemValue = SystemConfigService::more($keyValue);
        //格式化数据
        $systemValue = Arr::setValeTime($keyValue, is_array($systemValue) ? $systemValue : []);
        $reminder_deadline_second_card_time = $systemValue['reminder_deadline_second_card_time'];
        $reminder_deadline_second_card_time = (int)bcmul((string)$reminder_deadline_second_card_time, '3600', 0);
        $writeTime = time() + $reminder_deadline_second_card_time;
        $adventList = $this->dao->getAdventCartInfoList(time(), $writeTime);
        if ($adventList) {
            foreach ($adventList as $key => $item) {
                $cart_info = is_string($item['cart_info']) ? json_decode($item['cart_info'], true) : $item['cart_info'];
                $store_name = substrUTf8($cart_info['productInfo']['store_name'], 10, 'UTF-8', '');
                $data['store_name'] = $store_name;
                $order = $orderServices->get($item['oid'], ['id', 'uid', 'pay_time', 'user_phone']);
                if (!$order) {
                    continue;
                }
                $data['uid'] = $order['uid'];
                $data['end_time'] = date('Y-m-d H:i', $item['write_end']);
                $data['pay_time'] = date('Y-m-d H:i', $order['pay_time']);
                $data['phone'] = $order['user_phone'];
                event('notice.notice', [$data, 'reminder_brink_death']);
                $this->dao->update($item['id'], ['is_advent_sms' => 1]);
            }
        }
        // 过期
        $expireList = $this->dao->getExpireCartInfoList(time());
        if ($expireList) {
            foreach ($expireList as $key => $item) {
                $cart_info = is_string($item['cart_info']) ? json_decode($item['cart_info'], true) : $item['cart_info'];
                $store_name = substrUTf8($cart_info['productInfo']['store_name'], 10, 'UTF-8', '');
                $data['store_name'] = $store_name;
                $data['end_time'] = date('Y-m-d H:i', $item['write_end']);
                $order = $orderServices->get($item['oid'], ['id', 'uid', 'pay_time', 'user_phone']);
                if (!$order) {
                    continue;
                }
                $data['uid'] = $order['uid'];
                $data['phone'] = $order['user_phone'];
                event('notice.notice', [$data, 'expiration_reminder']);
                $this->dao->update($item['id'], ['is_expire_sms' => 1]);
            }
        }
        return true;
    }
}
