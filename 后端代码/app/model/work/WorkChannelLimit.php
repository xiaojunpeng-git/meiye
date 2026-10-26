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

namespace app\model\work;


use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * Class WorkChannelLimit
 * @package app\model\work
 */
class WorkChannelLimit extends BaseModel
{

    use ModelTrait;

    /**
     * @var string
     */
    protected $name = 'work_channel_limit';

    /**
     * @return \think\model\relation\HasOne
     */
    public function member()
    {
        return $this->hasOne(WorkMember::class, 'userid', 'userid')->bind([
            'name' => 'name',
            'avatar' => 'avatar'
        ]);
    }

}
