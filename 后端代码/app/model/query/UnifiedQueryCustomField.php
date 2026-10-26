<?php

namespace app\model\query;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class UnifiedQueryCustomField extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'unified_query_custom_field';
    protected $autoWriteTimestamp = false;
}
