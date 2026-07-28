<?php

namespace app\dao\query;

use app\dao\BaseDao;
use app\model\query\UnifiedQueryFieldAlias;

class UnifiedQueryFieldAliasDao extends BaseDao
{
    protected function setModel(): string
    {
        return UnifiedQueryFieldAlias::class;
    }
}
