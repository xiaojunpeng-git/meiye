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
namespace app\validate\supplier;

use think\Validate;

class SystemSupplierValidate extends Validate
{

    /**
     * 定义验证规则
     * 格式：'字段名'    =>    ['规则1','规则2'...]
     *
     * @var array
     */
    protected $rule = [
        'supplier_name' => 'require|max:25',
        'name' => 'max:25',
        'phone' => 'require|mobile',
        'email' => 'email|max:50',
        'address' => 'max:255',
        'province' => 'require|gt:0',
        'city' => 'require|gt:0',
        'area' => 'require|gt:0',
        'detailed_address' => 'max:255',
        'account' => 'require|length:4,64',
		'pwd' => ['require', 'length:4,64'],
        'mark' => 'max:255',
    ];

    /**
     * 定义错误信息
     * 格式：'字段名.规则名'    =>    '错误信息'
     *
     * @var array
     */
    protected $message = [
        'supplier_name.require' => '请填写供应商名称',
        'supplier_name.max' => '供应商名称最多不能超过25个字符',
        'name.max' => '名称最多不能超过25个字符',
        'phone.require' => '请填写手机号',
        'phone.mobile' => '手机号格式不正确',
        'email.email' => '邮箱格式不正确',
        'email.max' => '邮箱最多不能超过100个字符',
        'address.max' => '供应商地址最多不能超过255个字符',
        'mark.max' => '备注最多不能超过255个字符',
		'province.require' => '请选择省份',
        'province.gt' => '请选择省份',
		'city.require' => '请选择城市',
        'city.gt' => '请选择城市',
		'area.require' => '请选择地区',
        'area.gt' => '请选择地区',
        'detailed_address.max' => '详细地址最多不能超过255个字符',
        'account.require' => '请填写供应商登录用户名',
        'account.length' => '供应商登录用户名4-64长度字符',
		'pwd.require' => '请输入密码',
		'pwd.length' => '密码长度4-64位字符',
    ];

    protected $scene = [
		'login' => ['pwd'],
        'update' => ['supplier_name', 'name', 'phone', 'email', 'address', 'detailed_address', 'province', 'city', 'area'],
        'save' => ['supplier_name', 'name', 'phone', 'email', 'address', 'detailed_address', 'province', 'city', 'area','account', 'pwd', 'mark'],
		'admin_update' => ['supplier_name', 'name', 'phone', 'email', 'address', 'detailed_address', 'province', 'city', 'area','account', 'mark'],
    ];
}
