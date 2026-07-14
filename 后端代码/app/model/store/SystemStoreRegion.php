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
namespace app\model\store;


use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 门店区域模型
 * Class SystemStoreRegion
 * @package app\model\store
 */
class SystemStoreRegion extends BaseModel
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
    protected $name = 'system_region_agent';

    /**
     * 时间戳获取器转日期
     * @param $value
     * @return false|string
     */
    public static function getAddTimeAttr($value)
    {
        return date('Y-m-d H:i:s', $value);
    }

	/**
	 * 推荐城市信息
	 * @param $value
	 * @return array|mixed
	 */
	protected function setRecommendRegionAttr($value)
	{
		if ($value) {
			return is_array($value) ? json_encode($value) : $value;
		}
		return '';
	}

	/**
	 * 推荐城市信息
	 * @param $value
	 * @return array|mixed
	 */
	protected function getRecommendRegionAttr($value)
	{
		if ($value) {
			return is_string($value) ? json_decode($value, true) : $value;
		}
		return [];
	}

	/**
	 * 门店信息
	 * @param $value
	 * @return string
	 */
	protected function setStoreIdAttr($value)
	{
		if ($value) {
			return is_array($value) ? implode(',', $value) : $value;
		}
		return '';
	}

	/**
	 * 配送信息
	 * @param $value
	 * @return array|false|string[]
	 */
	protected function getStoreIdAttr($value)
	{
		if ($value) {
			return is_string($value) ? explode(',', $value) : $value;
		}
		return [];
	}

	/**
	 * ID取反搜索器
	 * @param $query
	 * @param $value
	 */
	public function searchNotIdAttr($query, $value)
	{
		if ($value) {
			if (is_array($value)) {
				$query->whereNotIn('id', $value);
			} else {
				$query->where('id', '<>', $value);
			}
		}
	}

	/**
	 * 是否显示
	 * @param $query
	 * @param $value
	 */
	public function searchIsShowAttr($query, $value)
	{
		if ($value !== '') {
			$query->where('is_show', $value);
		}
	}


	/**
     * 是否隔离
     * @param $query
     * @param $value
     */
    public function searchIsAloneAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('is_alone', $value);
        }
    }

    /**
     * 是否删除
     * @param $query
     * @param $value
     */
    public function searchIsDelAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('is_del', $value);
        }
    }

	/**
	 * 关键词搜索
	 * @param $query
	 * @param $value
	 */
	public function searchKeywordAttr($query, $value)
	{
		if ($value !== '') $query->whereLike('id|name', "%" . trim($value) . "%");
	}

	/**
	 * 省份搜索器
	 * @param $query
	 * @param $value
	 * @return void
	 */
	public function searchProvinceIdAttr($query, $value)
	{
		if ($value !== '') $query->whereFindInSet('province_id', $value);
	}

	/**
	 * 城市搜索器
	 * @param $query
	 * @param $value
	 * @return void
	 */
	public function searchCityIdAttr($query, $value)
	{
		if ($value !== '') $query->whereFindInSet('city_id', $value);
	}

	/**
	 * 区县搜索器
	 * @param $query
	 * @param $value
	 * @return void
	 */
	public function searchAreaIdAttr($query, $value)
	{
		if ($value !== '') $query->whereFindInSet('area_id', $value);
	}

}
