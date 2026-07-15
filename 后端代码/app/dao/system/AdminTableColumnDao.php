<?php
namespace app\dao\system;

use app\dao\BaseDao;
use app\model\system\AdminTableColumn;

class AdminTableColumnDao extends BaseDao
{
    protected function setModel(): string
    {
        return AdminTableColumn::class;
    }

    public function getByUniqueKey(int $adminType, int $adminId, string $tableKey)
    {
        return $this->getOne([
            'admin_type' => $adminType,
            'admin_id' => $adminId,
            'table_key' => $tableKey,
        ]);
    }
}
