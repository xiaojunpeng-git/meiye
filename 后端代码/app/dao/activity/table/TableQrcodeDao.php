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

namespace app\dao\activity\table;


use app\dao\BaseDao;
use app\model\activity\table\TableQrcode;

/**
 * 桌码
 * Class TableQrcodeDao
 * @package app\dao\activity\table
 */
class TableQrcodeDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return TableQrcode::class;
    }

    /**条件处理
     * @param array $where
     * @return \mohe\basic\BaseModel|mixed|\think\Model
     */
    public function search(array $where = [])
    {
        return parent::search($where)->when(isset($where['store_id']) && $where['store_id'], function ($query) use ($where) {
            $query->where('store_id', $where['store_id']);
        })->when(isset($where['cate_id']) && $where['cate_id'], function ($query) use ($where) {
            $query->where('cate_id', $where['cate_id']);
        })->when(isset($where['is_del']), function ($query) use ($where) {
            $query->where('is_del', $where['is_del']);
        });
    }

    /**获取桌码列表
     * @param array $where
     * @param int $storeId
     * @param int $page
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, int $page, int $limit,array $with)
    {
        return $this->search($where)->when(count($with), function ($query) use ($with) {
            $query->with($with);
        })->page($page,$limit)->order('add_time Asc')->select()->toArray();
    }

    /**获取座位信息
     * @param array $where
     * @param array $with
     * @return array|\mohe\basic\BaseModel|mixed|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getTableCodeOne(array $where, array $with)
    {
        return $this->getModel()->where($where)->when(count($with), function ($query) use ($with) {
            $query->with($with);
        })->find();
    }
}
