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

namespace app\dao\product\shipping;


use app\dao\BaseDao;
use app\model\other\CityArea;
use app\model\product\shipping\ShippingTemplatesFree;


/**
 * Class ShippingTemplatesFreeCityDao
 * @package app\dao\product\shipping
 */
class ShippingTemplatesFreeCityDao extends BaseDao
{
    /**
     * 主表别名
     * @var string
     */
    protected $alias = 'a';

    /**
     * 附表别名
     * @var string
     */
    protected $joinAlis = 'c';

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return ShippingTemplatesFree::class;
    }

    /**
     * 设置join连表模型
     * @return string
     */
    protected function setJoinModel(): string
    {
        return CityArea::class;
    }

    /**
     * 关联模型
     * @param string $alias
     * @param string $join_alias
     * @return \mohe\basic\BaseModel
     */
    protected function getModel(string $key = 'province_id', string $join = 'LEFT')
    {
        /** @var CityArea $city */
        $city = app()->make($this->setJoinModel());
        $name = $city->getName();
        return parent::getModel()->join($name . ' ' . $this->joinAlis, $this->alias . '.' . $key . ' = ' . $this->joinAlis . '.id', $join)->alias($this->alias);
    }

    /**
     * 获取指定条件下的包邮列表
     * @param array $where
     * @return mixed
     */
    public function getUniqidList(array $where, bool $group = true)
    {
        return $this->getModel($group ? 'province_id' : 'city_id')->when(isset($where['uniqid']), function ($query) use ($where) {
            $query->where($this->alias . '.uniqid', $where['uniqid']);
        })->when(isset($where['province_id']), function ($query) use ($where) {
            $query->where($this->alias . '.province_id', $where['province_id']);
        })->when($group, function ($query) {
            $query->group($this->alias . '.province_id');
        })->field($this->alias . '.province_id,' . $this->joinAlis . '.name,' . $this->alias . '.city_id')->select()->toArray();
    }
}
