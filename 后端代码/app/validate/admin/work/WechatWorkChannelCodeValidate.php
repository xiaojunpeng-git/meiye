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

class WechatWorkChannelCodeValidate extends Validate
{

    /**
     * @var array
     */
    protected $rule = [
        'name' => 'require',
        'reserve_userid' => 'require',
        'cate_id' => 'require',
        'label_id' => 'require',
        'welcome_words' => 'require',
    ];

    /**
     * @var array
     */
    protected $message = [
        'name.require' => '请填写渠道二维码名称',
        'reserve_userid.require' => '请选择渠道二维码备用成员',
        'cate_id.require' => '请选择渠道二维码分类',
        'label_id.require' => '请选择渠道二维码标签',
        'welcome_words.require' => '请填写渠道二维码欢迎语',
    ];
}
