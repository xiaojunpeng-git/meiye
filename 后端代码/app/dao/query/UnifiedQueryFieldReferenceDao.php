<?php

namespace app\dao\query;

use app\dao\BaseDao;
use app\model\query\UnifiedQueryFieldReference;

class UnifiedQueryFieldReferenceDao extends BaseDao
{
    protected function setModel(): string
    {
        return UnifiedQueryFieldReference::class;
    }
}
