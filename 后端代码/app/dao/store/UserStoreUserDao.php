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

namespace app\dao\store;

use think\model;
use app\dao\BaseDao;
use app\model\user\User;
use app\model\store\StoreUser;
use app\services\user\UserListStatServices;

/**
 * Class UserStoreUserDao
 * @package app\dao\store
 */
class UserStoreUserDao extends BaseDao
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
        return StoreUser::class;
    }

    /**
     * 关联模型
     * @param string $alias
     * @param string $join_alias
     * @param string $join
     * @return \mohe\basic\BaseModel
     */
    public function getModel(string $alias = 'u', string $join_alias = 's', $join = '')
    {
        $this->alias = $alias;
        $this->join_alis = $join_alias;
        /** @var StoreUser $storeUser */
        $storeUser = app()->make($this->joinModel());
        $table = $storeUser->getName();
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
        $userAlias = $this->alias . '.';
        $storeUserAlias = $this->join_alis . '.';
        if (isset($where['is_filter_del']) && $where['is_filter_del'] == 1) {
            $model = $model->where($userAlias . 'delete_time', null);
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
        //门店
        if (isset($where['store_ids']) && is_array($where['store_ids']) && $where['store_ids']) {
            $model = $model->whereIn($storeUserAlias . 'store_id', array_map('intval', $where['store_ids']));
        } elseif (isset($where['store_id']) && $where['store_id'] !== '') {
            $model = $model->where($storeUserAlias . 'store_id', $where['store_id']);
        }
        // 门店客户归属时间（store_user.add_time，与新增客户口径一致；禁止误用 u.add_time）
        if (!empty($where['store_user_add_time']) && is_array($where['store_user_add_time']) && count($where['store_user_add_time']) >= 2) {
            $suStart = (int)$where['store_user_add_time'][0];
            $suEnd = (int)$where['store_user_add_time'][1];
            if ($suStart > 0 && $suEnd >= $suStart) {
                $model = $model->whereBetween($storeUserAlias . 'add_time', [$suStart, $suEnd]);
            }
        }
        // 归属店员（我的客户）
        if (isset($where['salesman_id']) && (int)$where['salesman_id'] > 0) {
            $model = $model->where($userAlias . 'salesman_id', (int)$where['salesman_id']);
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
            $integral = explode('-', $where['integral']);
            $model = $model->whereBetween($userAlias . 'integral', $integral);
        }
        //当前余额
        if (isset($where['now_money_peice']) && $where['now_money_peice'] != '-' && $where['now_money_peice'] != '') {
            $now_money_peice = explode('-', $where['now_money_peice']);
            $model = $model->whereBetween($userAlias . 'now_money', $now_money_peice);
        }

        //用户等级
        if (isset($where['level']) && $where['level']) {
            $model = $model->where($userAlias . 'level', $where['level']);
        }
        //用户分组
        if (isset($where['group_id']) && $where['group_id']) {
            $model = $model->where($userAlias . 'group_id', $where['group_id']);
        }
        //用户状态
        if (isset($where['status']) && $where['status'] != '') {
            $model = $model->where($userAlias . 'status', $where['status']);
        }
        //用户是否为推广员
        if (isset($where['is_promoter']) && $where['is_promoter'] != '') {
            $model = $model->where($userAlias . 'is_promoter', $where['is_promoter']);
        }
        //用户标签
        if (isset($where['label_id']) && $where['label_id']) {
            $model = $model->whereIn($userAlias . 'uid', function ($query) use ($where) {
                if (is_array($where['label_id'])) {
                    $query->name('user_label_relation')->whereIn('label_id', $where['label_id'])->field('uid')->select();
                } else {
                    $query->name('user_label_relation')->where('label_id', $where['label_id'])->field('uid')->select();
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
                    $model = $model->where($userAlias . trim($fieldKey), 'like', "%" . trim($nickname) . "%");
                    break;
                case "phone":
                    $model = $model->where($userAlias . trim($fieldKey), 'like', '%' . trim($nickname) . '%');
                    break;
                case "uid":
                    $model = $model->where($userAlias . trim($fieldKey), trim($nickname));
                    break;
            }
        } else if ((!$fieldKey || $fieldKey === 'all') && $nickname) {
            $model = $model->where($userAlias . 'real_name|' . $userAlias . 'nickname|' . $userAlias . 'uid|' . $userAlias . 'phone', 'LIKE', "%$nickname%");
        }
        //用户类型
        if (isset($where['user_type']) && $where['user_type']) {
            $model = $model->where($userAlias . 'user_type', $where['user_type']);
        }
        //用户性别
        if (isset($where['sex']) && $where['sex'] !== '' && in_array($where['sex'], [0, 1, 2])) {
            $model = $model->where($userAlias . 'sex', $where['sex']);
        }
        if (isset($where['time'])) {
            $model->withSearch(['time'], ['time' => $where['time'], 'timeKey' => 'u.add_time']);
        }
        $storeId = isset($where['store_id']) && $where['store_id'] !== '' ? (int)$where['store_id'] : null;
        $model = UserListStatServices::applySearchFilters($model, $where, $userAlias, $storeId);
        return $field ? $model->field($field) : $model;
    }
}
