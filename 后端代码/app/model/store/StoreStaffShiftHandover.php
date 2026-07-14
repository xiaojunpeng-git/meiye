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

use app\model\user\User;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 收银台交班
 * Class StoreUser
 * @package app\model\store
 */
class StoreStaffShiftHandover extends BaseModel
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
    protected $name = 'store_staff_shift_handover';

    /**
     * 门店id搜索器
     * @param $query
     * @param $value
     */
    public function searchRelationIdAttr($query, $value)
    {
        if ($value && $value != -1) {
			if (is_array($value)) {
				$query->whereIn('relation_id', $value);
			} else {
				$query->where('relation_id', $value);
			}
        }
    }

}
