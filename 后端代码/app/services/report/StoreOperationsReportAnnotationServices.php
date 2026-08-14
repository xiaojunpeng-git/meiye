<?php

namespace app\services\report;

use think\facade\Db;

/**
 * 门店运营七表的受控补充记录服务。
 *
 * 该服务只保存报表补充字段和商品分类合作方配置；销售、收款、服务、
 * 业绩事实仍由 V3 事实写入器维护。调用方必须传入已经由认证中间件
 * 解析出的租户和可见门店范围，不能使用客户端提交的组织范围替代它。
 */
final class StoreOperationsReportAnnotationServices
{
    public const ANNOTATION_TABLE = 'cashier_v3_report_annotation';
    public const ANNOTATION_AUDIT_TABLE = 'cashier_v3_report_annotation_audit';
    public const CATEGORY_TABLE = 'cashier_v3_report_category_config';
    public const CATEGORY_AUDIT_TABLE = 'cashier_v3_report_category_config_audit';

    private const REPORT_CODES = [
        'partner_item_summary', 'partner_item_detail', 'member_consumption_detail',
        'store_item_analysis', 'store_craftsman_consumption',
        'store_salesperson_performance', 'market_performance',
    ];

    private const FIELD_RULES = [
        'partner_item_detail' => ['medical_elevation', 'medical_followup', 'expert_name', 'remark'],
        'market_performance' => ['walk_in_manual_count', 'refund_headcount_manual', 'manual_cash_amount', 'remark'],
    ];

    private const FIELD_TYPES = [
        'walk_in_manual_count' => 'integer',
        'refund_headcount_manual' => 'integer',
        'manual_cash_amount' => 'integer_cents',
    ];

    /** @return array<int,array<string,mixed>> */
    public function listAnnotations(array $context, array $filter = []): array
    {
        $scope = $this->scope($context);
        $query = Db::name(self::ANNOTATION_TABLE)
            ->where('tenant_id', $scope['tenant_id']);
        if ($scope['store_ids'] !== null) $query->whereIn('store_id', $scope['store_ids']);
        if (($reportCode = trim((string)($filter['report_code'] ?? ''))) !== '') {
            $this->assertReportCode($reportCode);
            $query->where('report_code', $reportCode);
        }
        foreach (['subject_type', 'subject_key', 'field_key'] as $key) {
            if (($value = trim((string)($filter[$key] ?? ''))) !== '') $query->where($key, $value);
        }
        return $query->order('updated_at', 'desc')->order('id', 'desc')->select()->toArray();
    }

