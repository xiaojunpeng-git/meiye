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
namespace app\validate\api\user;


use think\Validate;

/**
 * 注册验证
 * Class UserApplyValidate
 * @package app\http\validates\api\user
 */
class UserApplyValidate extends Validate
{
    protected $regex = ['phone' => '/^1[3456789]\d{9}$/'];

    protected $rule = [
        'phone' => 'require|regex:phone',
        'name' => 'require|length:2,20',
//        'captcha' => 'require|length:6',
        'system_name' => 'require|length:4,64',
		'images' => 'require'
    ];

    protected $message = [
        'phone.require' => '手机号必须填写',
        'phone.regex' => '手机号格式错误',
        'name.require' => '联系人必须填写',
		'name.length' => '联系人2-20长度字符',
//        'captcha.require' => '验证码必须填写',
//        'captcha.length' => '验证码长度不正确',
		'system_name.require' => '商户名称必须填写',
		'system_name.length' => '商户名称4-64长度字符',
		'images.require' => '请上传相关资质证明图片',
    ];

}
