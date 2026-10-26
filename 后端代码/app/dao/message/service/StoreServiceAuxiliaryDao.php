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

namespace app\dao\message\service;


use app\dao\other\queue\QueueAuxiliaryDao;

/**
 * 客服辅助表
 * Class StoreServiceAuxiliaryDao
 * @package app\dao\message\service
 */
class StoreServiceAuxiliaryDao extends QueueAuxiliaryDao
{

    /**
     * 搜索
     * @param array $where
     * @return \mohe\basic\BaseModel|mixed|\think\Model
     */
    protected function search(array $where = [])
    {
        return parent::search($where)->where('type', 0);
    }

}

