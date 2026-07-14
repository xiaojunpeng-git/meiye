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
use app\model\store\DeliveryService;

/**
 * 配送dao
 * Class DeliveryServiceDao
 * @package app\dao\store
 */
class DeliveryServiceDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return DeliveryService::class;
    }

    /**
     * 获取配送员列表
     * @param array $where
     * @param int $page
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getServiceList(array $where, int $page = 0, int $limit = 0)
    {
        $realName = $where['keyword'] ?? '';
        $fieldKey = $where['field_key'] ?? '';
        $fieldKey = $fieldKey == 'all' ? '' : $fieldKey;
        return $this->search($where)->with('user')->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->when($realName && $fieldKey && in_array($fieldKey, ['id', 'phone']), function ($query) use ($where, $realName, $fieldKey) {
            $query->whereLike($fieldKey, '%' . $where['keyword'] . '%');
        })->when($realName && !$fieldKey, function ($query) use ($where) {
            $query->whereLike('uid|id|nickname|phone', '%' . $where['keyword'] . '%');
        })->when(isset($where['noId']), function ($query) use ($where) {
            $query->where('id', '<>', $where['noId']);
        })->order('id DESC')->field('id,uid,avatar,nickname as wx_name,status,add_time,phone')->select()->toArray();
    }

    /**
     * 获取配送员select
     * @param array $where
     * @return array
     */
    public function getSelectList(array $where)
    {
        return $this->search($where)->field('uid,nickname')->select()->toArray();
    }

}
