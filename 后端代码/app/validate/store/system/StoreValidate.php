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

namespace app\validate\store\system;

use think\Validate;

class StoreValidate extends Validate
{

    protected $rule = [
        'image' => 'require',
        'name' => 'require',
        'phone' => 'require',
        'address' => 'require',
        'province' => 'require',
        'city' => 'require',
        'area' => 'require',
        'street' => 'require',
        'detailed_address' => 'require',
        'day_time' => 'require',
    ];

    protected $message = [
        'image.require' => '请选择门店图片',
        'name.require' => '门店名称必须填写',
        'phone.require' => '门店手机号必须填写',
        'address.require' => '请选择门店地址',
        'province.require' => '请选择门店地址省份',
        'city.require' => '请选择门店所属市',
        'area.require' => '请选择门店所属区',
        'street.require' => '请选择门店所属街道',
        'detailed_address.require' => '请选择门店详细地址',
        'day_time.require' => '请选择营业时间',
    ];
}
