<?php

namespace app\services\query;

use think\facade\Db;

/**
 * 账号 + 页面级显示名。只改变展示与导出表头，不改变字段稳定 key 或计算口径。
 */
class UnifiedQueryFieldAliasServices
{
    public const SET_TABLE = 'unified_query_field_alias_set';
    public const TABLE = 'unified_query_field_alias';

    /** @var UnifiedQueryPageRegistry */
    protected $registry;

    /** @var UnifiedQueryCustomFieldServices */
    protected $customFields;

    /** @var UnifiedQueryAccessPolicy */
    protected $access;

    public function __construct(
        UnifiedQueryPageRegistry $registry,
        UnifiedQueryCustomFieldServices $customFields,
        UnifiedQueryAccessPolicy $access
    ) {
        $this->registry = $registry;
        $this->customFields = $customFields;
        $this->access = $access;
    }

    /**
     * 对齐 save-unified-query-field-aliases。
     */
    public function save(array $rawContext, array $payload): array
    {
        $context = $this->access->normalizeContext($rawContext);
        $this->access->assertPageAccess($context);
        $pageCode = (string)($payload['pageCode'] ?? ($payload['page_code'] ?? ''));
        $this->registry->page($pageCode);
        $expectedVersion = (int)($payload['expectedAliasVersion']
            ?? ($payload['expected_alias_version'] ?? ($payload['expectedVersion'] ?? 0)));
        $aliases = $this->normalizeAliases($payload['aliases'] ?? []);
        $available = $this->availableFields($context, $pageCode);
        $finalLabels = [];
        $storedAliases = [];
        foreach ($available as $fieldKey => $field) {
            $original = (string)$field['label'];
            $alias = trim((string)($aliases[$fieldKey] ?? ''));
            if ($alias !== '') {
                $alias = $this->normalizeAlias($alias);
                if (!empty($field['customFieldId'])) {
                    $this->registry->assertCustomIdentityAllowed($fieldKey, $alias);
                } else {
                    $this->registry->assertAliasAllowed($pageCode, $fieldKey, $alias);
                }
            }
            $display = $alias !== '' ? $alias : $original;
            $nameKey = $this->nameKey($display);
            if (isset($finalLabels[$nameKey])) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_ALIAS_DUPLICATE',
                    '字段名称不能重复，请调整“' . $display . '”。',
                    ['field_keys' => [$finalLabels[$nameKey], $fieldKey]]
                );
            }
            $finalLabels[$nameKey] = $fieldKey;
            if ($alias !== '' && $alias !== $original) {
                $storedAliases[$fieldKey] = $alias;
            }
        }
        foreach ($aliases as $fieldKey => $alias) {
            if (!isset($available[$fieldKey]) && trim((string)$alias) !== '') {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_FIELD_NOT_ALLOWED',
                    '包含已不可用的字段名称，请刷新后重试。',
                    ['field_key' => $fieldKey]
                );
            }
        }

        return Db::transaction(function () use (
            $context,
            $pageCode,
            $expectedVersion,
            $storedAliases
        ) {
            $set = Db::name(self::SET_TABLE)
                ->where('tenant_id', $context['tenant_id'])
                ->where('account_id', $context['account_id'])
                ->where('page_code', $pageCode)
                ->lock(true)
                ->find();
            if (!$set) {
                // 未保存时对外的虚拟基线版本为 1。
                if ($expectedVersion !== 1) {
                    throw $this->versionConflict($expectedVersion, 1);
                }
                $setId = (int)Db::name(self::SET_TABLE)->insertGetId([
                    'tenant_id' => $context['tenant_id'],
                    'account_id' => $context['account_id'],
                    'page_code' => $pageCode,
                    'current_version' => 1,
                    'created_at' => time(),
                    'updated_at' => time(),
                ]);
                $set = ['id' => $setId, 'current_version' => 1];
            } elseif ($expectedVersion <= 0 || (int)$set['current_version'] !== $expectedVersion) {
                throw $this->versionConflict($expectedVersion, (int)$set['current_version']);
            }
            $currentVersion = (int)$set['current_version'];
            $newVersion = $currentVersion + 1;
            $updated = Db::name(self::SET_TABLE)
                ->where('id', (int)$set['id'])
                ->where('current_version', $currentVersion)
                ->update([
                    'current_version' => $newVersion,
                    'updated_at' => time(),
                ]);
            if ((int)$updated !== 1) {
                throw $this->versionConflict($currentVersion);
            }
            Db::name(self::TABLE)
                ->where('tenant_id', $context['tenant_id'])
                ->where('account_id', $context['account_id'])
                ->where('page_code', $pageCode)
                ->delete();
            $rows = [];
            foreach ($storedAliases as $fieldKey => $alias) {
                $rows[] = [
                    'tenant_id' => $context['tenant_id'],
                    'account_id' => $context['account_id'],
                    'page_code' => $pageCode,
                    'field_key' => $fieldKey,
                    'alias' => $alias,
                    'alias_name_key' => $this->nameKey($alias),
                    'alias_version' => $newVersion,
                    'created_at' => time(),
                    'updated_at' => time(),
                ];
            }
            if ($rows) {
                Db::name(self::TABLE)->insertAll($rows);
            }
            return [
                'pageCode' => $pageCode,
                'fieldAliases' => $storedAliases,
                'aliasVersion' => $newVersion,
            ];
        });
    }

    public function aliases(array $rawContext, string $pageCode): array
    {
        $context = $this->access->normalizeContext($rawContext);
        $this->access->assertPageAccess($context);
        $rows = Db::name(self::TABLE)
            ->where('tenant_id', $context['tenant_id'])
            ->where('account_id', $context['account_id'])
            ->where('page_code', $pageCode)
            ->order('id asc')
            ->select()
            ->toArray();
        $aliases = [];
        foreach ($rows as $row) {
            $aliases[(string)$row['field_key']] = (string)$row['alias'];
        }
        return $aliases;
    }

    public function currentVersion(array $rawContext, string $pageCode): int
    {
        $context = $this->access->normalizeContext($rawContext);
        $version = Db::name(self::SET_TABLE)
            ->where('tenant_id', $context['tenant_id'])
            ->where('account_id', $context['account_id'])
            ->where('page_code', $pageCode)
            ->value('current_version');
        return $version ? (int)$version : 1;
    }

    public function applyToFields(array $fields, array $aliases): array
    {
        foreach ($fields as &$field) {
            $key = (string)($field['key'] ?? '');
            if (isset($aliases[$key]) && trim((string)$aliases[$key]) !== '') {
                $field['originalLabel'] = (string)$field['label'];
                $field['label'] = (string)$aliases[$key];
            }
        }
        unset($field);
        return $fields;
    }

    protected function availableFields(array $context, string $pageCode): array
    {
        $available = [];
        foreach ($this->registry->fieldsForCapability($pageCode, $context['permissions']) as $field) {
            $available[(string)$field['key']] = $field;
        }
        foreach ($this->customFields->listVisible($context, $pageCode, true) as $field) {
            $available[(string)$field['key']] = $field;
        }
        return $available;
    }

    protected function normalizeAliases($aliases): array
    {
        if (!is_array($aliases)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_ALIAS_INVALID',
                '字段名称配置格式不正确。',
                []
            );
        }
        $normalized = [];
        foreach ($aliases as $key => $value) {
            if (is_array($value)) {
                $fieldKey = (string)($value['fieldKey'] ?? ($value['field_key'] ?? ''));
                $alias = (string)($value['alias'] ?? '');
            } else {
                $fieldKey = (string)$key;
                $alias = (string)$value;
            }
            if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $fieldKey)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_ALIAS_INVALID',
                    '字段名称配置包含无效字段。',
                    ['field_key' => $fieldKey]
                );
            }
            $normalized[$fieldKey] = $alias;
        }
        return $normalized;
    }

    protected function normalizeAlias(string $alias): string
    {
        $alias = trim(preg_replace('/\s+/u', ' ', $alias));
        $length = function_exists('mb_strlen') ? mb_strlen($alias, 'UTF-8') : strlen($alias);
        if ($alias === '' || $length > 32) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_ALIAS_INVALID',
                '字段名称需为 1 到 32 个字符。',
                []
            );
        }
        return $alias;
    }

    protected function nameKey(string $name): string
    {
        return hash('sha256', strtolower(preg_replace('/\s+/u', '', trim($name))));
    }

    protected function versionConflict(int $expected, int $current = 0): UnifiedQueryException
    {
        return new UnifiedQueryException(
            'UNIFIED_QUERY_ALIAS_VERSION_CONFLICT',
            '字段名称已在其他窗口更新，请刷新后重试。',
            ['expected_version' => $expected, 'current_version' => $current]
        );
    }
}
