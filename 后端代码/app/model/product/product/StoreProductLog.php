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
use think\model\concern\SoftDelete;

class StoreProductLog extends BaseModel
{
    use ModelTrait;
    use SoftDelete;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'store_product_log';

    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'add_time';

    protected $deleteTime = 'delete_time';

    /**
     * 添加时间修改器
     * @return int
     */
    public function setAddTimeAttr()
    {
        return time();
    }

    /**
     * 一对一关联
     * 商品记录关联商品名称
     * @return \think\model\relation\HasOne
     */
    public function storeName()
    {
        return $this->hasOne(StoreProduct::class, 'id', 'product_id')->bind([
            'store_name',
            'image',
            'product_price'=>'price',
            'stock',
            'is_show'
        ]);
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
				$query->whereIn('product_id', function ($query) use ($value) {
					$query->name('store_product_relation')->where('type', 1)->where('relation_id', 'IN', $value)->field('product_id')->select();
				});
			} else {
				$query->whereFindInSet('cate_id', $value);
			}
		}
	}

    /**
     * 记录类型搜索器
     * @param $query
     * @param $value
     */
    public function searchTypeAttr($query, $value)
    {
        if ($value != '') $query->where('type', $value);
    }

    /**
     * 商品ID搜索器
     * @param $query
     * @param $value
     */
    public function searchProductIdAttr($query, $value)
    {
        if ($value != '') $query->where('product_id', $value);
    }

	/**
	 * 门店
	 * @param Model $query
	 * @param $value
	 */
	public function searchStoreIdAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('store_id', $value);
		} else {
			if ($value !== '') $query->where('store_id', $value);
		}
	}

    /**
     * 用户ID搜索器
     * @param $query
     * @param $value
     */
    public function searchUidAttr($query, $value)
    {
        if ($value != '') $query->where('uid', $value);
    }
}
