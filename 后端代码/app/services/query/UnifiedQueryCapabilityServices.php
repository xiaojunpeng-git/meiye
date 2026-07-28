<?php

namespace app\services\query;

/**
 * query-unified-query-capabilities 的稳定 data 合同。
 */
class UnifiedQueryCapabilityServices
{
    /** @var UnifiedQueryPageRegistry */
    protected $registry;

    /** @var UnifiedQueryCustomFieldServices */
    protected $customFields;

    /** @var UnifiedQueryFieldAliasServices */
    protected $aliases;

    /** @var UnifiedQueryExportTaskServices */
    protected $exports;

    /** @var UnifiedQueryPreferenceServices */
    protected $preferences;

    /** @var UnifiedQueryAccessPolicy */
    protected $access;

    public function __construct(
        UnifiedQueryPageRegistry $registry,
        UnifiedQueryCustomFieldServices $customFields,
        UnifiedQueryFieldAliasServices $aliases,
        UnifiedQueryExportTaskServices $exports,
        UnifiedQueryPreferenceServices $preferences,
        UnifiedQueryAccessPolicy $access
    ) {
        $this->registry = $registry;
        $this->customFields = $customFields;
        $this->aliases = $aliases;
        $this->exports = $exports;
        $this->preferences = $preferences;
        $this->access = $access;
    }

    /**
     * $queryPreferenceVersion 必须来自服务端 query_preference/member_list 当前版本，
     * 不能从请求 payload 透传。
     */
    public function build(
        array $rawContext,
        string $pageCode,
        int $queryPreferenceVersion,
        int $dataAsOf = 0
    ): array {
        $context = $this->access->normalizeContext($rawContext);
        $this->access->assertPageAccess($context);
        if ($queryPreferenceVersion <= 0) {
            throw new \LogicException('capability 必须携带可信 query_preference 版本');
        }
        $permissions = $this->access->capabilityPermissions($context);
        $fieldAliases = $this->aliases->aliases($context, $pageCode);
        $aliasVersion = $this->aliases->currentVersion($context, $pageCode);
        $dataAsOf = $dataAsOf > 0 ? $dataAsOf : (int)($context['data_as_of'] ?? time());

        return [
            'enabled' => true,
            'pageCode' => $pageCode,
            'pageName' => (string)$this->registry->page($pageCode)['label'],
            'fields' => $this->registry->fieldsForCapability($pageCode, $context['permissions']),
            'customFields' => $this->customFields->listVisible($context, $pageCode, true),
            'fieldAliases' => $fieldAliases,
            'fieldAliasVersion' => $aliasVersion,
            'querySettings' => $this->preferences->load($context, $pageCode),
            'permissions' => $permissions,
            'export' => $this->exports->exportCapability($permissions),
            'queryCutoffDate' => (string)$context['query_cutoff_date'],
            'dataAsOf' => date('Y-m-d H:i:s', $dataAsOf),
            'schemaVersion' => $this->registry->schemaVersion(),
            'versions' => [
                'query_preference' => [
                    $pageCode => $queryPreferenceVersion,
                ],
                'field_aliases' => [
                    $pageCode => $aliasVersion,
                ],
            ],
        ];
    }
}
