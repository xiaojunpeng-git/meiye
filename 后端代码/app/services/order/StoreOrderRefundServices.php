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

use app\dao\order\StoreOrderRefundDao;
use app\jobs\order\OrderStatusJob;
use app\jobs\product\ProductStockJob;
use app\model\order\StoreOrder;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\activity\discounts\StoreDiscountsServices;
use app\services\activity\bargain\StoreBargainServices;
use app\services\activity\combination\StoreCombinationServices;
use app\services\activity\combination\StorePinkServices;
use app\services\activity\integral\StoreIntegralServices;
use app\services\activity\newcomer\StoreNewcomerServices;
use app\services\activity\seckill\StoreSeckillServices;
use app\services\BaseServices;
use app\services\activity\coupon\StoreCouponUserServices;
use app\services\user\UserCardHolderServices;
use app\services\other\ExpressServices;
use app\services\pay\PayServices;
use app\services\product\product\StoreProductServices;
use app\services\product\inventory\ProductInventoryChangeServices;
use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\store\SystemStoreServices;
use app\services\supplier\SystemSupplierServices;
use app\services\user\UserBillServices;
use app\services\user\UserBrokerageServices;
use app\services\user\UserMoneyServices;
use app\services\user\UserServices;
use app\services\wechat\WechatUserServices;
use mohe\services\AliPayService;
use mohe\services\CacheService;
use mohe\services\FormBuilder as Form;
use mohe\services\wechat\Payment;
use mohe\traits\OptionTrait;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 订单退款
 * Class StoreOrderRefundServices
 * @package app\services\order
 * @mixin StoreOrderRefundDao
 */
class StoreOrderRefundServices extends BaseServices
{
    use OptionTrait, ServicesTrait;

    /**
     * 退款方式
     * @var array|string[]
     */
    protected $refundPriceType = [
        PayServices::WEIXIN_PAY => '原微信返还',
        PayServices::YUE_PAY => '余额账户返还',
        PayServices::OFFLINE_PAY => '线下返还',
        PayServices::ALIAPY_PAY => '原支付宝返还',
        PayServices::CASH_PAY => '现金返还',
    ];

    /**
     * 订单services
     * @var StoreOrderServices
     */
    protected $storeOrderServices;

    /**
     * 构造方法
     * StoreOrderRefundServices constructor.
     * @param StoreOrderRefundDao $dao
     * @param StoreOrderServices $storeOrderServices
     */
    public function __construct(StoreOrderRefundDao $dao, StoreOrderServices $storeOrderServices)
    {
        $this->dao = $dao;
        $this->storeOrderServices = $storeOrderServices;
    }

	/**
	 * 获取退款原因
	 * @return false|string[]
	 */
	public function getRefundReason()
	{
		$reason = sys_config('stor_reason') ?: [];//退款理由
		$reason = str_replace("\r\n", "\n", $reason);//防止不兼容
		return explode("\n", $reason);
	}

