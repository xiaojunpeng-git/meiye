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

namespace app\dao\user;

use app\model\store\StoreUser;
use app\services\user\UserListStatServices;
use think\model;
use app\dao\BaseDao;
use app\model\user\User;
use app\model\wechat\WechatUser;

/**
 *
 * Class UserWechatUserDao
 * @package app\dao\user
 */
class UserWechatUserDao extends BaseDao
{
    /**
     * @var string
     */
    protected $alias = '';

    /**
     * @var string
     */
    protected $join_alis = '';

    /**
     * 精确搜索白名单
     * @var string[]
     */
    protected $withField = ['uid', 'nickname', 'user_type', 'phone'];

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return User::class;
    }

    public function joinModel(): string
    {
        return WechatUser::class;
    }

    /**
     * 关联模型
     * @param string $alias
     * @param string $join_alias
     * @return \mohe\basic\BaseModel
     */
    public function getModel(string $alias = 'u', string $join_alias = 'w', $join = 'left')
    {
        $this->alias = $alias;
        $this->join_alis = $join_alias;
        /** @var WechatUser $wechcatUser */
        $wechcatUser = app()->make($this->joinModel());
        $table = $wechcatUser->getName();
        return parent::getModel()->withTrashed()->alias($alias)->join($table . ' ' . $join_alias, $alias . '.uid = ' . $join_alias . '.uid', $join);
    }

    /**
     * 获取列表
     * @param array $where
     * @param string $field
     * @param int $page
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, $field = '*', int $page = 0, int $limit = 0)
    {
        return $this->getModel()->where($where)->field($field)->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->select()->toArray();
    }

    /**
     * 获取总数
     * @param array $where
     * @return int
     */
    public function getCount(array $where): int
    {
        return $this->getModel()->where($where)->count();
    }

    /**
     * 组合条件模型条数
     * @param Model $model
     * @return int
     */
    public function getCountByWhere(array $where): int
    {
        return $this->searchWhere($where)->group($this->alias . '.uid')->count();
    }

    /**
     * 组合条件模型查询列表
     * @param array $where
     * @param string $field
     * @param string $order
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function getListByModel(array $where, string $field = '', string $order = '', int $page = 0, int $limit = 0): array
    {
        return $this->searchWhere($where)->field($field)->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->group($this->alias . '.uid')->order(($order ? $order . ' ,' : '') . $this->alias . '.uid desc')->select()->toArray();
    }

    /**
     * @param $where
     * @param array|null $field
     * @param int $page
     * @param int $limit
     * @return \mohe\basic\BaseModel
     */
    public function searchWhere($where, ?array $field = [])
    {
        $model = $this->getModel();
		if (isset($where['store_id']) && $where['store_id']) {//关联门店用户表
			/** @var WechatUser $wechcatUser */
			$storeUser = app()->make(StoreUser::class);
			$table = $storeUser->getName();
			$model->join($table . ' ' . 's', $this->alias . '.uid = s.uid', 'left');
			if (is_array($where['store_id'])) {
				$model->whereIn('s.store_id', $where['store_id']);
			} else {
				$model->where('s.store_id', $where['store_id']);
			}
		}
        $userAlias = $this->alias . '.';
        $wechatUserAlias = $this->join_alis . '.';
        if (isset($where['is_filter_del']) && $where['is_filter_del'] == 1) {
            $model = $model->where($userAlias . 'delete_time', null);
        }
        if (isset($where['uid']) && $where['uid'] !== '') {
            if (is_array($where['uid'])) {
                $model = $model->whereIn($userAlias . 'uid', $where['uid']);
            } else {
                $model = $model->where($userAlias . 'uid', $where['uid']);
            }
        }
        if (isset($where['birthday_type']) && !empty($where['birthday_type'])) {
            if($where['birthday_type'] == 1){
                //今天生日
                $model = $model->whereTime($userAlias .'birthday', 'today');
            }
            if($where['birthday_type'] == 2){
                //明天生日
                $start = strtotime(date('Y-m-d 00:00:00', strtotime("+1 day")));
                $end = strtotime(date('Y-m-d 23:59:59', strtotime("+1 day")));
                $model = $model->whereBetween($userAlias .'birthday', [$start,$end]);
            }
            if($where['birthday_type'] == 3){
                //本月生日
                $model = $model->whereTime($userAlias .'birthday', 'month');
            }
        }
        // 用户访问时间
        if (isset($where['user_time_type']) && isset($where['user_time'])) {
            //最后一次访问时间
            if ($where['user_time_type'] == 'visitno' && $where['user_time'] != '') {
                [$startTime, $endTime] = explode('-', $where['user_time']);
                if ($startTime && $endTime) {
                    $endTime = strtotime($endTime) + 24 * 3600;
                    $model = $model->where($userAlias . "last_time < " . strtotime($startTime) . " OR " . $userAlias . "last_time > " . $endTime);
                }
            }
            //访问时间
            if ($where['user_time_type'] == 'visit' && $where['user_time'] != '') {
                [$startTime, $endTime] = explode('-', $where['user_time']);
                if ($startTime && $endTime) {
                    $model = $model->where($userAlias . 'last_time', '>', strtotime($startTime));
                    $model = $model->where($userAlias . 'last_time', '<', strtotime($endTime) + 24 * 3600);
                }
            }
            //添加时间
            if ($where['user_time_type'] == 'add_time' && $where['user_time'] != '') {
                [$startTime, $endTime] = explode('-', $where['user_time']);
                if ($startTime && $endTime) {
                    $model = $model->where($userAlias . 'add_time', '>', strtotime($startTime));
                    $model = $model->where($userAlias . 'add_time', '<', strtotime($endTime) + 24 * 3600);
                }
            }
        }
        //当前积分
        if (isset($where['integral']) && $where['integral'] != '-' && $where['integral'] != '') {
            $integral = explode('-',$where['integral']);
            $model = $model->whereBetween($userAlias . 'integral', $integral);
        }
        //当前余额
        if (isset($where['now_money_peice']) && $where['now_money_peice'] != '-' && $where['now_money_peice'] != '') {
            $now_money_peice = explode('-',$where['now_money_peice']);
            $model = $model->whereBetween($userAlias . 'now_money', $now_money_peice);
        }
        //消费金额
//        if (isset($where['pay_price']) && $where['pay_price'] != '-' && $where['pay_price'] != '') {
//            $pay_price = explode('-',$where['pay_price']);
//            $model = $model->whereIn($userAlias . 'uid', function ($query) use ($pay_price) {
//                $query->name('store_order')->where(['pid' => 0, 'paid' => 1, 'refund_status' => [0, 3], 'is_del' => 0, 'is_system_del' => 0])->group('uid')->having('sum(pay_price) < ' . $pay_price[1])
//                    ->having('sum(pay_price) > ' . $pay_price[0])
//                    ->field('uid')->select();
//                $query->name('store_order')->getLastSql();
//            });
//        }
        //储值次数
//        if (isset($where['recharge_sum']) && $where['recharge_sum'] != '-' && $where['recharge_sum'] != '') {
//            $recharge_sum = explode('-',$where['recharge_sum']);
//            $model = $model->whereIn($userAlias . 'uid', function ($query) use ($recharge_sum) {
//                $query->name('user_recharge')->group('uid')->having('count(uid) < ' . $recharge_sum[1])->having('sum(uid) > ' . $recharge_sum[0])->field('uid')->select();
//            });
//        }
        //储值金额
//        if (isset($where['recharge_price']) && $where['recharge_price'] != '-' && $where['recharge_price'] != '') {
//            $recharge_price = explode('-',$where['recharge_price']);
//            $model = $model->whereIn($userAlias . 'uid', function ($query) use ($recharge_price) {
//                $query->name('user_recharge')->group('uid')->having('sum(price) < ' . $recharge_price[1])->having('sum(price) > ' . $recharge_price[0])->field('uid')->select();
//            });
//        }
        //用户等级
        if (isset($where['level']) && $where['level']) {
            $model = $model->where($userAlias . 'level', $where['level']);
        }
        //用户分组
        if (isset($where['group_id']) && $where['group_id']) {
            $model = $model->where($userAlias . 'group_id', $where['group_id']);
        }
        //用户状态
        if (isset($where['status']) && $where['status'] !== '') {
            $model = $model->where($userAlias . 'status', $where['status']);
        }
        //用户是否为推广员
        if (isset($where['is_promoter']) && $where['is_promoter'] !== '') {
            $model = $model->where($userAlias . 'is_promoter', $where['is_promoter']);
        }
		//用户归属门店
		if (isset($where['belong_store_id']) && $where['belong_store_id'] != '') {
			$model = $model->where($userAlias . 'belong_store_id', $where['belong_store_id']);
		}
        //用户标签
        if (isset($where['label_id']) && $where['label_id']) {
            $model = $model->whereIn($userAlias . 'uid', function ($query) use ($where) {
                if (is_array($where['label_id'])) {
                    $query->name('user_label_relation')->whereIn('label_id', $where['label_id'])->field('uid')->select();
                } else {
                    if (strpos($where['label_id'], ',') !== false) {
                        $query->name('user_label_relation')->whereIn('label_id', explode(',', $where['label_id']))->field('uid')->select();
                    } else {
                        $query->name('user_label_relation')->where('label_id', $where['label_id'])->field('uid')->select();
                    }
                }
            });
        }
        //是否付费会员
        if (isset($where['isMember']) && $where['isMember'] != '') {
            if ($where['isMember'] == 0) {
                $model = $model->where($userAlias . 'is_money_level', 0);
            } else {
                $model = $model->where($userAlias . 'is_money_level', '>', 0);
            }

        }
        //用户昵称,uid,手机号搜索
        $fieldKey = $where['field_key'] ?? '';
        $nickname = $where['nickname'] ?? '';
        if ($fieldKey && $nickname && in_array($fieldKey, $this->withField)) {
            switch ($fieldKey) {
                case "nickname":
                case "phone":
                    $model = $model->where($userAlias . trim($fieldKey), 'like', "%" . trim($nickname) . "%");
                    break;
                case "uid":
                    $model = $model->where($userAlias . trim($fieldKey), trim($nickname));
                    break;
            }
        } else if ((!$fieldKey || $fieldKey == 'all') && $nickname) {
            $model = $model->where($userAlias . 'real_name|' .$userAlias . 'nickname|' . $userAlias . 'uid|' . $userAlias . 'phone', 'LIKE', "%$where[nickname]%");
        }
        //用户类型
        if (isset($where['user_type']) && $where['user_type']) {
            $model = $model->where($userAlias . 'user_type', $where['user_type']);
        }
        //用户性别
        if (isset($where['sex']) && $where['sex'] !== '' && in_array($where['sex'], [0, 1, 2])) {
            $model = $model->where(function ($query) use ($wechatUserAlias, $userAlias, $where) {
                $query->where($userAlias . 'sex', $where['sex']);
            });
        }
        //所在国家 所在省份 所在城市
        if ((isset($where['country']) && $where['country']) || (isset($where['province']) && $where['province']) || (isset($where['city']) && $where['city'])) {
            $model = $model->where(function ($query) use ($wechatUserAlias, $userAlias, $where) {
                $query->when(isset($where['country']) && $where['country'], function ($g) use ($wechatUserAlias, $userAlias, $where) {
                    if ($where['country'] == 'domestic') {
                        $g->where($wechatUserAlias . 'country', 'in', ['中国', 'China']);
                    } else if ($where['country'] == 'abroad') {
                        $g->whereOr($userAlias . 'addres', '');
                    }
                })->when(isset($where['province']) && $where['province'], function ($q) use ($wechatUserAlias, $userAlias, $where) {
                    $q->whereOr($wechatUserAlias . 'province', $where['province'])->whereOr($userAlias . 'provincials', 'Like', '%' . $where['province'] . '%')->whereOr($userAlias . 'addres', 'Like', '%' . $where['province'] . '%');
                })->when(isset($where['city']) && $where['city'], function ($c) use ($wechatUserAlias, $userAlias, $where) {
                    $c->whereOr($wechatUserAlias . 'city', $where['city'])->whereOr($userAlias . 'provincials', 'Like', '%' . $where['city'] . '%')->whereOr($userAlias . 'addres', 'Like', '%' . $where['city'] . '%');
                });
            });
        }

        if (isset($where['time'])) {
            $model->withSearch(['time'], ['time' => $where['time'], 'timeKey' => 'u.add_time']);
        }
        $model = UserListStatServices::applySearchFilters($model, $where, $userAlias, null);
        return $field ? $model->field($field) : $model;
    }

    /**
     * 地域全部用户
     * @param $time
     * @param $userType
     * @return mixed
     */
    public function getRegionAll($time, $userType)
    {
        return $this->getModel()->when($userType != '', function ($query) use ($userType) {
            $query->where($this->alias . '.user_type', $userType);
        })->where(function ($query) use ($time) {
            $query->whereTime($this->alias . '.add_time', '<', strtotime($time[1]) + 86400)->whereOr($this->alias . '.add_time', NULL);
        })->field('count(' . $this->alias . '.uid) as allNum,' . $this->join_alis . '.province')
            ->group($this->join_alis . '.province')->select()->toArray();
    }

    /**
     * 地域新增用户
     * @param $time
     * @param $userType
     * @return mixed
     */
    public function getRegionNew($time, $userType)
    {
        return $this->getModel()->when($userType != '', function ($query) use ($userType) {
            $query->where($this->alias . '.user_type', $userType);
        })->where(function ($query) use ($time) {
            if ($time[0] == $time[1]) {
                $query->whereDay($this->alias . '.add_time', $time[0]);
            } else {
                $time[1] = date('Y/m/d', strtotime($time[1]) + 86400);
                $query->whereTime($this->alias . '.add_time', 'between', $time);
            }
        })->field('count(' . $this->alias . '.uid) as newNum,' . $this->join_alis . '.province')
            ->group($this->join_alis . '.province')->select()->toArray();
    }

    /**
     * 获取用户性别
     * @param $time
     * @param $userType
     * @return mixed
     */
    public function getSex($time, $userType)
    {
        return $this->getModel()->when($userType != '', function ($query) use ($userType) {
            $query->where($this->join_alis . '.user_type', $userType);
        })->where(function ($query) use ($time) {
            if ($time[0] == $time[1]) {
                $query->whereDay($this->alias . '.add_time', $time[0]);
            } else {
                $time[1] = date('Y/m/d', strtotime($time[1]) + 86400);
                $query->whereTime($this->alias . '.add_time', 'between', $time);
            }
        })->field($this->alias . '.uid,' . $this->alias . '.sex as u_name,' . $this->join_alis . '.sex as w_name')
            ->select()->toArray();
    }
}
