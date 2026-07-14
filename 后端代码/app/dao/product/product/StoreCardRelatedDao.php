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

namespace app\dao\product\product;

use app\dao\BaseDao;
use app\model\product\product\StoreCardRelated;
use think\facade\Config;

/**
 * Class StoreCardRelatedDao
 * @package app\dao\product\product
 */
class StoreCardRelatedDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return StoreCardRelated::class;
    }

    /**
     * 搜索
     * @param array $where
     * @return \mohe\basic\BaseModel|mixed|\think\Model
     */
    public function search(array $where = [])
    {
        return parent::search($where)->when(!empty($where['product_id']),function($query) use ($where){
            if(is_array($where['product_id'])){
                $query->whereIn("product_id",$where['product_id']);
            }else{
                $query->where("product_id",$where['product_id']);
            }
        })->where('status',1);
    }

    /**
     * 获取卡项关联商品列表
     * @param array $where
     * @param int $page
     * @param int $limit
     * @param array $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, int $page = 0, int $limit = 0, array $with = [])
    {
        return $this->search($where)->when(count($with), function ($query) use ($with) {
                $query->with($with);
            })->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->select()->toArray();
    }

    /**
     * 设置
     * @param array $ids
     * @param int $status
     * @param string $key
     * @return \mohe\basic\BaseModel
     */
    public function setStatus(array $ids, int $status = 1, string $key = 'product_id')
    {
        return $this->getModel()->whereIn($key, $ids)->update(['status' => $status]);
    }
}
