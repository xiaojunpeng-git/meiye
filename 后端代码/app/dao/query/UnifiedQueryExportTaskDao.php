<?php

namespace app\dao\query;

use app\dao\BaseDao;
use app\model\query\UnifiedQueryExportTask;

class UnifiedQueryExportTaskDao extends BaseDao
{
    protected function setModel(): string
    {
        return UnifiedQueryExportTask::class;
    }
}
