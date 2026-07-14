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

namespace app\services\activity\table;

use app\services\BaseServices;
use app\dao\activity\table\TableSeatsDao;

/**
 *
 * Class TableSeatsServices
 * @package app\services\activity\table
 * @mixin TableSeatsDao
 */
class TableSeatsServices extends BaseServices
{

    /**
     * UserCollageCodeServices constructor.
     * @param TableSeatsDao $dao
     */
    public function __construct(TableSeatsDao $dao)
    {
        $this->dao = $dao;
    }

    /**获取餐桌座位数
     * @param int $storeId
     * @return array
     */
    public function tableSeatsList(int $storeId)
    {
        return $this->dao->tableSeats($storeId, []);
    }
}
