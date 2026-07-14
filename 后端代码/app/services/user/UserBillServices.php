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

use app\jobs\user\UserLevelJob;
use app\services\BaseServices;
use app\dao\user\UserBillDao;
use think\Exception;
use think\exception\ValidateException;
use mohe\services\CacheService;

/**
 *
 * Class UserBillServices
 * @package app\services\user
 * @mixin UserBillDao
 */
class UserBillServices extends BaseServices
{

    /**
     * 用户记录模板
     * @var array[]
     */
    protected $incomeData = [
        'pay_give_integral' => [
            'title' => '购买商品赠送积分',
            'category' => 'integral',
            'type' => 'gain',
            'mark' => '购买商品赠送{%num%}积分',
            'status' => 1,
            'pm' => 1
        ],
        'order_give_integral' => [
            'title' => '下单赠送积分',
            'category' => 'integral',
            'type' => 'gain',
            'mark' => '下单赠送{%num%}积分',
            'status' => 1,
            'pm' => 1
        ],
        'order_promotions_give_integral' => [
            'title' => '下单优惠活动赠送积分',
            'category' => 'integral',
            'type' => 'gain',
            'mark' => '下单优惠活动赠送{%num%}积分',
            'status' => 1,
            'pm' => 1
        ],
        'order_give_exp' => [
            'title' => '下单赠送经验',
            'category' => 'exp',
            'type' => 'gain',
            'mark' => '下单赠送{%num%}经验',
            'status' => 1,
            'pm' => 1
        ],
		'integral_refund' => [
			'title' => '赠送积分回退',
			'category' => 'integral',
			'type' => 'deduction',
			'mark' => '下单赠送积分,回退{%num%}积分',
			'status' => 1,
			'pm' => 0
		],
		'order_integral_refund' => [
			'title' => '下单支付积分回退',
			'category' => 'integral',
			'type' => 'integral_refund',
			'mark' => '下单支付积分,回退{%num%}积分',
			'status' => 1,
			'pm' => 1
		],
		'pay_product_integral_back' => [
			'title' => '下单抵扣积分回退',
			'category' => 'integral',
			'type' => 'pay_product_integral_back',
			'mark' => '下单抵扣积分，返还{%num%}积分',
			'status' => 1,
			'pm' => 1
		],
        'deduction' => [
            'title' => '积分抵扣',
            'category' => 'integral',
            'type' => 'deduction',
            'mark' => '购买商品使用{%number%}积分抵扣{%deductionPrice%}元',
            'status' => 1,
            'pm' => 0
        ],
        'lottery_use_integral' => [
            'title' => '参与抽奖使用积分',
            'category' => 'integral',
            'type' => 'lottery_use',
            'mark' => '参与抽奖使用{%num%}积分',
            'status' => 1,
            'pm' => 0
        ],
        'lottery_give_integral' => [
            'title' => '抽奖中奖赠送积分',
            'category' => 'integral',
            'type' => 'lottery_add',
            'mark' => '抽奖中奖赠送{%num%}积分',
            'status' => 1,
            'pm' => 1
        ],
		'storeIntegral_use_integral' => [
			'title' => '积分兑换商品',
			'category' => 'integral',
			'type' => 'storeIntegral_use',
			'mark' => '积分商城兑换商品使用{%num%}积分',
			'status' => 1,
			'pm' => 0
		],
        'system_clear_integral' => [
            'title' => '到期自动清除积分',
            'category' => 'integral',
            'type' => 'system_clear',
            'mark' => '到期自动清除{%num%}积分',
            'status' => 1,
            'pm' => 0
        ],
        'newcomer_give_integral' => [
            'title' => '新人礼赠送积分',
            'category' => 'integral',
            'type' => 'newcomer_add',
            'mark' => '新人礼赠送{%num%}积分',
            'status' => 1,
            'pm' => 1
        ],
        'level_give_integral' => [
            'title' => '会员卡激活赠送积分',
            'category' => 'integral',
            'type' => 'level_add',
            'mark' => '会员卡激活赠送{%num%}积分',
            'status' => 1,
            'pm' => 1
        ],
		'system_add_integral' => [
			'title' => '系统增加积分',
			'category' => 'integral',
			'type' => 'system_add',
			'mark' => '系统增加了{%num%}积分，{%mark%}',
			'status' => 1,
			'pm' => 1
		],
		'system_sub_integral' => [
			'title' => '系统减少积分',
			'category' => 'integral',
			'type' => 'system_sub',
			'mark' => '系统扣除了{%num%}积分，{%mark%}',
			'status' => 1,
			'pm' => 0
		],
        'community_add_integral' => [
            'title' => '发布种草内容奖励积分',
            'category' => 'integral',
            'type' => 'community_add_integral',
            'mark' => '发布种草内容赠送{%num%}积分',
            'status' => 1,
            'pm' => 1
        ],
        'community_add_exp' => [
            'title' => '发布种草内容奖励经验',
            'category' => 'exp',
            'type' => 'community_add_exp',
            'mark' => '发布种草内容赠送{%num%}经验',
            'status' => 1,
            'pm' => 1
        ],
//        'reply_add_integral' => [
//            'title' => '评价赠送积分',
//            'category' => 'integral',
//            'type' => 'reply_add_integral',
//            'mark' => '评价商品赠送{%num%}积分',
//            'status' => 1,
//            'pm' => 1
//        ],
//        'reply_add_exp' => [
//            'title' => '评价赠送经验',
//            'category' => 'exp',
//            'type' => 'reply_add_exp',
//            'mark' => '评价商品赠送{%num%}经验',
//            'status' => 1,
//            'pm' => 1
//        ],
    ];

