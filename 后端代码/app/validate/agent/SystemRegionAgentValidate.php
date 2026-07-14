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
namespace app\validate\agent;

use think\Validate;

class SystemRegionAgentValidate extends Validate
{

    /**
     * 定义验证规则
     * 格式：'字段名'    =>    ['规则1','规则2'...]
     *
     * @var array
     */
    protected $rule = [
        'name' => 'require|max:50',
        'phone' => 'require',
        'account' => 'require|length:4,64',
		'pwd' => ['require', 'length:4,64'],
		'conf_pwd' => ['require', 'length:4,64'],
    ];

    /**
     * 定义错误信息
     * 格式：'字段名.规则名'    =>    '错误信息'
     *
     * @var array
     */
    protected $message = [
        'name.require' => '请填写联系人名称',
        'name.max' => '联系人名称最多不能超过50个字符',
        'phone.require' => '请填写手机号',
        'account.require' => '请填写代理商管理员账号',
        'account.length' => '代理商管理员账号4-64长度字符',
		'pwd.require' => '请输入密码',
		'pwd.length' => '密码长度4-64位字符',
		'conf_pwd.require' => '请输入确认密码',
		'conf_pwd.length' => '确认密码长度4-64位字符',
    ];

    protected $scene = [
		'login' => ['pwd'],
        'update' => ['name', 'phone', 'account'],
        'save' => ['name', 'phone', 'account', 'pwd', 'conf_pwd'],
    ];
}