    /**
     * 退款订单列表
     * @param array $where
     * @param array $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function refundList(array $where, array $with = ['user'])
    {
		if (isset($where['refund_type']) && ($where['refund_type'] == 'all' || $where['refund_type'] == -1)) $where['refund_type'] = '';
		//处理退款原因搜索
		if (isset($where['refund_reason'])) {
			$refundReason = $this->getRefundReason();
			$where['refund_reason'] = $refundReason[$where['refund_reason']] ?? $where['refund_reason'];
		}
        $where['is_cancel'] = 0;
		$where['is_system_del'] = 0;
        $where['store_id'] = $where['store_id'] ?? '';
        $where['supplier_id'] = $where['supplier_id'] ?? '';
        if (isset($where['time']) && $where['time'] != '') {
            $where['time'] = is_string($where['time']) ? explode('-', $where['time']) : $where['time'];
        }
        [$page, $limit] = $this->getPageValue();
        $with = array_merge($with, ['order' => function ($query) {
            $query->field('id,type,shipping_type,store_delivery_type,pay_type,paid,status')
                ->bind(['shipping_type', 'type', 'store_delivery_type', 'pay_type', 'paid', 'status']);
        }]);
        $list = $this->dao->getRefundList($where, '*', $with, $page, $limit);
        $count = $this->dao->count($where);
        if ($list) {
            /** @var StoreOrderTerminalOperationServices $terminal */
            $terminal = app()->make(StoreOrderTerminalOperationServices::class);
            $dateMap = $terminal->mapBusinessAndOperatedByRefundIds(array_column($list, 'id'));
            $siteUrl = sys_config('site_url');
            foreach ($list as &$item) {
                $item['refund'] = [];
                $item['is_all_refund'] = 1;
                $item['paid'] = 1;
                $item['add_time'] = isset($item['add_time']) ? date('Y-m-d H:i', (int)$item['add_time']) : '';
                // 阶段6：退款日期（业务归属）与实际操作时间同时返回
                $dates = $dateMap[(int)$item['id']] ?? null;
                $item['refund_business_date'] = $dates['refund_business_date'] ?? '';
                $item['operated_at'] = $dates['operated_at'] ?? 0;
                $item['operated_at_text'] = $dates['operated_at_text'] ?? '';
                $item['cartInfo'] = $item['cart_info'];
                if (in_array($item['refund_type'], [0, 1, 2, 4, 5])) {
                    $item['refund_status'] = 1;
                } elseif ($item['refund_type'] == 6) {
                    $item['refund_status'] = 2;
                } elseif ($item['refund_type'] == 3) {
                    $item['refund_status'] = 3;
                }
                foreach ($item['cart_info'] as $items) {
                    $item['_info'][]['cart_info'] = $items;
                }
                $item['store_name'] = '';
                if ($item['store_id']) {
                    /** @var SystemStoreServices $systemStoreServices */
                    $systemStoreServices = app()->make(SystemStoreServices::class);
                    $item['store_name'] = $systemStoreServices->value(['id' => $item['store_id']], 'name');
                }
                $item['total_num'] = $item['refund_num'];
                $item['pay_price'] = $item['refund_price'];
                $item['pay_postage'] = 0;
                if (isset($item['shipping_type']) && !in_array($item['shipping_type'], [2, 4])) {
                    $item['pay_postage'] = floatval($this->getOrderSumPrice($item['cart_info'], 'postage_price', false));
                }
                [$type, $title, $status_name, $pic, $desc] = $this->tidyOrderStatus($item);
                [$plat_type, $plat_name] = $this->storeOrderServices->tidayOrderPlatType($item);
                $item['plat_type'] = $plat_type;
                $item['plat_name'] = $plat_name;
                [$pink_name, $color] = $this->storeOrderServices->tidyOrderType($item, true);
                $item['pink_name'] = $pink_name;
                $item['status_name'] = [
                    'pic' => $siteUrl . $pic,
                    'status_name' => $status_name
                ];
                unset($item['cart_info']);
                $item['_status'] = [
                    '_type' => $type,
                    '_title' => $title,
                    'pic' => $siteUrl . $pic,
                    'status_name' => $status_name,
                    'desc' => $desc
                ];
            }
        }
        $data['list'] = $list;
        $data['count'] = $count;

        $supplierId = $where['supplier_id'] ?? 0;
        if ($supplierId) {
            $del_where = ['supplier_id' => $supplierId, 'is_cancel' => 0];
        } else {
            $del_where = ['store_id' => $where['store_id'], 'is_cancel' => 0];
        }
        $data['num'] = [
            'all' => ['name' => '全部', 'num' => $this->dao->count($del_where)],
			0 => ['name' => '未处理', 'num' => $this->dao->count($del_where + ['refund_type' => 0])],
            1 => ['name' => '仅退款', 'num' => $this->dao->count($del_where + ['refund_type' => 1])],
            2 => ['name' => '退货退款', 'num' => $this->dao->count($del_where + ['refund_type' => 2])],
            3 => ['name' => '拒绝退款', 'num' => $this->dao->count($del_where + ['refund_type' => 3])],
            4 => ['name' => '商品待退货', 'num' => $this->dao->count($del_where + ['refund_type' => 4])],
            5 => ['name' => '退货待收货', 'num' => $this->dao->count($del_where + ['refund_type' => 5])],
            6 => ['name' => '已退款', 'num' => $this->dao->count($del_where + ['refund_type' => 6])]
        ];
        return $data;
    }

    /**
     * 前端订单列表
     * @param array $where
     * @param array|string[] $field
     * @param array $with
     * @return mixed
     */
    public function getRefundOrderList(array $where, string $field = '*', array $with = [])
    {
        [$page, $limit] = $this->getPageValue();
        $where['is_cancel'] = 0;
        $where['is_del'] = 0;
        $data = $this->dao->getRefundList($where, $field, $with, $page, $limit);
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        $dateMap = $terminal->mapBusinessAndOperatedByRefundIds(array_column($data, 'id'));
        $siteUrl = sys_config('site_url');
        foreach ($data as &$item) {
            $item['add_time'] = isset($item['add_time']) ? date('Y-m-d H:i', (int)$item['add_time']) : '';
            $dates = $dateMap[(int)$item['id']] ?? null;
            $item['refund_business_date'] = $dates['refund_business_date'] ?? '';
            $item['operated_at'] = $dates['operated_at'] ?? 0;
            $item['operated_at_text'] = $dates['operated_at_text'] ?? '';
            $item['cartInfo'] = $item['cart_info'];
            unset($item['cart_info']);
            [$type, $title, $status_name, $pic, $desc] = $this->tidyOrderStatus($item);
            $item['_status'] = [
                '_type' => $type,
                '_title' => $title,
                'pic' => $siteUrl . $pic,
                'status_name' => $status_name,
                'desc' => $desc
            ];
        }
        return $data;
    }

    /**
     * 处理退款订单状态
     * @param array $refund
     * @return array
     */
    public function tidyOrderStatus($refund)
    {
        $path = '/statics/images/order/';
        if ($refund['is_cancel'] || $refund['is_del']) {
            $type = -1;
            $title = '已撤销';
            $status_name = '用户已撤销';
            $pic = 'refund_cancel_icon.png';
            $desc = '您已撤销售后申请，感谢您对我们的支持！';
        } else {
            if (in_array($refund['refund_type'], [0, 1, 2])) {
                $type = 0;
                $title = '申请中';
                $status_name = '商家审核中';
                $pic = 'refund_verify_icon.png';
                $desc = '退款前请与商家协商一致，有助于更好的处理售后问题，感谢您对我们的支持！';
            } else {
                switch ($refund['refund_type']) {
                    case 3://已拒绝
                        $type = 3;
                        $title = '拒绝退款';
                        $status_name = '商家已拒绝';
                        $pic = 'refund_refuse_icon.png';
                        $desc = '商家已拒绝您的申请，拒绝原因：' . $refund['refuse_reason'];
                        break;
                    case 4://待退货
                        $type = 4;
                        $title = '待退货';
                        $status_name = '商家已同意';
                        $pic = 'refund_success_icon.png';
                        $desc = '商家已确认退货退款，您尽快寄回商品！';
                        break;
                    case 5://退款中
                        $type = 5;
                        $title = '退款中';
                        $status_name = '商家收货中';
                        $pic = 'refund_success_icon.png';
                        $desc = '商家确认收货寄回商品后进行打款，请您耐心等待！';
                        break;
                    case 6://已退款
                        $type = 6;
                        $title = '已退款';
                        $status_name = '已退款完成';
                        $pic = 'refund_success_icon.png';
                        $desc = '商家已为您退款，感谢您对我们的支持！';
                        break;
                    default:
                        $type = 0;
                        $title = '申请中';
                        $status_name = '商家审核中';
                        $pic = 'refund_verify_icon.png';
                        $desc = '退款前请与商家协商一致，有助于更好的处理售后问题，感谢您对我们的支持！';
                        break;
                }
            }
        }
        return [$type, $title, $status_name, $path . $pic, $desc];
    }

    /**
     * 订单申请退款
     * @param int $id
     * @param int $uid
     * @param array $order
     * @param array $cart_ids
     * @param int $apply_type
     * @param float $refundPrice
     * @param array $refundData
     * @return mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    /**
     * 事务内：若订单已有活动中退款申请则抛错（供锁单后调用）。
     */
    public function assertNoActiveRefundApply(int $storeOrderId): void
    {
        if (sys_config('erp_open')) {
            return;
        }
        $active = Db::name('store_order_refund')
            ->where('store_order_id', $storeOrderId)
            ->whereIn('refund_type', [0, 1, 2, 4, 5])
            ->where('is_cancel', 0)
            ->where('is_del', 0)
            ->lock(true)
            ->find();
        if ($active) {
            throw new ValidateException('退款处理中！');
        }
    }

    /**
     * 组装退款申请落库数据（不做事务、不投递副作用）。
     * @return array{order:array,refundData:array,cart_ids:array,cartInfo:array,cartInfos:array}
     */
    public function buildApplyRefundPersistPayload(int $id, int $uid, $order = [], array $cart_ids = [], int $apply_type = 1, float $refundPrice = 0.00, array $refundData = [], int $origin = 0): array
    {
        if (!$order) {
            $order = $this->storeOrderServices->get($id);
        }
        if (!$order) {
            throw new ValidateException('支付订单不存在!');
        }
        $order = is_array($order) ? $order : $order->toArray();
        if (!$this->storeOrderServices->isRefundAvailable($id)) {
            throw new ValidateException('已超过设置售后期限，请联系商家');
        }

        $refund_num = $order['total_num'];
        $refund_price = $order['pay_price'];
        /** @var StoreOrderCartInfoServices $storeOrderCartInfoServices */
        $storeOrderCartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        $cartInfo = [];
        $cartInfos = $storeOrderCartInfoServices->getCartColunm(['oid' => $id], 'id,cart_id,product_type,is_support_refund,cart_num,refund_num,cart_info');
        if ($cart_ids) {
            $cartInfo = array_combine(array_column($cartInfos, 'cart_id'), $cartInfos);
            $refund_num = 0;
            foreach ($cart_ids as $cart) {
                if (!isset($cartInfo[$cart['cart_id']])) throw new ValidateException('该订单中商品不存在，请重新选择!');
                if (!$cartInfo[$cart['cart_id']]['is_support_refund'] && $origin == 0) {
                    throw new ValidateException('该订单中有商品不支持退款，请联系管理员');
                }
                if ($cart['cart_num'] + $cartInfo[$cart['cart_id']]['refund_num'] > $cartInfo[$cart['cart_id']]['cart_num']) {
                    throw new ValidateException('已退款完成，或者在售后订单操作!');
                }
                $refund_num = bcadd((string)$refund_num, (string)$cart['cart_num'], 0);
            }
            $total_num = array_sum(array_column($cart_ids, 'cart_num'));
            if ($total_num < $order['total_num']) {
                /** @var StoreOrderSplitServices $storeOrderSpliteServices */
                $storeOrderSpliteServices = app()->make(StoreOrderSplitServices::class);
                $cartInfos = $storeOrderSpliteServices->getSplitOrderCartInfo($id, $cart_ids, $order);
                $total_price = $pay_postage = 0;
                foreach ($cartInfos as $cart) {
                    $_info = is_string($cart['cart_info']) ? json_decode($cart['cart_info'], true) : $cart['cart_info'];
                    $total_price = bcadd((string)$total_price, (string)$cart['pay_price'], 2);
                    if (!in_array($order['shipping_type'], [2, 4])) {
                        $pay_postage = bcadd((string)$pay_postage, (string)($_info['postage_price'] ?? 0), 2);
                    }
                }
                $refund_price = bcadd((string)$total_price, (string)$pay_postage, 2);
            }
        } else {
            foreach ($cartInfos as $cart) {
                if (!$cart['is_support_refund']) {
                    throw new ValidateException('该订单中有商品不支持退款，请联系管理员');
                }
                if ($cart['refund_num'] > 0) {
                    throw new ValidateException('已退款完成，或者在售后订单操作!');
                }
            }
            // 整单：允许调用方传入与实付一致的金额；否则仍以订单 pay_price 为准
            if ($refundPrice > 0) {
                $refund_price = bcadd((string)$refundPrice, '0', 2);
            }
        }
        foreach ($cartInfos as &$cart) {
            $cart['cart_info'] = is_string($cart['cart_info']) ? json_decode($cart['cart_info'], true) : $cart['cart_info'];
        }
        unset($cart);
        $refundData['uid'] = $uid;
        $refundData['store_id'] = $order['store_id'];
        $refundData['supplier_id'] = $order['supplier_id'];
        $refundData['store_order_id'] = $id;
        $refundData['refund_num'] = $refund_num;
        $refundData['apply_type'] = $apply_type;
        $refundData['refund_price'] = $refund_price;
        $refundData['order_id'] = $this->getUniqueId('re');
        $refundData['add_time'] = time();
        $refundCartInfo = array_column($cartInfos, 'cart_info');
        $refundData['cart_info'] = json_encode($refundCartInfo);
        $settlePrice = 0;
        foreach ($refundCartInfo as $cart) {
            $settle_price = bcmul((string)($cart['productInfo']['attrInfo']['settle_price'] ?? 0), (string)$cart['cart_num'], 2);
            $settlePrice = bcadd((string)$settlePrice, (string)$settle_price, 2);
        }
        $refundData['settle_price'] = $settlePrice;

        return [
            'order' => $order,
            'refundData' => $refundData,
            'cart_ids' => $cart_ids,
            'cartInfo' => $cartInfo,
            'cartInfos' => $cartInfos,
        ];
    }

    /**
     * 事务内纯数据库写入：创建退款申请并更新 cart refund_num。不启事务、不清缓存、不投递 Job/事件。
     */
    public function persistApplyRefundInTransaction(int $id, array $payload): int
    {
        $order = $payload['order'];
        $cart_ids = $payload['cart_ids'];
        $refundData = $payload['refundData'];
        $cartInfo = $payload['cartInfo'];
        $cartInfos = $payload['cartInfos'];
        /** @var StoreOrderCartInfoServices $storeOrderCartInfoServices */
        $storeOrderCartInfoServices = app()->make(StoreOrderCartInfoServices::class);

        $res3 = $this->save($refundData);
        if (!$res3) {
            throw new ValidateException('添加退款申请失败');
        }
        $this->allocateCashierRefundDocumentNoInTx(
            (int)$res3->id,
            (int)$order['store_id'],
            (int)$refundData['add_time']
        );
        $res4 = true;
        if ($cart_ids) {
            foreach ($cart_ids as $cart) {
                $res4 = $res4 && $storeOrderCartInfoServices->update(
                    ['oid' => $id, 'cart_id' => $cart['cart_id']],
                    ['refund_num' => (($cartInfo[$cart['cart_id']]['refund_num'] ?? 0) + $cart['cart_num'])]
                );
            }
        } else {
            foreach ($cartInfos as $cart) {
                $res4 = $res4 && $storeOrderCartInfoServices->update(
                    ['oid' => $id, 'cart_id' => $cart['cart_id']],
                    ['refund_num' => $cart['cart_num']]
                );
            }
        }
        if (!$res4) {
            throw new ValidateException('添加退款申请失败');
        }
        return (int)$res3->id;
    }

    /**
     * Legacy refunds do not carry a tenant column and their order_id remains
     * the historical payment/refund reference. Store the new display number
     * separately, in the same transaction, without rewriting either field.
     */
    private function allocateCashierRefundDocumentNoInTx(int $refundId, int $storeId, int $occurredAt): void
    {
        if ($refundId <= 0 || $storeId <= 0 || $occurredAt <= 0) {
            throw new ValidateException('退款单号资料无效');
        }
        (new CashierV3BusinessDocumentNumberServices())->allocateForSourceInTx(
            '0',
            CashierV3BusinessDocumentNumberServices::REFUND,
            'legacy_store_order_refund',
            (string)$refundId,
            date('Y-m-d', $occurredAt),
            $occurredAt
        );
    }

    /**
     * 事务提交成功后的缓存清理、Job、事件（禁止在外层事务未提交前调用）。
     */
    public function dispatchApplyRefundAfterCommit(array $order, int $refundId, array $refundData, bool $isSync = true): void
    {
        /** @var StoreOrderCartInfoServices $storeOrderCartInfoServices */
        $storeOrderCartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        $storeOrderCartInfoServices->clearOrderCartInfo((int)$order['id']);
        OrderStatusJob::dispatch([
            (int)$order['id'],
            'apply_refund',
            [
                'change_message' => '用户申请退款，原因：' . ($refundData['refund_reason'] ?? '无'),
                'change_manager_type' => 'user',
            ],
        ]);
        event('order.applyRefund', [$order, $refundId, $isSync]);
    }

    public function applyRefund(int $id, int $uid, $order = [], array $cart_ids = [], int $apply_type = 1, float $refundPrice = 0.00, array $refundData = [], int $origin = 0, bool $isSync = true)
    {
        $payload = $this->buildApplyRefundPersistPayload($id, $uid, $order, $cart_ids, $apply_type, $refundPrice, $refundData, $origin);
        $refundId = (int)$this->transaction(function () use ($id, $payload) {
            // 事务内再次校验活动申请，避免并发双写
            $this->assertNoActiveRefundApply($id);
            return $this->persistApplyRefundInTransaction($id, $payload);
        });
        if ($refundId <= 0) {
            throw new ValidateException('添加退款申请失败');
        }
        $this->dispatchApplyRefundAfterCommit($payload['order'], $refundId, $payload['refundData'], $isSync);
        return $refundId;
    }

	/**
	 * 再次申请退款
	 * @param int $uid
	 * @param string $order_id
	 * @return bool|\think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function againRefundOrder(int $uid, int $id)
	{
		$orderRefund = $this->dao->get(['id' => $id, 'is_cancel' => 0, 'is_del' => 0]);
		if (!$orderRefund || $orderRefund['uid'] != $uid) {
			throw new ValidateException('订单不存在');
		}
		$refundData = [
			'refund_reason' => $orderRefund['refund_reason'],
			'refund_explain' => $orderRefund['refund_explain'],
			'refund_img' => $orderRefund['refund_img'] ? (is_array($orderRefund['refund_img']) ? json_encode($orderRefund['refund_img']) : $orderRefund) : '',
		];
		$cart_ids = [];
		if ($orderRefund['cart_info']) {
			$cart_info = is_string($orderRefund['cart_info']) ? json_decode($orderRefund['cart_info'], true) : $orderRefund['cart_info'];
			foreach ($cart_info as $item) {
				$cart_ids[] = ['cart_id' => $item['id'], 'cart_num' => $item['cart_num']];
			}
		}
		$applyPrice = (float)$orderRefund['apply_price'];
		//再次申请
		$this->applyRefund((int)$orderRefund['store_order_id'], $uid, [], $cart_ids, (int)$orderRefund['apply_type'], $applyPrice, $refundData);
		return true;
	}

    /**后台操作退款
     * @param int $id
     * @param $order
     * @param array $cart_ids
     * @param int $refundType
     * @param float $refundedPrice
     * @param array $refundData
     * @param int $origin
     * @param bool $isSync
     * @return mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function splitApplyRefund(int $id, $order = [], array $cart_ids = [], int $refundType = 0, float $refundedPrice = 0.00, array $refundData = [], int $origin = 0, bool $isSync = true)
    {
        if (!$order) {
            $order = $this->storeOrderServices->get($id);
        }
        if (!$order) {
            throw new ValidateException('支付订单不存在!');
        }
        if (!sys_config('erp_open')) {
            $is_now = $this->dao->getCount([
                ['store_order_id', '=', $id],
                ['refund_type', 'in', [0, 1, 2, 4, 5]],
                ['is_cancel', '=', 0],
                ['is_del', '=', 0]
            ]);
            if ($is_now) throw new ValidateException('退款处理中！');
        }

        $refund_num = $order['total_num'];
        $refund_price = $order['pay_price'];
        /** @var StoreOrderCartInfoServices $storeOrderCartInfoServices */
        $storeOrderCartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        //退部分
        $cartInfo = [];
        $cartInfos = $storeOrderCartInfoServices->getCartColunm(['oid' => $id], 'id,cart_id,product_type,is_support_refund,cart_num,refund_num,cart_info');
        if ($cart_ids) {
            $cartInfo = array_combine(array_column($cartInfos, 'cart_id'), $cartInfos);
            $refund_num = 0;
            foreach ($cart_ids as $cart) {
                if (!isset($cartInfo[$cart['cart_id']])) throw new ValidateException('该订单中商品不存在，请重新选择!');
                if ($cart['cart_num'] + $cartInfo[$cart['cart_id']]['refund_num'] > $cartInfo[$cart['cart_id']]['cart_num']) {
                    throw new ValidateException('已退款完成，或者在售后订单操作!');
                }
                $refund_num = bcadd((string)$refund_num, (string)$cart['cart_num'], 0);
            }
            //总共申请多少件
            $total_num = array_sum(array_column($cart_ids, 'cart_num'));
            if ($total_num < $order['total_num']) {
                /** @var StoreOrderSplitServices $storeOrderSpliteServices */
                $storeOrderSpliteServices = app()->make(StoreOrderSplitServices::class);
                $cartInfos = $storeOrderSpliteServices->getSplitOrderCartInfo($id, $cart_ids, $order);
                $total_price = $pay_postage = 0;
                foreach ($cartInfos as $cart) {
                    $_info = is_string($cart['cart_info']) ? json_decode($cart['cart_info'], true) : $cart['cart_info'];
                    $total_price = bcadd((string)$total_price, bcmul((string)($_info['truePrice'] ?? 0), (string)$cart['cart_num'], 4), 2);
                    if (!in_array($order['shipping_type'], [2, 4])) {
                        $pay_postage = bcadd((string)$pay_postage, (string)($_info['postage_price'] ?? 0), 2);
                    }
                }
                //实际退款金额
                $refund_pay_price = bcadd((string)$total_price, (string)$pay_postage, 2);
                $refund_price = $refund_pay_price;
            }
        } else {//整单退款
            foreach ($cartInfos as $cart) {
                if ($cart['refund_num'] > 0) {
                    throw new ValidateException('已退款完成，或者在售后订单操作!');
                }
            }
        }
        foreach ($cartInfos as &$cart) {
            $cart['cart_info'] = is_string($cart['cart_info']) ? json_decode($cart['cart_info'], true) : $cart['cart_info'];
        }
        $refundData['uid'] = $order['uid'];
        $refundData['store_id'] = $order['store_id'];
        $refundData['supplier_id'] = $order['supplier_id'];
        $refundData['store_order_id'] = $id;
        $refundData['refund_num'] = $refund_num;
        $refundData['refund_type'] = $refundType;
        $refundData['refund_price'] = $refund_price;
		$refundData['refunded_price'] = $refundedPrice;
        $refundData['order_id'] = $this->getUniqueId('re');
        $refundData['add_time'] = time();
		$refundCartInfo = array_column($cartInfos, 'cart_info');
		$refundData['cart_info'] = json_encode($refundCartInfo);
		$settlePrice = 0;
		if ($order['supplier_id']) {//供应商订单
			unset($cart);
			foreach ($refundCartInfo as $cart) {
				$settle_price = bcmul((string)($cart['productInfo']['attrInfo']['settle_price'] ?? 0), (string)$cart['cart_num'], 2);
				$settlePrice = bcadd((string)$settlePrice, (string)$settle_price, 2);
			}
		}
		$refundData['settle_price'] = $settlePrice;
        $refundId = $this->transaction(function () use ($id, $order, $cart_ids, $refundData, $storeOrderCartInfoServices, $cartInfo, $cartInfos) {
            $res1 = true;
            $res2 = true;
            //添加退款数据
            /** @var StoreOrderRefundServices $storeOrderRefundServices */
            $storeOrderRefundServices = app()->make(StoreOrderRefundServices::class);
            $res3 = $storeOrderRefundServices->save($refundData);
            if (!$res3) {
                throw new ValidateException('添加退款申请失败');
            }
            $storeOrderRefundServices->allocateCashierRefundDocumentNoInTx(
                (int)$res3->id,
                (int)$order['store_id'],
                (int)$refundData['add_time']
            );
            $res4 = true;
            if ($cart_ids) {
                //修改订单商品退款信息
                foreach ($cart_ids as $cart) {
                    $res4 = $res4 && $storeOrderCartInfoServices->update(['oid' => $id, 'cart_id' => $cart['cart_id']], ['refund_num' => (($cartInfo[$cart['cart_id']]['refund_num'] ?? 0) + $cart['cart_num'])]);
                }
            } else {//整单退款
                //修改原订单状态
                foreach ($cartInfos as $cart) {
                    $res4 = $res4 && $storeOrderCartInfoServices->update(['oid' => $id, 'cart_id' => $cart['cart_id']], ['refund_num' => $cart['cart_num']]);
                }
            }
            if ($res1 && $res2 && $res3 && $res4) {
                return (int)$res3->id;
            } else {
                return false;
            }
        });
        $storeOrderCartInfoServices->clearOrderCartInfo($order['id']);
        return $refundId;
    }

    /**
     * 拒绝退款
     * @param int $id
     * @param array $data
     * @param array $orderRefundInfo
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function refuseRefund(int $id, array $data, $orderRefundInfo = [])
    {
        if (!$orderRefundInfo) {
            $orderRefundInfo = $this->dao->get(['id' => $id, 'is_cancel' => 0]);
        }
        if (!$orderRefundInfo) {
            throw new ValidateException('售后订单不存在');
        }
        $this->transaction(function () use ($id, $orderRefundInfo, $data) {
            //处理售后订单
            if (isset($data['refund_price'])) unset($data['refund_price']);
            $this->dao->update($id, $data);
            //处理订单
            $oid = (int)$orderRefundInfo['store_order_id'];
            $this->storeOrderServices->update($oid, ['refund_status' => 0, 'refund_type' => 3]);
            //处理订单商品cart_info
            $this->cancelOrderRefundCartInfo($id, $oid, $orderRefundInfo);
        });
        $orderInfo = $this->storeOrderServices->get((int)$orderRefundInfo['store_order_id']);
		//记录
		OrderStatusJob::dispatch([$id, 'refund_n', ['change_message' => '不退款原因:' . ($data['refund_reason'] ?? $data['refuse_reason'] ?? '')]]);
        //订单拒绝退款事件
        event('order.refuseRefund', [$orderInfo]);
        return true;
    }

    /**
     * 取消申请退款
     * @param int $uid
     * @param string $order_id
     * @return bool|\think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function cancelApplyRefund(int $uid, string $order_id)
    {
        $orderRefund = $this->dao->get(['order_id' => $order_id, 'is_cancel' => 0]);
        if (!$orderRefund || $orderRefund['uid'] != $uid) {
            throw new ValidateException('订单不存在');
        }
        if (!in_array($orderRefund['refund_type'], [0, 1, 2, 4, 5])) {
            throw new ValidateException('当前状态不能取消申请');
        }
        $this->transaction(function () use ($uid, $orderRefund) {
            $this->dao->update($orderRefund['id'], ['is_cancel' => 1]);
            $this->cancelOrderRefundCartInfo((int)$orderRefund['id'], (int)$orderRefund['store_order_id'], $orderRefund);
        });
		OrderStatusJob::dispatch([$orderRefund['store_order_id'], 'cancel_apply_refund', ['change_message' => '用户取消申请退款', 'change_manager_type' => 'user']]);
        return true;
    }

    /**
     * 取消申请、后台拒绝处理cart_info refund_num数据
     * @param int $id
     * @param int $oid
     * @param array $orderRefundInfo
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function cancelOrderRefundCartInfo(int $id, int $oid, $orderRefundInfo = [])
    {
        if (!$orderRefundInfo) {
            $orderRefundInfo = $this->dao->get(['id' => $id, 'is_cancel' => 0]);
        }
        if (!$orderRefundInfo) {
            throw new ValidateException('售后订单不存在');
        }
        /** @var StoreOrderServices $storeOrderServices */
        $storeOrderServices = app()->make(StoreOrderServices::class);
        $orderInfo = $storeOrderServices->get($oid, ['id', 'type', 'pink_id', 'activity_id']);
        if (!$orderInfo) {
            throw new ValidateException('订单不存在');
        }
        //拼团订单处理拼团
        if ($orderInfo['type'] == 3 && $orderInfo['pink_id']) {
            /** @var StorePinkServices $pinkServices */
            $pinkServices = app()->make(StorePinkServices::class);
            $addTime = $pinkServices->value(['id' => $orderInfo['pink_id']], 'add_time');
            /** @var StoreCombinationServices $services */
            $services = app()->make(StoreCombinationServices::class);
            $product = $services->getOne(['id' => $orderInfo['activity_id']], 'effective_time,title,people');
            $pinkServices->update(['id' => $orderInfo['pink_id']], ['status' => 1, 'stop_time' => $addTime + $product['effective_time'] * 3600]);
        }
        $cart_ids = array_column($orderRefundInfo['cart_info'], 'id');
        /** @var StoreOrderCartInfoServices $storeOrderCartInfoServices */
        $storeOrderCartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        $cartInfos = $storeOrderCartInfoServices->getColumn([['oid', '=', $oid], ['cart_id', 'in', $cart_ids]], 'cart_id,refund_num', 'cart_id');
        foreach ($orderRefundInfo['cart_info'] as $cart) {
            $cart_refund_num = $cartInfos[$cart['id']]['refund_num'] ?? 0;
            if ($cart['cart_num'] >= $cart_refund_num) {
                $refund_num = 0;
            } else {
                $refund_num = bcsub((string)$cart_refund_num, (string)$cart['cart_num'], 0);
            }
            $storeOrderCartInfoServices->update(['oid' => $oid, 'cart_id' => $cart['id']], ['refund_num' => $refund_num]);
        }
        $storeOrderCartInfoServices->clearOrderCartInfo($oid);
        return true;
    }

    /**
     * 商家同意退货退款，等待客户退货
     * @param int $id
     * @return bool
     */
    public function agreeRefundProdcut(int $id)
    {
        $refundOrder = $this->dao->get($id);
        if (!$refundOrder) {
            throw new ValidateException('订单不存在');
        }
        $res = $this->dao->update(['id' => $id], ['refund_type' => 4]);
		OrderStatusJob::dispatch([$refundOrder['store_order_id'], 'refund_express', ['change_message' => '等待用户退货']]);
        if ($res) return true;
        throw new ValidateException('操作失败');
    }

    /**
     * 同意退款：拆分退款单、退积分、佣金等
     * @param int $id
     * @param array $refundData
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function agreeRefund(int $id, array $refundData, $refundBen = 0, $refundGive = 0, ?int $recoverCouponFlag = null)
    {
        // 阶段2：禁止绕过终态领域服务直接打款
        if (!(int)$this->getItem('terminal_authorized', 0)) {
            \app\services\order\terminal\OrderTerminalError::throw(
                \app\services\order\terminal\OrderTerminalError::LEGACY_REFUND_DISABLED
            );
        }
        $orderRefundInfo = $this->dao->get($id);
        if ($orderRefundInfo) {
            $storeOrder = $this->storeOrderServices->get((int)$orderRefundInfo['store_order_id']);
            if ($storeOrder) {
                $storeOrderArr = is_array($storeOrder) ? $storeOrder : $storeOrder->toArray();
                $currentRefund = (float)($refundData['refund_price'] ?? 0);
                if ($currentRefund <= 0) {
                    $currentRefund = (float)($orderRefundInfo['refunded_price'] ?? 0);
                }
                // 订单已累计退款（不含本次）；勿与售后单 refunded_price 重复相加（主动退款创建售后单时二者相同）
                $priorRefunded = (float)($storeOrderArr['refund_price'] ?? 0);
                app()->make(StoreDebtServices::class)->validateRepayOrderRefundAmount(
                    $storeOrderArr,
                    $currentRefund,
                    $priorRefunded
                );
            }
        }
        // 兼容入口：按步骤跳过已完成项；领域服务优先调用下方分步方法
        $order = $this->agreeSplitRefundOrder($id);
        if (!(int)$this->getItem('terminal_skip_entitlement', 0)) {
            $this->runEntitlementRollback($order, $id, $recoverCouponFlag);
        }
        if (!(int)$this->getItem('terminal_skip_inventory', 0)) {
            $this->runInventoryRollback($order, $id);
        }
        if (!(int)$this->getItem('terminal_skip_performance', 0)) {
            $this->runPerformanceRollback($order);
        }
        if (!(int)$this->getItem('terminal_skip_status_notify', 0)) {
            $this->dispatchRefundStatusNotify($order, (string)($refundData['refund_price'] ?? '0'));
        }
        if (!(int)$this->getItem('terminal_skip_refund_event', 0)) {
            $this->dispatchRefundEvent($order, $refundData);
        }
        return true;
    }

    public function prepareRefundOrder(int $refundId)
    {
        return $this->agreeSplitRefundOrder($refundId);
    }

    public function runEntitlementRollback($order, int $refundId, ?int $recoverCouponFlag = null): void
    {
        if (!$this->integralAndCouponBack($order, $recoverCouponFlag)) {
            throw new ValidateException('回退积分和优惠卷失败');
        }
        if ($order['pid'] == 0 && $order['type'] == 3) {
            /** @var StorePinkServices $pinkServices */
            $pinkServices = app()->make(StorePinkServices::class);
            if (!$pinkServices->setRefundPink($order)) {
                throw new ValidateException('拼团修改失败!');
            }
        }
        /** @var UserBrokerageServices $userBrokerageServices */
        $userBrokerageServices = app()->make(UserBrokerageServices::class);
        if (!$userBrokerageServices->orderRefundBrokerageBack($order)) {
            throw new ValidateException('回退佣金失败');
        }
    }

    public function runInventoryRollback($order, int $refundId): void
    {
        if ($order['status'] == 0) {
            /** @var StoreOrderStatusServices $services */
            $services = app()->make(StoreOrderStatusServices::class);
            if (!$services->count(['oid' => $order['id'], 'change_type' => 'refund_price'])) {
                $this->regressionStock($order, $refundId);
            }
        } else {
            $stock_in_type = $this->getItem('stock_in_type', 0);
            if (in_array($stock_in_type, [1, 2], true)) {
                $this->regressionStock($order, $refundId, false);
                /** @var ProductInventoryChangeServices $inventoryChange */
                $inventoryChange = app()->make(ProductInventoryChangeServices::class);
                $inventoryChange->handleShippedRefundInbound($refundId, $stock_in_type == 1, true);
            } else {
                $this->regressionStock($order, $refundId, true);
            }
        }
    }

    public function runPerformanceRollback($order): void
    {
        if ($order['type'] == 11) {
            /** @var UserCardHolderServices $cardHolderServices */
            $cardHolderServices = app()->make(UserCardHolderServices::class);
            $cardHolderServices->update(['oid' => $order['id']], ['is_del' => 1]);
        } elseif ($order['type'] == 12) {
            /** @var StoreReservationOrderServices $reservationOrderService */
            $reservationOrderService = app()->make(StoreReservationOrderServices::class);
            $reservationOrderService->delete(['oid' => $order['id']]);
        }
        try {
            $oldOids = StoreOrder::where('card_upgrade_use_oid', (int)$order['id'])->column('id');
            if ($oldOids) {
                $oldOids = array_values(array_unique(array_filter(array_map('intval', $oldOids))));
                /** @var StoreOrderServices $storeOrderServices */
                $storeOrderServices = app()->make(StoreOrderServices::class);
                foreach ($oldOids as $oldOid) {
                    $storeOrderServices->update($oldOid, ['card_upgrade_use_oid' => 0]);
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public function dispatchRefundStatusNotify($order, string $refundPrice): void
    {
        $opNo = (string)$this->getItem('terminal_operation_no', '');
        $statusKey = (string)$this->getItem('terminal_status_notify_key', '');
        if ($statusKey === '' && $opNo !== '') {
            $statusKey = $opNo . ':status_notify';
        }
        OrderStatusJob::dispatch([$order['id'], 'refund_price', [
            'change_message' => '退款给用户：' . $refundPrice . '元',
            'change_manager_type' => $this->getItem('change_manager_type'),
            'change_manager_id' => $this->getItem('change_manager_id'),
            'terminal_idempotency_key' => $statusKey,
            'operation_no' => $opNo,
            'terminal_operation_id' => (int)$this->getItem('terminal_operation_id', 0),
        ]]);
    }

    public function dispatchRefundEvent($order, array $refundData): void
    {
        $opNo = (string)$this->getItem('terminal_operation_no', '');
        $opId = (int)$this->getItem('terminal_operation_id', 0);
        if ($opNo !== '') {
            $refundData['operation_no'] = $opNo;
            $refundData['terminal_operation_id'] = $opId;
            $order = is_array($order) ? $order : $order->toArray();
            $order['terminal_operation_no'] = $opNo;
            $order['terminal_operation_id'] = $opId;
            $order['terminal_store_finance_key'] = $opNo . ':store_finance';
            $order['terminal_staff_finance_key'] = $opNo . ':staff_finance';
            $order['terminal_capital_flow_key'] = $opNo . ':capital_flow';
            $order['terminal_product_log_key'] = $opNo . ':product_log';
            $order['terminal_refund_notice_key'] = $opNo . ':refund_notice';
        }
        event('order.refund', [$refundData, $order, 'order_refund']);
    }

	/**
	 * 处理退款 拆分订单
	 * @param int $id
	 * @param $orderRefundInfo
	 * @return array|\think\Model|null
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function agreeSplitRefundOrder(int $id, $orderRefundInfo = [])
    {
        if (!$orderRefundInfo) {
            $orderRefundInfo = $this->dao->get($id);
        }
        if (!$orderRefundInfo) {
            throw new ValidateException('数据不存在');
        }
        $cart_ids = [];
        if ($orderRefundInfo['cart_info']) {
            foreach ($orderRefundInfo['cart_info'] as $cart) {
                $cart_ids[] = [
                    'cart_id' => $cart['id'],
                    'cart_num' => $cart['cart_num'],
                ];
            }
        }
		/** @var StoreOrderSplitServices $storeOrderSplitServices */
		$storeOrderSplitServices = app()->make(StoreOrderSplitServices::class);
		$oid = (int)$orderRefundInfo['store_order_id'];
		$splitResult = $storeOrderSplitServices->equalSplit($oid, $cart_ids, [], 0, true, false);
		$orderInfo = [];
		$otherOrder = [];
		if ($splitResult) {//拆分发货
			[$orderInfo, $otherOrder] = $splitResult;
		}
        $orderInfo=false;
        //商城全部都改成整单退款了
		if ($orderInfo) {
			/** @var StoreOrderServices $storeOrderServices */
			$storeOrderServices = app()->make(StoreOrderServices::class);
			//原订单退款状态清空
			$storeOrderServices->update($oid, ['refund_status' => 0, 'refund_type' => 0]);
			//修改新生成拆分退款订单状态
			$storeOrderServices->update($orderInfo['id'], ['refund_status' => 2, 'refund_type' => 6]);
			//修改售后订单 关联退款订单
			$this->dao->update($orderRefundInfo['id'], ['store_order_id' => $orderInfo['id']]);
			if ($otherOrder && $oid != $otherOrder['id']) {//拆分生成新订单了
				//修改原订单还在申请的退款单
				$this->dao->update(['store_order_id' => $oid], ['store_order_id' => $otherOrder['id']]);
			}
			$orderInfo = $storeOrderServices->get($orderInfo['id']);
			//处理订单生成预约单
			/** @var StoreReservationOrderServices $reservationOrderService */
			$reservationOrderService = app()->make(StoreReservationOrderServices::class);
			//取出拆分订单数量的未处理核销预约单 修改到退款单
			$reservationOrder = $reservationOrderService->getList(['oid' => $oid, 'status' => [0, 1]], '*', 0, $orderInfo['total_num']);
			if ($reservationOrder) {
				$reservationOrderService->update(['id' => array_column($reservationOrder, 'id')], ['oid' => $orderInfo['id']]);
			}
			//剩余预约单绑定拆分生成新订单
			if ($otherOrder) $reservationOrderService->update(['oid' => $oid], ['oid' => $otherOrder['id']]);
		} else {//整单退款
			/** @var StoreOrderServices $storeOrderServices */
			$storeOrderServices = app()->make(StoreOrderServices::class);
			$storeOrderServices->update($oid, ['refund_status' => 2, 'refund_type' => 6, 'reservation_status' => 2]);
			//修改订单商品申请退款数量
			/** @var StoreOrderCartInfoServices $storeOrderCartInfoServices */
			$storeOrderCartInfoServices = app()->make(StoreOrderCartInfoServices::class);
			$storeOrderCartInfoServices->update(['oid' => $oid], ['refund_num' => 0]);
			$orderInfo = $storeOrderServices->get($oid);

            // 若存在“旧卡订单”被标记 card_upgrade_use_oid=当前退款订单，则恢复标记（不修改退款字段）
            try {
                $oldOids = Db::name('store_order')
                    ->where('card_upgrade_use_oid', $oid)
                    ->column('id');
                if ($oldOids) {
                    $oldOids = array_values(array_unique(array_filter(array_map('intval', $oldOids))));
                    foreach ($oldOids as $oldOid) {
                        $storeOrderServices->update($oldOid, ['card_upgrade_use_oid' => 0]);
                    }
                }
            } catch (\Throwable $e) {
                // ignore restore failure
            }
		}
		return $orderInfo;

    }

    /**
     * 订单退款表单
     * @param int $id
     * @param string $type
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function refundOrderForm(int $id, string $type = 'refund')
    {
        if ($type == 'refund') {//售后订单
            $orderRefund = $this->dao->get($id);
            if (!$orderRefund) {
                throw new ValidateException('未查到订单');
            }
            $order = $this->storeOrderServices->get((int)$orderRefund['store_order_id']);
            if (!$order) {
                throw new ValidateException('未查到订单');
            }
            if (!$order['paid']) {
                throw new ValidateException('未支付无法退款');
            }
            if ($orderRefund['refund_price'] > 0 && in_array($orderRefund['refund_type'], [1, 5])) {
                if ($orderRefund['refund_price'] <= $orderRefund['refunded_price']) {
                    throw new ValidateException('订单已退款');
                }
            }
            $f[] = Form::input('order_id', '退款单号：', $orderRefund->getData('order_id'))->disabled(true);
            $f[] = Form::number('refund_price', '退款金额：', (float)bcsub((string)$orderRefund->getData('refund_price'), (string)$orderRefund->getData('refunded_price'), 2))->min(0)->required('请输入退款金额');
            return create_form('退款处理', $f, $this->url('/refund/refund/' . $id), 'PUT');
        } else {//订单主动退款
            $order = $this->storeOrderServices->get((int)$id);
            if (!$order) {
                throw new ValidateException('未查到订单');
            }
            if (!$order['paid']) {
                throw new ValidateException('未支付无法退款');
            }
            if ($order['pay_price'] > 0 && in_array($order['refund_status'], [0, 1])) {
                if ($order['pay_price'] <= $order['refund_price']) {
                    throw new ValidateException('订单已退款');
                }
            }
            if ($order['pid'] >= 0) {//未拆分主订单、已拆分子订单
                /** @var StoreOrderRefundServices $storeOrderRefundServices */
                $storeOrderRefundServices = app()->make(StoreOrderRefundServices::class);
                if ($storeOrderRefundServices->count(['store_order_id' => $id, 'refund_type' => [1, 2, 4, 5, 6], 'is_cancel' => 0, 'is_del' => 0])) {
                    throw new ValidateException('请到售后订单列表处理');
                }
            } else {//已拆分发货
                throw new ValidateException('主订单已拆分发货，暂不支持整单主动退款');
            }
            $f[] = Form::input('order_id', '退款单号：', $order->getData('order_id'))->disabled(true);
            $maxRefund = (float)$order->getData('pay_price');
            if (!empty($order['is_debt_repay'])) {
                $maxRefund = (float)bcsub((string)$maxRefund, (string)($order['refund_price'] ?? 0), 2);
                if ($maxRefund < 0) {
                    $maxRefund = 0;
                }
            }
            $refundField = Form::number('refund_price', '退款金额：', $maxRefund)->min(0)->required('请输入退款金额');
            if (!empty($order['is_debt_repay'])) {
                $refundField = $refundField->max($maxRefund)->info('补交订单退款金额不能超过本次补交金额');
            }
            $f[] = $refundField;
			$f[] = Form::input('refund_explain', '退款说明：');
			if ($order['status'] >= 1) {//已发货订单
				$f[] = Form::radio('stock_in_type', '售后入库：', 0)->options([['label' => '暂不入库', 'value' => 0], ['label' => '入良品库', 'value' => 1], ['label' => '入残次品库', 'value' => 2]])->info('选择售后商品是否需要执行入库操作，若需存入不同仓库，请于入库管理模块中操作退货入库。');
			}
			return create_form('退款处理', $f, $this->url('/order/refund/' . $id), 'PUT');
        }
    }

    /**
     * 余额退款
     * @param $order
     * @param array $refundData
     * @return bool
     */
    public function yueRefund($order, array $refundData, $refundBen = 0, $refundGive = 0, ?string $idempotencyKey = null)
    {
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        if (!$order['uid']) {
            return true;
        }
        if (!$userServices->getUserInfo($order['uid'])) {
            return true;
        }
        $refundBen = bcadd((string)$refundBen, '0', 2);
        $refundGive = bcadd((string)$refundGive, '0', 2);
        // 禁止用整单 refund_price 顶替本金：未显式传入本金/赠金时不退余额
        if (bccomp($refundBen, '0', 2) === 0 && bccomp($refundGive, '0', 2) === 0) {
            return true;
        }
        /** @var StoreOrderCartInfoServices $cartInfoServices */
        $cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        $storeName = $cartInfoServices->getCarIdByProductTitle($order['id']);
        $arr = explode('|', $storeName);
        $num = count($arr);
        if ($num > 1) {
            $title = '购买' . substrUTf8($arr[0], 9, 'UTF-8', '') . '等';
        } else {
            $title = '购买' . substrUTf8($storeName, 10, 'UTF-8', '');
        }
        $title .= '退款';
        if ($idempotencyKey === null || $idempotencyKey === '') {
            $idempotencyKey = (string)$this->getItem('terminal_balance_idempotency_key', '');
        }
        /** @var \app\services\user\UserBalanceAtomicServices $balanceAtomic */
        $balanceAtomic = app()->make(\app\services\user\UserBalanceAtomicServices::class);
        $balanceAtomic->creditBenGive(
            (int)$order['uid'],
            $refundBen,
            $refundGive,
            'pay_product_refund',
            (int)$order['id'],
            $title,
            $idempotencyKey !== '' ? $idempotencyKey : null
        );
        return true;
    }

    /**
     * 外部渠道退款（禁止放在本地副作用事务内）
     */
    public function executeExternalChannelRefund(array $order, array $refundData, string $externalAmount, string $externalPayType = ''): void
    {
        if (bccomp($externalAmount, '0', 2) <= 0) {
            return;
        }
        $stableNo = (string)$this->getItem('terminal_external_refund_no', '');
        if ($stableNo !== '') {
            $refundData['refund_id'] = $stableNo;
        } elseif (empty($refundData['refund_id'])) {
            $refundData['refund_id'] = (string)($order['order_id'] ?? '') . 'R';
        }
        $channelData = $refundData;
        $channelData['refund_price'] = $externalAmount;
        $channelData['pay_price'] = $externalAmount;
        $payType = $externalPayType !== '' ? $externalPayType : (string)($order['pay_type'] ?? '');
        if ($payType === PayServices::WEIXIN_PAY || (string)($order['pay_type'] ?? '') === PayServices::WEIXIN_PAY) {
            $no = $order['order_id'];
            if (!empty($order['trade_no']) && $order['trade_no'] != $order['order_id']) {
                $no = $order['trade_no'];
                $channelData['type'] = 'trade_no';
            }
            if ((int)($order['is_channel'] ?? 0) === 1) {
                $pay_routine_open = (bool)sys_config('pay_routine_open', 0);
                if ($pay_routine_open) {
                    $channelData['refund_no'] = $order['order_id'];
                    /** @var WechatUserServices $wechatUserServices */
                    $wechatUserServices = app()->make(WechatUserServices::class);
                    $channelData['open_id'] = $wechatUserServices->value(['uid' => (int)$order['uid']], 'openid');
                    if (in_array(substr((string)$order['unique'], 0, 2), ['wx', 'cp', 'hy', 'cz'], true)) {
                        $channelData['routine_order_id'] = $order['unique'];
                    } else {
                        $channelData['routine_order_id'] = $order['order_id'];
                    }
                    $channelData['pay_routine_open'] = true;
                }
                Payment::instance()->setAccessEnd(Payment::MINI)->payOrderRefund($no, $channelData);
            } else {
                Payment::instance()->setAccessEnd(Payment::WEB)->payOrderRefund($no, $channelData);
            }
            return;
        }
        if ($payType === PayServices::ALIAPY_PAY || (string)($order['pay_type'] ?? '') === PayServices::ALIAPY_PAY) {
            AliPayService::instance()->refund(
                strpos((string)($order['trade_no'] ?? ''), '_') !== false ? $order['trade_no'] : $order['order_id'],
                floatval($externalAmount),
                $channelData['refund_id']
            );
        }
    }

    /**
     * 回退积分和优惠卷
     * @param $order
     * @return bool
     */
    /**
     * @param mixed $order
     * @param int|null $recoverCouponFlag null=读 setItem('recover_coupon')，否则 1/0 强制是否退券
     */
    public function integralAndCouponBack($order, ?int $recoverCouponFlag = null)
    {
        $res = true;
        if ($recoverCouponFlag === null) {
            $recoverCoupon = (int)$this->getItem('recover_coupon', 1);
        } else {
            $recoverCoupon = $recoverCouponFlag ? 1 : 0;
        }
        //回退优惠卷（订单级 + 明细级，拆分子订单不退优惠券）
        if ($recoverCoupon && !$order['pid']) {
            $res = $res && $this->recoverOrderCoupons($order);
        }
        //回退积分
        [$order, $changeIntegral] = $this->regressionIntegral($order);
		OrderStatusJob::dispatch([$order['id'], 'integral_back', [
			'change_message' => '商品退积分:' . $changeIntegral,
			'change_manager_type' => $this->getItem('change_manager_type'),
			'change_manager_id' => $this->getItem('change_manager_id')
		]]);
        return $res && $order->save();
    }

    /**
     * 退回订单使用的优惠券（兼容订单级 coupon_id 与明细 cart_info.coupon_id）
     * @param mixed $order
     * @return bool
     */
    protected function recoverOrderCoupons($order): bool
    {
        $couponUserIds = [];
        if (!empty($order['coupon_id'])) {
            $couponUserIds[(int)$order['coupon_id']] = true;
        }
        /** @var StoreOrderCartInfoServices $cartInfoServices */
        $cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        $cartList = $cartInfoServices->getCartColunm(['oid' => (int)$order['id']], 'cart_info');
        foreach ($cartList as $cartInfoRaw) {
            $info = is_string($cartInfoRaw) ? json_decode($cartInfoRaw, true) : (is_array($cartInfoRaw) ? $cartInfoRaw : []);
            if (!is_array($info)) {
                continue;
            }
            $cartCouponId = (int)($info['coupon_id'] ?? 0);
            if ($cartCouponId > 0) {
                $couponUserIds[$cartCouponId] = true;
            }
        }
        if (!$couponUserIds) {
            return true;
        }
        /** @var StoreCouponUserServices $coumonUserServices */
        $coumonUserServices = app()->make(StoreCouponUserServices::class);
        $res = true;
        foreach (array_keys($couponUserIds) as $couponUserId) {
            $res = $res && $coumonUserServices->recoverCoupon((int)$couponUserId);
        }
        return $res;
    }

    /**
     * 回退使用积分和赠送积分
     * @param $order
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function regressionIntegral($order)
    {
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $userInfo = $userServices->get($order['uid'], ['integral']);
        if (!$userInfo) {
            $order->back_integral = $order->use_integral;
            return [$order, 0];
        }
        $integral = $userInfo['integral'];
        if ($order['status'] == -2 || $order['is_del']) {
            return [$order, 0];
        }
        $res1 = $res2 = $res3 = $res4 = true;
        //订单赠送积分
        /** @var UserBillServices $userBillServices */
        $userBillServices = app()->make(UserBillServices::class);
        $where = [
            'uid' => $order['uid'],
            'category' => 'integral',
            'type' => 'gain',
            'link_id' => $order['id']
        ];
        $give_integral = $userBillServices->sum($where, 'number');
        if ((int)$order['refund_status'] != 2 && $order['back_integral'] >= $order['use_integral']) {
            return [$order, 0];
        }
        //子订单退款 再次查询主订单
        if (!$give_integral && $order['pid']) {
            $where['link_id'] = $order['pid'];
            $give_integral = $userBillServices->sum($where, 'number');
            if ($give_integral) {
                $p_order = $this->storeOrderServices->get($order['pid']);
                if ((float)$p_order['pay_price']) {
                    $give_integral = bcmul((string)$give_integral, (string)bcdiv((string)$order['pay_price'], (string)$p_order['pay_price'], 4), 0);
                }
            }
        }
        if ($give_integral) {
            //判断订单是否已经回退积分
            $count = $userBillServices->count(['category' => 'integral', 'type' => 'deduction', 'link_id' => $order['id']]);
            if (!$count) {
                if ($integral > $give_integral) {
                    $integral = bcsub((string)$integral, (string)$give_integral);
                } else {
                    $integral = 0;
                }
                //记录赠送积分收回
                $res1 = $userBillServices->income('integral_refund', $order['uid'], (int)$give_integral, (int)$integral, $order['id']);
                //清除积分冻结
                $userBillServices->update(['link_id' => $order['id']], ['frozen_time' => 0]);
            }
        }
        //返还下单支付积分 积分兑换
        $pay_integral = $order['pay_integral'];
        if ($pay_integral > 0) {
            $integral = bcadd((string)$integral, (string)$pay_integral);
            //记录下单支付积分还回
            $res2 = $userBillServices->income('order_integral_refund', $order['uid'], (int)$pay_integral, (int)$integral, $order['id']);
        }
        //返还下单抵扣积分
        $use_integral = $order['use_integral'];
        if ($use_integral > 0) {
            $integral = bcadd((string)$integral, (string)$use_integral);
            //记录下单抵扣积分还回
            $res2 = $userBillServices->income('pay_product_integral_back', $order['uid'], (int)$use_integral, (int)$integral, $order['id']);
        }
        $res3 = $userServices->update($order['uid'], ['integral' => $integral]);
        if (!($res1 && $res2 && $res3)) {
            throw new ValidateException('回退积分增加失败');
        }
        if ($use_integral > $give_integral) {
            $order->back_integral = bcsub($use_integral, $give_integral, 2);
        }
        return [$order, bcsub((string)$integral, (string)$userInfo['integral'], 0)];
    }

    /**
     * 回退库存与销量（按退款单商品行）
     * @param $order
     * @param int $refundId 退款单ID（必填才能行级幂等）
     * @param bool $writeRefundInbound 未发货退款写退货入库台账（库存已回退，isStock=false）
     * @return bool
     */
    public function regressionStock($order, int $refundId = 0, bool $writeRefundInbound = true)
    {
        if ($order['status'] == -2 || $order['is_del']) return true;
        $orderArr = is_array($order) ? $order : $order->toArray();
        $activity_id = (int)($orderArr['activity_id'] ?? 0);
        $store_id = (int)($orderArr['store_id'] ?? 0);

        // 活动额度/Redis 仍按订单全量回退（与旧逻辑一致）
        $res5 = true;
        /** @var StoreOrderCartInfoServices $cartServices */
        $cartServices = app()->make(StoreOrderCartInfoServices::class);
        /** @var StoreSeckillServices $seckillServices */
        $seckillServices = app()->make(StoreSeckillServices::class);
        /** @var StoreCombinationServices $pinkServices */
        $pinkServices = app()->make(StoreCombinationServices::class);
        /** @var StoreBargainServices $bargainServices */
        $bargainServices = app()->make(StoreBargainServices::class);
        /** @var StoreDiscountsServices $discountServices */
        $discountServices = app()->make(StoreDiscountsServices::class);
        /** @var StoreNewcomerServices $storeNewcomerServices */
        $storeNewcomerServices = app()->make(StoreNewcomerServices::class);
		/** @var StoreIntegralServices $storeIntegralServices */
		$storeIntegralServices = app()->make(StoreIntegralServices::class);

        $refundCartLines = [];
        if ($refundId > 0) {
            $refund = $this->dao->get($refundId);
            if (!$refund) {
                throw new ValidateException('退款单不存在');
            }
            $refundArr = is_array($refund) ? $refund : $refund->toArray();
            $raw = $refundArr['cart_info'] ?? [];
            if (is_string($raw)) {
                $raw = json_decode($raw, true) ?: [];
            }
            foreach ((array)$raw as $line) {
                if (isset($line['cart_info']) && is_array($line['cart_info'])) {
                    $line = $line['cart_info'];
                }
                $refundCartLines[] = $line;
            }
            // 有退款单 ID 时必须按退款单明细回退，禁止回退整张销售单
            if (!$refundCartLines) {
                throw new ValidateException('退款单无商品明细，禁止回退库存');
            }
        } else {
            // 仅无退款单的旧取消订单调用：兼容用整单购物车
            $rows = $cartServices->getCartInfoList(['oid' => $orderArr['id'], 'cart_type' => [0, 1]], ['product_id', 'sku_unique', 'cart_num', 'cart_info', 'product_type']);
            foreach ($rows as $row) {
                $info = is_string($row['cart_info'] ?? null) ? json_decode($row['cart_info'], true) : ($row['cart_info'] ?? []);
                if (!is_array($info)) $info = [];
                $info['cart_num'] = $row['cart_num'] ?? ($info['cart_num'] ?? 0);
                $info['product_id'] = $row['product_id'] ?? ($info['product_id'] ?? 0);
                $info['sku_unique'] = $row['sku_unique'] ?? '';
                $info['product_type'] = $row['product_type'] ?? ($info['product_type'] ?? 0);
                $refundCartLines[] = $info;
            }
        }

        // 活动额度回退（不回实物，实物由统一服务处理）
        foreach ($refundCartLines as $cart) {
            $info = is_array($cart) ? $cart : [];
            $unique = (string)($info['productInfo']['attrInfo']['unique'] ?? $info['product_attr_unique'] ?? $info['sku_unique'] ?? '');
            $cart_num = (float)($info['cart_num'] ?? 0);
            $cart_num_i = (int)ceil($cart_num);
            switch ((int)($orderArr['type'] ?? 0)) {
                case 1:
                    $res5 = $res5 && $seckillServices->incSeckillStock($cart_num_i, $activity_id, $unique, $store_id, false);
                    break;
                case 2:
                    $res5 = $res5 && $bargainServices->incBargainStock($cart_num_i, $activity_id, $unique, $store_id, false);
                    break;
                case 3:
                    $res5 = $res5 && $pinkServices->incCombinationStock($cart_num_i, $activity_id, $unique, $store_id, false);
                    break;
				case 4:
					$res5 = $res5 && $storeIntegralServices->incIntegralStock($cart_num_i, $activity_id, $unique, $store_id, false);
					break;
                case 5:
                    CacheService::setStock(md5($activity_id), 1, 5, false);
                    break;
                case 7:
                    // 新人礼额度不改实物
                    break;
            }
            if (in_array((int)($orderArr['type'] ?? 0), [1, 2, 3])) {
                CacheService::setStock($unique, $cart_num_i, (int)$orderArr['type'], false);
            }
        }
        if ((int)($orderArr['type'] ?? 0) == 5 && $activity_id) {
            $res5 = $res5 && $discountServices->changeDiscountLimit($activity_id, false);
        }
        $this->regressionRedisStock($orderArr);

        // 实物库存 + 销量：统一服务按退款单幂等处理
        if ($refundId > 0) {
            /** @var ProductInventoryChangeServices $inventoryChange */
            $inventoryChange = app()->make(ProductInventoryChangeServices::class);
            // writeRefundInbound=false：已发货路径只回销量；实物库存+入库台账由 handleShippedRefundInbound(refund_id) 处理
            if ($writeRefundInbound) {
                $inventoryChange->handleRefundInventory($orderArr, $refundCartLines, $refundId);
            } else {
                // 已发货且由入库 Job 改库存时：这里只回销量，库存交给入库单 isStock=true
                $refund = Db::name('store_order_refund')->where('id', $refundId)->lock(true)->find();
                if ($refund && (int)($refund['sales_refunded'] ?? 0) !== 1) {
                    $paid = (int)($orderArr['paid'] ?? 0);
                    $orderSalesHandled = (int)($orderArr['sales_handled'] ?? 0);
                    if ($paid === 1 && $orderSalesHandled === 1) {
                        /** @var StoreProductServices $productServices */
                        $productServices = app()->make(StoreProductServices::class);
						$salesLines = [];
                        foreach ($refundCartLines as $cart) {
                            $cart = $inventoryChange->enrichCartWithInventorySnapshot(is_array($cart) ? $cart : []);
                            try {
                                [$productId, $unique] = $inventoryChange->resolveInventoryTarget($cart);
                            } catch (\Throwable $e) {
                                continue;
                            }
	                            $num = (string)($cart['cart_num'] ?? 0);
	                            if ($productId <= 0 || bccomp($num, '0', 4) <= 0) continue;
	                            $salesLines[] = [
	                                'product_id' => $productId,
	                                'unique' => $unique,
	                                'num' => (int)ceil((float)$num),
	                            ];
	                        }
	                        $productServices->decProductSalesBatch($salesLines, $store_id);
                        Db::name('store_order_refund')->where('id', $refundId)->update(['sales_refunded' => 1]);
                    }
                }
            }
        }

        // 禁止重置订单 inventory_handled/sales_handled，以支持部分退款
        return $res5;
    }

    /**
     * 回退redis占用库存
     * @param $order
     * @return bool
     */
    public function regressionRedisStock($order)
    {
        if ($order['status'] == -2 || $order['is_del']) return true;
        $type = $order['type'] ?? 0;
        /** @var StoreOrderCartInfoServices $storeOrderCartInfoServices */
        $storeOrderCartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        $cartInfo = $storeOrderCartInfoServices->getOrderCartInfoCache((int)$order['id']);
        //回退套餐限量库
        if ($type == 5 && $order['activity_id']) CacheService::setStock(md5($order['activity_id']), 1, 5, false);
        foreach ($cartInfo as $item) {//回退redis占用
            if (!isset($item['product_attr_unique']) || !$item['product_attr_unique']) continue;
            $type = $item['type'];
            if (in_array($type, [1, 2, 3])) CacheService::setStock($item['product_attr_unique'], (int)$item['cart_num'], $type, false);
        }
        return true;
    }

    /**
     * 同意退款退款失败写入订单记录
     * @param int $id
     * @param $refund_price
     */
    public function storeProductOrderRefundYFasle(int $id, $refund_price)
    {
		OrderStatusJob::dispatch([$id, 'refund_price', ['change_message' => '退款给用户：' . $refund_price . '元失败']]);
    }

	/**
	 * 不退款表单
	 * @param int $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function noRefundForm(int $id)
    {
        $orderRefund = $this->dao->get($id);
        if (!$orderRefund) {
            throw new ValidateException('未查到订单');
        }
        $order = $this->storeOrderServices->get((int)$orderRefund['store_order_id']);
        if (!$order) {
            throw new ValidateException('未查到订单');
        }
        $f[] = Form::input('order_id', '不退款单号：', $order->getData('order_id'))->disabled(true);
        $f[] = Form::input('refund_reason', '不退款原因：')->type('textarea')->required('请填写不退款原因');
        return create_form('不退款原因', $f, $this->url('order/no_refund/' . $id), 'PUT');
    }


    /**
     * 写入退款快递单号
     * @param $order
     * @param $express
     * @return bool
     */
    public function editRefundExpress($data)
    {
        $id = (int)$data['id'];
        $refundOrder = $this->dao->get($id);
        if (!$refundOrder) {
            throw new ValidateException('退款订单不存在');
        }
		$data['refund_type'] = 5;
		$res = false !== $this->dao->update(['id' => $id], $data);
		if (!$res)
			throw new ValidateException('提交失败!');
		//记录主订单状态
		OrderStatusJob::dispatch([$refundOrder['store_order_id'], 'refund_express', ['change_message' => '用户已退货，快递单号：' . $data['refund_express'], 'change_manager_type' => 'user']]);
        return true;
    }

	/**
	 * 获取退货物流信息
	 * @param int $id
	 * @return array|\think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getRefundExpress(int $id)
	{
		if (!$orderInfo = $this->dao->get($id))
			throw new ValidateException('订单不存在');
		if ($orderInfo['apply_type'] != 2)
			throw new ValidateException('该订单不是快递退回，无法查询物流信息');
		if (!$orderInfo['refund_express'])
			throw new ValidateException('该订单不存在快递单号');

		$cacheName = $orderInfo['order_id'] . $orderInfo['refund_express'];

		$data['delivery_name'] = $orderInfo['refund_express_name'];
		$data['delivery_id'] = $orderInfo['refund_express'];

		$delivery_id = $orderInfo['refund_express'];
		/** @var ExpressServices $services */
		$services = app()->make(ExpressServices::class);
		$data['result'] = $delivery_id ? $services->query($cacheName, $delivery_id, '', (string)$orderInfo['refund_phone']) : [];

		return $data;
	}

    /**
     * 退款订单详情
     * @param $uni
     * @param array $field
     * @param array $with
     * @return mixed
     */
    public function refundDetail($uni, array $field = ['*'], array $with = ['invoice', 'virtual'])
    {
        if (!strlen(trim($uni))) throw new ValidateException('参数错误');
        $order = $this->dao->get(['id|order_id' => $uni], ['*']);
        if (!$order) throw new ValidateException('订单不存在');
        $order = $order->toArray();
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $orderInfo = $orderServices->get($order['store_order_id'], $field, $with);
        $orderInfo = $orderInfo->toArray();
        $orderInfo = $orderServices->tidyOrder($orderInfo, true, true);
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $userInfo = $userServices->getUserWithTrashedInfo($order['uid']);
        $order['mapKey'] = sys_config('tengxun_map_key');
        $order['yue_pay_status'] = (int)sys_config('balance_func_status') && (int)sys_config('yue_pay_status') == 1 ? (int)1 : (int)2;//余额支付 1 开启 2 关闭
        $order['pay_weixin_open'] = (int)sys_config('pay_weixin_open') ?? 0;//微信支付 1 开启 0 关闭
        $order['ali_pay_status'] = (bool)sys_config('ali_pay_status');//支付包支付 1 开启 0 关闭
        $orderData = $order;
        $orderData['type'] = $orderInfo['type'];
        $orderData['refunded_price'] = floatval($orderData['refunded_price']) ?: $orderData['refund_price'];
        $orderData['store_order_sn'] = $orderInfo['order_id'];
        $orderData['product_type'] = $orderInfo['product_type'];
        $orderData['store_id'] = $orderInfo['store_id'];
        $orderData['supplier_id'] = $orderInfo['supplier_id'] ?? 0;
        $orderData['supplierInfo'] = $orderInfo['supplierInfo'] ?? null;
        $orderData['cartInfo'] = $orderData['cart_info'];
        $orderData['invoice'] = $orderInfo['invoice'];
        $orderData['virtual'] = $orderInfo['virtual'];
        $orderData['virtual_info'] = $orderInfo['virtual_info'];
		$orderData['custom_form_title'] = $orderInfo['custom_form_title'] ?? '';
		$orderData['system_form_type'] = $orderInfo['system_form_type'] ?? 1;
        $orderData['custom_form'] = is_string($orderInfo['custom_form']) ? json_decode($orderInfo['custom_form'], true) : $orderInfo['custom_form'];
        $orderData['first_order_price'] = $orderInfo['first_order_price'];
        $orderData['refund_price_type'] = $this->refundPriceType[$orderInfo['pay_type']] ?? '其他方式返还';
        // 阶段6：详情同时返回退款日期与实际操作时间
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        $dates = $terminal->mapBusinessAndOperatedByRefundIds([(int)$order['id']]);
        $dateRow = $dates[(int)$order['id']] ?? null;
        $orderData['refund_business_date'] = $dateRow['refund_business_date'] ?? '';
        $orderData['operated_at'] = $dateRow['operated_at'] ?? 0;
        $orderData['operated_at_text'] = $dateRow['operated_at_text'] ?? '';
        $cateData = [];
        $orderData['give_coupon'] = [];
        if ($orderInfo['give_coupon'] || $orderInfo['coupon_id']) {
            $couponIds = is_string($orderInfo['give_coupon']) ? stringToIntArray($orderInfo['give_coupon']) : $orderInfo['give_coupon'];
            /** @var StoreCouponIssueServices $couponIssueService */
            $couponIssueService = app()->make(StoreCouponIssueServices::class);
            /** @var StoreCouponUserServices $couponUserService */
            $couponUserService = app()->make(StoreCouponUserServices::class);
            if($orderInfo['coupon_id']) {
                $cid = $couponUserService->value(['id'=>$orderInfo['coupon_id']],'cid');
                $couponIds = $couponIds + [$cid];
            }
            $give_coupon = $couponIssueService->getColumn([['id', 'IN', $couponIds]], 'id,coupon_title,coupon_issue_type,relation_id');
            foreach ($give_coupon as $key=>&$item) {
                $format = $couponIssueService->getFormat($item['coupon_issue_type'], $item['relation_id']);
                $item['author'] = $format['author'] ?? '';
                $item['author_image'] = $format['author_image'] ?? '';
            }
            $orderData['give_coupon'] = $give_coupon;
        }
        if (isset($orderData['cartInfo']) && $orderData['cartInfo']) {
            $productId = array_column($orderData['cartInfo'], 'product_id');
            /** @var StoreProductServices $productServices */
            $productServices = app()->make(StoreProductServices::class);
            $cateData = $productServices->productIdByProductCateName($productId);
        }
        //核算优惠金额
        $vipTruePrice = 0.00;
        $total_price = 0.00;
        $promotionsPrice = 0.00;
        foreach ($orderData['cartInfo'] ?? [] as $key => &$cart) {
            if (!isset($cart['sum_true_price'])) $cart['sum_true_price'] = bcmul((string)$cart['truePrice'], (string)$cart['cart_num'], 2);
            $cart['vip_sum_truePrice'] = bcmul($cart['vip_truePrice'], $cart['cart_num'] ? $cart['cart_num'] : 1, 2);
            $vipTruePrice = bcadd((string)$vipTruePrice, (string)$cart['vip_sum_truePrice'], 2);
            if (isset($order['split']) && $order['split']) {
                $orderData['cartInfo'][$key]['cart_num'] = $cart['surplus_num'];
                if (!$cart['surplus_num']) unset($orderData['cartInfo'][$key]);
            }
            $total_price = bcadd($total_price, bcmul((string)$cart['sum_price'], (string)$cart['cart_num'], 2), 2);
            $orderData['cartInfo'][$key]['class_name'] = $cateData[$cart['product_id']] ?? '';
            $promotionsPrice = bcadd($promotionsPrice, bcmul((string)($cart['promotions_true_price'] ?? 0), (string)$cart['cart_num'], 2), 2);
        }
        //优惠活动优惠详情
        /** @var StoreOrderPromotionsServices $storeOrderPromotiosServices */
        $storeOrderPromotiosServices = app()->make(StoreOrderPromotionsServices::class);
        if ($orderData['refund_type'] == 6) {
            $orderData['promotions_detail'] = $storeOrderPromotiosServices->getOrderPromotionsDetail((int)$orderData['store_order_id']);
        } else {
            $orderData['promotions_detail'] = $storeOrderPromotiosServices->applyRefundOrderPromotions((int)$orderData['store_order_id'], $orderData['cartInfo']);
        }
        if (!$orderData['promotions_detail'] && $promotionsPrice) {
            $orderData['promotions_detail'][] = [
                'name' => '优惠活动',
                'title' => '优惠活动',
                'promotions_price' => $promotionsPrice,
            ];
        }
        $orderData['use_integral'] = $this->getOrderSumPrice($orderData['cartInfo'], 'use_integral', false);
        $orderData['integral_price'] = $this->getOrderSumPrice($orderData['cartInfo'], 'integral_price', false);
        $orderData['coupon_id'] = $orderInfo['coupon_id'];
        $orderData['coupon_price'] = $this->getOrderSumPrice($orderData['cartInfo'], 'coupon_price', false);
        $orderData['deduction_price'] = $this->getOrderSumPrice($orderData['cartInfo'], 'integral_price', false);
        $orderData['vip_true_price'] = $vipTruePrice;
        $orderData['postage_price'] = 0.00;
        $orderData['pay_postage'] = 0.00;
        if (!in_array($orderInfo['shipping_type'], [2, 4])) {
            $orderData['pay_postage'] = $this->getOrderSumPrice($orderData['cart_info'], 'postage_price', false);
        }
        $orderData['member_price'] = 0;
        $orderData['routine_contact_type'] = sys_config('routine_contact_type', 0);
        $orderData['_add_time'] = date('Y-m-d H:i:s', $orderData['add_time']);
        $orderData['add_time_y'] = date('Y-m-d', $orderData['add_time']);
        $orderData['add_time_h'] = date('H:i:s', $orderData['add_time']);

        if ($orderData['store_id']) {
            /** @var SystemStoreServices $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            $storeInfo = $storeServices->get($orderData['store_id']);
            $refund_name = $storeInfo['name'] ?? '';
            $refund_phone = $storeInfo['phone'] ?? '';
            $refund_address = ($storeInfo['address'] ?? '') . $storeInfo['detailed_address'] ?? '';
        } elseif ($orderData['supplier_id']) {
            /** @var SystemSupplierServices $supplierServices */
            $supplierServices = app()->make(SystemSupplierServices::class);
            $supplierIno = $supplierServices->get($orderData['supplier_id']);
            $refund_name = $supplierIno['supplier_name'] ?? '';
            $refund_phone = $supplierIno['phone'] ?? '';
            $refund_address = ($supplierIno['address'] ?? '') . $supplierIno['detailed_address'] ?? '';
        } else {
            $refund_name = sys_config('refund_name', '');
            $refund_phone = sys_config('refund_phone', '');
            $refund_address = sys_config('refund_address', '');
        }
        [$type, $title, $status_name, $pic, $desc] = $this->tidyOrderStatus($orderData);
        $orderData['_status'] = [
            '_type' => $type,
            '_title' => $title,
            '_msg' => $status_name,
            'pic' => sys_config('site_url') . $pic,
            'desc' => $desc,
            '_payType' => PayServices::PAY_TYPE[$orderInfo['pay_type']] ?? '其他支付',
            'refund_name' => $refund_name,
            'refund_phone' => $refund_phone,
            'refund_address' => $refund_address,
            'latitude' => $latitude ?? '',
            'longitude' => $longitude ?? '',
        ];

        $orderData['shipping_type'] = $orderInfo['shipping_type'];
        $orderData['real_name'] = $orderInfo['real_name'];
        $orderData['user_phone'] = $orderInfo['user_phone'];
        $orderData['user_address'] = $orderInfo['user_address'];
        $orderData['_pay_time'] = $orderInfo['pay_time'] ? date('Y-m-d H:i:s', $orderInfo['pay_time']) : '';
        $orderData['_add_time'] = $orderInfo['add_time'] ? date('Y-m-d H:i:s', $orderInfo['add_time']) : '';
        $orderData['_refund_time'] = $orderData['add_time'] ? date('Y-m-d H:i:s', $orderData['add_time']) : '';
        $orderData['nickname'] = $userInfo['nickname'] ?? '';
        $orderData['total_num'] = $orderData['refund_num'];
        $orderData['pay_price'] = $orderData['refund_price'];
        $orderData['refund_status'] = in_array($orderData['refund_type'], [0, 1, 2, 4, 5]) ? 1 : 2;
        $orderData['total_price'] = floatval(bcsub((string)$total_price, (string)$vipTruePrice, 2));
        $orderData['paid'] = 1;
        $orderData['mark'] = $orderInfo['mark'];
        $orderData['express_list'] = $orderData['refund_type'] == 4 ? app()->make(ExpressServices::class)->expressList(['is_show' => 1]) : [];
        $orderData['spread_uid'] = $orderInfo['spread_uid'] ?? 0;
        $orderData['orderStatus'] = $orderInfo['_status'];
		$orderData['settlePrice'] = $orderData['settle_price'];
        return $orderData;
    }

    /**
     * 获取某个字段总金额
     * @param $cartInfo
     * @param string $key
     * @param bool $is_unit
     * @return int|string
     */
    public function getOrderSumPrice($cartInfo, $key = 'truePrice', $is_unit = true)
    {
        $SumPrice = 0;
        foreach ($cartInfo as $cart) {
            if (isset($cart['cart_info'])) $cart = $cart['cart_info'];
            if ($is_unit) {
                $SumPrice = bcadd($SumPrice, bcmul($cart['cart_num'] ?? 1, $cart[$key] ?? 0, 2), 2);
            } else {
                $SumPrice = bcadd($SumPrice, $cart[$key] ?? 0, 2);
            }
        }
        return $SumPrice;
    }
}
