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

use app\model\other\Category;
use app\model\product\product\StoreProduct;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 门店列表
 * Class SystemStore
 * @package app\model\store
 */
class SystemStore extends BaseModel
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
    protected $name = 'system_store';

	/**
	 * @return \think\model\relation\HasOne
	 */
	public function categoryName()
	{
		return $this->hasOne(Category::class, 'id', 'cate_id')->where('group', 5)->field('id,pid,name')->bind([
			'cate_name' => 'name'
		]);
	}

	/**
	 * @return \think\model\relation\HasOne
	 */
	public function category()
	{
		return $this->hasOne(Category::class, 'id', 'cate_id')->where('group', 5);
	}

	/**
	 * 关联商品
	 * @return \think\model\relation\HasMany
	 */
	public function product()
	{
		return $this->hasMany(StoreProduct::class, 'relation_id', 'id')->where('type', 1);
	}


    /**
     * 经纬度获取器
     * @param $value
     * @param $data
     * @return string
     */
    public static function getLatlngAttr($value, $data)
    {
        return $data['latitude'] . ',' . $data['longitude'];
    }

	/**
     * id
     * @param Model $query
     * @param $value
     */
    public function searchIdAttr($query, $value)
    {
		if ($value) {
			if (is_array($value))
				$query->whereIn('id', $value);
			else
				$query->where('id', $value);
		}
    }

	/**
	 * type搜索器
	 * @param $query
	 * @param $value
	 */
	public function searchTypeAttr($query, $value)
	{
		if ($value !== '') $query->where('type', $value);
	}

	/**
	 * cate_id
	 * @param Model $query
	 * @param $value
	 */
	public function searchCateIdAttr($query, $value)
	{
		if ($value) {
			if (is_array($value))
				$query->whereIn('cate_id', $value);
			else
				$query->where('cate_id', $value);
		}
	}

	/**
	 * region_id
	 * @param Model $query
	 * @param $value
	 */
	public function searchRegionIdAttr($query, $value)
	{
//		if ($value) {
//			if (is_array($value))
//				$query->whereIn('region_id', $value);
//			else
//				$query->where('region_id', $value);
//		}
        if ($value) {
            if (is_array($value)){
                $stores=SystemStoreRegion::whereIn("id",$value)->select();
                $ids=[];
                foreach ($stores as $k=>$v){
                    $storeIds=explode(",",$v['store_id']);
                    $ids=array_merge($ids,$storeIds);
                }
            }else{
                 $ids=SystemStoreRegion::where("id",$value)->value("store_id");
                 $ids=explode(",",$ids);
            }
            $ids=array_filter(array_unique($ids));
            $query->whereIn('id', $ids);
        }
	}

	/**
	 * 代理商区域ID批量筛选
	 */
	public function searchRegionIdInAttr($query, $value)
	{
		if (is_array($value) && $value) {
			$query->whereIn('region_id', $value);
		}
	}

    /**
     * 店铺类型搜索器
     * @param Model $query
     * @param $value
     */
    public function searchStatusAttr($query, $value)
    {
        if ($value !== '') {
            switch ((int)$value) {
                case 1://营业中
                case 0://休息中
                    $query->where(['is_del' => 0, 'is_show' => 1]);
                    break;
                case -1://已停业
                    $query->where(['is_del' => 0, 'is_show' => 0]);
                    break;
                default:
                    $query->where(['is_del' => 0]);
                    break;
            }
        }
    }

    /**
     * 配送信息
     * @param $value
     * @return string
     */
    protected function setDeliveryTypeAttr($value)
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
    protected function getDeliveryTypeAttr($value)
    {
        if ($value) {
            return is_string($value) ? explode(',', $value) : $value;
        }
        return [];
    }
	/**
	 * delivery_type
	 * @param Model $query
	 * @param $value
	 */
    public function searchDeliveryTypeAttr($query, $value)
    {
        if (in_array($value, [1, 2, 3])) {
            $query->where('find_in_set(' . $value . ',`delivery_type`)');
        }
    }

	/**
	 * is_show搜索器
	 * @param $query
	 * @param $value
	 */
	public function searchIsShowAttr($query, $value)
	{
		if ($value !== '') $query->where('is_show', $value);
	}

    /**
     * is_del搜索器
     * @param $query
     * @param $value
     */
    public function searchIsDelAttr($query, $value)
    {
        if ($value !== '') $query->where('is_del', $value);
    }

	/**
	 * is_alone搜索器
	 * @param $query
	 * @param $value
	 */
	public function searchIsAloneAttr($query, $value)
	{
		if ($value !== '') $query->where('is_alone', $value);
	}

	/**
	 * is_region_alone搜索器
	 * @param $query
	 * @param $value
	 */
	public function searchIsRegionAloneAttr($query, $value)
	{
		if ($value !== '') $query->where('is_region_alone', $value);
	}

    /**
     * is_store搜索器
     * @param $query
     * @param $value
     */
    public function searchIsStoreAttr($query, $value)
    {
        if ($value !== '') $query->where('is_store', $value);
    }

    /**
     * 手机号,id,昵称搜索器
     * @param Model $query
     * @param $value
     */
    public function searchKeywordsAttr($query, $value)
    {
        if ($value != '') {
            $query->where('id|name|introduction|phone|detailed_address|address', 'LIKE', "%$value%");
        }
    }

	/**
	 * product_status搜索器
	 * @param $query
	 * @param $value
	 */
	public function searchProductStatusAttr($query, $value)
	{
		if ($value !== '') $query->where('product_status', $value);
	}

	/**
	 * product_category_status搜索器
	 * @param $query
	 * @param $value
	 */
	public function searchProductCategoryStatusAttr($query, $value)
	{
		if ($value !== '') $query->where('product_category_status', $value);
	}

	/**
	 * product_verify_status搜索器
	 * @param $query
	 * @param $value
	 */
	public function searchProductVerifyStatusAttr($query, $value)
	{
		if ($value !== '') $query->where('product_verify_status', $value);
	}

	/**
	 * use_system_money搜索器
	 * @param $query
	 * @param $value
	 */
	public function searchUseSystemMoneyAttr($query, $value)
	{
		if ($value !== '') $query->where('use_system_money', $value);
	}

    /**
     * coupon_verify_status搜索器
     * @param $query
     * @param $value
     */
    public function searchCouponVerifyStatusAttr($query, $value)
    {
        if ($value !== '') $query->where('coupon_verify_status', $value);
    }

    /**
     * coupon_self_built_status搜索器
     * @param $query
     * @param $value
     */
    public function searchCouponSelfBuiltStatusAttr($query, $value)
    {
        if ($value !== '') $query->where('coupon_self_built_status', $value);
    }

    /**
     * 行政区域
     * @param $value
     * @param $data
     * @return mixed
     */
    protected function setRegionAttr($value)
    {
        if ($value) {
            return is_array($value) ? json_encode($value) : $value;
        }
        return '';
    }

    /**
     * 行政区域
     * @param $value
     * @param $data
     * @return mixed
     */
    protected function getRegionAttr($value)
    {
        if ($value) {
            return is_string($value) ? json_decode($value, true) : $value;
        }
        return [];
    }

    /**
     * 电子围栏配置
     * @param $value
     * @param $data
     * @return mixed
     */
    protected function setFenceAttr($value)
    {
        if ($value) {
            return is_array($value) ? json_encode($value) : $value;
        }
        return '';
    }

    /**
     * 电子围栏配置
     * @param $value
     * @param $data
     * @return mixed
     */
    protected function getFenceAttr($value)
    {
        if ($value) {
            return is_string($value) ? json_decode($value, true) : $value;
        }
        return [];
    }
}
