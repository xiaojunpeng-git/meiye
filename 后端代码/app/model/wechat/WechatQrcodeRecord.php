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

namespace app\model\wechat;

use app\model\user\User;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 关键词
 * Class WechatReply
 * @package app\model\wechat
 */
class WechatQrcodeRecord extends BaseModel
{
    use ModelTrait;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'wechat_qrcode_record';

    /**
     * 关联user
     * @return \think\model\relation\HasOne
     */
    public function user()
    {
        return $this->hasOne(User::class, 'uid', 'uid');
    }

    public function searchQidAttr($query, $value)
    {
        if ($value) $query->where('qid', $value);
    }

    public function searchIsFollowAttr($query, $value)
    {
        if ($value !== '') $query->where('is_follow', $value);
    }
}
