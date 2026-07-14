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
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 城市数据(已废弃留存)
 * Class SystemCity
 * @package app\model\other
 */
class SystemCity extends BaseModel
{
    use ModelTrait;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'system_city';

    /**
     * 获取子集分类查询条件
     * @return \think\model\relation\HasMany
     */
    public function children()
    {
        return $this->hasMany(self::class, 'parent_id', 'city_id')->order('id ASC');
    }


    /**
     * city搜索器
     * @param Model $query
     * @param $value
     */
    public function searchCityIdAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereIn('city_id', $value);
        } else {
            $query->where('city_id', $value);
        }
    }

    /**
     * ParentId搜索器
     * @param Model $query
     * @param $value
     */
    public function searchParentIdAttr($query, $value)
    {
        $query->where('parent_id', $value);
    }


}
