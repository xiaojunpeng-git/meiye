<?php

namespace app\dao\query;

use app\dao\BaseDao;
use app\model\query\UnifiedQueryPreference;

class UnifiedQueryPreferenceDao extends BaseDao
{
    protected function setModel(): string
    {
        return UnifiedQueryPreference::class;
    }
}
