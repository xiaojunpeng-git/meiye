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

namespace app\services\pc;


use app\services\BaseServices;
use app\services\other\CityAreaServices;

class PublicServices extends BaseServices
{
    /**
     * 获取城市数据
     * @param int $pid
     * @return mixed
     */
    public function getCity(int $pid)
    {
        /** @var CityAreaServices $city */
        $city = app()->make(CityAreaServices::class);
        $list = $city->getColumn(['parent_id' => $pid], 'id,name');
        return $list;
    }
}
