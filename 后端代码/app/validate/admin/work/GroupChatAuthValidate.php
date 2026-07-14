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

namespace app\validate\admin\work;


use think\Validate;

/**
 * 自动拉群
 * Class GroupChatAuthValidate
 * @package app\validate\admin\work
 */
class GroupChatAuthValidate extends Validate
{

    /**
     * @var array
     */
    protected $rule = [
        'name' => 'require',
        'chat_id' => 'require',
    ];

    /**
     * @var array
     */
    protected $message = [
        'name.require' => '请填写二维码名称',
        'chat_id.require' => '请选择群聊',
    ];
}
