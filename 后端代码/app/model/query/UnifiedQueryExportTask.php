<?php

namespace app\model\query;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class UnifiedQueryExportTask extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'unified_query_export_task';
    protected $autoWriteTimestamp = false;
}
