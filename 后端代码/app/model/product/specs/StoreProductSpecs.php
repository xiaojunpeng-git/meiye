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

namespace app\model\product\specs;

use app\model\product\product\StoreProduct;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 商品参数
 * Class StoreProductSpecs
 * @package app\model\product\specs
 */
class StoreProductSpecs extends BaseModel
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
    protected $name = 'store_product_specs';

    /**
     * 添加时间获取器
     * @param $value
     * @return false|string
     */
    protected function getAddTimeAttr($value)
    {
        return date('Y-m-d H:i:s', $value);
    }

    /**
     * id搜索器
     * @param Model $query
     * @param $value
     */
    public function searchIdAttr($query, $value)
    {
        if (is_array($value)) {
            if ($value) $query->whereIn('id', $value);
        } else {
            if ($value !== '') $query->where('id', $value);
        }
    }

    /**
     * @param Model $query
     * @param $value
     */
    public function searchNameAttr($query, $value)
    {
        $query->whereLike('name', '%' . $value . '%');
    }

    /**
     * temp_id搜索器
     * @param Model $query
     * @param $value
     */
    public function searchTempIdAttr($query, $value)
    {
        if (is_array($value)) {
            if ($value) $query->whereIn('temp_id', $value);
        } else {
            if ($value !== '') $query->where('temp_id', $value);
        }
    }

    /**
     * status搜索器
     * @param Model $query
     * @param $value
     */
    public function searchStatusAttr($query, $value)
    {
        if ($value !== '') $query->where('status', $value);
    }

	/**
	 * 商户搜索器
	 * @param Model $query
	 * @param $value
	 */
	public function searchTypeAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('type', $value);
		} else {
			if ($value !== '') $query->where('type', $value);
		}
	}

	/**
	 * 关联门店ID、供应商ID搜索器
	 * @param Model $query
	 * @param $value
	 */
	public function searchRelationIdAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('relation_id', $value);
		} else {
			if ($value !== '') $query->where('relation_id', $value);
		}
	}

	/**
	 * 供应商
	 * @param Model $query
	 * @param $value
	 */
	public function searchSupplierIdAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('relation_id', $value)->where('type', 2);
		} else {
			if ($value !== '') $query->where('relation_id', $value)->where('type', 2);
		}
	}

	/**
	 * 门店
	 * @param Model $query
	 * @param $value
	 */
	public function searchStoreIdAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('relation_id', $value)->where('type', 1);
		} else {
			if ($value !== '') $query->where('relation_id', $value)->where('type', 1);
		}
	}

}