    /**
     * 保存一项报表补充字段。新建使用 expected_version=0，更新必须提交服务端
     * 上次返回的 version；相同幂等键重放只返回第一次结果。
     */
    public function saveAnnotation(array $context, array $payload): array
    {
        $scope = $this->scope($context);
        $reportCode = $this->assertReportCode($payload['report_code'] ?? '');
        $subjectType = $this->opaque($payload['subject_type'] ?? '', 'subject_type', 32);
        $subjectKey = $this->opaque($payload['subject_key'] ?? '', 'subject_key', 128);
        $fieldKey = $this->fieldKey($reportCode, $payload['field_key'] ?? '');
        $idempotencyKey = $this->opaque($payload['idempotency_key'] ?? '', 'idempotency_key', 128);
        $value = (string)($payload['field_value'] ?? '');
        $valueType = (string)(self::FIELD_TYPES[$fieldKey] ?? 'text');
        if ($valueType !== 'text' && !preg_match('/^-?\d+$/D', trim($value))) {
            throw new \InvalidArgumentException('该补充字段必须填写整数');
        }
        if (mb_strlen($value, 'UTF-8') > 65535) throw new \InvalidArgumentException('补充字段内容不能超过 65535 个字符');
        $storeId = (int)($payload['store_id'] ?? ($context['store_id'] ?? 0));
        $this->assertStoreAllowed($scope, $storeId);
        $expectedVersion = (int)($payload['expected_version'] ?? 0);
        $operatorId = (int)($context['operator_id'] ?? $context['admin_id'] ?? 0);
        $operatorName = mb_substr((string)($context['operator_name'] ?? $context['admin_name'] ?? ''), 0, 128);
        $now = time();

        return Db::transaction(function () use ($scope, $reportCode, $subjectType, $subjectKey, $fieldKey, $idempotencyKey, $value, $valueType, $storeId, $expectedVersion, $operatorId, $operatorName, $now): array {
            $audit = Db::name(self::ANNOTATION_AUDIT_TABLE)
                ->where('tenant_id', $scope['tenant_id'])->where('idempotency_key', $idempotencyKey)->lock(true)->find();
            if (is_array($audit)) {
                if ((string)$audit['report_code'] !== $reportCode || (string)$audit['field_key'] !== $fieldKey || (string)$audit['after_value'] !== $value) {
                    throw new \InvalidArgumentException('幂等标识已用于其他补充内容');
                }
                $row = Db::name(self::ANNOTATION_TABLE)->where('id', (int)$audit['annotation_id'])->find();
                if (!$row) throw new \RuntimeException('补充记录审计存在但当前记录缺失');
                return $this->projection($row, true);
            }

            $query = Db::name(self::ANNOTATION_TABLE)->where('tenant_id', $scope['tenant_id'])
                ->where('report_code', $reportCode)->where('subject_type', $subjectType)
                ->where('subject_key', $subjectKey)->where('field_key', $fieldKey)->lock(true);
            $existing = $query->find();
            if (is_array($existing)) {
                if ((int)$existing['store_id'] !== $storeId
                    || ($scope['store_ids'] !== null && !in_array((int)$existing['store_id'], $scope['store_ids'], true))) {
                    throw new \InvalidArgumentException('补充记录不属于当前门店数据范围');
                }
                if ($expectedVersion <= 0 || $expectedVersion !== (int)$existing['version']) {
                    throw new \InvalidArgumentException('补充记录已更新，请刷新后再保存');
                }
                $beforeValue = (string)$existing['field_value'];
                $newVersion = (int)$existing['version'] + 1;
                Db::name(self::ANNOTATION_TABLE)->where('id', (int)$existing['id'])->update([
                    'field_value' => $value, 'version' => $newVersion, 'updated_by' => $operatorId,
                    'updated_by_name_snapshot' => $operatorName, 'updated_at' => $now,
                ]);
                $annotationId = (int)$existing['id'];
                $action = 'updated';
            } else {
                if ($expectedVersion !== 0) throw new \InvalidArgumentException('新补充记录的版本必须为 0');
                $beforeValue = '';
                $newVersion = 1;
                $annotationId = (int)Db::name(self::ANNOTATION_TABLE)->insertGetId([
                    'tenant_id' => $scope['tenant_id'], 'organization_id' => (string)($context['organization_id'] ?? ''),
                    'store_id' => $storeId, 'report_code' => $reportCode, 'subject_type' => $subjectType,
                    'subject_key' => $subjectKey, 'source_fact_id' => (int)($context['source_fact_id'] ?? 0),
                    'source_order_id' => mb_substr((string)($context['source_order_id'] ?? ''), 0, 64),
                    'source_line_id' => mb_substr((string)($context['source_line_id'] ?? ''), 0, 64),
                    'field_key' => $fieldKey, 'value_type' => $valueType,
                    'field_value' => $value, 'version' => 1, 'created_by' => $operatorId,
                    'created_by_name_snapshot' => $operatorName, 'updated_by' => $operatorId,
                    'updated_by_name_snapshot' => $operatorName, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $action = 'created';
            }
            Db::name(self::ANNOTATION_AUDIT_TABLE)->insert([
                'tenant_id' => $scope['tenant_id'], 'annotation_id' => $annotationId, 'report_code' => $reportCode,
                'subject_type' => $subjectType, 'subject_key' => $subjectKey, 'field_key' => $fieldKey,
                'action' => $action, 'idempotency_key' => $idempotencyKey, 'before_value' => $beforeValue,
                'after_value' => $value, 'before_version' => $newVersion - 1, 'after_version' => $newVersion,
                'operator_id' => $operatorId, 'operator_name_snapshot' => $operatorName, 'occurred_at' => $now,
            ]);
            $row = Db::name(self::ANNOTATION_TABLE)->where('id', $annotationId)->find();
            if (!is_array($row)) throw new \RuntimeException('补充记录保存后读取失败');
            return $this->projection($row, false);
        });
    }

    /** @return array<int,array<string,mixed>> */
    public function listCategoryConfigs(array $context, bool $enabledOnly = false): array
    {
        $scope = $this->scope($context);
        $query = Db::name(self::CATEGORY_TABLE)->where('tenant_id', $scope['tenant_id']);
        if ($enabledOnly) $query->where('enabled', 1);
        return $query->order('category_path_snapshot', 'asc')->order('id', 'asc')->select()->toArray();
    }

    /** 返回 8080 商品分类中当前启用的分类，并合并已保存的合作方配置。 */
    public function listAvailableCategories(array $context): array
    {
        $scope = $this->scope($context);
        $configs = [];
        foreach ($this->listCategoryConfigs($context) as $row) {
            $configs[(string)$row['category_id']] = $row;
        }
        $rows = Db::name('store_product_category')->where('is_show', 1)
            ->field('id,pid,cate_name')->order('pid', 'asc')->order('id', 'asc')->select()->toArray();
        $result = [];
        foreach ($rows as $row) {
            $id = (string)$row['id'];
            $config = $configs[$id] ?? [];
            $result[] = [
                'category_id' => $id,
                'parent_id' => (string)($row['pid'] ?? 0),
                'category_name' => (string)$row['cate_name'],
                'category_path' => $this->categoryPath((int)$row['id']),
                'partner_label' => $this->categoryPath((int)$row['id']),
                // partner_name 仅为旧响应兼容字段，前端不得编辑。
                'partner_name' => (string)($config['partner_name'] ?? ''),
                'enabled' => (int)($config['enabled'] ?? 0),
                'partner_enabled' => (int)($config['enabled'] ?? 0),
                'partner_default_ratio' => $this->partnerRatio($config),
                'partnerDefaultRatio' => $this->partnerRatio($config),
                'version' => (int)($config['version'] ?? 0),
            ];
        }
        return $result;
    }

    /** 保存分类级合作方开关；合作方名称不再由客户端维护。 */
    public function saveCategoryConfig(array $context, array $payload): array
    {
        $scope = $this->scope($context);
        $categoryId = $this->opaque($payload['category_id'] ?? '', 'category_id', 64);
        $category = Db::name('store_product_category')->where('id', $categoryId)->where('is_show', 1)->find();
        if (!is_array($category)) throw new \InvalidArgumentException('商品分类不存在或已停用');
        if (array_key_exists('partner_name', $payload)) {
            throw new \InvalidArgumentException('合作方配置只支持开关，不支持输入名称');
        }
        if (!array_key_exists('enabled', $payload) || !in_array((string)$payload['enabled'], ['0', '1'], true)) {
            throw new \InvalidArgumentException('合作方开关值无效');
        }
        $enabled = (int)$payload['enabled'];
        $partnerDefaultRatio = $this->partnerRatioValue($payload['partner_default_ratio'] ?? 0);
        $expectedVersion = array_key_exists('expected_version', $payload) ? (int)$payload['expected_version'] : null;
        $partnerLabel = mb_substr($this->categoryPath((int)$category['id']), 0, 128);
        $idempotencyKey = $this->opaque($payload['idempotency_key'] ?? '', 'idempotency_key', 128);
        $operatorId = (int)($context['operator_id'] ?? $context['admin_id'] ?? 0);
        $operatorName = mb_substr((string)($context['operator_name'] ?? $context['admin_name'] ?? ''), 0, 128);
        $now = time();
        return Db::transaction(function () use ($scope, $categoryId, $category, $enabled, $partnerDefaultRatio, $expectedVersion, $partnerLabel, $idempotencyKey, $operatorId, $operatorName, $now): array {
            $audit = Db::name(self::CATEGORY_AUDIT_TABLE)->where('tenant_id', $scope['tenant_id'])->where('idempotency_key', $idempotencyKey)->lock(true)->find();
            if (is_array($audit)) {
                if ((string)$audit['category_id'] !== $categoryId) {
                    throw new \InvalidArgumentException('幂等标识已用于其他商品分类');
                }
                $replay = Db::name(self::CATEGORY_TABLE)->where('id', (int)$audit['category_config_id'])->find();
                if (!is_array($replay)) throw new \RuntimeException('分类配置审计存在但当前配置缺失');
                if ((int)($replay['enabled'] ?? 0) !== $enabled
                    || $this->partnerRatio($replay) !== $partnerDefaultRatio) {
                    throw new \InvalidArgumentException('幂等标识已用于其他合作方开关');
                }
                return $this->categoryProjection($replay);
            }
            $row = Db::name(self::CATEGORY_TABLE)->where('tenant_id', $scope['tenant_id'])->where('category_id', $categoryId)->lock(true)->find();
            $before = is_array($row) ? $row : [];
            if ($expectedVersion !== null && (int)($row['version'] ?? 0) !== $expectedVersion) {
                throw new \InvalidArgumentException('合作方配置已被其他人修改，请刷新后重试');
            }
            $data = [
                'category_name_snapshot' => mb_substr((string)$category['cate_name'], 0, 128),
                'category_parent_id_snapshot' => (string)($category['pid'] ?? 0),
                'category_parent_name_snapshot' => $this->categoryParentName((int)($category['pid'] ?? 0)),
                'category_path_snapshot' => mb_substr($this->categoryPath((int)$category['id']), 0, 512),
                // 旧表结构保留 partner_name，但值由服务端从分类路径推导，不接受人工名称。
                'partner_name' => $enabled === 1 ? $partnerLabel : '', 'enabled' => $enabled,
                'partner_default_ratio' => $partnerDefaultRatio,
                'updated_by' => $operatorId, 'updated_by_name_snapshot' => $operatorName, 'updated_at' => $now,
            ];
            if (is_array($row)) {
                $data['version'] = (int)$row['version'] + 1;
                Db::name(self::CATEGORY_TABLE)->where('id', (int)$row['id'])->update($data);
                $id = (int)$row['id']; $action = 'updated';
            } else {
                $data += ['tenant_id' => $scope['tenant_id'], 'category_id' => $categoryId, 'version' => 1, 'created_by' => $operatorId, 'created_by_name_snapshot' => $operatorName, 'created_at' => $now];
                $id = (int)Db::name(self::CATEGORY_TABLE)->insertGetId($data); $action = 'created';
            }
            $after = Db::name(self::CATEGORY_TABLE)->where('id', $id)->find();
            Db::name(self::CATEGORY_AUDIT_TABLE)->insert([
                'tenant_id' => $scope['tenant_id'], 'category_config_id' => $id, 'category_id' => $categoryId,
                'action' => $action, 'idempotency_key' => $idempotencyKey,
                'before_snapshot_json' => $this->json($before), 'after_snapshot_json' => $this->json($after ?: []),
                'operator_id' => $operatorId, 'operator_name_snapshot' => $operatorName, 'occurred_at' => $now,
            ]);
            return $this->categoryProjection($after ?: []);
        });
    }

    private function scope(array $context): array
    {
        $tenant = trim((string)($context['tenant_id'] ?? ''));
        if ($tenant === '') throw new \InvalidArgumentException('报表数据范围缺少租户');
        $ids = array_values(array_filter(array_map('intval', (array)($context['store_ids'] ?? []))));
        if (array_key_exists('store_id', $context) && (int)$context['store_id'] > 0 && !$ids) $ids = [(int)$context['store_id']];
        return ['tenant_id' => $tenant, 'store_ids' => $ids ?: null];
    }

    private function assertStoreAllowed(array $scope, int $storeId): void
    {
        if ($storeId <= 0 || ($scope['store_ids'] !== null && !in_array($storeId, $scope['store_ids'], true))) throw new \InvalidArgumentException('无权写入该门店报表补充记录');
    }

    private function assertReportCode($value): string
    {
        $code = trim((string)$value);
        if (!in_array($code, self::REPORT_CODES, true)) throw new \InvalidArgumentException('报表类型无效');
        return $code;
    }

    private function fieldKey(string $reportCode, $value): string
    {
        $key = trim((string)$value);
        if (!in_array($key, self::FIELD_RULES[$reportCode] ?? [], true)) throw new \InvalidArgumentException('该报表字段不允许编辑');
        return $key;
    }

    private function opaque($value, string $field, int $max): string
    {
        $value = trim((string)$value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $max) throw new \InvalidArgumentException($field . '无效');
        return $value;
    }

    private function projection(array $row, bool $replayed): array
    {
        return ['id' => (int)$row['id'], 'report_code' => (string)$row['report_code'], 'subject_type' => (string)$row['subject_type'], 'subject_key' => (string)$row['subject_key'], 'field_key' => (string)$row['field_key'], 'field_value' => (string)$row['field_value'], 'version' => (int)$row['version'], 'replayed' => $replayed, 'updated_at' => (int)$row['updated_at']];
    }

    private function categoryProjection(array $row): array
    {
        $enabled = (int)($row['enabled'] ?? 0);
        $ratio = $this->partnerRatio($row);
        return ['id' => (int)($row['id'] ?? 0), 'category_id' => (string)($row['category_id'] ?? ''), 'category_name_snapshot' => (string)($row['category_name_snapshot'] ?? ''), 'category_path_snapshot' => (string)($row['category_path_snapshot'] ?? ''), 'partner_label' => (string)($row['category_path_snapshot'] ?? ''), 'partner_name' => (string)($row['partner_name'] ?? ''), 'enabled' => $enabled, 'partner_enabled' => $enabled, 'partner_default_ratio' => $ratio, 'partnerDefaultRatio' => $ratio, 'version' => (int)($row['version'] ?? 0), 'updated_at' => (int)($row['updated_at'] ?? 0)];
    }

    private function partnerRatio(array $row): int
    {
        return max(0, min(100, (int)($row['partner_default_ratio'] ?? $row['partnerDefaultRatio'] ?? 0)));
    }

    private function partnerRatioValue($value): int
    {
        $value = trim((string)$value);
        if ($value === '' || !preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) {
            throw new \InvalidArgumentException('合作方默认比例必须是 0 到 100 的整数');
        }
        $ratio = (int)$value;
        if ($ratio < 0 || $ratio > 100) {
            throw new \InvalidArgumentException('合作方默认比例必须是 0 到 100 的整数');
        }
        return $ratio;
    }

    private function json(array $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function categoryPath(int $categoryId): string
    {
        $parts = [];
        $parent = $categoryId;
        $guard = 0;
        while ($parent > 0 && $guard++ < 8) {
            $row = Db::name('store_product_category')->where('id', $parent)->where('is_show', 1)->field('id,pid,cate_name')->find();
            if (!$row) break;
            array_unshift($parts, (string)$row['cate_name']);
            $parent = (int)$row['pid'];
        }
        return implode(' / ', array_filter($parts));
    }

    private function categoryParentName(int $parentId): string
    {
        return $parentId > 0 ? (string)(Db::name('store_product_category')->where('id', $parentId)->where('is_show', 1)->value('cate_name') ?? '') : '';
    }
}
