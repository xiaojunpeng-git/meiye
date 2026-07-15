<?php
namespace app\services\system;

use app\dao\system\AdminTableColumnDao;
use app\services\BaseServices;
use mohe\exceptions\AdminException;

class AdminTableColumnServices extends BaseServices
{
    public function __construct(AdminTableColumnDao $dao)
    {
        $this->dao = $dao;
    }

    public function getColumnSetting(int $adminType, int $adminId, string $tableKey): array
    {
        if ($adminId <= 0 || $tableKey === '') {
            throw new AdminException('参数有误');
        }
        try {
            $row = $this->dao->getByUniqueKey($adminType, $adminId, $tableKey);
        } catch (\Throwable $e) {
            // 升级包未执行时表可能不存在，降级为空配置，避免列表页 500
            if ($this->isMissingTableError($e)) {
                return [
                    'table_key' => $tableKey,
                    'columns' => [],
                ];
            }
            throw $e;
        }
        if (!$row) {
            return [
                'table_key' => $tableKey,
                'columns' => [],
            ];
        }
        $data = is_object($row) ? $row->toArray() : (array)$row;
        $columns = [];
        if (!empty($data['columns_json'])) {
            $decoded = json_decode((string)$data['columns_json'], true);
            $columns = is_array($decoded) ? $decoded : [];
        }
        return [
            'table_key' => $tableKey,
            'columns' => $columns,
        ];
    }

    public function saveColumnSetting(int $adminType, int $adminId, string $tableKey, $columns): bool
    {
        if ($adminId <= 0 || $tableKey === '') {
            throw new AdminException('参数有误');
        }
        if (!is_array($columns)) {
            throw new AdminException('列配置格式错误');
        }
        $json = json_encode($columns, JSON_UNESCAPED_UNICODE);
        $time = time();
        try {
            $row = $this->dao->getByUniqueKey($adminType, $adminId, $tableKey);
            if ($row) {
                return (bool)$this->dao->update((int)$row['id'], [
                    'columns_json' => $json,
                    'update_time' => $time,
                ]);
            }
            return (bool)$this->dao->save([
                'admin_type' => $adminType,
                'admin_id' => $adminId,
                'table_key' => $tableKey,
                'columns_json' => $json,
                'update_time' => $time,
            ]);
        } catch (\Throwable $e) {
            if ($this->isMissingTableError($e)) {
                throw new AdminException('列设置表未初始化，请先执行数据库升级包 20260715-008-staff-manage-opt');
            }
            throw $e;
        }
    }

    protected function isMissingTableError(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return strpos($msg, 'admin_table_column') !== false
            || strpos($msg, "doesn't exist") !== false
            || strpos($msg, 'Base table or view not found') !== false;
    }
}
