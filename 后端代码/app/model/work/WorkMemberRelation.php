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
 * 企业微信成员关联表
 * Class WorkMemberRelation
 * @package app\model\work
 */
class WorkMemberRelation extends BaseModel
{
    use ModelTrait;

    /**
     * @var string
     */
    protected $name = 'work_member_relation';

    /**
     * @var string
     */
    protected $key = 'id';

    /**
     * @var bool
     */
    protected $autoWriteTimestamp = false;
}
