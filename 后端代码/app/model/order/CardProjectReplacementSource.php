<?php

namespace app\model\order;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 卡内项目替换来源明细
 */
class CardProjectReplacementSource extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';

    protected $name = 'card_project_replacement_source';
}
