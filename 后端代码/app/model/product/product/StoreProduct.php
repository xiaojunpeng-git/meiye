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

use app\model\product\brand\StoreBrand;
use app\model\product\sku\StoreProductAttrValue;
use app\model\store\SystemStore;
use app\model\supplier\SystemSupplier;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use app\model\activity\coupon\StoreCouponProduct;
use think\Model;

/**
 *  商品Model
 * Class StoreProduct
 * @package app\model\product\product
 */
class StoreProduct extends BaseModel
{
    use  ModelTrait;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'store_product';

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
     * 配送信息
     * @param $value
     * @return string
     */
    protected function setStoreDeliveryTypeAttr($value)
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
    protected function getStoreDeliveryTypeAttr($value)
    {
        if ($value) {
            return is_string($value) ? explode(',', $value) : $value;
        }
        return [];
    }

    /**
     * 自定义表单信息
     * @param $value
     * @return array|mixed
     */
    protected function getCustomFormAttr($value)
    {
        if ($value) {
            return is_string($value) ? json_decode($value, true) : $value;
        }
        return [];
    }

    /**
     * 商品标签
     * @param $value
     * @return string
     */
    protected function setLabelIdAttr($value)
    {
        if ($value) {
            return is_array($value) ? implode(',', $value) : $value;
        }
        return '';
    }

    /**
     * 商品标签
     * @param $value
     * @return array|mixed
     */
    protected function getLabelIdAttr($value)
    {
        if ($value) {
            return is_string($value) ? array_map('intval', array_filter(explode(',', $value))) : $value;
        }
        return [];
    }

    /**
     * 商品标签
     * @param $value
     * @return string
     */
    protected function setStoreLabelIdAttr($value)
    {
        if ($value) {
            return is_array($value) ? implode(',', $value) : $value;
        }
        return '';
    }

    /**
     * @param $value
     * @return array|mixed
     */
    public function getStoreLabelIdAttr($value)
    {
        if ($value) {
            return is_string($value) ? array_map('intval', array_filter(explode(',', $value))) : $value;
        }
        return [];
    }

    /**
     * 商品保障服务
     * @param $value
     * @return string
     */
    protected function setEnsureIdAttr($value)
    {
        if ($value) {
            return is_array($value) ? implode(',', $value) : $value;
        }
        return '';
    }

    /**
     * 商品保障服务
     * @param $value
     * @return array|mixed
     */
    protected function getEnsureIdAttr($value)
    {
        if ($value) {
            return is_string($value) ? array_map('intval', array_filter(explode(',', $value))) : $value;
        }
        return [];
    }

    /**
     * 参数信息
     * @param $value
     * @return array|mixed
     */
    protected function getSpecsAttr($value)
    {
        if ($value) {
            return is_string($value) ? json_decode($value, true) : $value;
        }
        return [];
    }

    /**
     * 适用门店信息
     * @param $value
     * @return string
     */
    protected function setApplicableStoreIdAttr($value)
    {
        if ($value) {
            return is_array($value) ? implode(',', $value) : $value;
        }
        return '';
    }

    /**
     * 适用门店信息
     * @param $value
     * @return array|false|string[]
     */
    protected function getApplicableStoreIdAttr($value)
    {
        if ($value) {
            return is_string($value) ? array_map('intval', array_filter(explode(',', $value))) : $value;
        }
        return [];
    }

	/**
	 * 自定义时间段划分数据
	 * @param $value
	 * @return string
	 */
	protected function setCustomizeTimePeriodAttr($value)
	{
		if ($value) {
			return is_array($value) ? json_encode($value) : $value;
		}
		return '';
	}

	/**
	 * 自定义时间段划分数据
	 * @param $value
	 * @return array|false|string[]
	 */
	protected function getCustomizeTimePeriodAttr($value)
	{
		if ($value) {
			return is_string($value) ? json_decode($value, true) : $value;
		}
		return [];
	}

	/**
	 * 销售日期每周设置
	 * @param $value
	 * @return string
	 */
	protected function setSaleTimeWeekAttr($value)
	{
		if ($value) {
			return is_array($value) ? implode(',', $value) : $value;
		}
		return '';
	}

	/**
	 * 销售日期每周设置
	 * @param $value
	 * @return array|false|string[]
	 */
	protected function getSaleTimeWeekAttr($value)
	{
		if ($value !== '') {
			return is_string($value) ? array_map('intval', explode(',', $value)) : $value;
		}
		return [];
	}


