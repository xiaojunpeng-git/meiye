<?php

namespace app\services\report;

use think\facade\Db;

/**
 * 门店运营六表的受控补充记录服务。
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
        'store_salesperson_performance',
        'market_performance', 'market_detail',
        'field_acquisition_detail', 'field_acquisition_summary',
        'cross_industry_customer_detail', 'cross_industry_customer_summary',
        'new_customer_analysis',
        'six_dimension_consumption_refund_detail',
        'operations_pre_sale_bdegh', 'operations_customer_status_bdegh', 'operations_referral_beautician_pre_sale',
        'operations_performance_comparison', 'operations_health_data', 'operations_beauty_item',
        'operations_annual_member_consumption', 'six_dimension_analysis', 'marketing_acquisition_pre_sale',
        'marketing_referral_pre_sale', 'marketing_post_sale_performance', 'marketing_health_data',
        'marketing_beauty_new_item', 'marketing_customer_status', 'product_monetization_performance_total',
        'product_monetization_beauty_performance_total', 'product_monetization_beauty_item',
        'product_monetization_beauty_market_distribution', 'product_monetization_six_dimension_performance',
        'product_monetization_six_dimension_item_performance', 'product_monetization_six_dimension_efficiency',
        'product_monetization_six_dimension_market_distribution', 'product_monetization_haomei_performance',
        'product_monetization_private_performance', 'product_monetization_private_item_performance',
        'product_monetization_private_efficiency', 'product_monetization_private_market_distribution',
        'phase_six_garden_item_analysis', 'phase_six_monthly_featured_item', 'phase_six_headquarters_acquisition',
        'phase_six_other_multi_payment', 'phase_six_salary_summary', 'phase_six_salary_detail',
        'phase_six_training_employee', 'phase_six_acquisition_source', 'phase_six_human_store_health',
    ];

    private const FIELD_RULES = [
        'partner_item_detail' => ['medical_elevation', 'medical_followup', 'expert_name', 'remark'],
        'member_consumption_detail' => ['experience_cash', 'experience_payment_method'],
        'market_performance' => [],
        'market_detail' => ['walk_in'],
        'field_acquisition_detail' => ['card_sale_date', 'visit_over_one_hour'],
        'field_acquisition_summary' => [],
        'cross_industry_customer_detail' => [],
        'cross_industry_customer_summary' => ['customer_acquired_at', 'partner_store_name'],
        'new_customer_analysis' => ['experience_card_amount', 'care_duration'],
        'six_dimension_consumption_refund_detail' => ['complaint_count'],
        'operations_pre_sale_bdegh' => ['experience_people'],
        'operations_referral_beautician_pre_sale' => ['experience_people'],
        'marketing_acquisition_pre_sale' => ['experience_target', 'new_customer_target', 'new_customer_amount_target'],
        'marketing_referral_pre_sale' => ['experience_target', 'new_customer_target', 'new_customer_amount_target'],
        'operations_performance_comparison' => ['full_target_amount'],
        'operations_health_data' => ['actual_staff'],
        'marketing_health_data' => ['performance_target', 'required_staff', 'actual_staff'],
        'product_monetization_beauty_performance_total' => ['target_amount'],
        'product_monetization_beauty_market_distribution' => ['target_amount'],
        'product_monetization_six_dimension_performance' => ['target_amount'],
        'product_monetization_six_dimension_efficiency' => ['visit_count', 'new_visit_count'],
        'product_monetization_six_dimension_market_distribution' => ['target_amount'],
        'product_monetization_haomei_performance' => ['target_amount'],
        'product_monetization_private_performance' => ['target_amount'],
        'product_monetization_private_efficiency' => ['visit_count', 'new_visit_count'],
        'product_monetization_private_market_distribution' => ['target_amount'],
        'phase_six_garden_item_analysis' => ['expert_name'],
        'phase_six_monthly_featured_item' => ['target_amount'],
        'phase_six_other_multi_payment' => ['unit_price', '领取日期', '领取数量', '备注'],
        'phase_six_salary_detail' => ['备注'],
        'phase_six_training_employee' => ['mentor_name', 'exam_apply_time', 'exam_level', 'passed_level', 'skill_score', 'professional_score'],
        'phase_six_human_store_health' => ['beautician_establishment_count'],
    ];

    private const FIELD_TYPES = [
        'walk_in' => 'integer',
        'refund_headcount_manual' => 'integer',
        'manual_cash_amount' => 'integer_cents',
        // 会员消费明细的体验现金业绩由页面按元录入、按分持久化；服务端必须拒绝非整数分值。
        'experience_cash' => 'integer_cents',
        'experience_card_amount' => 'integer_cents',
        'visit_over_one_hour' => 'integer',
        'complaint_count' => 'nonnegative_integer',
        'target_amount' => 'integer_cents',
        'target_count' => 'nonnegative_integer',
        'visit_count' => 'nonnegative_integer',
        'new_visit_count' => 'nonnegative_integer',
        'experience_people' => 'nonnegative_integer',
        'experience_target' => 'nonnegative_integer',
        'new_customer_target' => 'nonnegative_integer',
        'new_customer_amount_target' => 'integer_cents',
        'full_target_amount' => 'integer_cents',
        'performance_target' => 'integer_cents',
        'required_staff' => 'nonnegative_integer',
        'actual_staff' => 'nonnegative_integer',
        'card_sale_date' => 'date',
        'customer_acquired_at' => 'date',
        'unit_price' => 'integer_cents',
        '领取日期' => 'date',
        '领取数量' => 'nonnegative_integer',
        '备注' => 'text',
        'expert_name' => 'text',
        'mentor_name' => 'text',
        'exam_apply_time' => 'date',
        'exam_level' => 'text',
        'passed_level' => 'text',
        'skill_score' => 'decimal',
        'professional_score' => 'decimal',
        'beautician_establishment_count' => 'nonnegative_integer',
    ];

    /** @return array<int,array<string,mixed>> */
    public function listAnnotations(array $context, array $filter = []): array
    {
        $scope = $this->scope($context);
        $reportCode = $this->assertReportCode($filter['report_code'] ?? '');
        if ($scope['store_ids'] === []) return [];
        $query = Db::name(self::ANNOTATION_TABLE)
            ->where('tenant_id', $scope['tenant_id'])
            ->where('report_code', $reportCode);
        if ($scope['store_ids'] !== null) $query->whereIn('store_id', $scope['store_ids']);
        foreach (['subject_type', 'subject_key', 'field_key'] as $key) {
            if (($value = trim((string)($filter[$key] ?? ''))) !== '') $query->where($key, $value);
        }
        $rows = $query->order('updated_at', 'desc')->order('id', 'desc')->select()->toArray();
        if ($scope['authorization_mode'] === 'self_participant') {
            $participant = new StoreReportParticipantScopeServices();
            $rows = array_values(array_filter($rows, function (array $row) use ($participant, $scope): bool {
                return $participant->annotationIsVisible($row, $scope['tenant_id'], $scope['participant_employee_id']);
            }));
        }
        return $rows;
    }

    /** 保存一项报表补充字段，并用稳定行版本防止并发覆盖。 */
    public function saveAnnotation(array $context, array $payload): array
    {
        $scope = $this->scope($context);
        $reportCode = $this->assertReportCode($payload['report_code'] ?? '');
        $subjectType = $this->opaque($payload['subject_type'] ?? '', 'subject_type', 32);
        $subjectKey = $this->opaque($payload['subject_key'] ?? '', 'subject_key', 128);
        $fieldKey = $this->fieldKey($reportCode, $payload['field_key'] ?? '');
        $idempotencyKey = $this->opaque($payload['idempotency_key'] ?? '', 'idempotency_key', 128);
        $value = (string)($payload['field_value'] ?? '');
        $valueType = $this->fieldType($fieldKey);
        $this->validateFieldValue($valueType, $value);
        if ($reportCode === 'market_detail' && $fieldKey === 'walk_in' && $value !== '' && (int)$value < 0) {
            throw new \InvalidArgumentException('进店数不能小于 0');
        }
        if (mb_strlen($value, 'UTF-8') > 65535) throw new \InvalidArgumentException('补充字段内容不能超过 65535 个字符');
        $storeId = (int)($payload['store_id'] ?? ($context['store_id'] ?? 0));
        $expectedVersion = (int)($payload['expected_version'] ?? 0);
        $operatorId = (int)($context['operator_id'] ?? $context['admin_id'] ?? 0);
        $operatorName = mb_substr((string)($context['operator_name'] ?? $context['admin_name'] ?? ''), 0, 128);
        $sourceFactId = max(0, (int)($payload['source_fact_id'] ?? 0));
        $sourceOrderId = mb_substr(trim((string)($payload['source_order_id'] ?? '')), 0, 64);
        $sourceLineId = mb_substr(trim((string)($payload['source_line_id'] ?? '')), 0, 64);
        if ($reportCode === 'market_detail' && $subjectType === 'market_member_day') {
            // 保存目标必须是当前权限下可见的会员每日来源行；客户端不能仅凭
            // 自报 store_id 伪造一条补充记录，也不能把合计值写回任一原单。
            $resolved = (new StoreReportParticipantScopeServices())->resolveSubject(
                $scope['tenant_id'], $subjectType, $subjectKey,
                $scope['authorization_mode'] === 'self_participant' ? $scope['participant_employee_id'] : 0
            );
            if ($resolved === null) throw new \InvalidArgumentException('市场明细行不存在或无权编辑');
            $storeId = (int)$resolved['store_id'];
            $sourceFactId = 0;
            $sourceOrderId = '';
            $sourceLineId = '';
        } elseif ($reportCode === 'six_dimension_consumption_refund_detail') {
            if ($subjectType !== 'business_event_line') {
                throw new \InvalidArgumentException('客诉数量必须绑定真实业务事件');
            }
            $resolved = (new StoreReportParticipantScopeServices())->resolveSubject(
                $scope['tenant_id'], $subjectType, $subjectKey,
                $scope['authorization_mode'] === 'self_participant' ? $scope['participant_employee_id'] : 0
            );
            if ($resolved === null) throw new \InvalidArgumentException('业务事件不存在或无权编辑');
            $storeId = (int)$resolved['store_id'];
            $sourceFactId = (int)$resolved['source_fact_id'];
            $sourceOrderId = (string)$resolved['source_order_id'];
            $sourceLineId = (string)$resolved['source_line_id'];
        } elseif ($subjectType === 'phase_four_month') {
            if ($storeId !== 0) throw new \InvalidArgumentException('月度手动字段只能保存到当前报表汇总行');
        } elseif ($scope['authorization_mode'] === 'self_participant') {
            $resolved = (new StoreReportParticipantScopeServices())->resolveSubject(
                $scope['tenant_id'], $subjectType, $subjectKey, $scope['participant_employee_id']
            );
            if ($resolved === null) throw new \InvalidArgumentException('无权编辑非本人参与的报表数据');
            $storeId = (int)$resolved['store_id'];
            $sourceFactId = (int)$resolved['source_fact_id'];
            $sourceOrderId = (string)$resolved['source_order_id'];
            $sourceLineId = (string)$resolved['source_line_id'];
        }
        if ($storeId !== 0) $this->assertStoreAllowed($scope, $storeId);
        $organizationId = (string)($context['organization_id'] ?? '');
        $now = time();

        return Db::transaction(function () use ($scope, $reportCode, $subjectType, $subjectKey, $fieldKey, $idempotencyKey, $value, $valueType, $storeId, $expectedVersion, $operatorId, $operatorName, $sourceFactId, $sourceOrderId, $sourceLineId, $organizationId, $now): array {
            $audit = Db::name(self::ANNOTATION_AUDIT_TABLE)
                ->where('tenant_id', $scope['tenant_id'])->where('idempotency_key', $idempotencyKey)->lock(true)->find();
            if (is_array($audit)) {
                if ((string)$audit['report_code'] !== $reportCode
                    || (string)$audit['subject_type'] !== $subjectType
                    || (string)$audit['subject_key'] !== $subjectKey
                    || (string)$audit['field_key'] !== $fieldKey
                    || (string)$audit['after_value'] !== $value) {
                    throw new \InvalidArgumentException('幂等标识已用于其他补充内容');
                }
                $row = Db::name(self::ANNOTATION_TABLE)->where('id', (int)$audit['annotation_id'])->find();
                if (!$row) throw new \RuntimeException('补充记录审计存在但当前记录缺失');
                $row['field_value'] = (string)$audit['after_value'];
                $row['version'] = (int)$audit['after_version'];
                $row['updated_at'] = (int)$audit['occurred_at'];
                return $this->projection($row, true);
            }

            $query = Db::name(self::ANNOTATION_TABLE)->where('tenant_id', $scope['tenant_id'])
                ->where('report_code', $reportCode)->where('subject_type', $subjectType)
                ->where('subject_key', $subjectKey)->where('field_key', $fieldKey)->lock(true);
            $existing = $query->find();
            if (is_array($existing)) {
                if ((int)$existing['store_id'] !== $storeId
                    || ($scope['store_ids'] !== null && $storeId !== 0 && !in_array((int)$existing['store_id'], $scope['store_ids'], true))) {
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
                    'tenant_id' => $scope['tenant_id'], 'organization_id' => $organizationId,
                    'store_id' => $storeId, 'report_code' => $reportCode, 'subject_type' => $subjectType,
                    'subject_key' => $subjectKey, 'source_fact_id' => $sourceFactId,
                    'source_order_id' => $sourceOrderId, 'source_line_id' => $sourceLineId,
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
        $categoryById = [];
        foreach ($rows as $row) $categoryById[(int)$row['id']] = $row;
        $result = [];
        foreach ($rows as $row) {
            $id = (string)$row['id'];
            $config = $configs[$id] ?? [];
            $pathParts = [];
            $cursor = (int)$row['id'];
            $seen = [];
            while ($cursor > 0 && isset($categoryById[$cursor]) && !isset($seen[$cursor])) {
                $seen[$cursor] = true;
                array_unshift($pathParts, trim((string)$categoryById[$cursor]['cate_name']));
                $cursor = (int)$categoryById[$cursor]['pid'];
            }
            $categoryPath = implode(' / ', array_filter($pathParts, static fn(string $part): bool => $part !== ''));
            $result[] = [
                'category_id' => $id,
                'parent_id' => (string)($row['pid'] ?? 0),
                'category_name' => (string)$row['cate_name'],
                'category_path' => $categoryPath,
                'partner_label' => $categoryPath,
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
        $authorizationMode = trim((string)($context['authorization_mode'] ?? 'stores'));
        $participantEmployeeId = $authorizationMode === 'self_participant'
            ? max(0, (int)($context['participant_employee_id'] ?? 0)) : 0;
        if ($authorizationMode === 'none') throw new \InvalidArgumentException('当前账号没有可查看的数据范围');
        if ($authorizationMode === 'self_participant' && $participantEmployeeId <= 0) {
            throw new \InvalidArgumentException('个人数据权限缺少有效员工身份');
        }
        return [
            'tenant_id' => $tenant,
            'store_ids' => $authorizationMode === 'self_participant' ? null : $ids,
            'authorization_mode' => $authorizationMode,
            'participant_employee_id' => $participantEmployeeId,
        ];
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
        if ($reportCode === 'product_monetization_beauty_market_distribution'
            && preg_match('/^company_[1-9][0-9]*_target_amount$/D', $key)) return $key;
        if (!in_array($key, self::FIELD_RULES[$reportCode] ?? [], true)) throw new \InvalidArgumentException('该报表字段不允许编辑');
        return $key;
    }

    private function fieldType(string $fieldKey): string
    {
        if (preg_match('/^company_[1-9][0-9]*_target_amount$/D', $fieldKey)) return 'integer_cents';
        return (string)(self::FIELD_TYPES[$fieldKey] ?? 'text');
    }

    private function validateFieldValue(string $valueType, string $value): void
    {
        // Empty string is a deliberate manual clear and must survive readback.
        if ($value === '' || $valueType === 'text') return;
        if (in_array($valueType, ['integer', 'integer_cents', 'nonnegative_integer'], true)
            && !preg_match('/^-?\d+$/D', trim($value))) {
            throw new \InvalidArgumentException('该补充字段必须填写整数');
        }
        if ($valueType === 'nonnegative_integer' && (int)$value < 0) {
            throw new \InvalidArgumentException('客诉数量不能小于 0');
        }
        if ($valueType === 'date') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) {
                throw new \InvalidArgumentException('日期格式必须为 YYYY-MM-DD');
            }
        }
    }

    private function opaque($value, string $field, int $max): string
    {
        $value = trim((string)$value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $max) throw new \InvalidArgumentException($field . '无效');
        return $value;
    }

    private function projection(array $row, bool $replayed): array
    {
        $result = ['id' => (int)$row['id'], 'report_code' => (string)$row['report_code'], 'subject_type' => (string)$row['subject_type'], 'subject_key' => (string)$row['subject_key'], 'field_key' => (string)$row['field_key'], 'field_value' => (string)$row['field_value'], 'replayed' => $replayed, 'updated_at' => (int)$row['updated_at']];
        $result['version'] = (int)$row['version'];
        return $result;
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
