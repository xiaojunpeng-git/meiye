<?php

namespace app\model\query;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class UnifiedQueryFieldAlias extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'unified_query_field_alias';
    protected $autoWriteTimestamp = false;
}