    /**
     * 一对一关联
     * 商品关联商品商品详情
     * @return \think\model\relation\HasOne
     */
    public function descriptions()
    {
        return $this->hasOne(StoreDescription::class, 'product_id', 'id')->where('type', 0)->bind(['description']);
    }

    /**
     * 一对一关联
     * 商品关联商品商品品牌
     * @return \think\model\relation\HasOne
     */
    public function brand()
    {
        return $this->hasOne(StoreBrand::class, 'id', 'brand_id')->field('id,brand_name')->where(['is_show' => 1, 'is_del' => 0])->bind(['brand_name']);
    }

    /**
     * 一对一关联
     * 商品关联供应商
     * @return \think\model\relation\HasOne
     */
    public function supplier()
    {
        return $this->hasOne(SystemSupplier::class, 'id', 'relation_id')->where('type', 2)->field('id,supplier_name')->where(['is_show' => 1, 'is_del' => 0])->bind(['supplier_name']);
    }

    /**
     * 一对一关联
     * 商品关联供应商
     * @return \think\model\relation\HasOne
     */
    public function supplierInfo()
    {
        return $this->hasOne(SystemSupplier::class, 'id', 'relation_id')->where(['is_show' => 1, 'is_del' => 0]);
    }

    /**
     * 一对一关联
     * 商品关联门店
     * @return \think\model\relation\HasOne
     */
    public function store()
    {
        return $this->hasOne(SystemStore::class, 'id', 'relation_id')->field('id,name,delivery_type')->where(['is_show' => 1, 'is_del' => 0])->bind(['store_name' => 'name', 'store_is_store' => 'delivery_type']);
    }

    /**
     * 一对一关联
     * 商品关联门店
     * @return \think\model\relation\HasOne
     */
    public function storeInfo()
    {
        return $this->hasOne(SystemStore::class, 'id', 'relation_id')->where(['is_show' => 1, 'is_del' => 0]);
    }


    /**
     * 一对多关联
     * 商品关联优惠卷模板id
     * @return \think\model\relation\HasMany
     */
    public function couponId()
    {
        return $this->hasMany(StoreCouponProduct::class, 'product_id', 'id');
    }

    /**
     * 优惠券名称一对多
     * @return \think\model\relation\HasMany
     */
    public function coupons()
    {
        return $this->hasMany(StoreProductCoupon::class, 'product_id', 'id');
    }

    /**
     * 评论一对多
     * @return \think\model\relation\HasMany
     */
    public function star()
    {
        return $this->hasMany(StoreProductReply::class, 'product_id', 'id')->where('is_del', 0)->field('product_score,product_id');
    }

    /**
     * 分类一对多
     * @return \think\model\relation\HasMany
     */
    public function cateName()
    {
        return $this->hasMany(StoreProductCate::class, 'product_id', 'id')->with('cateName');
    }

    /**
     * sku一对多
     * @return \think\model\relation\HasMany
     */
    public function attrValue()
    {
        return $this->hasMany(StoreProductAttrValue::class, 'product_id', 'id')->where('type', 0);
    }


    /**
     * 轮播图获取器
     * @param $value
     * @return array|mixed
     */
    public function getSliderImageAttr($value)
    {
        return is_string($value) && $value ? json_decode($value, true) : [];
    }

    /**
     * @param $value
     * @return bool
     */
    public function getStoreDeliveryAttr($value)
    {
        //1=快递，2=门店核销，3=门店配送
        return in_array(3, $this->delivery_type);
    }

    /**
     * @param $value
     * @return bool
     */
    public function getStoreMentionAttr($value)
    {
        //1=快递，2=门店核销，3=门店配送
        return in_array(2, $this->delivery_type);
    }

    /**
     * @param $value
     * @return bool
     */
    public function getExpressDeliveryAttr($value)
    {
        //1=快递，2=门店核销，3=门店配送
        return in_array(1, $this->delivery_type);
    }

    /**
     * 配送方式
     * @param $query
     * @param $value
     */
    public function searchDeliveryTypeAttr($query, $value)
    {
        if (in_array($value, [1, 2, 3])) {
            $query->where('find_in_set(' . $value . ',`delivery_type`)');
        }
    }

    public function searchNotIdsAttr($query, $value){
         if(!empty($value) && is_array($value)){
                $query->whereNotIn("id",$value);
         }
    }
    /**
     * 门店配送方式
     * @param $query
     * @param $value
     * @return void
     */
    public function searchStoreDeliveryTypeAttr($query, $value)
    {
        if (in_array($value, [1, 2])) {
            $query->where('find_in_set(' . $value . ',`store_delivery_type`)');
        }
    }

