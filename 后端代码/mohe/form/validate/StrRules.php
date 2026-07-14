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

use mohe\form\FormValidate;

/**
 * Class StrRules
 * @package mohe\form\validate
 */
class StrRules extends BaseRules
{

    /**
     * 手机号正则
     */
    const PHONE_NUMBER = '/^400[0-9]{7}|^1[3456789]\d{9}$|^0[0-9]{2,3}-[0-9]{7,8}/';

    /**
     * 设置类型
     * @return string
     */
    public static function getType(): string
    {
        return 'string';
    }

}
