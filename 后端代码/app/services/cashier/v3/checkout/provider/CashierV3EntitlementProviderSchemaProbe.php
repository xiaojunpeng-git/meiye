<?php

namespace app\services\cashier\v3\checkout\provider;

use think\facade\Db;

final class CashierV3EntitlementProviderSchemaProbe
{
    public static function missingColumns(string $table, array $requiredColumns): array
    {
        try {
            $rows = Db::query(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
                [$table]
            );
        } catch (\Throwable $exception) {
            return array_values($requiredColumns);
        }
        $present = [];
        foreach ((array)$rows as $row) {
            $present[(string)($row['COLUMN_NAME'] ?? $row['column_name'] ?? '')] = true;
        }
        $missing = [];
        foreach ($requiredColumns as $column) {
            if (!isset($present[$column])) {
                $missing[] = $column;
            }
        }
        return $missing;
    }

    public static function tablesStatus(array $contracts): array
    {
        $missing = [];
        foreach ($contracts as $table => $columns) {
            $missingColumns = self::missingColumns($table, $columns);
            if ($missingColumns) {
                $missing[$table] = $missingColumns;
            }
        }
        return [
            'ready' => $missing === [],
            'missing' => $missing,
        ];
    }
}