    /**
     * 商品类型搜索器
     * @param $query
     * @param $value
     */
    public function searchProductTypeAttr($query, $value)
    {
        if ($value !== '' && $value !== -1) {
            if (is_array($value)) {
                if ($value) $query->whereIn('product_type', $value);
            } else {
                $query->where('product_type', $value ?? 0);
            }
        }
    }

    /**
     * 卡项规则搜索器
     * @param $query
     * @param $value
     */
    public function searchCardRuleTypeAttr($query, $value)
    {
        if (in_array($value, ['normal', 'choice_kind', 'choice_count', 'time'], true)) {
            $query->where('card_rule_type', $value);
        }
    }

	/**
	 * 商品类型搜索器
	 * @param $query
	 * @param $value
	 * @return void
	 */
	public function searchNtoProductTypeAttr($query, $value)
	{
		if ($value !== '' && $value !== -1) {
			if (is_array($value)) {
				$query->whereNotIn('product_type', $value);
			} else {
				$query->where('product_type', '<>',$value ?? 0);
			}
		}
	}

	/**
	 * 单、多规格
	 * @param $query
	 * @param $value
	 */
	public function searchSpecTypeAttr($query, $value)
	{
		if ($value !== '') {
			$query->where('spec_type', $value ?? 0);
		}
	}

	/**
	 * 是否开启会员价
	 * @param $query
	 * @param $value
	 */
	public function searchIsVipAttr($query, $value)
	{
		if ($value !== '') {
			$query->where('is_vip', $value ?? 0);
		}
	}

	/**
	 * 是否参与库存管理
	 * @param $query
	 * @param $value
	 */
	public function searchIsInventoryAttr($query, $value)
	{
		if ($value !== '') {
			$query->where('is_inventory', $value);
		}
	}

	/**
	 * 是否允许负库存
	 * @param $query
	 * @param $value
	 */
	public function searchAllowNegativeStockAttr($query, $value)
	{
		if ($value !== '') {
			$query->where('allow_negative_stock', $value);
		}
	}

    /**
     * 是否是svip商品搜索器
     * @param $query
     * @param $value
     */
    public function searchIsVipProductAttr($query, $value)
    {
        if ($value !== '') {
            switch ($value) {
                case -1:
                    break;
                case 0:
                    $query->where('is_vip_product', 0);
                    break;
                case 1:
                    $query->where('is_vip_product', 1);
                    break;
                default:
                    $query->where('is_vip_product', 0);
                    break;
            }
        }
    }

    /**
     * 是否预售搜索器
     * @param $query
     * @param $value
     */
    public function searchIsPresaleProductAttr($query, $value)
    {
        if ($value !== '') $query->where('is_presale_product', $value ?? 0);
    }

