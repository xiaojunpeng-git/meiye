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


use app\dao\order\StoreOrderDao;
use app\dao\order\StoreOrderWriteoffDao;
use app\jobs\store\StaffFinanceJob;
use app\jobs\store\StoreFinanceJob;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\order\StoreOrderWriteoff;
use app\model\order\StoreReservationOrder;
use app\model\product\category\StoreProductCategory;
use app\model\product\product\StoreProduct;
use app\model\product\product\StoreProductRelation;
use app\model\user\UserCardHolder;
use app\model\yeji\StaffYeji;
use app\services\BaseServices;
use app\services\message\service\StoreServiceServices;
use app\services\pay\PayServices;
use app\services\store\DeliveryServiceServices;
use app\services\store\SystemStoreServices;
use app\services\store\SystemStoreStaffServices;
use app\services\supplier\SystemSupplierServices;
use app\services\system\admin\SystemAdminServices;
use app\services\user\UserServices;
use app\services\yeji\SatffYejiServices;
use think\exception\ValidateException;

/**
 * 核销订单
 * Class StoreOrderWriteOffServices
 * @package app\sservices\order
 * @mixin StoreOrderDao
 */
class StoreOrderWriteOffServices extends BaseServices
{
    protected $orderDao;

    /**
     * 构造方法
     * @param StoreOrderWriteoffDao $dao
     * @param StoreOrderDao $orderDao
     */
    public function __construct(StoreOrderWriteoffDao $dao, StoreOrderDao $orderDao)
    {
        $this->dao = $dao;
        $this->orderDao = $orderDao;
    }

    /**
     * 撤销核销（平台/门店共用）
     *
     * 一个事务内完成：撤销核销记录、失效业绩、恢复权益次数、恢复订单状态、院装退料并恢复库存。
     * 平台与门店的撤销入口统一调用本方法，不再各自复制直改表逻辑。
     *
     * @param int $subOrderId 核销子订单ID（order_type=2；其 link_id 指向核销记录）
     * @param string $remark  撤销原因
     * @param int $storeScope 门店端传自身 store_id 做归属校验；平台端传 0
     * @return bool
     */
    public function cancelWriteoff(int $subOrderId, string $remark = '', int $storeScope = 0): bool
    {
        if ($subOrderId <= 0) {
            throw new ValidateException('缺少订单参数');
        }
        $order = $this->orderDao->get($subOrderId);
        if (!$order) {
            throw new ValidateException('订单不存在');
        }
        $order = is_array($order) ? $order : $order->toArray();
        if ((int)($order['refund_status'] ?? 0) !== 0) {
            throw new ValidateException('该订单状态不允许撤销！');
        }
        $linkId = (int)($order['link_id'] ?? 0);
        if ($linkId <= 0) {
            throw new ValidateException('核销记录不存在');
        }
        if ($storeScope > 0 && (int)($order['store_id'] ?? 0) !== $storeScope) {
            throw new ValidateException('无权撤销其它门店的核销');
        }

        return (bool)$this->transaction(function () use ($subOrderId, $remark, $linkId) {
            // 锁核销记录，防并发/重复撤销
            $writeoffModel = StoreOrderWriteoff::where('id', $linkId)->lock(true)->find();
            if (!$writeoffModel) {
                throw new ValidateException('核销记录不存在');
            }
            $writeoff = $writeoffModel->toArray();
            if ((int)($writeoff['status'] ?? 0) === 1) {
                throw new ValidateException('该核销已撤销');
            }

            $this->orderDao->update($subOrderId, ['back_reason' => $remark, 'refund_status' => 2]);
            StoreOrderWriteoff::where('id', $linkId)->update(['status' => 1]);
            StaffYeji::where('link_id', $linkId)->where('type', 3)->update(['status' => 1]);

            $cartId = (int)($writeoff['order_cart_id'] ?? 0);
            $number = $writeoff['writeoff_num'] ?? 1;
            if ($cartId > 0) {
                StoreOrderCartInfo::where('id', $cartId)->inc('write_surplus_times', $number)->update();
                StoreOrderCartInfo::where('id', $cartId)->update(['is_writeoff' => 0]);
            }
            UserCardHolder::where('uid', $writeoff['uid'])->where('oid', $writeoff['oid'])->inc('write_surplus_times', $number)->update();
            StoreOrder::where('id', $writeoff['oid'])->update(['status' => 5]);

            // 院装退料：按原扣料流水原量退回，幂等；无原扣料记录不凭空加库存。同事务，失败则整体回滚。
            /** @var \app\services\product\inventory\SalonStockWriteoffServices $salonWriteoffServices */
            $salonWriteoffServices = app()->make(\app\services\product\inventory\SalonStockWriteoffServices::class);
            $salonWriteoffServices->returnForWriteoff($linkId);

            return true;
        });
    }

