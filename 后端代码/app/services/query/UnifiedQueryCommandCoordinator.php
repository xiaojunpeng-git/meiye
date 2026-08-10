<?php

namespace app\services\query;

use app\jobs\query\UnifiedQueryExportJob;

/**
 * 统一查询元数据写命令的中立白名单分派。
 *
 * 幂等、query_preference 资源锁与命令回执仍由宿主 gateway 负责；本类只接受
 * 宿主已经锁定并写入 context 的可信 query_preference_version。
 */
class UnifiedQueryCommandCoordinator
{
    public const SAVE_SETTINGS = 'save-unified-query-settings';
    public const SAVE_ALIASES = 'save-unified-query-field-aliases';
    public const SAVE_CUSTOM_FIELD = 'save-unified-query-custom-field';
    public const CHANGE_CUSTOM_FIELD_STATUS = 'change-unified-query-custom-field-status';
    public const ARCHIVE_CUSTOM_FIELD = 'archive-unified-query-custom-field';
    public const UPGRADE_FIELD_REFERENCE = 'upgrade-unified-query-field-reference';
    public const CREATE_EXPORT = 'create-unified-query-export';

    /** @var UnifiedQueryPageRegistry */
    protected $registry;

    /** @var UnifiedQueryPreferenceServices */
    protected $preferences;

    /** @var UnifiedQueryFieldAliasServices */
    protected $aliases;

    /** @var UnifiedQueryCustomFieldServices */
    protected $customFields;

    /** @var UnifiedQueryExportTaskServices */
    protected $exports;

    public function __construct(
        UnifiedQueryPageRegistry $registry,
        UnifiedQueryPreferenceServices $preferences,
        UnifiedQueryFieldAliasServices $aliases,
        UnifiedQueryCustomFieldServices $customFields,
        UnifiedQueryExportTaskServices $exports
    ) {
        $this->registry = $registry;
        $this->preferences = $preferences;
        $this->aliases = $aliases;
        $this->customFields = $customFields;
        $this->exports = $exports;
    }

    public function supportedActions(): array
    {
        return [
            self::SAVE_SETTINGS,
            self::SAVE_ALIASES,
            self::SAVE_CUSTOM_FIELD,
            self::CHANGE_CUSTOM_FIELD_STATUS,
            self::ARCHIVE_CUSTOM_FIELD,
            self::UPGRADE_FIELD_REFERENCE,
            self::CREATE_EXPORT,
        ];
    }

    /**
     * @return array{data:array,business_no:string,touched:array,message:string}
     */
    public function dispatch(string $action, array $context, array $payload): array
    {
        if (!in_array($action, $this->supportedActions(), true)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_COMMAND_NOT_ALLOWED',
                '该统一查询操作不受支持，请刷新后重试。',
                ['action' => $action]
            );
        }
        if ((int)($context['query_preference_version'] ?? 0) <= 0) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_GATEWAY_VERSION_REQUIRED',
                '查询设置缺少并发保护，请刷新后重试。',
                ['action' => $action]
            );
        }
        $pageCode = trim((string)($payload['pageCode'] ?? ($payload['page_code'] ?? '')));
        $this->registry->page($pageCode);
        if (isset($context['page_code'])
            && (string)$context['page_code'] !== ''
            && (string)$context['page_code'] !== $pageCode) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_PAGE_CONFLICT',
                '查询页面已变化，请刷新后重试。',
                [
                    'expected' => (string)$context['page_code'],
                    'received' => $pageCode,
                ]
            );
        }

        if ($action === self::SAVE_SETTINGS) {
            $data = $this->preferences->save($context, $payload);
        } elseif ($action === self::SAVE_ALIASES) {
            $data = $this->aliases->save($context, $payload);
        } elseif ($action === self::SAVE_CUSTOM_FIELD) {
            $data = $this->customFields->save($context, $payload);
        } elseif ($action === self::CHANGE_CUSTOM_FIELD_STATUS) {
            $data = $this->customFields->changeStatus($context, $payload);
        } elseif ($action === self::ARCHIVE_CUSTOM_FIELD) {
            $data = $this->customFields->archive($context, $payload);
        } elseif ($action === self::UPGRADE_FIELD_REFERENCE) {
            $data = $this->preferences->upgradeReference($context, $payload);
        } else {
            $data = ['exportTask' => $this->exports->create($context, $payload)];
            $taskNo = (string)($data['exportTask']['taskId'] ?? '');
            if ($taskNo === '') {
                throw new \LogicException('统一查询导出任务创建结果缺少任务编号');
            }
            // 任务创建事务已经完成后再投递；任务 worker 仍使用数据库 lease 领取，
            // 因而网络重试或幂等回执重放不会生成重复文件。
            UnifiedQueryExportJob::dispatch([$taskNo]);
        }
        $taskNo = (string)($data['exportTask']['taskId'] ?? '');
        return [
            'data' => $data,
            'business_no' => $taskNo,
            'touched' => ['query_preference'],
            'message' => $this->successMessage($action),
        ];
    }

    protected function successMessage(string $action): string
    {
        $messages = [
            self::SAVE_SETTINGS => '查询设置已保存。',
            self::SAVE_ALIASES => '字段名称已更新。',
            self::SAVE_CUSTOM_FIELD => '自定义字段已保存。',
            self::CHANGE_CUSTOM_FIELD_STATUS => '字段状态已更新。',
            self::ARCHIVE_CUSTOM_FIELD => '自定义字段已删除。',
            self::UPGRADE_FIELD_REFERENCE => '已升级到字段最新版本。',
            self::CREATE_EXPORT => '导出任务已创建。',
        ];
        return $messages[$action];
    }
}
