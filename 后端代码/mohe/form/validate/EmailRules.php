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

namespace mohe\form\validate;

/**
 * 邮箱
 * Class EmailRules
 * @package mohe\form\validate
 */
class EmailRules extends BaseRules
{

    /**
     * @return string
     */
    public static function getType(): string
    {
        return 'email';
    }
}
