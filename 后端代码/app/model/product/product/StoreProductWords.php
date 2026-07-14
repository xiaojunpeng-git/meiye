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

namespace app\model\product\product;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;


/**
 * 热词
 * Class StoreProductWords
 * @package app\model\product\product
 */
class StoreProductWords extends BaseModel
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
    protected $name = 'store_product_words';



    /**
     * 单位名称
     * @param $query
     * @param $value
     */
    public function searchNameAttr($query, $value)
    {
        if ($value !== '') $query->whereLike('id|name', '%' . $value . '%');
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
     * 门店ID
     * @param $query
     * @param $value
     */
    public function searchStoreIdAttr($query, $value)
    {
        if ($value !== '') {
            if ($value == -1) {//所有门店
                $query->where('store_id', '>', 0);
            } else {
                $query->where('store_id', $value);
            }
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
     * 是否显示
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchIsShowAttr($query, $value, $data)
    {
        if ($value != '') $query->where('is_show', $value ?: 0);
    }

	/**
     * 热词
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchIsHotAttr($query, $value, $data)
    {
        if ($value != '') $query->where('is_hot', $value ?: 0);
    }

	/**
     * 大家都在搜
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchIsSearchAttr($query, $value, $data)
    {
        if ($value != '') $query->where('is_search', $value ?: 0);
    }

    /**
     * 是否删除搜索器
     * @param $query
     * @param $value
     */
    public function searchIsDelAttr($query, $value)
    {
        if ($value !== '') $query->where('is_del', $value);
    }

}
