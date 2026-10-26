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


use app\dao\order\OtherOrderStatusDao;
use app\services\BaseServices;

/**
 * Class OtherOrderStatusServices
 * @package app\services\order
 * @mixin OtherOrderStatusDao
 */
class OtherOrderStatusServices extends BaseServices
{

    /**
     * OtherOrderStatusServices constructor.
     * @param OtherOrderStatusDao $dao
     */
    public function __construct(OtherOrderStatusDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取订单状态列表
     * @param int $id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStatusList(int $id)
    {
        return $this->dao->getStatusList(['oid' => $id], 0, 0);
    }
}
