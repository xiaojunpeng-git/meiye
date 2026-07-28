<?php

namespace app\dao\query;

use app\dao\BaseDao;
use app\model\query\UnifiedQueryCustomField;

class UnifiedQueryCustomFieldDao extends BaseDao
{
    protected function setModel(): string
    {
        return UnifiedQueryCustomField::class;
    }

    public function lockByFieldKey(string $tenantId, string $fieldKey): ?array
    {
        $row = $this->getModel()
            ->where('tenant_id', $tenantId)
            ->where('field_key', $fieldKey)
            ->lock(true)
            ->find();
        return $row ? $row->toArray() : null;
    }
}
