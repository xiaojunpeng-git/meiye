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
declare (strict_types=1);

namespace app\services\user;

use app\dao\order\StoreOrderDao;
use app\jobs\store\StoreFinanceJob;
use app\jobs\system\SocketPushJob;
use app\jobs\user\MicroPayOrderJob;
use app\model\activity\coupon\StoreCouponUser;
use app\model\order\CombinationOrder;
use app\model\order\StoreOrder;
use app\model\user\UserRecharge;
use app\model\yeji\CashSource;
use app\model\yeji\CashType;
use app\model\yeji\StaffYeji;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\BaseServices;
use app\dao\user\UserRechargeDao;
use app\services\order\StoreDebtServices;
use app\services\order\cashier\CashierOrderServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\ValidCashOrderServices;
use app\services\order\StoreOrderServices;
use app\services\pay\PayServices;
use app\services\pay\RechargeServices;
use app\services\system\config\SystemGroupDataServices;
use app\services\yeji\SatffYejiServices;
use mohe\exceptions\AdminException;
use mohe\traits\ServicesTrait;
use mohe\services\{AliPayService, FormBuilder as Form, wechat\Payment};
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Route as Url;
use app\services\wechat\WechatUserServices;

/**
 *
 * Class UserRechargeServices
 * @package app\services\user
 * @mixin UserRechargeDao
 */
class UserRechargeServices extends BaseServices
{

    use ServicesTrait;

