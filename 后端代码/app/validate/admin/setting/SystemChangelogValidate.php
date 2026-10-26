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

namespace app\validate\admin\setting;

use think\Validate;

/**
 * 系统更新日志验证
 * Class SystemChangelogValidate
 * @package app\validate\admin\setting
 */
class SystemChangelogValidate extends Validate
{
    protected $rule = [
        'title' => 'require|max:150',
        'publish_date' => 'require|regex:/^\d{4}-\d{2}-\d{2}$/',
        'platforms' => 'require',
        'items' => 'require|array|min:1',
        'release_key' => 'max:64',
        'status' => 'in:0,1',
        'is_important' => 'in:0,1',
        'is_popup' => 'in:0,1',
        'sort' => 'integer',
        'version' => 'max:50',
        'summary' => 'max:500',
        'internal_note' => 'max:500',
    ];

    protected $message = [
        'title.require' => '请填写标题',
        'title.max' => '标题最多150字',
        'publish_date.require' => '请填写发布日期',
        'publish_date.regex' => '发布日期格式应为 YYYY-MM-DD',
        'platforms.require' => '请至少选择一个展示端',
        'items.require' => '请至少添加一条变更明细',
        'items.array' => '变更明细格式错误',
        'items.min' => '请至少添加一条变更明细',
        'release_key.max' => 'release_key 最多64字符',
        'status.in' => '状态值不正确',
        'is_important.in' => '重要更新参数错误',
        'is_popup.in' => '首页提示参数错误',
    ];

    protected $scene = [
        'save' => ['title', 'publish_date', 'platforms', 'items', 'status', 'is_important', 'is_popup', 'sort', 'version', 'summary', 'internal_note'],
        'upsert' => ['title', 'publish_date', 'platforms', 'items', 'release_key', 'is_important', 'is_popup', 'version', 'summary'],
    ];
}
