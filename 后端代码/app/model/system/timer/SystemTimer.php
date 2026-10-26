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

namespace app\model\system\timer;

use mohe\basic\BaseModel;
use mohe\traits\JwtAuthModelTrait;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 定时任务模型
 * Class SystemTimer
 * @package app\model\system\timer
 */
class SystemTimer extends BaseModel
{
    use ModelTrait;
    use JwtAuthModelTrait;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'system_timer';

    protected $insert = ['add_time'];

}