    /**
     * 获取核销列表
     * @param $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function writeOffList($where)
    {
        [$page, $limit] = $this->getPageValue();

        $list = $this->dao->getList($where, '*', $page, $limit, [
            'userInfo',
            'staffInfo',
            'orderInfo' => function ($query) {
                $query->field('id,order_id,pay_type');
            },
            'cartInfo' => function ($query) {
                $query->field('id,cart_info');
            },
        ]);
        $count = $this->dao->count($where);
        if ($list) {
            $supplierIds = $storeIds = [];
            foreach ($list as $value) {
                switch ($value['type']) {
                    case 0:
                        break;
                    case 1://门店
                        $storeIds[] = $value['relation_id'];
                        break;
                    case 2://供应商
                        $supplierIds[] = $value['relation_id'];
                        break;
                }
            }
            $supplierIds = array_unique($supplierIds);
            $storeIds = array_unique($storeIds);
            $supplierList = $storeList = [];
            if ($supplierIds) {
                /** @var SystemSupplierServices $supplierServices */
                $supplierServices = app()->make(SystemSupplierServices::class);
                $supplierList = $supplierServices->getColumn([['id', 'in', $supplierIds], ['is_del', '=', 0]], 'id,supplier_name', 'id');
            }
            if ($storeIds) {
                /** @var SystemStoreServices $storeServices */
                $storeServices = app()->make(SystemStoreServices::class);
                $storeList = $storeServices->getColumn([['id', 'in', $storeIds], ['is_del', '=', 0]], 'id,name', 'id');
            }
            $systemPayType = PayServices::PAY_TYPE;
            foreach ($list as &$item) {
                $cartInfo = $item['cartInfo'] ?? [];
                $cartInfo = is_string($cartInfo['cart_info']) ? json_decode($cartInfo['cart_info'], true) : $cartInfo['cart_info'];
                $item['productInfo'] = $cartInfo['productInfo'] ?? [];
                $orderInfo = $item['orderInfo'] ?? [];
                $item['order_id'] = $orderInfo['order_id'] ?? '';
                $item['pay_type'] = $orderInfo['pay_type'] ?? '';
                $item['pay_type_name'] = $systemPayType[$item['pay_type']] ?? '其他支付';
                $item['plate_name'] = '平台';
                switch ($item['type']) {
                    case 0:
                        $item['plate_name'] = '平台';
                        break;
                    case 1://门店
                        $item['plate_name'] = '门店：' . ($storeList[$item['relation_id']]['name'] ?? '');
                        break;
                    case 2://供应商
                        $item['plate_name'] = '供应商：' . ($supplierList[$item['relation_id']]['supplier_name'] ?? '');
                        break;
                }
            }
        }

        return compact('list', 'count');
    }

    /**
     * 取「本次实际核销商品行快照」（核销主事务须在改变权益状态前调用）。
     *
     * 口径与 WriteOffOrderServices::writeoffOrder 的实际核销完全一致：
     * - 仅取未核销行 is_writeoff=0；
     * - 卡项(type=11)：项目权益行固定 cart_type=2（整卡/部分一致；卡项头 product_type=5、项目行 product_type=6）；
     * - 普通/几选几/赠送/补单：走 DAO 默认 cart_type IN(0,1,3)；
     * - 部分核销再限定 cart_id ∈ 本次选中。
     *
     * 同一份返回结果既用于「是否含项目」检测，也直接传入 saveWriteOff 写核销记录+院装扣料，
     * 禁止在权益状态变更后重查，从而杜绝「整卡核销落入默认 cart_type 导致不扣院装耗材」及
     * 「后置重查选行漂移造成重复扣料」。
     *
     * @param array $orderInfo 需含 id、type
     * @param array $cartIds 本次核销明细（[['cart_id'=>..,'cart_num'=>..],...]），空表示整单核销
     */
    public function getWriteoffCartRows(array $orderInfo, array $cartIds, string $field = '*', string $key = 'cart_id')
    {
        /** @var StoreOrderCartInfoServices $cartInfoServices */
        $cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        return $cartInfoServices->getCartColunm($this->buildWriteoffCartWhere($orderInfo, $cartIds), $field, $key);
    }

    /**
     * 构造「本次实际核销行」查询条件（快照唯一真源）。
     */
    protected function buildWriteoffCartWhere(array $orderInfo, array $cartIds): array
    {
        $where = [
            'oid' => (int)($orderInfo['id'] ?? 0),
            'is_writeoff' => 0,
        ];
        // 卡项：项目权益行固定 cart_type=2；整卡不再用 is_card='' 落到默认 cart_type IN(0,1,3)
        if ((int)($orderInfo['type'] ?? 0) == 11) {
            $where['cart_type'] = 2;
        }
        if ($cartIds) {
            $where['cart_id'] = array_values(array_unique(array_column($cartIds, 'cart_id')));
        }
        return $where;
    }

    /**
     * 判断给定「本次实际核销行快照」是否包含项目(product_type=6)。
     *
     * 由核销主事务在改权益前用同一快照判断，据此决定 salon_sync 与同步扣料，
     * 覆盖卡项(头5/行6)、几选几、赠送项目、补单。
     */
    public function snapshotContainsProject(array $cartSnapshot): bool
    {
        foreach ($cartSnapshot as $row) {
            if ((int)($row['product_type'] ?? 0) === 6) {
                return true;
            }
        }
        return false;
    }

    /**
     * 保存核销记录
     * @param int $oid
     * @param array $cartIds
     * @param array $data
     * @param array $orderInfo
     * @param array $cartInfo
     * @return bool
     */
    public function saveWriteOff(int $oid, int $reservation_oid = 0, array $cartIds = [], array $data = [], array $orderInfo = [], array $cartInfo = [])
    {
        if (!$oid) {
            throw new ValidateException('缺少核销订单信息');
        }
        if (!$orderInfo) {
            /** @var StoreOrderServices $storeOrderServices */
            $storeOrderServices = app()->make(StoreOrderServices::class);
            $orderInfo = $storeOrderServices->get($oid);
        }
        if (!$orderInfo) {
            throw new ValidateException('核销订单不存在');
        }
        $onePrice=0;
        $productId=StoreOrderCartInfo::where("oid",$oid)->where("cart_type",0)->value("product_id");
        if(!empty($productId)){
            $productInfo=StoreProduct::where("id",$productId)->find();
            if($productInfo['card_num'] > 0 && $productInfo['card_num_type'] == 1){
                //几选几套餐 按订单付款金额判断单次金额
                $onePrice=bcdiv($orderInfo['pay_price'],$productInfo['card_num'],2);
            }
        }
        $orderInfo = is_object($orderInfo) ? $orderInfo->toArray() : $orderInfo;
        $isBudan = $data['is_budan'] ?? 0;
        $isAuto = $data['is_auto'] ?? 0;
        $budan_time = $data['budan_time'] ?? '';
        $addTime = time();
        if ($isBudan == 1 && !empty($budan_time)) {
            $addTime = strtotime($budan_time);
        } else {
            $isBudan = 0;
        }
        $reservationOrderInfo = [];
        if ($reservation_oid) {//核销的预约单
            /** @var StoreReservationOrderServices $reservationOrderServices */
            $reservationOrderServices = app()->make(StoreReservationOrderServices::class);
            $reservationOrderInfo = $reservationOrderServices->get($reservation_oid);
            if (!$reservationOrderInfo) {
                throw new ValidateException('核销预约单不存在');
            }
            $reservationOrderInfo = $reservationOrderInfo->toArray();
            $reservationStoreId = (int)($reservationOrderInfo['store_id'] ?? 0);
            if ($reservationStoreId > 0) {
                $data['store_id'] = $reservationStoreId;
            }
            $writeOffInfo = $this->dao->get(['oid' => $oid, 'reservation_oid' => $reservation_oid], ['id']);
            if ($writeOffInfo) {//预约单已生成核销记录
                $this->ensureWriteoffSubOrder(
                    (int)$writeOffInfo['id'],
                    $oid,
                    is_array($writeOffInfo) ? $writeOffInfo : $writeOffInfo->toArray(),
                    $data,
                    $isAuto,
                    $isBudan,
                    $addTime
                );
                return true;
            }
        }
        // cart_id 可能是 string/number，无论是否外部传入快照都先归一化为 string→明细 映射，
        // 保证下方 $cartIds[(string)$cart['cart_id']] 能匹配到本次核销数量/服务对象
        if ($cartIds) {
            $cartIdMap = [];
            foreach ($cartIds as $ci) {
                if (!isset($ci['cart_id'])) continue;
                $cartIdMap[(string)$ci['cart_id']] = $ci;
            }
            $cartIds = $cartIdMap;
        }
        // $cartInfo 为空时才回表：异步 OrderWriteoffJob / 预约核销在权益变更后执行，须按原口径重查
        // （不加 is_writeoff 过滤，否则查不到已置位的本次行）；
        // 含项目的核销主事务由 WriteOffOrderServices 在改权益前取「本次实际核销行快照」并直接传入，禁止此处后置重查
        if (!$cartInfo) {
            $where = [];
            /** @var StoreOrderCartInfoServices $cartInfoServices */
            $cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
            if ($cartIds) {//商城存在部分核销
                if ((int)$orderInfo['type'] == 11) {
                    $where['cart_type'] = 2;
                }
                $cartInfo = $cartInfoServices->getCartColunm(['oid' => $orderInfo['id'], 'cart_id' => array_keys($cartIds)] + $where, '*', 'cart_id');
            } else {//整单核销
                if ((int)$orderInfo['type'] == 11) {
                    $where['is_card'] = '';
                }
                $cartInfo = $cartInfoServices->getCartColunm(['oid' => $orderInfo['id']] + $where, '*', 'cart_id');
            }
        }

        $setYejiAll=$data['sync_all'] ?? [];
        // 核销记录 + 院装扣料同事务：任一步失败整体回滚，保证与核销权益一致；院装耗材不足由 consumeForWriteoff 抛出阻断核销
        $this->transaction(function () use ($cartInfo, $cartIds, $orderInfo, $oid, $reservation_oid, $reservationOrderInfo, $data, $onePrice, $addTime, $isBudan, $isAuto, $setYejiAll) {
        $writeOffData = ['uid' => $orderInfo['uid'], 'oid' => $oid, 'reservation_oid' => $reservation_oid, 'writeoff_code' => $reservationOrderInfo['verify_code'] ?? $orderInfo['verify_code'], 'add_time' => time()];
        foreach ($cartInfo as $cart) {
            $write = $cartIds[(string)$cart['cart_id']] ?? [];
            if (!$cartIds || $write) {
                $writeOffData['order_cart_id'] = $cart['id'];
                $writeOffData['writeoff_num'] = $write['cart_num'] ?? $cart['cart_num'];
                $lineSo = trim((string)($write['service_object'] ?? ''));
                $orderSo = trim((string)($orderInfo['service_object'] ?? ''));
                $writeOffData['service_object'] = ($lineSo === '朋友' || $orderSo === '朋友') ? '朋友' : '本人';
                $writeOffData['type'] = $cart['type'];
                if ($reservation_oid > 0 && !empty($reservationOrderInfo['store_id'])) {
                    $writeOffData['relation_id'] = (int)$reservationOrderInfo['store_id'];
                } else {
                    $writeOffData['relation_id'] = $data['store_id'] ?? $cart['relation_id'] ?? 0;
                }
                $writeOffData['product_id'] = $cart['product_id'];
                $writeOffData['product_type'] = $cart['product_type'];
                if($onePrice > 0){
                    $unit_price=$onePrice;
                }else{
                    $unit_price = bcdiv((string)$cart['pay_price'], (string)$cart['write_times'], 2);
                }
				$writeOffData['writeoff_price'] = (float)bcmul((string)$unit_price, (string)$writeOffData['writeoff_num'], 2);
                $writeOffData['staff_id'] = $data['staff_id'] ?? 0;
                $writeOffData['service_type'] = $data['service_type'] ?? 0;
                $writeOffData['add_time']=$addTime;
                $res = $this->dao->save($writeOffData);
                event('notice.notice', [$writeOffData, 'order_writeoff']);
                $id = $res->id;
                // 院装耗材同事务扣料：仅项目(product_type=6)且实际核销门店>0；幂等锚定核销记录ID
                if ((int)($writeOffData['product_type'] ?? 0) === 6) {
                    /** @var \app\services\product\inventory\SalonStockWriteoffServices $salonWriteoffServices */
                    $salonWriteoffServices = app()->make(\app\services\product\inventory\SalonStockWriteoffServices::class);
                    $salonWriteoffServices->consumeForWriteoff(
                        (int)$id,
                        (int)($writeOffData['relation_id'] ?? 0),
                        (int)($cart['product_id'] ?? 0),
                        (string)($cart['sku_unique'] ?? ''),
                        (string)($writeOffData['writeoff_num'] ?? 0)
                    );
                }
//                $writeOffDataAll[] = $writeOffData;
                $syncHandled = false;
                if($setYejiAll && !empty($setYejiAll)){
                    foreach ($setYejiAll as $k=>$v){
                        $syncCartId = (string)($v['cart_id'] ?? '');
                        if ($syncCartId === '0') {
                            $syncCartId = '';
                        }
                        $lineCartId = (string)($cart['cart_id'] ?? '');
                        if ($syncCartId === '' || $syncCartId === $lineCartId) {
                            $staffYeji = app()->make(SatffYejiServices::class);
                            $v['link_id'] = $id;
                            $v['order_id'] = $oid;
                            $v['is_budan']=$isBudan;
                            $v['add_time']=$addTime;
                            $v['is_auto']=$isAuto;
                            $v['service_object'] = $writeOffData['service_object'] ?? '本人';
                            if (empty($v['cart_id']) || (string)$v['cart_id'] === '0') {
                                $v['cart_id'] = $cart['cart_id'];
                            }
                            if (empty($v['goods_id'])) {
                                $v['goods_id'] = (int)($cart['product_id'] ?? 0);
                            }
                            if (empty($v['type'])) {
                                $v['type'] = 3;
                            }
                            $staffYeji->saveYeji($v);
                            $syncHandled = true;
                        }
                    }
                }
                if (!$syncHandled) {
                    $staffYeji = app()->make(SatffYejiServices::class);
                     $addOrder=[
                         'order_id'=>$oid,
                         'link_id'=>$id,
                         'is_auto'=>$isAuto,
                         'is_budan'=>$isBudan,
                         'add_time'=>$addTime,
                         'price'=>$writeOffData['writeoff_price'],
                         'store_id' => (int)($data['store_id'] ?? ($orderInfo['store_id'] ?? 0)),
                         'service_object' => $writeOffData['service_object'] ?? '本人'
                     ];
                    $staffYeji->saveOrder($addOrder);
                }
                if ($reservation_oid > 0) {
                    $this->ensureWriteoffSubOrder($id, $oid, $writeOffData, $data, $isAuto, $isBudan, $addTime);
                }
            }
        }
        });
        //店员核销给店员写入业绩
        if (isset($data['staff_id']) && $data['staff_id'] && isset($data['price']) && $data['price']) {
            $orderInfo['staff_id'] = $data['staff_id'];
            StaffFinanceJob::dispatch([$orderInfo, 6, $data['price']]);
        }
        if (isset($data['price']) && $data['price']) {
            if(isset($data['staff_id']) && $data['staff_id']) {
                $orderInfo['staff_id'] = $data['staff_id'];
            }
            if(isset($data['store_id']) && $data['store_id']) {
                $orderInfo['store_id'] = $data['store_id'];
            }
            //分配门店账单流水
            StoreFinanceJob::dispatch([$orderInfo, 8, $data['price']]);
        }
        return true;
    }

    /**
     * 确保生成 order_type=2 的核销子订单（与消耗列表 saveOrder 一致）
     */
    public function ensureWriteoffSubOrder(
        int $writeoffId,
        int $oid,
        array $writeOffRow = [],
        array $data = [],
        int $isAuto = 0,
        int $isBudan = 0,
        int $addTime = 0
    ): void {
        if (!$writeoffId || !$oid) {
            return;
        }
        if (!$writeOffRow || empty($writeOffRow['writeoff_price'])) {
            $writeOffModel = StoreOrderWriteoff::where('id', $writeoffId)->find();
            $writeOffRow = $writeOffModel ? (is_array($writeOffModel) ? $writeOffModel : $writeOffModel->toArray()) : [];
        }
        $storeId = $this->resolveReservationWriteoffStoreId($writeOffRow, $data, $oid);
        $existsOrder = StoreOrder::where('order_type', 2)
            ->where('link_id', $writeoffId)
            ->where('is_del', 0)
            ->find();
        if ($existsOrder) {
            $existsArr = is_array($existsOrder) ? $existsOrder : $existsOrder->toArray();
            if ((int)($writeOffRow['reservation_oid'] ?? 0) > 0 && $storeId > 0 && (int)$existsArr['store_id'] !== $storeId) {
                /** @var StoreOrderCreateServices $orderCreateServices */
                $orderCreateServices = app()->make(StoreOrderCreateServices::class);
                StoreOrder::where('id', (int)$existsArr['id'])->update([
                    'store_id' => $storeId,
                    'kua_store' => $orderCreateServices->isKuadianhx($storeId, (int)($existsArr['link_order'] ?? $oid)),
                ]);
            }
            return;
        }
        $price = (float)($writeOffRow['writeoff_price'] ?? ($data['price'] ?? 0));
        if ($price <= 0 && !empty($writeOffRow['order_cart_id'])) {
            $cart = StoreOrderCartInfo::where('id', (int)$writeOffRow['order_cart_id'])->find();
            if ($cart) {
                $cart = is_array($cart) ? $cart : $cart->toArray();
                $times = max((int)($cart['write_times'] ?? 1), 1);
                $num = max((int)($writeOffRow['writeoff_num'] ?? 1), 1);
                $price = (float)bcmul(bcdiv((string)($cart['pay_price'] ?? 0), (string)$times, 2), (string)$num, 2);
            }
        }
        /** @var SatffYejiServices $staffYeji */
        $staffYeji = app()->make(SatffYejiServices::class);
        $staffYeji->saveOrder([
            'order_id' => $oid,
            'link_id' => $writeoffId,
            'price' => $price,
            'store_id' => $storeId,
            'is_auto' => $isAuto,
            'is_budan' => $isBudan,
            'add_time' => $addTime ?: (int)($writeOffRow['add_time'] ?? time()),
            'service_object' => $writeOffRow['service_object'] ?? ($data['service_object'] ?? '本人'),
        ]);
    }

    /**
     * 预约消耗核销：门店以预约单 store_id 为准
     */
    protected function resolveReservationWriteoffStoreId(array $writeOffRow, array $data = [], int $oid = 0): int
    {
        $reservationOid = (int)($writeOffRow['reservation_oid'] ?? 0);
        if ($reservationOid > 0) {
            $reservationStoreId = (int)StoreReservationOrder::where('id', $reservationOid)->value('store_id');
            if ($reservationStoreId > 0) {
                return $reservationStoreId;
            }
        }
        $storeId = (int)($data['store_id'] ?? $writeOffRow['relation_id'] ?? 0);
        if ($storeId > 0) {
            return $storeId;
        }
        if ($oid > 0) {
            return (int)(StoreOrder::where('id', $oid)->value('store_id') ?: 0);
        }
        return (int)(StoreOrder::where('id', (int)($writeOffRow['oid'] ?? 0))->value('store_id') ?: 0);
    }

    /**核销记录
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function userOrderWriteOffRecords(array $where = [], int $product_type = 0)
    {
        [$page, $limit] = $this->getPageValue();
        $count = 0;
        $times = [];
        /** @var StoreServiceServices $serviceServices */
        $serviceServices = app()->make(StoreServiceServices::class);
        /** @var SystemAdminServices $adminServices */
        $adminServices = app()->make(SystemAdminServices::class);
        /** @var DeliveryServiceServices $deliverServiceServices */
        $deliverServiceServices = app()->make(DeliveryServiceServices::class);
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        /** @var SystemStoreStaffServices $storeStaffServices */
        $storeStaffServices = app()->make(SystemStoreStaffServices::class);
        $info = [];
        $with = ['cartInfo'];
        if ($product_type == 4) {
            $where = $where + ['product_type' => 4];
            $with = [];
        }
        $list = $this->dao->getList($where, '*', $page, $limit,$with);
        if($product_type != 4) {
            $count = $this->dao->count($where);
        }
        if ($list) {
            foreach ($list as &$item) {
                $item['time_key'] = $item['time'] = $item['add_time'] ? date('Y-m-d H:i', (int)$item['add_time']) : '';
                $item['add_time'] = $item['add_time'] ? date('Y-m-d H:i', (int)$item['add_time']) : '';
                if($product_type != 4) {
                    $value = is_string($item['cartInfo']['cart_info']) ? json_decode($item['cartInfo']['cart_info'], true) : $item['cartInfo']['cart_info'];
                    $value['productInfo']['store_name'] = $value['productInfo']['store_name'] ?? '';
//                    $value['productInfo']['store_name'] = substrUTf8($value['productInfo']['store_name'], 10, 'UTF-8', '');
                    $item['cartInfo'] = $value;
                    switch ($item['service_type']) {
                        case 1:
                            $service = $serviceServices->getOne(['uid' => $item['staff_id'], 'status' => 1, 'is_del' => 0]);
                            $info['name'] = sys_config('site_name');
                            $info['staff'] = $service ? $service['nickname'] : '客服核销';
                            break;
                        case 2:
                            $admin = $adminServices->get($item['staff_id']);
                            $info['name'] = sys_config('site_name');
                            $info['staff'] = $admin ? $admin['account'] : '管理员核销';
                            break;
                        case 3:
                            $deliver = $deliverServiceServices->get($item['staff_id']);
                            $info['name'] = sys_config('site_name');
                            if ($deliver && $deliver['type'] == 1 && $deliver['relation_id']) {
                                $store = $storeServices->get($deliver['relation_id']);
                                $info['name'] = $store ? $store['name'] : sys_config('site_name');
                                $item['relation_id'] = $deliver['relation_id'];
                            }
                            $info['staff'] = $deliver ? $deliver['nickname'] : '客服核销';
                            break;
                        default:
                            $store = $storeServices->get($item['relation_id']);
                            $staffInfo = $storeStaffServices->getOne(['id' => $item['staff_id'], 'is_del' => 0]);
                            $info['name'] = $store ? $store['name'] : sys_config('site_name');
                            $info['staff'] = $staffInfo ? $staffInfo['staff_name'] : '客服核销';
                    }
                    $item['storeInfo'] = $info;
                }
            }
            if($product_type != 4) {
                $times = array_merge(array_unique(array_column($list, 'time_key')));
            }
        }
        return ['count' => $count, 'list' => $list, 'time' => $times];
    }

    /**
     * 订单详情获取核销记录
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOrderWriteOffRecords(array $where = [])
    {
        $list = $this->dao->getList($where, '*', 0, 0, ['cartInfo']);
        if ($list) {
            /** @var StoreOrderCartInfoServices $cartInfoServices */
            $cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
            /** @var StoreServiceServices $serviceServices */
            $serviceServices = app()->make(StoreServiceServices::class);
            /** @var SystemAdminServices $adminServices */
            $adminServices = app()->make(SystemAdminServices::class);
            /** @var DeliveryServiceServices $deliverServiceServices */
            $deliverServiceServices = app()->make(DeliveryServiceServices::class);
            /** @var SystemStoreServices $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            /** @var SystemStoreStaffServices $storeStaffServices */
            $storeStaffServices = app()->make(SystemStoreStaffServices::class);
            foreach ($list as &$item) {
                $staffs=StaffYeji::where("order_id",$item['oid'])
                    ->where("goods_id",$item['product_id'])
                    ->where("link_id",$item['id'])
                    ->where("type",3)
                    ->select();
                $staffsAttr=[];
                foreach ($staffs as $staffOne){
                    $dian="轮";
                    if($staffOne['is_dian'] == 1){
                        $dian="点";
                    }
                   $staffsAttr[]=$staffOne['staff_name']."($dian)";
                }
                $item['store_id']=$item['relation_id'];
                $item['yeji_staff']=implode(",",$staffsAttr);
                $item['add_time'] = $item['add_time'] ? date('Y-m-d H:i', (int)$item['add_time']) : '';
				$value = [];
				if (isset($item['cartInfo']['cart_info'])) {
					$value = is_string($item['cartInfo']['cart_info']) ? json_decode($item['cartInfo']['cart_info'], true) : $item['cartInfo']['cart_info'];
				}
                $item['store_name'] = $value['productInfo']['store_name'] ?? '';
//                $item['store_name'] = substrUTf8($store_name, 10, 'UTF-8', '');
                $item['write_surplus_times'] = $cartInfoServices->value(['id' => $item['order_cart_id'], 'oid' => $item['oid'], 'uid' => $item['uid']], 'write_surplus_times');
                $item['image'] = $value['productInfo']['image'] ?? '';
                switch ($item['service_type']) {
                    case 1:
                        $service = $serviceServices->getOne(['uid' => $item['staff_id'], 'status' => 1, 'is_del' => 0]);
                        $item['name'] = sys_config('site_name');
                        $item['staff_name'] = $service ? $service['nickname'] : '客服核销';
                        break;
                    case 2:
                        $admin = $adminServices->get($item['staff_id']);
                        $item['name'] = sys_config('site_name');
                        $item['staff_name'] = $admin ? $admin['account'] : '管理员核销';
                        break;
                    case 3:
                        $deliver = $deliverServiceServices->get($item['staff_id']);
                        $item['name'] = sys_config('site_name');
                        if ($deliver && $deliver['type'] == 1 && $deliver['relation_id']) {
                            $store = $storeServices->get($deliver['relation_id']);
                            $item['name'] = $store ? $store['name'] : sys_config('site_name');
                        }
                        $item['staff_name'] = $deliver ? $deliver['nickname'] : '客服核销';
                        break;
                    default:
                        $store = $storeServices->get($item['relation_id']);
                        $staffInfo = $storeStaffServices->getOne(['id' => $item['staff_id'], 'is_del' => 0]);
                        $item['name'] = $store ? $store['name'] : sys_config('site_name');
                        $item['staff_name'] = $staffInfo ? $staffInfo['staff_name'] : '客服核销';
                }
                unset($item['cartInfo']);
            }
        }
        return $list;
    }

    /**
     * 核销记录
     * @param int $relation_id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getAllWriteOffRecords(array $where,$limit=0)
    {
        if ($limit) {
            [$page] = $this->getPageValue();
        } else {
            [$page, $limit] = $this->getPageValue();
        }
        $list = $this->dao->getList($where, '*', $page, $limit, ['orderInfo']);
        $count = $this->dao->count($where);
        if ($list) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            /** @var StoreServiceServices $serviceServices */
            $serviceServices = app()->make(StoreServiceServices::class);
            /** @var SystemAdminServices $adminServices */
            $adminServices = app()->make(SystemAdminServices::class);
            /** @var DeliveryServiceServices $deliverServiceServices */
            $deliverServiceServices = app()->make(DeliveryServiceServices::class);
            /** @var SystemStoreServices $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            /** @var SystemStoreStaffServices $storeStaffServices */
            $storeStaffServices = app()->make(SystemStoreStaffServices::class);
            foreach ($list as &$item) {
                //手艺人
                $yeji=StaffYeji::where("type",3)->where("link_id",$item['id'])->select();
                $staffName=[];
                foreach ($yeji as $nk=>$v){
                    $dian="点";
                    if($v['is_dian'] != 1){
                        $dian="轮";
                    }
                    $staffName[]=$v['staff_name']."($dian)";
                }
                $cateIds=StoreProductRelation::where("type",1)
                    ->where("product_id",$item['product_id'])->column("relation_id");
                $cateNames=StoreProductCategory::whereIn("id",$cateIds)->column("cate_name");
                $item['cate_name']=implode(",",$cateNames);
                $item['yeji_staff']=implode(",",$staffName);
                $item['product_name']=StoreProduct::where("id",$item['product_id'])->value("store_name");
                $item['add_time'] = $item['add_time'] ? date('Y-m-d H:i', (int)$item['add_time']) : '';
                $orderInfo = $item['orderInfo'];
                unset($item['orderInfo']);
                // 列表/导出展示的订单号：核销时生成的子单号（order_type=2），与筛选栏语义一致
                $hxOrderNo = StoreOrder::where('order_type', 2)
                    ->where('link_id', (int)$item['id'])
                    ->order('id', 'desc')
                    ->value('order_id');
                $item['order_id'] = !empty($hxOrderNo) ? $hxOrderNo : ($orderInfo['order_id'] ?? '');
                $item['store_id'] = $orderInfo['store_id'] ?? '';
                $item['ordering_store'] = '平台';
                if (isset($orderInfo['store_id']) && $orderInfo['store_id']) {
                    $item['ordering_store'] = $storeServices->value(['id' => $orderInfo['store_id']],'name') ?? '门店不存在';
                }
                $item['user'] = [];
                $item['phone']='';
                $item['real_name']='';
                if($item['uid']) {
                    $item['user'] = $userServices->get(['uid'=>$item['uid']],['nickname','avatar','delete_time','real_name','phone']);
                    $item['phone']=$item['user']['phone'] ?? '';
                    $item['real_name']=$item['user']['real_name'] ?? '';
                }
                switch ($item['service_type']) {
                    case 1:
                        $service = $serviceServices->getOne(['uid' => $item['staff_id'], 'status' => 1]);
                        $item['write_off_store'] = '管理平台';
                        $item['staff_name'] = $service ? $service['nickname'] : '客服核销';
                        break;
                    case 2:
                        $admin = $adminServices->get($item['staff_id']);
                        $item['write_off_store'] = '管理平台';
                        $item['staff_name'] = $admin ? $admin['account'] : '管理员核销';
                        break;
                    case 3:
                        $deliver = $deliverServiceServices->get($item['staff_id']);
                        $item['write_off_store'] = '管理平台';
                        if ($deliver && $deliver['type'] == 1 && $deliver['relation_id']) {
                            $store = $storeServices->get($deliver['relation_id']);
                            $item['write_off_store'] = $store ? $store['name'] : sys_config('site_name');
                        }
                        $item['staff_name'] = $deliver ? $deliver['nickname'] : '配送员核销';
                        break;
                    default:
                        $store = $storeServices->get($item['relation_id']);
                        $staffInfo = $storeStaffServices->getOne(['id' => $item['staff_id']]);
                        $item['write_off_store'] = $store ? $store['name'] : sys_config('site_name');
                        $item['staff_name'] = $staffInfo ? $staffInfo['staff_name'] : '客服核销';
                }
            }
        }
        return ['count' => $count, 'list' => $list];
    }
}
