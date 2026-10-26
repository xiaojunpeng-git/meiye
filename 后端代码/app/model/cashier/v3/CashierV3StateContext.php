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
 * 收银 V3 工作台投影上下文与投影游标。
 * 一行 = 一个「登录账号 + 强制门店 + 浏览器工作台会话」。
 */
class CashierV3StateContext extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';

    protected $name = 'cashier_v3_state_context';

    protected $autoWriteTimestamp = false;
}
