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

namespace app\model\order;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 *  订单修改状态记录Model
 * Class StoreOrderStatus
 * @package app\model\order
 */
class StoreOrderStatus extends BaseModel
{
    use ModelTrait;

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'store_order_status';

    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'change_time';

    /**
     * 订单ID搜索器
     * @param Model $query
     * @param $value
     * @param $data
     */
    public function searchOidAttr($query, $value, $data)
    {
        $query->where('oid', $value);
    }

    /**
     * 变动类型搜索器
     * @param Model $query
     * @param $value
     */
    public function searchChangeTypeAttr($query, $value)
    {
		if ($value) {
			if (is_array($value)) {
				$query->whereIn('change_type', $value);
			} else {
				if ($value !== '') $query->where('change_type', $value);
			}
		}
    }
}
