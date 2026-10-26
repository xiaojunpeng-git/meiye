<?php

namespace app\model\query;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class UnifiedQueryPreference extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'unified_query_preference';
    protected $autoWriteTimestamp = false;
}