    /**
     * UserRechargeServices constructor.
     * @param UserRechargeDao $dao
     */
    public function __construct(UserRechargeDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取单条数据
     * @param int $id
     * @param array $field
     */
    public function getRecharge(int $id, array $field = [])
    {
        return $this->dao->get($id, $field);
    }

    /**
     * @param int $storeId
     * @return int
     */
    public function getRechargeCount(int $storeId)
    {
        return $this->dao->count(['store_id' => $storeId, 'paid' => 1]);
    }

    /**
     * 获取统计数据
     * @param array $where
     * @param string $field
     * @return float
     */
    public function getRechargeSum(array $where, string $field = '')
    {
        $whereData = [];
        if (isset($where['data'])) {
            $whereData['time'] = $where['data'];
        }
        if (isset($where['paid']) && $where['paid'] != '') {
            $whereData['paid'] = $where['paid'];
        }
        if (isset($where['nickname']) && $where['nickname']) {
            $whereData['like'] = $where['nickname'];
        }
        if (isset($where['recharge_type']) && $where['recharge_type']) {
            $whereData['recharge_type'] = $where['recharge_type'];
        }
        if (isset($where['store_id'])) {
            $whereData['store_id'] = $where['store_id'];
        }
        return $this->dao->getWhereSumField($whereData, $field);
    }

    /**
     * 获取储值列表
     * @param array $where
     * @param string $field
     * @param int $limit
     * @return array
     */
    public function getRechargeList(array $where, string $field = '*', int $limit = 0, array $with = [])
    {
        $whereData = $where;
        if (isset($where['data'])) {
            $whereData['time'] = $where['data'];
            unset($whereData['data']);
        }
        if (isset($where['nickname']) && $where['nickname']) {
            $whereData['like'] = $where['nickname'];
            unset($whereData['nickname']);
        }
        if ($limit) {
            [$page] = $this->getPageValue();
        } else {
            [$page, $limit] = $this->getPageValue();
        }
        $list = $this->dao->getList($whereData, $field, $page, $limit, $with);
        $count = $this->dao->count($whereData);
        $cashTypes=CashType::column("name","id");
        $cashSource=CashSource::column("name","id");
        foreach ($list as &$item) {
            switch ($item['recharge_type']) {
                case 'routine':
                    $item['_recharge_type'] = '小程序储值';
                    break;
                case 'weixin':
                    $item['_recharge_type'] = '公众号储值';
                    break;
                case 'alipay':
                    $item['_recharge_type'] = '支付宝储值';
                    break;
                case 'balance':
                    $item['_recharge_type'] = '佣金转入';
                    break;
                case 'store':
                    $item['_recharge_type'] = '门店余额储值';
                    break;
				case 'offline' :
					$item['_recharge_type'] = "线下支付";
					break;
				case 'cash' :
//					$item['_recharge_type'] = "现金支付";
                    $item['_recharge_type'] = $cashTypes[$item['cash_choose']] ?? '';
                  //  $item['_recharge_type'] = $item['_recharge_type'].'(记账收款)';
					break;
                case PayServices::COMBINATION_PAY :
                    $item['_recharge_type'] = "组合支付";
                    break;
                default:
                    $item['_recharge_type'] = '其他储值';
                    break;
            }
            $item['_pay_time'] = $item['pay_time'] ? date('Y-m-d H:i:s', $item['pay_time']) : '暂无';
            $item['_add_time'] = $item['add_time'] ? date('Y-m-d H:i:s', $item['add_time']) : '暂无';
            $item['paid_type'] = $item['paid'] ? '已支付' : '待付款';
            $item['source_name']=$cashSource[$item['source']] ?? '老客扣卡';
            $debtAmount = (float)($item['debt_amount'] ?? 0);
            $repaidDebt = (float)($item['repaid_debt_amount'] ?? 0);
            $item['pending_debt_amount'] = $debtAmount > 0
                ? max(0, (float)bcsub((string)$debtAmount, (string)$repaidDebt, 2))
                : 0;
            unset($item['user']);
        }
        return compact('list', 'count');
    }

//    public function getSourceName($order){
//        $sourceName = ['','大众','抖音','自然进店','小红书','转介绍','老客续卡'];
//        return $sourceName[$order['source']] ?? '';
//    }
    /**
     * 获取用户储值数据
     * @return array
     */
    public function user_recharge(array $where)
    {
        $data = [];
        $where['paid'] = 1;
        $data['sumPrice'] = $this->getRechargeSum($where, 'price');
        $data['sumRefundPrice'] = $this->getRechargeSum($where, 'refund_price');
        $where['recharge_type'] = 'routine';
        $data['sumRoutinePrice'] = $this->getRechargeSum($where, 'price');
        $where['recharge_type'] = 'weixin';
        $data['sumWeixinPrice'] = $this->getRechargeSum($where, 'price');
        return [
            [
                'name' => '储值总金额',
                'field' => '元',
                'count' => $data['sumPrice'],
                'className' => 'logo-yen',
                'col' => 6,
            ],
            [
                'name' => '储值退款金额',
                'field' => '元',
                'count' => $data['sumRefundPrice'],
                'className' => 'logo-usd',
                'col' => 6,
            ],
            [
                'name' => '小程序储值金额',
                'field' => '元',
                'count' => $data['sumRoutinePrice'],
                'className' => 'logo-bitcoin',
                'col' => 6,
            ],
            [
                'name' => '公众号储值金额',
                'field' => '元',
                'count' => $data['sumWeixinPrice'],
                'className' => 'ios-bicycle',
                'col' => 6,
            ],
        ];
    }

    /**
     * 退款表单
     * @param int $id
     * @return mixed
     */
    public function refund_edit(int $id)
    {
        $UserRecharge = $this->getRecharge($id);
        if (!$UserRecharge) {
            throw new AdminException('数据不存在!');
        }
        if ($UserRecharge['paid'] != 1) {
            throw new AdminException('订单未支付');
        }
        if ($UserRecharge['price'] == $UserRecharge['refund_price']) {
            throw new AdminException('已退完支付金额!不能再退款了');
        }
        if ($UserRecharge['recharge_type'] == 'balance') {
            throw new AdminException('佣金转入余额，不能退款');
        }
        $f = array();
        $f[] = Form::input('order_id', '退款单号：', $UserRecharge->getData('order_id'))->disabled(true);
        $f[] = Form::input('price', '本金：', (float)$UserRecharge->getData('price'));
        $f[] = Form::input('give_price', '赠金：', (float)$UserRecharge->getData('give_price'));
//        $f[] = Form::radio('refund_price', '状态：', 1)->options([['label' => '本金(扣赠送余额)', 'value' => 1], ['label' => '仅本金', 'value' => 0]]);
        return create_form('退款', $f, Url::buildUrl('/finance/recharge/' . $id), 'PUT');
        //        $f[] = Form::number('refund_price', '退款金额：', (float)$UserRecharge->getData('price'))->precision(2)->min(0)->max($UserRecharge->getData('price'));
//        if ($UserRecharge['store_id']) {
//            return create_form('退款', $f, Url::buildUrl('/order/recharge/' . $id), 'PUT');
//        } else {
//            return create_form('退款', $f, Url::buildUrl('/order/recharge/' . $id), 'PUT');
//        }
    }

    /**
     * 储值退款操作
     * @param int $id
     * @param $refund_price
     * @return mixed
     */
    public function refund_update(int $id, array $refundInfo)
    {
        $UserRecharge = $this->getRecharge($id);
        if (!$UserRecharge) {
            throw new AdminException('数据不存在!');
        }
        if ($UserRecharge['price'] == $UserRecharge['refund_price']) {
            throw new AdminException('已退完支付金额!不能再退款了');
        }
        if ($UserRecharge['recharge_type'] == 'balance') {
            throw new AdminException('佣金转入余额，不能退款');
        }
        $UserRecharge = $UserRecharge->toArray();

//        $data['refund_price'] = $UserRecharge['price'];
        $refund_data['pay_price'] = $refundInfo['price'];    //用于微信支付宝退款
        $refund_data['refund_price'] = $refundInfo['price']; //用户微信支付宝退款
        $number = bcadd($refundInfo['price'], $refundInfo['give_price'], 2);
		/** @var UserServices $userServices */
		$userServices = app()->make(UserServices::class);
		$userInfo = $userServices->getUserInfo((int)$UserRecharge['uid']);
		if (!$userInfo) {
			throw new AdminException('用户不存在或已注销');
		}
		if ($userInfo['now_money'] < $number) {//退回余额 大于用户账户余额 不让退
			throw new AdminException('用户余额不足，不能退款');
		}
        if ($userInfo['ben_money'] < $refundInfo['price']) {//退回余额 大于用户账户余额 不让退
            throw new AdminException('用户本金不足，不能退款');
        }
        if ($userInfo['give_money'] < $refundInfo['give_price']) {//退回余额 大于用户账户余额 不让退
            throw new AdminException('用户赠金不足，不能退款');
        }
        try {
            $recharge_type = $UserRecharge['recharge_type'];
			switch ($recharge_type) {
				case 'alipay'://支付宝
					mt_srand();
					$refund_id = $refundData['refund_id'] ?? $UserRecharge['order_id'] . rand(100, 999);
					//支付宝退款
					AliPayService::instance()->refund($UserRecharge['order_id'], $refund_data['refund_price'], $refund_id);
					break;
				case 'weixin'://微信
					$pay_routine_open = (bool)sys_config('pay_routine_open', 0);
					if ($pay_routine_open) {
						$refund_data['refund_no'] = $UserRecharge['order_id'];  // 退款订单号
						/** @var WechatUserServices $wechatUserServices */
						$wechatUserServices = app()->make(WechatUserServices::class);
						$refund_data['open_id'] = $wechatUserServices->value(['uid' => (int)$UserRecharge['uid']], 'openid');
						$refund_data['routine_order_id'] = $UserRecharge['order_id'];
						$refund_data['pay_routine_open'] = true;
					}
					$transaction_id = $UserRecharge['trade_no'];
					if (!$transaction_id) {
						$refund_data['type'] = 'out_trade_no';
						$transaction_id = $UserRecharge['order_id'];
					} else {
						$refund_data['type'] = 'transaction_id';
					}
					Payment::instance()->setAccessEnd(Payment::WEB)->payOrderRefund($transaction_id, $refund_data);
					break;
				case 'routine'://小程序
					$transaction_id = $UserRecharge['trade_no'];
					if (!$transaction_id) {
						$refund_data['type'] = 'out_trade_no';
						$transaction_id = $UserRecharge['order_id'];
					} else {
						$refund_data['type'] = 'transaction_id';
					}
					Payment::instance()->setAccessEnd(Payment::MINI)->payOrderRefund($transaction_id, $refund_data);
					break;
				case 'cash'://现金
				case 'store'://门店余额储值
					break;
			}
        } catch (\Exception $e) {
            throw new AdminException($e->getMessage());
        }
        $data['refund_price']=$number;
        $data['refund_ben']=$refundInfo['price'];
        $data['refund_give']=$refundInfo['give_price'];
        if (!$this->dao->update($id, $data)) {
            throw new AdminException('修改提现数据失败');
        }
        if ($userInfo['now_money'] > $number) {
            $now_money = bcsub((string)$userInfo['now_money'], $number, 2);
        } else {
            $number = $userInfo['now_money'];
            $now_money = 0;
        }
        $userServices->update((int)$UserRecharge['uid'], ['now_money' => $now_money], 'uid');
        //充值单修改状态
        $orderId=StoreOrder::where("link_id",$id)->where("order_type",1)->value("id");
        if(!empty($orderId)) {
            $service = app()->make(StoreOrderServices::class);
            $service->update(['id' => $orderId], ['refund_status' => 2]);
            //赠送的优惠劵未使用的退掉
            StoreCouponUser::where("use_time",0)
                ->where('oid',$orderId)
                ->where('type','recharge_get')
                ->delete();
        }
        //业绩失效
        StaffYeji::where("link_id",$id)->where("type",1)->update(['status'=>1]);
        $UserRecharge['nickname'] = $userInfo['nickname'];
        $UserRecharge['phone'] = $userInfo['phone'];
        $UserRecharge['now_money'] = $userInfo['now_money'];

        /** @var UserMoneyServices $userMoneyServices */
        $userMoneyServices = app()->make(UserMoneyServices::class);
        //保存余额记录
        $userMoneyServices->income('user_recharge_refund', $UserRecharge['uid'], $number, $now_money, $id,'',$refundInfo['give_price']);

        //储值退款事件
        event('user.rechargeRefund', [$UserRecharge, $data]);
        return true;
    }

    /**
     * 删除
     * @param int $id
     * @return bool
     */
    public function delRecharge(int $id)
    {
        $rechargInfo = $this->getRecharge($id);
        if (!$rechargInfo) throw new AdminException('订单未找到');
        if ($rechargInfo->paid) {
            throw new AdminException('已支付的订单记录无法删除');
        }
        if ($this->dao->delete($id))
            return true;
        else
            throw new AdminException('删除失败');
    }

	/**
	 * 导入佣金到余额
	 * @param int $uid
	 * @param $price
	 * @param string $type recharge : 储值流程 extract:提现流程
	 * @return mixed
	 */
    public function importNowMoney(int $uid, $price, string $type = 'recharge')
    {
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $user = $userServices->getUserInfo($uid);
        if (!$user) {
            throw new ValidateException('数据不存在');
        }
        /** @var UserBrokerageServices $userBrokerageServices */
        $userBrokerageServices = app()->make(UserBrokerageServices::class);
        $broken_commission = $userBrokerageServices->getUserFrozenPrice($uid);
        $commissionCount = bcsub((string)$user['brokerage_price'], (string)$broken_commission, 2);
        if ($price > $commissionCount && $type == 'recharge') {
            throw new ValidateException('转入金额不能大于可提现佣金！');
        }
        return $this->transaction(function () use ($uid, $user, $price, $userServices, $type) {
            $edit_data = [];
            $edit_data['now_money'] = bcadd((string)$user['now_money'], (string)$price, 2);
            //写入储值记录
            $rechargeInfo = [
                'uid' => $uid,
                'order_id' => $this->getUniqueId('cz'),
                'recharge_type' => 'balance',
                'price' => $price,
                'give_price' => 0,
                'paid' => 1,
                'pay_time' => time(),
                'add_time' => time()
            ];
            //写入储值记录
            $re = $this->dao->save($rechargeInfo);
            /** @var UserMoneyServices $userMoneyServices */
            $userMoneyServices = app()->make(UserMoneyServices::class);
            //余额记录
            $userMoneyServices->income('brokerage_to_nowMoney', $uid, $price, $edit_data['now_money'], $re['id']);
			//佣金储值写入提现记录
			if ($type == 'recharge') {
				//修改用户佣金
				$edit_data['brokerage_price'] = $user['brokerage_price'] > $price ? bcsub((string)$user['brokerage_price'], (string)$price, 2) : 0;
				$extractInfo = [
					'uid' => $uid,
					'real_name' => $user['nickname'],
					'extract_type' => 'balance',
					'extract_price' => $price,
					'balance' => $edit_data['brokerage_price'],
					'add_time' => time(),
					'status' => 1
				];
				/** @var UserExtractServices $userExtract */
				$userExtract = app()->make(UserExtractServices::class);
				//写入提现记录
				$userExtract->save($extractInfo);
				//佣金提现记录
				/** @var UserBrokerageServices $userBrokerageServices */
				$userBrokerageServices = app()->make(UserBrokerageServices::class);
				$userBrokerageServices->income('brokerage_to_nowMoney', $uid, $price, $edit_data['brokerage_price'], $re['id']);
			}
			//修改用户佣金、余额信息
			$userServices->update($uid, $edit_data, 'uid');
        });
    }

    public function addYeji($staffChoose,$linkId,$price,$orderId=0){
        $data['type']=1;
        $data['link_id']=$linkId;
        $data['order_id']=$orderId;
        $data['goods_id']=0;
        $data['cart_id']=0;
        $data['price']=$price;
        $data['staffChoose']=$staffChoose;
        $staffYeji = app()->make(SatffYejiServices::class);
        $staffYeji->saveYeji($data);
    }
    /**
     * 申请储值
     * @param int $uid
     * @param $price
     * @param $recharId
     * @param $type
     * @param $from
     * @param array $staffinfo
     * @param string $authCode 扫码code
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function recharge(int $uid, $price, $recharId, $type, $from, array $staffinfo = [], string $authCode = '',array $staffChoose=[],$paid_price=0,$cashChoose=0,$remarkInfo=[],$combinationInfo=[],$real_pay_type=0,$source=0,$is_budan=0,$budan_time='',$sendAll=[],$is_gendan=0,$gendan_staff_id=0,$debtPayAmount=0)
    {
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $user = $userServices->getUserInfo($uid);
        if (!$user) {
            throw new ValidateException('数据不存在');
        }
        if (!empty($combinationInfo)) {
            CashType::validateCombinationInfo($combinationInfo);
        }
        $debtPayAmount = max(0, (float)$debtPayAmount);
        if ($recharId) {
            /** @var SystemGroupDataServices $systemGroupData */
            $systemGroupData = app()->make(SystemGroupDataServices::class);
            $data = $systemGroupData->getDateValue($recharId);
            if (!$data) {
                return app('json')->fail('您选择的储值方式已下架!');
            } else {
                $paid_price = $data['give_money'] ?? 0;
            }
            $price = $data['price'];
        }
        switch ((int)$type) {
            case 0: //支付储值余额
                $recharge_data = [];
                $recharge_data['order_id'] = $this->getUniqueId('cz');
                $recharge_data['uid'] = $uid;
                $recharge_data['price'] = $price;
                $recharge_data['cash_choose'] = $cashChoose;
                $recharge_data['source'] = $source;
                $recharge_data['remark_info'] = json_encode($remarkInfo);
                $recharge_data['recharge_type'] = $from;
                $recharge_data['paid'] = 0;
                $recharge_data['add_time'] = time();
                $recharge_data['give_price'] = $paid_price;
                $recharge_data['debt_amount'] = $debtPayAmount;
                $recharge_data['repaid_debt_amount'] = 0;
                $recharge_data['channel_type'] = $user['user_type'];
                $recharge_data['send_all'] = json_encode($sendAll);
                $recharge_data['staff_choose']=json_encode($staffChoose);
                $recharge_data['combination_info']=json_encode($combinationInfo);
                $gendanStaffId = (int)$gendan_staff_id;
                $recharge_data['gendan_staff_id'] = $gendanStaffId > 0 ? $gendanStaffId : 0;
                $recharge_data['is_gendan'] = $gendanStaffId > 0 ? 1 : ((int)$is_gendan ? 1 : 0);
                if(!empty($budan_time) && $is_budan == 1){
                    $theBudan_time=strtotime($budan_time);
                    $recharge_data['add_time']=$theBudan_time;
                    $recharge_data['pay_time']=$theBudan_time;
                    $recharge_data['is_budan'] = $is_budan;
                    $recharge_data['budan_time'] = $theBudan_time;
                }
                if (!$rechargeOrder = $this->dao->save($recharge_data)) {
                    throw new ValidateException('储值订单生成失败');
                }
                return ['msg' => '', 'type' => $from, 'data' => $rechargeOrder];
                break;
            case 1: //佣金转入余额
                $this->importNowMoney($uid, $price);
                return ['msg' => '转入余额成功', 'type' => $from, 'data' => []];
                break;
            case 2://门店储值-用户扫码付款
            case 3://门店储值-付款码付款
                if (!$staffinfo) {
                    throw new ValidateException('请稍后重试');
                }
                $recharge_data = [];
                $recharge_data['order_id'] = $this->getUniqueId('cz');
                $recharge_data['uid'] = $uid;
                $recharge_data['store_id'] = $staffinfo['store_id'];
                $recharge_data['staff_id'] = $staffinfo['id'];
                $recharge_data['cash_choose'] = $cashChoose;
                $recharge_data['source'] = $source;
                $recharge_data['remark_info'] = json_encode($remarkInfo);
                $recharge_data['price'] = $price;
                $recharge_data['send_all'] = json_encode($sendAll);
                $recharge_data['staff_choose']=json_encode($staffChoose);
                $recharge_data['combination_info']=json_encode($combinationInfo);
                $gendanStaffId = (int)$gendan_staff_id;
                $recharge_data['gendan_staff_id'] = $gendanStaffId > 0 ? $gendanStaffId : 0;
                $recharge_data['is_gendan'] = $gendanStaffId > 0 ? 1 : ((int)$is_gendan ? 1 : 0);
                //自动判定支付方式
                if ($authCode) {
                    $recharge_data['auth_code'] = $authCode;
                    if (Payment::isWechatAuthCode($authCode)) {
                        $recharge_data['recharge_type'] = PayServices::WEIXIN_PAY;
                    } else if (AliPayService::isAliPayAuthCode($authCode)) {
                        $recharge_data['recharge_type'] = PayServices::ALIAPY_PAY;
                    } else {
                        throw new ValidateException('付款二维码错误');
                    }
                } else {
                    $recharge_data['recharge_type'] = $from;
                }
                $recharge_data['paid'] = 0;
                $recharge_data['add_time'] = time();
                $recharge_data['give_price'] = $paid_price;
                $recharge_data['debt_amount'] = $debtPayAmount;
                $recharge_data['repaid_debt_amount'] = 0;
                $recharge_data['channel_type'] = $user['user_type'];
            if(!empty($budan_time) && $is_budan == 1){
                $theBudan_time=strtotime($budan_time);
                $recharge_data['add_time']=$theBudan_time;
                $recharge_data['pay_time']=$theBudan_time;
                $recharge_data['is_budan'] = $is_budan;
                $recharge_data['budan_time'] = $theBudan_time;
            }
                if (!$rechargeOrder = $this->dao->save($recharge_data)) {
                    throw new ValidateException('储值订单生成失败');
                }
                try {
                    /** @var RechargeServices $recharge */
                    $recharge = app()->make(RechargeServices::class);
                    $order_info = $recharge->recharge($rechargeOrder->order_id, $authCode);
                    if ($type === 3) {
                        if ($order_info['paid'] === 1) {
                            //修改支付状态
                            $this->rechargeSuccess($recharge_data['order_id'], ['trade_no' => $order_info['payInfo']['transaction_id'] ?? ''], $this->buildRechargeGendanSnapshot($recharge_data, $rechargeOrder));
                            return [
                                'msg' => $order_info['message'],
                                'status' => 'SUCCESS',
                                'type' => $from,
                                'payInfo' => [],
                                'data' => [
                                    'jsConfig' => [],
                                    'order_id' => $recharge_data['order_id']
                                ]
                            ];
                        } else {
                            //发起支付但是还没有支付，需要在5秒后查询支付状态
                            if ($recharge_data['recharge_type'] === PayServices::WEIXIN_PAY) {
                                if (isset($order_info['payInfo']['err_code']) && in_array($order_info['payInfo']['err_code'], ['AUTH_CODE_INVALID', 'NOTENOUGH'])) {
                                    return ['status' => 'ERROR', 'msg' => '支付失败', 'payInfo' => $order_info];
                                }
                                $secs = 5;
                                if (isset($order_info['payInfo']['err_code']) && $order_info['payInfo']['err_code'] === 'USERPAYING') {
                                    $secs = 10;
                                }
                                MicroPayOrderJob::dispatchSece($secs, [$recharge_data['order_id']]);
                            }
                            return [
                                'msg' => $order_info['message'] ?? '等待支付中',
                                'status' => 'PAY_ING',
                                'type' => $from,
                                'payInfo' => $order_info,
                                'data' => [
                                    'jsConfig' => [],
                                    'order_id' => $recharge_data['order_id']
                                ]
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    \think\facade\Log::error('储值失败，原因：' . $e->getMessage());
                    throw new ValidateException('储值失败：' . $e->getMessage());
                }
                return ['msg' => '', 'status' => 'PAY', 'type' => $from, 'data' => ['jsConfig' => $order_info, 'order_id' => $recharge_data['order_id']]];
                break;
            case 4: //现金支付
                if (!$staffinfo) {
                    throw new ValidateException('请稍后重试');
                }
                $recharge_data = [];
                $recharge_data['order_id'] = $this->getUniqueId('cz');
                $recharge_data['uid'] = $uid;
                $recharge_data['price'] = $price;
                $recharge_data['store_id'] = $staffinfo['store_id'];
                $recharge_data['staff_id'] = $staffinfo['id'];
                $recharge_data['recharge_type'] = PayServices::CASH_PAY;
                $recharge_data['paid'] = 0;
                $recharge_data['add_time'] = time();
                $recharge_data['give_price'] = $paid_price;
                $recharge_data['debt_amount'] = $debtPayAmount;
                $recharge_data['repaid_debt_amount'] = 0;
                $recharge_data['channel_type'] = $user['user_type'];
                $recharge_data['cash_choose'] = $cashChoose;
                $recharge_data['source'] = $source;
                $recharge_data['remark_info'] = json_encode($remarkInfo);
                $recharge_data['send_all'] = json_encode($sendAll);
                $recharge_data['staff_choose']=json_encode($staffChoose);
                $recharge_data['combination_info']=json_encode($combinationInfo);
                $gendanStaffId = (int)$gendan_staff_id;
                $recharge_data['gendan_staff_id'] = $gendanStaffId > 0 ? $gendanStaffId : 0;
                $recharge_data['is_gendan'] = $gendanStaffId > 0 ? 1 : ((int)$is_gendan ? 1 : 0);
                $recharge_data['is_budan'] = $is_budan;
                $recharge_data['budan_time'] = strtotime($budan_time);
                if($real_pay_type == PayServices::COMBINATION_PAY){
                    $recharge_data['recharge_type'] =PayServices::COMBINATION_PAY;
                }
                if(!empty($budan_time) && $is_budan == 1){
                    $theBudan_time=strtotime($budan_time);
                    $recharge_data['add_time']=$theBudan_time;
                    $recharge_data['pay_time']=$theBudan_time;
                    $recharge_data['is_budan'] = $is_budan;
                    $recharge_data['budan_time'] = $theBudan_time;
                }
                if (!$rechargeOrder = $this->dao->save($recharge_data)) {
                    throw new ValidateException('储值订单生成失败');
                }
                try {
                    //修改支付状态
                    $this->rechargeSuccess($recharge_data['order_id'], [], $this->buildRechargeGendanSnapshot($recharge_data, $rechargeOrder));
                } catch (\Exception $e) {
                    throw new ValidateException($e->getMessage());
                }
                return ['msg' => '','status' => 'SUCCESS', 'type' => $from, 'data' => ['order_id' => $recharge_data['order_id']]];
                break;
            default:
                throw new ValidateException('缺少参数');
                break;
        }
    }

    public function addOrderYeji($recharge_data){
        //充值创建订单
        $uid=$recharge_data['uid'];
        $price=$recharge_data['price'];
        $source=$recharge_data['source'];
        $real_pay_type=$recharge_data['recharge_type'];
        $sendAll=json_decode($recharge_data['send_all'],true);
        $staffChoose=json_decode($recharge_data['staff_choose'],true);
        $remarkInfo=json_decode($recharge_data['remark_info'],true);
        $combinationInfo=json_decode($recharge_data['combination_info'],true);
        $is_budan=$recharge_data['is_budan'];
        $budan_time=$recharge_data['budan_time'];
        $gendanStaffId = (int)($recharge_data['gendan_staff_id'] ?? 0);
        $isGendan = $gendanStaffId > 0 ? 1 : ((int)($recharge_data['is_gendan'] ?? 0) ? 1 : 0);
        $orderInfo=[
            'uid' => $uid,
            'pid'=>-2,
            'order_id' => $this->getUniqueId(),
            'add_time'=>$recharge_data['add_time'],
            'pay_time'=>$recharge_data['add_time'],
            'total_price'=>$price,
            'pay_price'=>$price,
            'unique'=>generateUnique32Str(),
            'order_type'=>1,
            'send_all' => $recharge_data['send_all'],
            'link_id'=>$recharge_data['id'],
            'paid'=>1,
            'status'=>2,
            'pay_type'=>$recharge_data['recharge_type'],
            'store_id'=>$recharge_data['store_id'],
            'source'=>$source,
            'staff_id'=>$recharge_data['staff_id'],
            'cash_choose'=>$recharge_data['cash_choose'],
            'remark_info'=>$recharge_data['remark_info'],
            'gendan_staff_id' => $gendanStaffId,
            'is_gendan' => $isGendan,
        ];
        // 计算“余额支付金额”与“欠款金额”
        $yuePayPrice = 0.00;
        $debtPayPrice = max(0, (float)($recharge_data['debt_amount'] ?? 0));
        $payType=$orderInfo['pay_type'];
        $payPrice=$price;
        if ($payType === PayServices::YUE_PAY) {
            $yuePayPrice = (float)$payPrice;
        } elseif (!empty($combinationInfo) && $payType == PayServices::COMBINATION_PAY) {
            foreach ($combinationInfo as $v) {
                if (($v['activePay'] ?? 0) == 3) {
                    $subType = $v['pay_sub_type'] ?? 'balance';
                    if (in_array($subType, ['balance', 'card_upgrade'], true)) {
                        $yuePayPrice = (float)bcadd((string)$yuePayPrice, (string)($v['price'] ?? 0), 2);
                    }
                }
            }
        }
        $orderInfo['debt_amount'] = $debtPayPrice;
        $orderInfo['repaid_debt_amount'] = 0.00;
        $cashPayPrice = ValidCashOrderServices::calcOrderCashPayPrice(
            $payPrice,
            $yuePayPrice,
            (int)($recharge_data['cash_choose'] ?? 0),
            is_array($combinationInfo) ? $combinationInfo : [],
            (string)$payType,
            $debtPayPrice
        );
        $orderInfo['yue_pay_price'] = $yuePayPrice;
        $orderInfo['cash_pay_price'] = $cashPayPrice;
        $orderServices = app()->make(StoreOrderCreateServices::class);
        $order=$orderServices->save($orderInfo);
        StoreOrder::where('id', (int)$order->id)->update([
            'add_time' => $recharge_data['add_time'],
            'gendan_staff_id' => $gendanStaffId,
            'is_gendan' => $isGendan,
            'debt_amount' => $debtPayPrice,
            'repaid_debt_amount' => 0,
        ]);
        if ($debtPayPrice > 0) {
            try {
                $orderArray = $order->toArray();
                $orderArray['debt_amount'] = $debtPayPrice;
                $orderArray['repaid_debt_amount'] = 0;
                app()->make(StoreDebtServices::class)->createFromPaidOrder($orderArray);
            } catch (\Throwable $e) {
                \think\facade\Log::error('充值欠款记录创建失败：' . $e->getMessage());
            }
        }
        if(!empty($staffChoose)){
            $this->addYeji($staffChoose,$recharge_data['id'],$cashPayPrice,$order->id);
        }
        //赠送优惠劵
        $service=app()->make(StoreCouponIssueServices::class);
        $service->orderPayGetCoupon($order,'recharge_get');
        //赠送商品订单
        $cashService=app()->make(CashierOrderServices::class);
        $sendCart=$cashService->addSend($sendAll,$uid,$recharge_data['store_id']);
        if(!empty($sendCart)) {
            $coupon = false;
            $integral = false;
            $coupon_id = 0;
            $staffId = 0;
            $remarks = '';
            $payType = "cash";
            $new = 0;
            $changePrice = 0;
            $changeCartInfo = [];
            $seckillId = 0;
            $collate_code_id = 0;
            $cashChoose = 10;
            $isPrice = 0;
            $selectedProduct = [];
            $userService = app()->make(\app\services\user\UserServices::class);
            $userInfo = $userService->getUserInfo($uid);
            $userInfo = $userInfo->toArray();
            $computeData = $cashService->computeOrder($uid, $recharge_data['store_id'], $sendCart, !!$integral, !!$coupon, $userInfo, (int)$coupon_id, !!$new);
            $cartInfo = $computeData['cartInfo'];
            $payPrice = 0;
            $totalPrice = 0;
            $sumPrice = 0;
            foreach ($cartInfo as $kk => $vv) {
                $cartInfo[$kk]['pay_price'] = 0;
                $cartInfo[$kk]['sum_price'] = 0;
                $cartInfo[$kk]['truePrice'] = 0;
                $cartInfo[$kk]['total_price]'] = 0;
                $cartInfo[$kk]['pay_price'] = 0;
            }
            $computeData['payPrice'] = $payPrice;
            $computeData['totalPrice'] = $totalPrice;
            $computeData['sumPrice'] = $sumPrice;
            $computeData['cartInfo'] = $cartInfo;
            $orderInfo = $cashService->createOrder((int)$uid, $userInfo, $computeData, $recharge_data['store_id'], (int)$staffId, $sendCart, $payType, !!$integral, !!$coupon, $remarks, (string)$changePrice, $changeCartInfo, !!$isPrice, $coupon_id, $seckillId, $collate_code_id, 0, [], 2, 0, '', 0, $selectedProduct, $cashChoose, $remarkInfo, $combinationInfo, 0, [], [], $is_budan, $budan_time, $sendCart, $sendAll);
            $updata = ['paid' => 1, 'pay_type' => PayServices::CASH_PAY, 'pay_time' => time(),'link_id'=>$recharge_data['id']];
            $oid = $orderInfo['id'];
            $dao = app()->make(StoreOrderDao::class);
            $dao->update($oid, $updata);
        }
        //保存组合支付信息
        if(!empty($combinationInfo) && $real_pay_type == PayServices::COMBINATION_PAY){
            ValidCashOrderServices::validateCombinationTotal($combinationInfo, $price);
            foreach ($combinationInfo as $k=>$v){
                $combinationOrder=[
                    'active_pay'=>$v['activePay'],
                    'name'=>$v['name'],
                    'price'=>$v['price'],
                    'remarkInfo'=>json_encode($v['remarkInfo']),
                    'cash_choose'=>$v['type'],
                    'order_id'=>$order->id,
                    'type'=>1,
                    'add_time'=>$recharge_data['add_time']
                ];
                Db::name("combination_order")->insert($combinationOrder);
            }
        }
        return true;
    }
    /**
     * 用户储值成功
     * @param $orderId
     * @return bool
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function rechargeSuccess($orderId, array $other = [], array $gendanSnapshot = [])
    {
        $payTime=time();
        $order = $this->dao->getOne(['order_id' => $orderId, 'paid' => 0]);
        if (!$order) {
            throw new ValidateException('订单失效或者不存在');
        }
        $order = $order->toArray();
        $this->mergeRechargeGendanSnapshot($order, $gendanSnapshot);
        if($order['is_budan'] == 1 && !empty($order['budan_time'])){
             $payTime=$order['budan_time'];
        }
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $user = $userServices->getUserInfo((int)$order['uid']);
        if (!$user) {
            throw new ValidateException('数据不存在');
        }
        $price = bcadd((string)$order['price'], (string)$order['give_price'], 2);
        if (!$this->dao->update($order['id'], ['paid' => 1, 'pay_time' =>$payTime, 'trade_no' => $other['trade_no'] ?? ''], 'id')) {
            throw new ValidateException('修改订单失败');
        }
        $now_money = bcadd((string)$user['now_money'], (string)$price, 2);
        /** @var UserMoneyServices $userMoneyServices */
        $userMoneyServices = app()->make(UserMoneyServices::class);
        $userMoneyServices->income('user_recharge', $user['uid'], ['number' => $price, 'price' => $order['price'], 'give_price' => $order['give_price']], $now_money, $order['id'],'',$order['give_price']);
        if (!$userServices->update((int)$order['uid'], ['now_money' => $now_money], 'uid')) {
            throw new ValidateException('修改用户信息失败');
        }
        if ($order['store_id'] > 0) {
            $data = $order;
            $data['pay_time'] = time();
			//储值：记录流水账单
			StoreFinanceJob::dispatch([$data, 2]);
        }
        $order['nickname'] = $user['nickname'];
        $order['phone'] = $user['phone'];
		$order['trade_no'] = $other['trade_no'] ?? '';
		$order['pay_time'] = time();

        if ($order['staff_id']) {
            //发送消息
			SocketPushJob::dispatch([$order['store_id'], 'changUser', ['uid' => $user['uid']], 'cashier']);
        }
        $this->addOrderYeji($order);
        //用户储值成功事件
        event('user.recharge', [$order, $now_money]);
        return true;
    }

    /**
     * 构建充值跟单快照（下单时传入，避免 rechargeSuccess 重读丢失）
     */
    protected function buildRechargeGendanSnapshot(array $rechargeData, $rechargeOrder = null): array
    {
        $snapshot = [
            'gendan_staff_id' => (int)($rechargeData['gendan_staff_id'] ?? 0),
            'is_gendan' => (int)($rechargeData['is_gendan'] ?? 0),
        ];
        if ($rechargeOrder) {
            $orderArr = is_object($rechargeOrder) && method_exists($rechargeOrder, 'toArray')
                ? $rechargeOrder->toArray()
                : (array)$rechargeOrder;
            if (!empty($orderArr['id'])) {
                $snapshot['id'] = (int)$orderArr['id'];
            }
            if (!empty($orderArr['gendan_staff_id'])) {
                $snapshot['gendan_staff_id'] = (int)$orderArr['gendan_staff_id'];
            }
            if (isset($orderArr['is_gendan'])) {
                $snapshot['is_gendan'] = (int)$orderArr['is_gendan'];
            }
        }
        return $snapshot;
    }

    /**
     * 合并充值跟单信息到订单数组
     */
    protected function mergeRechargeGendanSnapshot(array &$order, array $snapshot = []): void
    {
        if (!empty($snapshot['gendan_staff_id'])) {
            $order['gendan_staff_id'] = (int)$snapshot['gendan_staff_id'];
            $order['is_gendan'] = 1;
            return;
        }
        if (isset($snapshot['is_gendan'])) {
            $order['is_gendan'] = (int)$snapshot['is_gendan'] ? 1 : 0;
        }
        if (!empty($order['gendan_staff_id'])) {
            $order['is_gendan'] = 1;
            return;
        }
        $rechargeId = (int)($order['id'] ?? 0);
        if ($rechargeId <= 0) {
            return;
        }
        $row = Db::name('user_recharge')
            ->where('id', $rechargeId)
            ->field('gendan_staff_id,is_gendan')
            ->find();
        if (!$row) {
            return;
        }
        $staffId = (int)($row['gendan_staff_id'] ?? 0);
        $order['gendan_staff_id'] = $staffId;
        $order['is_gendan'] = $staffId > 0 ? 1 : ((int)($row['is_gendan'] ?? 0) ? 1 : 0);
    }

    /**
     * 根据查询用户储值金额
     * @param array $where
     * @return float|int
     */
    public function getRechargeMoneyByWhere(array $where, string $rechargeSumField, string $selectType, string $group = "")
    {
        switch ($selectType) {
            case "sum" :
                return $this->dao->getWhereSumField($where, $rechargeSumField);
            case "group" :
                return $this->dao->getGroupField($where, $rechargeSumField, $group);
        }
    }

    /**
     * 储值每日统计数据
     * @param int $store_id
     * @param int $staff_id
     * @param array $time
     * @return array
     */
    public function getDataPriceCount(int $store_id, int $staff_id = 0, $time = [])
    {
        [$page, $limit] = $this->getPageValue();
        $where = ['store_id' => $store_id, 'paid' => 1, 'time' => $time];
        if ($staff_id) {
            $where['staff_id'] = $staff_id;
        }
        return $this->dao->getDataPriceCount($where, ['sum(price) as price', 'count(id) as count', 'FROM_UNIXTIME(add_time, \'%m-%d\') as time'], $page, $limit);
    }

	/**
	 * 门店储值统计详情列表
	 * @param int $store_id
	 * @param int $staff_id
	 * @param array $time
	 * @param string $timeType
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function time(int $store_id, int $staff_id, array $time = [], string $timeType = 'day')
    {
        if (!$time) {
            return [];
        }
        $where = ['store_id' => $store_id, 'paid' => 1];
        if ($staff_id) $where['staff_id'] = $staff_id;
        return $this->dao->rechargeAddTimeList($where, $time, $timeType);
    }
}
