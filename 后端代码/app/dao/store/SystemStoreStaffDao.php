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

namespace app\dao\store;


use app\dao\BaseDao;
use app\model\store\SystemStoreStaff;
use think\facade\Db;

/**
 * 门店店员
 * Class SystemStoreStaffDao
 * @package app\dao\store
 */
class SystemStoreStaffDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return SystemStoreStaff::class;
    }

    /**
     * @return \mohe\basic\BaseModel
     */
    public function getWhere()
    {
        return $this->getModel();
    }

    /**
     * 门店店员搜索器
     * @param array $where
     * @return \mohe\basic\BaseModel|mixed|\think\Model
     */
    public function search(array $where = [])
    {
        return parent::search($where)->when(isset($where['keyword']) && $where['keyword'], function ($query) use ($where) {
            if (!isset($where['field_key']) || $where['field_key'] == '') {
                $query->whereLike('id|uid|staff_name|phone', '%' . $where['keyword'] . '%');
            } else {
                $query->where($where['field_key'], $where['keyword']);
            }
        })->when(isset($where['is_work_member']) && $where['is_work_member'], function ($query) use ($where) {
            $query->where('work_member_id', '>', 0);
        })->when(isset($where['salary_status']) && $where['salary_status'], function ($query) use ($where) {
            $query->where('salary_status',$where['salary_status']);
        })->when(isset($where['is_customer']) && $where['is_customer'], function ($query) use ($where) {
            $query->where('is_customer', 1);
        })->when(isset($where['staff_id']) && $where['staff_id'], function ($query) use ($where) {
            $query->where('id', $where['staff_id']);
        })->when(isset($where['is_shift']) && $where['is_shift'], function ($query) use ($where) {
            $query->where('shift_start_time','>', 0);
        })->when(isset($where['shift_time']) && $where['shift_time'] != '', function ($query) use ($where) {
            [$startTime, $endTime] = explode('-', $where['shift_time']);
            $startTime = trim($startTime) ? strtotime($startTime) : 0;
            $endTime = trim($endTime) ? strtotime($endTime) : 0;
            if ($startTime && $endTime) {
                if ($startTime == $endTime || $endTime == strtotime(date('Y-m-d', $endTime))) {
                    $endTime = $endTime + 86400;
                }
            }
            $query->whereBetween('shift_start_time', [$startTime, $endTime]);
        });
    }

    /**
     * 获取门店管理员列表
     * @param array $where
     * @param int $page
     * @param int $limit
     * @param array|string[] $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreAdminList(array $where, int $page = 0, int $limit = 0, array $with = ['user'])
    {
        return $this->search($where)->when($with, function ($query) use ($with) {
            $query->with($with);
        })->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->order('add_time DESC')->select()->toArray();
    }


    /**
     * 获取店员列表
     * @param array $where
     * @param string $field
     * @param int $page
     * @param int $limit
     * @param array|string[] $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreStaffList(array $where, string $field = '*', int $page = 0, int $limit = 0, array $with = ['store', 'user'],$order="add_time DESC")
    {
        return $this->search($where)->field($field)->when($with, function ($query) use ($with) {
            $query->with(array_merge($with, ['store', 'user']));
        })->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->when(isset($where['notId']), function ($query) use ($where) {
            $query->where('id', '<>', $where['notId']);
        })->where("is_del",0)->order($order)->select()->toArray();
    }

    /**
     * 组织范围列表先计算轻量候选 ID，再按同一后端筛选条件读取当前页详情。
     * 保留权限条件可防止候选 ID 与详情读取之间扩大可见范围。
     */
    public function getStoreStaffRowsByIds(array $where, array $ids, array $with = []): array
    {
        if (!$ids) return [];
        return $this->search($where)
            ->whereIn('id', $ids)
            ->where('is_del', 0)
            ->with(array_merge($with, ['store', 'user']))
            ->select()->toArray();
    }

    /**
     * 获取店员select
     * @param array $where
     * @return array
     */
    public function getSelectList(array $where,$field="id,staff_name")
    {
        return $this->search($where)->field($field)->select()->toArray();
    }

    public function getChooseList(array $where)
    {
        $storeId = (int)($where['store_id'] ?? 0);
        unset($where['store_id']);
        $query = $this->search($where);
        if ($storeId > 0) {
            $query->where(function ($q) use ($storeId) {
                $q->where('store_id', $storeId)->whereOr('can_choose', 1);
            });
        }
        $list = $query->select()->toArray();
       $position=Db::name("position")->column("name","id");
       $positionLevel=Db::name("positionLevel")->column("name","id");
       foreach ($list as &$v){
           $v['position_label']=$position[$v['position']] ?? '';
           $v['position_level_label']=$positionLevel[$v['position_level']] ?? '';
       }

       return $list;
    }
	/**
	 * 用户注销删除门店店员
	 * @param int $uid
	 * @return \mohe\basic\BaseModel
	 */
	public function cancelUserDel(int $uid)
	{
		return $this->getModel()->where('uid', $uid)->where('level', '>', 0)->update(['is_del' => 1]);
	}
}
