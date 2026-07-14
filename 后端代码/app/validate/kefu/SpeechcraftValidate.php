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

namespace app\validate\kefu;


use think\Validate;

class SpeechcraftValidate extends Validate
{
    /**
     * @var string[]
     */
    protected $rule = [
        'title' => 'chsAlphaNum|length:0,50',
        'message' => 'require|length:0,500',
        'sort' => 'number',
    ];

    /**
     * @var string[]
     */
    protected $message = [
        'title.chsAlphaNum' => '请填汉字字母或者数字',
        'title.length' => '标题长度不能超过50个字',
        'cate_id.require' => '请选择分类',
        'cate_id.number' => '分类必须为数字',
        'message.require' => '请填写话术内容',
        'message.length' => '话术长度不能超过500个字',
        'sort.number' => '排序必须为数字',
    ];
}
