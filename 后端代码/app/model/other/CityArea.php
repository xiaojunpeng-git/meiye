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

namespace app\model\other;


use mohe\basic\BaseModel;

/**
 * 城市数据（包含街道）
 * Class CityArea
 * @package app\model\other
 */
class CityArea extends BaseModel
{

    /**
     * @var string
     */
    protected $name = 'city_area';

    /**
     * @var string
     */
    protected $key = 'id';

    /**
     * @return \think\model\relation\HasOne
     */
    public function children()
    {
        return $this->hasMany(self::class, 'parent_id', 'id');
    }

}
