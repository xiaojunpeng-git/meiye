<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2022 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\model\other;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 赠送配置（商品/储值档位等）
 */
class StoreGiftConfig extends BaseModel
{
    use ModelTrait;

    /** @var int 商品赠送 */
    public const GIFT_TYPE_PRODUCT = 1;

    /** @var int 储值档位赠送 */
    public const GIFT_TYPE_RECHARGE = 2;

    protected $pk = 'id';

    protected $name = 'store_gift_config';

    public function searchGiftTypeAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereIn('gift_type', $value);
        } else {
            $query->where('gift_type', $value);
        }
    }

    public function searchRelationIdAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereIn('relation_id', $value);
        } else {
            $query->where('relation_id', $value);
        }
    }
}
