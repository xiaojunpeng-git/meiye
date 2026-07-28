<?php

namespace app\services\query;

use think\facade\Db;

/**
 * 保存查询、报表和导出任务对字段版本的显式引用。
 */
class UnifiedQueryFieldReferenceServices
{
    public const TABLE = 'unified_query_field_reference';
    public const EXPORT_TABLE = 'unified_query_export_task';

    public function register(
        string $tenantId,
        string $consumerType,
        string $consumerId,
        array $fieldVersions,
        string $queryCutoffDate
    ): void {
        if (!in_array($consumerType, ['saved_query', 'report', 'export_task'], true)
            || !preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $consumerId)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_REFERENCE_INVALID',
                '查询引用信息不合法，请刷新后重试。',
                ['consumer_type' => $consumerType, 'consumer_id' => $consumerId]
            );
        }
        $normalizedVersions = [];
        foreach ($fieldVersions as $fieldKey => $version) {
            $fieldKey = (string)$fieldKey;
            $version = (int)$version;
            if (!preg_match('/^cf_[a-f0-9]{20,40}$/D', $fieldKey) || $version <= 0) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_REFERENCE_INVALID',
                    '查询引用的字段版本不合法，请重新保存。',
                    ['field_key' => $fieldKey, 'version' => $version]
                );
            }
            $normalizedVersions[$fieldKey] = $version;
        }
        // 多字段导出/保存查询与字段停用共用字段行锁；稳定顺序避免两个请求
        // 以相反顺序锁多行造成死锁。
        ksort($normalizedVersions, SORT_STRING);
        $now = time();
        $fieldKeys = array_keys($normalizedVersions);
        foreach ($normalizedVersions as $fieldKey => $version) {
            $field = Db::name('unified_query_custom_field')
                ->where('tenant_id', $tenantId)
                ->where('field_key', $fieldKey)
                ->lock(true)
                ->find();
            if (!$field) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_FIELD_NOT_FOUND',
                    '引用的自定义字段已不存在，请重新选择。',
                    ['field_key' => $fieldKey]
                );
            }
            // 版本快照可继续引用 active 字段的旧版本（upgrade_available），但停用/
            // 归档字段绝不能在校验与 register 之间重新写入新任务引用。
            if ((string)$field['status'] !== 'active') {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_CUSTOM_FIELD_UNAVAILABLE',
                    '该字段已停用或失效，请移除、替换或恢复字段后再执行。',
                    ['field_key' => $fieldKey, 'status' => (string)$field['status']]
                );
            }
            $versionExists = Db::name('unified_query_custom_field_version')
                ->where('custom_field_id', (int)$field['id'])
                ->where('version', $version)
                ->count();
            if ((int)$versionExists !== 1) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_FIELD_VERSION_NOT_FOUND',
                    '引用的字段版本已不可用，请升级后重试。',
                    ['field_key' => $fieldKey, 'version' => $version]
                );
            }
            $upgradeAvailable = $version < (int)$field['current_version'];
            $existing = Db::name(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('consumer_type', $consumerType)
                ->where('consumer_id', $consumerId)
                ->where('field_key', $fieldKey)
                ->find();
            $data = [
                'custom_field_id' => (int)$field['id'],
                'field_version' => $version,
                'status' => $upgradeAvailable ? 'upgrade_available' : 'active',
                'invalid_reason' => $upgradeAvailable
                    ? '该字段已有新版本，可选择升级。'
                    : '',
                'query_cutoff_date' => $queryCutoffDate,
                'updated_at' => $now,
            ];
            if ($existing) {
                Db::name(self::TABLE)->where('id', (int)$existing['id'])->update($data);
            } else {
                Db::name(self::TABLE)->insert(array_merge($data, [
                    'tenant_id' => $tenantId,
                    'consumer_type' => $consumerType,
                    'consumer_id' => $consumerId,
                    'field_key' => $fieldKey,
                    'created_at' => $now,
                ]));
            }
        }
        $query = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('consumer_type', $consumerType)
            ->where('consumer_id', $consumerId);
        if ($fieldKeys) {
            $query->whereNotIn('field_key', $fieldKeys);
        }
        $query->where('status', '<>', 'released')->update([
            'status' => 'released',
            'invalid_reason' => '',
            'updated_at' => $now,
        ]);
    }

    public function markUpgradeAvailable(string $tenantId, string $fieldKey, int $currentVersion): void
    {
        Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('field_key', $fieldKey)
            ->where('field_version', '<', $currentVersion)
            ->whereIn('status', ['active', 'upgrade_available'])
            ->update([
                'status' => 'upgrade_available',
                'invalid_reason' => '该字段已有新版本，可选择升级。',
                'updated_at' => time(),
            ]);
    }

    public function invalidate(string $tenantId, string $fieldKey, string $reason): void
    {
        $now = time();
        $taskNos = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('field_key', $fieldKey)
            ->where('consumer_type', 'export_task')
            ->where('status', '<>', 'released')
            ->column('consumer_id');
        Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('field_key', $fieldKey)
            ->whereIn('status', ['active', 'upgrade_available'])
            ->update([
                'status' => 'invalid',
                'invalid_reason' => $reason,
                'updated_at' => $now,
            ]);
        if ($taskNos) {
            $taskNos = array_values(array_unique(array_map('strval', $taskNos)));
            Db::name(self::EXPORT_TABLE)
                ->where('tenant_id', $tenantId)
                ->whereIn('task_no', $taskNos)
                ->where('status', 'pending')
                ->update([
                    'status' => 'blocked',
                    'error_reason' => $reason,
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);
        }
        // blocked 是不可再执行的导出终态。任务行仍保留冻结计划、字段快照和错误
        // 原因；释放的是“阻止归档的活跃引用”，不是删除历史。
        $this->releaseBlockedExportReferencesForField($tenantId, $fieldKey, $now);
    }

    public function restoreAsUpgradeAvailable(string $tenantId, string $fieldKey): void
    {
        Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('field_key', $fieldKey)
            ->where('status', 'invalid')
            ->where('consumer_type', '<>', 'export_task')
            ->update([
                'status' => 'upgrade_available',
                'invalid_reason' => '字段已恢复，请确认是否升级到最新版本。',
                'updated_at' => time(),
            ]);
    }

    public function blockingCount(string $tenantId, string $fieldKey): int
    {
        // 兼容已经按旧路径被标 blocked 的任务：重复调用是幂等的，能在归档前收口
        // 遗留活跃引用，避免管理员只能手工改库。
        $this->releaseBlockedExportReferencesForField($tenantId, $fieldKey, time());
        return (int)Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('field_key', $fieldKey)
            ->where('status', '<>', 'released')
            ->count();
    }

    public function release(string $tenantId, string $consumerType, string $consumerId): void
    {
        Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('consumer_type', $consumerType)
            ->where('consumer_id', $consumerId)
            ->where('status', '<>', 'released')
            ->update([
                'status' => 'released',
                'invalid_reason' => '',
                'updated_at' => time(),
        ]);
    }

    /**
     * 只回收已明确 blocked 的导出任务引用。失败/成功路径由 ExportTaskServices
     * 自己在同一事务中 release；这里专门修复字段停用的 pending -> blocked 转换。
     */
    protected function releaseBlockedExportReferencesForField(
        string $tenantId,
        string $fieldKey,
        int $now
    ): void {
        $taskNos = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('field_key', $fieldKey)
            ->where('consumer_type', 'export_task')
            ->where('status', '<>', 'released')
            ->column('consumer_id');
        if (!$taskNos) {
            return;
        }
        $taskNos = array_values(array_unique(array_map('strval', $taskNos)));
        $blockedTaskNos = Db::name(self::EXPORT_TABLE)
            ->where('tenant_id', $tenantId)
            ->whereIn('task_no', $taskNos)
            ->where('status', 'blocked')
            ->column('task_no');
        if (!$blockedTaskNos) {
            return;
        }
        Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('consumer_type', 'export_task')
            ->whereIn('consumer_id', array_values(array_unique(array_map('strval', $blockedTaskNos))))
            ->where('status', '<>', 'released')
            ->update([
                'status' => 'released',
                // 任务的 error_reason 保存原始停用原因；这里保留其终止语义，便于
                // 审计引用行时理解为何不再阻止归档。
                'invalid_reason' => '导出任务已因字段停用终止；冻结快照已保留。',
                'updated_at' => $now,
            ]);
    }
}