	/**
	 * 显示类型搜索器
	 * @param $query
	 * @param $value
	 */
	public function searchShowTypeAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('show_type', $value);
		} else {
			if ($value !== '') $query->where('show_type', $value ?? 1);
		}
	}

    /**
     * 是否显示搜索器
     * @param $query
     * @param $value
     */
    public function searchIsShowAttr($query, $value)
    {
        if ($value !== '') $query->where('is_show', $value ?? 1);
    }

    /**
     * 是否审核搜索器
     * @param $query
     * @param $value
     */
    public function searchIsVerifyAttr($query, $value)
    {
        if ($value !== '') $query->where('is_verify', $value ?? 1);
    }

    /**
     * @param Model $query
     * @param $value
     */
    public function searchIdAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereIn('id', $value);
        } else {
            $query->where('id', $value);
        }
    }

    /**
     *    供应商
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


    /**
     * 是否删除搜索器
     * @param Model $query
     * @param $value
     */
    public function searchIsDelAttr($query, $value)
    {
        if ($value !== '') $query->where('is_del', $value ?: 0);
    }

    /**
     * 条形码搜索器
     * @param Model $query
     * @param $value
     */
    public function searchBarCodeAttr($query, $value)
    {
        if ($value !== '') $query->where(function ($query) use ($value) {
            $query->where('bar_code', $value)->whereOr('id', 'IN', function ($q) use ($value) {
                $q->name('store_product_attr_value')->field('product_id')->where('bar_code', $value)->select();
            });
        });
    }

    /**
     * 商户搜索器
     * @param Model $query
     * @param $value
     */
    public function searchPidAttr($query, $value)
    {
        if (is_array($value)) {
            if ($value) $query->whereIn('pid', $value);
        } else {
            if ($value !== '') {
                if ($value == -1) {
                    $query->where('pid', '>', 0);
                } else {
                    $query->where('pid', $value);
                }
            }
        }
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
	 * 查询平台+供应商+特定门店商品
	 * @param $query
	 * @param $value
	 * @return void
	 */
	public function searchShopOperationRelationIdAttr($query, $value)
	{
		if ($value !== '') $query->where(function ($q) use ($value) {
			$q->where(function ($a) use ($value) {
				$a->whereIn('type', [0, 2])->where(function ($b) use ($value) {
					$b->whereIn('applicable_type', [0, 1])->whereOr(function ($c) use ($value) {
						$c->where('applicable_type', 2)->whereFindInSet('applicable_store_id', $value);
					});
				});
			})->whereOr(function ($qe) use ($value) {
				$qe->where('type', 1)->where('relation_id', $value);
			});
		});
	}

    /**
     * 商户ID搜索器
     * @param Model $query
     * @param $value
     */
    public function searchMerIdAttr($query, $value)
    {
        $query->where('mer_id', $value ?? 0);
    }

    /**
     * keyword搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchKeywordAttr($query, $value, $data)
    {
        if ($value != '' && !isset($data['store_id'])) {
            $field = 'id|keyword|store_name|store_info|bar_code';
            if (is_string($value)) {
                $query->whereLike($field, htmlspecialchars("%" . trim($value) . "%"));
            } elseif (is_array($value) && count($value) > 0) {
                $query->where(function ($q) use ($value, $field) {
                    $data = [];
                    foreach ($value as $k) {
                        $data[] = [$field, 'like', "%" . trim($k) . "%"];
                    }
                    $q->whereOr($data);
                });
            }
        }
    }


    /**
     * 热卖商品搜索器
     * @param Model $query
     * @param int $value
     */
    public function searchIsHotAttr($query, $value)
    {
		if ($value) $query->whereIn('id', function ($query) use ($value) {
			$query->name('store_product_relation')->where('type', 3)->whereIn('relation_id', 1)->field('product_id')->select();
		});
    }

	/**
	 * 促销商品搜索器
	 * @param Model $query
	 * @param int $value
	 */
	public function searchIsBenefitAttr($query, $value)
	{
		if ($value) $query->whereIn('id', function ($query) use ($value) {
			$query->name('store_product_relation')->where('type', 3)->whereIn('relation_id', 2)->field('product_id')->select();
		});
	}

    /**
     * 精品商品搜索器
     * @param Model $query
     * @param int $value
     */
    public function searchIsBestAttr($query, $value)
    {
		if ($value) $query->whereIn('id', function ($query) use ($value) {
			$query->name('store_product_relation')->where('type', 3)->whereIn('relation_id', 3)->field('product_id')->select();
		});
    }

	/**
	 * 新品商品搜索器
	 * @param Model $query
	 * @param int $value
	 */
	public function searchIsNewAttr($query, $value)
	{
		if ($value) $query->whereIn('id', function ($query) use ($value) {
			$query->name('store_product_relation')->where('type', 3)->whereIn('relation_id', 4)->field('product_id')->select();
		});
	}

    /**
     * 精品商品搜索器
     * @param Model $query
     * @param int $value
     */
    public function searchIsGoodAttr($query, $value)
    {
		if ($value) $query->whereIn('id', function ($query) use ($value) {
			$query->name('store_product_relation')->where('type', 3)->whereIn('relation_id', 5)->field('product_id')->select();
		});
    }

    /**
     * 用户标签搜索器
     * @param Model $query
     * @param int $value
     */
    public function searchLabelIdAttr($query, $value)
    {
        if ($value !== '') $query->whereFindInSet('label_id', $value);
    }

    /**
     * 保障服务搜索器
     * @param Model $query
     * @param int $value
     */
    public function searchEnsureIdAttr($query, $value)
    {
        if ($value !== '') $query->whereFindInSet('ensure_id', $value);
    }

    /**
     * SPU搜索器
     * @param Model $query
     * @param int $value
     */
    public function searchSpuAttr($query, $value)
    {
        $query->where('spu', $value);
    }

    /**
     * 库存搜索器
     * @param Model $query
     * @param int $value
     */
    public function searchStockAttr($query, $value)
    {
        $query->where('stock', $value);
    }

    /**
     * 分类搜索器
     * @param Model $query
     * @param int $value
     */
    public function searchCateIdAttr($query, $value)
    {
        if ($value) {
            if (is_array($value)) {
                $query->whereIn('id', function ($query) use ($value) {
                    $query->name('store_product_relation')->where('type', 1)->where('relation_id', 'IN', $value)->field('product_id')->select();
                });
            } else {
                $query->whereFindInSet('cate_id', $value);
            }
        }
    }

    /**
     * 商品数量条件搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchStatusAttr($query, $value, $data)
    {
        if ($value !== '') {
            switch ((int)$value) {
                case -2://强制下架
                    $query->where(['is_verify' => -2]);
                    break;
                case -1://审核未通过
                    $query->where(['is_verify' => -1]);
                    break;
                case 0://待审核
                    $query->where(['is_verify' => 0, 'is_del' => 0]);
                    break;
                case 1:
                    $query->where(['is_show' => 1, 'is_del' => 0, 'is_verify' => 1]);
                    break;
                case 2:
                    $query->where(['is_show' => 0, 'is_del' => 0, 'is_verify' => 1]);
                    break;
                case 3:
                    $query->where(['is_del' => 0, 'is_verify' => 1]);
                    break;
                case 4:
                    $query->where(['is_show' => 1,'is_del' => 0, 'is_verify' => 1])->where(function ($query) {
                        $query->whereOr('stock', 0)->whereOr('is_sold', 1);
                    });
                    break;
                case 5:
//                    if (isset($data['store_stock']) && $data['store_stock']) {
//                        $store_stock = $data['store_stock'];
//                        $query->where(['is_show' => 1, 'is_del' => 0, 'is_verify' => 1, 'is_police' => 1])->where('stock', '<=', $store_stock)->where('stock', '>', 0);
//                    } else {
                        $query->where(['is_show' => 1, 'is_del' => 0, 'is_verify' => 1, 'is_police' => 1])->where('stock', '>=', 0);
//                    }
                    break;
                case 6:
                    $query->where(['is_del' => 1]);
                    break;
                case 7://回收站 & 下架商品
                    $query->where(function ($q) {
                        $q->where(['is_del' => 1])->whereOr('is_show', 0);
                    });
                    break;
            };
        }
    }

    /**
     * 品牌搜索器
     * @param Model $query
     * @param $value
     */
    public function searchBrandIdAttr($query, $value)
    {
        if ($value) {
            if (is_array($value)) {
                $query->whereIn('brand_id', $value);
            } else {
                $query->where('brand_id', $value);
            }
        }
    }

    /**
     * 库存预警
     * @author 等风来
     * @email 136327134@qq.com
     * @date 2023/4/18
     * @param $query
     * @param $value
     */
    public function searchIsPoliceAttr($query, $value)
    {
        if ('' !== $value) $query->where('is_police', $value);
    }

    /**
     * 是否售罄
     * @author 等风来
     * @email 136327134@qq.com
     * @date 2023/4/18
     * @param $query
     * @param $value
     */
    public function searchIsSoldAttr($query, $value)
    {
        if ('' !== $value) $query->where('is_sold', $value);
    }

    /**
     * 系统表单搜索器
     * @param Model $query
     * @param $value
     */
    public function searchSystemFormIdAttr($query, $value)
    {
        if (is_array($value)) {
            if ($value) $query->whereIn('system_form_id', $value);
        } else {
            if ($value !== '') $query->where('system_form_id', $value);
        }
    }

	/**
	 * 适用门店类型搜索器
	 * @param $query
	 * @param $value //适用门店：0：仅平台1：所有2：部分
	 * @return void
	 */
    public function searchApplicableTypeAttr($query, $value)
    {
        if (is_array($value)) {
            if ($value) $query->whereIn('applicable_type', $value);
        } else {
            if ($value !== '') $query->where('applicable_type', $value);
        }
    }

	/**
	 * 适用本门店商品
	 * @param $query
	 * @param $value
	 * @return void
	 */
	public function searchApplicableStoreIdAttr($query, $value)
	{
		if ($value !== '') $query->where(function ($q) use ($value) {
			$q->where('applicable_type', 1)->whereOr(function ($qe) use ($value) {
				$qe->where('applicable_type', 2)->whereFindinSet('applicable_store_id', $value);
			});
		});
	}

    /**
     * 分类搜索器
     * @param $query
     * @param $value
     * @return void
     */
    public function searchStoreCateIdAttr($query, $value)
    {
        if ($value) {
            if (is_array($value)) {
                $query->whereIn('id', function ($query) use ($value) {
                    $query->name('store_product_relation')->where('type', 1)->where('relation_id', 'IN', $value)->field('product_id')->select();
                });
            } elseif ($value == -1) {
                $query->where('store_cate_id', '');
            } else {
                $query->whereFindInSet('store_cate_id', $value);
            }
        }
    }
}