    /**
     * UserBillServices constructor.
     * @param UserBillDao $dao
     */
    public function __construct(UserBillDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     *  获取用户记录总和
     * @param $uid
     * @param string $category
     * @param array $type
     * @return mixed
     */
    public function getRecordCount(int $uid, $category = 'now_money', $type = [], $time = '', $pm = false)
    {

        $where = [];
        $where['uid'] = $uid;
        $where['category'] = $category;
        $where['status'] = 1;

        if (is_string($type) && strlen(trim($type))) {
            $where['type'] = explode(',', $type);
        }
        if ($time) {
            $where['time'] = $time;
        }

		$where['pm'] = $pm ? 1 : 0;

        return $this->dao->getBillSumColumn($where);
    }

    /**
     * 获取积分列表
     * @param int $uid
     * @param array $where_time
     * @param string $field
     * @return array
     */
    public function getIntegralList(int $uid = 0, $where_time = [], string $field = '*')
    {
        [$page, $limit] = $this->getPageValue();
        $where = ['category' => 'integral'];
        if ($uid) $where['uid'] = $uid;
        if ($where_time) $where['add_time'] = $where_time;
		$list = $this->dao->getList($where, $field, $page, $limit);
		$count = $this->dao->count($where);
		$times = [];
		if ($list) {
			foreach ($list as &$item) {
				$addTime = strtotime($item['add_time']);
				$item['time_key'] = $item['time'] = $addTime ? date('Y-m', (int)$addTime) : '';
				$item['day'] = $addTime ? date('Y-m-d', (int)$addTime) : '';
				$item['number'] = intval($item['number']);
				$item['balance'] = intval($item['balance']);
                $item['is_frozen'] = $item['frozen_time'] > time() ? 1 : 0;
			}
			$times = array_merge(array_unique(array_column($list, 'time_key')));
		}
		return compact('list', 'count', 'times');
    }

    /**
     * 获取签到列表
     * @param int $uid
     * @param array $where_time
     * @param string $field
     * @return array
     */
    public function getSignList(int $uid = 0, $where_time = [], string $field = '*')
    {
        [$page, $limit] = $this->getPageValue();
        $where = ['category' => 'integral', 'type' => 'sign'];
        if ($uid) $where['uid'] = $uid;
        if ($where_time) $where['add_time'] = $where_time;
        $list = $this->dao->getList($where, $field, $page, $limit);
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**
     * 经验总数
     * @param int $uid
     * @param array $where_time
     * @return float
     */
    public function getExpSum(int $uid = 0, $where_time = [])
    {
        $where = ['category' => ['exp'], 'pm' => 1, 'status' => 1];
        if ($uid) $where['uid'] = $uid;
        if ($where_time) $where['time'] = $where_time;
        return $this->dao->getBillSum($where);
    }

    /**
     * 获取所有经验列表
     * @param int $uid
     * @param array $where_time
     * @param string $field
     * @return array
     */
    public function getExpList(int $uid = 0, $where_time = [], string $field = '*')
    {
        [$page, $limit] = $this->getPageValue();
        $where = ['category' => ['exp']];
        $where['status'] = 1;
        if ($uid) $where['uid'] = $uid;
        if ($where_time) $where['time'] = $where_time;
        $list = $this->dao->getList($where, $field, $page, $limit);
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

	/**
	 * 记录积分变化
	 * @param int $uid
	 * @param string $type
	 * @param array $data
	 * @param int $pm
	 * @return bool
	 * @throws Exception
	 */
    public function incomeIntegral(int $uid, string $type, array $data, int $pm = 1)
    {
        $data['uid'] = $uid;
        $data['category'] = 'integral';
        $data['type'] = $type;
        $data['pm'] = $pm;
        $data['status'] = 1;
        $data['add_time'] = time();
        if (!$this->dao->save($data))
            throw new Exception('增加记录失败');
        return true;
    }


    /**
     * 写入用户记录
     * @param string $type 写入类型
     * @param int $uid
     * @param int|string|array $number
     * @param int|string $balance
     * @param int $link_id
     * @return bool|mixed
     */
    public function income(string $type, int $uid, $number, $balance, $link_id = 0, $obtain_time = 0, $mark = '')
    {
        $data = $this->incomeData[$type] ?? null;
        if (!$data) {
            return true;
        }
        $data['uid'] = $uid;
        $data['balance'] = $balance ?? 0;
        $data['link_id'] = $link_id;
        if (is_array($number)) {
            $key = array_keys($number);
            $key = array_map(function ($item) {
                return '{%' . $item . '%}';
            }, $key);
            $value = array_values($number);
            $data['number'] = $number['number'] ?? $number['num'] ?? 0;
            $data['mark'] = trim(str_replace($key, $value, $data['mark']), '，');
        } else {
            $data['number'] = $number;
            $data['mark'] = trim(str_replace(['{%num%}'], $number, $data['mark']), '，');
        }
		if (!(int)$data['number']) {//为0的积分，不记录
			return true;
		}
        if ($mark) $data['mark'] = $mark;
        $data['add_time'] = time();
        $data['obtain_time'] = $obtain_time;
		//需要冻结积分:下单商品关联赠送积分 下单赠送积分 下单优惠活动赠送积分
		if (in_array($type, ['pay_give_integral', 'order_give_integral', 'order_promotions_give_integral'])) {
            $integral_frozen = sys_config('integral_frozen');
            if ($integral_frozen) {
                $data['frozen_time'] = $data['add_time'] + ($integral_frozen * 86400);
            }
        }

        return $this->dao->save($data);
    }

    /**
     * 邀请新用户增加经验
     * @param int $spreadUid
     */
    public function inviteUserIncExp(int $spreadUid)
    {
        if (!$spreadUid) {
            return false;
        }
        //用户等级是否开启
        if (!sys_config('member_func_status', 1)) {
            return false;
        }
        /** @var UserServices $userService */
        $userService = app()->make(UserServices::class);
        $spread_user = $userService->getUserInfo($spreadUid);
        if (!$spread_user) {
            return false;
        }
        $exp_num = sys_config('invite_user_exp', 0);
        if ($exp_num) {
            $userService->incField($spreadUid, 'exp', (int)$exp_num);
            $data = [];
            $data['uid'] = $spreadUid;
            $data['number'] = $exp_num;
            $data['category'] = 'exp';
            $data['type'] = 'invite_user';
            $data['title'] = $data['mark'] = '邀新奖励';
            $data['balance'] = (int)$spread_user['exp'];
            $data['pm'] = 1;
            $data['status'] = 1;
            $this->dao->save($data);
        }
		//检测会员升级
		UserLevelJob::dispatch([$spreadUid]);
        return true;
    }

    /**
     * 获取type
     * @param array $where
     * @param string $filed
     */
    public function getBillType(array $where)
    {
        return $this->dao->getType($where);
    }

    /**
     * 资金类型
     */
    public function bill_type()
    {
        $where = [];
        $where['not_type'] = ['gain', 'system_sub', 'deduction', 'sign'];
        $where['not_category'] = ['exp', 'integral'];
        return CacheService::get('user_type_list', function () use ($where) {
            return ['list' => $this->dao->getType($where)];
        }, 600);
    }

    /**
     * 记录分享次数
     * @param int $uid 用户uid
     * @param int $cd 冷却时间
     * @return Boolean
     * */
    public function setUserShare(int $uid, $cd = 300)
    {
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $user = $userServices->getUserInfo($uid);
        if (!$user) {
            throw new ValidateException('用户不存在！');
        }
        $cachename = 'Share_' . $uid;
        if (CacheService::get($cachename)) {
            return false;
        }
        $data = ['title' => '用户分享记录', 'uid' => $uid, 'category' => 'share', 'type' => 'share', 'number' => 0, 'link_id' => 0, 'balance' => 0, 'mark' => date('Y-m-d H:i:s', time()) . ':用户分享'];
        if (!$this->dao->save($data)) {
            throw new ValidateException('记录分享记录失败');
        }
        CacheService::set($cachename, 1, $cd);
        return true;
    }

    /**
     * 获取积分列表
     * @param array $where
     * @param string $field
     * @param int $limit
     * @return array
     */
    public function getPointList(array $where, string $field = '*', int $limit = 0)
    {
        $where_data = [];
        $where_data['category'] = 'integral';
        if (isset($where['uid']) && $where['uid'] != '') {
            $where_data['uid'] = $where['uid'];
        }
        if ($where['start_time'] != '' && $where['end_time'] != '') {
            $where_data['time'] = $where['start_time'] . ' - ' . $where['end_time'];
        }
        if (isset($where['type']) && $where['type'] != '') {
            $where_data['type'] = $where['type'];
        }
        if (isset($where['nickname']) && $where['nickname'] != '') {
            $where_data['like'] = $where['nickname'];
        }
        if (isset($where['excel']) && $where['excel'] != '') {
            $where_data['excel'] = $where['excel'];
        } else {
            $where_data['excel'] = 0;
        }
        if ($limit) {
            [$page] = $this->getPageValue();
        } else {
            [$page, $limit] = $this->getPageValue();
        }
        $list = $this->dao->getBillList($where_data, $field, $page, $limit);
        foreach ($list as &$item) {
            $item['nickname'] = $item['user']['nickname'] ?? '';
            $item['number'] = intval($item['number']);
            $item['balance'] = intval($item['balance']);
            unset($item['user']);
        }
        $count = $this->dao->count($where_data);
        return compact('list', 'count');
    }

    /**
     * 积分头部信息
     * @param array $where
     * @return array[]
     */
    public function getUserPointBadgelist(array $where)
    {
        $data = [];
        $where_data = [];
        $where_data['category'] = 'integral';
        if ($where['start_time'] != '' && $where['end_time'] != '') {
            $where_data['time'] = $where['start_time'] . ' - ' . $where['end_time'];
        }
        if (isset($where['nickname']) && $where['nickname'] != '') {
            $where_data['like'] = $where['nickname'];
        }
		$data['SumIntegral'] = intval($this->dao->getBillSumColumn($where_data + ['pm' => 1]));
		$where_data['type'] = 'sign';
		$data['CountSign'] = $this->dao->getUserSignPoint($where_data);
		$data['SumSign'] = intval($this->dao->getBillSumColumn($where_data));
		unset($where_data['type']);
		$data['SumDeductionIntegral'] = intval($this->dao->getBillSumColumn($where_data + ['pm' => 0]));
        return [
            [
                'col' => 6,
                'count' => $data['SumIntegral'],
                'name' => '总积分(个)',
            ],
            [
                'col' => 6,
                'count' => $data['CountSign'],
                'name' => '客户签到次数(次)',
            ],
            [
                'col' => 6,
                'count' => $data['SumSign'],
                'name' => '签到送出积分(个)',
            ],
            [
                'col' => 6,
                'count' => $data['SumDeductionIntegral'],
                'name' => '使用积分(个)',
            ],
        ];
    }

    /**
     * @param $uid
     * @param $type
     * @return array
     */
    public function getUserBillList(int $uid, int $type)
    {
        $where = [];
        $where['uid'] = $uid;
        $where['category'] = 'now_money';
        switch ((int)$type) {
            case 0:
                $where['type'] = ['recharge', 'pay_money', 'system_add', 'pay_product_refund', 'system_sub', 'pay_member', 'offline_scan', 'lottery_use', 'lottery_add'];
                break;
            case 1:
                $where['type'] = ['pay_money', 'pay_member', 'offline_scan', 'user_recharge_refund', 'lottery_use'];
                break;
            case 2:
                $where['type'] = ['recharge', 'system_add', 'lottery_add'];
                break;
            case 3:
                $where['type'] = ['brokerage', 'brokerage_user'];
                break;
            case 4:
                $where['type'] = ['extract'];
                break;
        }
        $field = 'FROM_UNIXTIME(add_time,"%Y-%m") as time,group_concat(id SEPARATOR ",") ids';
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getUserBillListByGroup($where, $field, 'time', $page, $limit);
        $data = [];
        if ($list) {
            $listIds = array_column($list, 'ids');
            $ids = [];
            foreach ($listIds as $id) {
                $ids = array_merge($ids, explode(',', $id));
            }
            $info = $this->dao->getColumn([['id', 'in', $ids]], 'FROM_UNIXTIME(add_time,"%Y-%m-%d %H:%i") as add_time,title,number,pm', 'id');
            foreach ($list as $item) {
                $value['time'] = $item['time'];
                $id = explode(',', $item['ids']);
                array_multisort($id, SORT_DESC);
                $value['list'] = [];
                foreach ($id as $v) {
                    if (isset($info[$v])) {
                        $value['list'][] = $info[$v];
                    }
                }
                array_push($data, $value);
            }
        }
        return $data;
    }
}
