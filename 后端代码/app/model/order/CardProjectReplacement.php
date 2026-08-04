<?php

namespace app\model\order;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 卡内项目替换业务主表
 */
class CardProjectReplacement extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';

    protected $name = 'card_project_replacement';

    protected $autoWriteTimestamp = false;
}
