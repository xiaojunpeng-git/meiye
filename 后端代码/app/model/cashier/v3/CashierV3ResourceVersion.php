<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\model\cashier\v3;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 收银 V3 既有资源版本登记表。
 * 业务主表仍是业务权威源；本表只是尚未具备版本列时的默认落点。
 */
class CashierV3ResourceVersion extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';

    protected $name = 'cashier_v3_resource_version';

    protected $autoWriteTimestamp = false;
}
