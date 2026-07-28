<?php

namespace app\model\query;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class UnifiedQueryFieldAliasSet extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'unified_query_field_alias_set';
    protected $autoWriteTimestamp = false;
}
