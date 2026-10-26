<?php
declare(strict_types=1);

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\report\GroupManagementDashboardDailyAggregateServices;
use think\facade\Db;

/**
 * 第22轮订单归属更正：只改业务归属日或来源快照，并在同一事务留下前后值。
 * 金额、实际发生时间、结算时间、请求指纹和后续独立业务事件均不参与更正。
 */
final class CashierV3OrderAttributionCorrectionServices
{
    private const LOG_TABLE = 'cashier_v3_order_attribution_change';
    private const SOURCE_COLUMNS = [
        'business_source_primary_id', 'business_source_primary_name_snapshot',
        'business_source_secondary_id', 'business_source_secondary_name_snapshot',
        'business_source_label_snapshot',
    ];
    private const FACT_TABLES = [
        'cashier_v3_sale_fact', 'cashier_v3_payment_fact',
        'cashier_v3_balance_fact', 'cashier_v3_performance_fact',
    ];

    /** 来源选择只能来自当前有效配置；历史名称只用于审计显示。 */
    public function options(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $this->assertAccess($operator, $scope);
        return ['sources' => (new CashierV3BusinessConfigServices())->sourceTree(true)];
    }

    /** 详情的变更记录由独立审计表读取，不增加订单或事实的业务版本。 */
    public function history(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $this->assertAccess($operator, $scope);
        [$type, $id] = $this->identity($payload);
        $this->record($type, $id, $operator, $scope, false);
        $rows = Db::name(self::LOG_TABLE)->where('tenant_id', $scope->tenantId())
            ->where(function ($query) use ($type, $id): void {
                $query->where(function ($root) use ($type, $id): void {
                    $root->where('root_record_type', $type)->where('root_record_id', $id);
                })->whereOr(function ($own) use ($type, $id): void {
                    $own->where('record_type', $type)->where('record_id', $id);
                });
            })->order('id', 'desc')->limit(100)->select()->toArray();
        return ['changes' => array_map(static function (array $row): array {
            return [
                'recordType' => (string)$row['record_type'], 'recordId' => (string)$row['record_id'],
                'recordNo' => (string)$row['record_no_snapshot'], 'field' => (string)$row['field_key'],
                'oldValue' => (string)$row['old_value'], 'newValue' => (string)$row['new_value'],
                'operatorName' => (string)$row['operator_name_snapshot'], 'operatorId' => (int)$row['operator_id'],
                'occurredAt' => (int)$row['occurred_at'],
            ];
        }, $rows)];
    }

    /** Gateway 已开启事务；本方法在锁定主单和关联欠款后一次更新权威主表、事实及审计。 */
    public function executeInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('orderAttributionCorrection');
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        $recorder = $scope['event_recorder'] ?? null;
        $execution = $scope['event_execution'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext
            || !$recorder instanceof CashierV3BusinessEventRecorder || !$execution instanceof CashierV3BusinessEventExecution) {
            throw CashierV3CommandException::invalidContext('更正服务未就绪，请刷新订单中心。');
        }
        $this->assertAccess($operator, $dataScope);
        $payload = (array)($scope['payload'] ?? []);
        [$type, $id] = $this->identity($payload);
        $action = (string)($scope['action'] ?? '');
        $field = (string)($payload['field'] ?? '');
        if (($type === 'sales' && $action !== 'correct-sales-order-attribution')
            || ($type === 'refund' && $action !== 'correct-refund-date')
            || ($type === 'service' && $action !== ($field === 'source'
                ? 'correct-service-order-attribution' : 'correct-service-order-date'))
            || ($type === 'recharge' && $action !== 'correct-recharge-order-attribution')
            || ($type === 'supplement' && $action !== ($field === 'source'
                ? 'correct-debt-repayment-source' : 'correct-debt-repayment-date'))
            || !in_array($field, $type === 'refund' ? ['business_date'] : ['business_date', 'source'], true)) {
            throw CashierV3CommandException::invalidContext('该单据不支持此项更正。');
        }
        $key = trim((string)($scope['idempotency_key'] ?? ''));
        if ($key === '') throw CashierV3CommandException::invalidContext('更正请求缺少操作标识。');
        $expected = trim((string)($payload['expectedValue'] ?? ''));
        $targetDate = $field === 'business_date' ? $this->validDate((string)($payload['newValue'] ?? '')) : '';
        $primaryId = $field === 'source' ? (int)($payload['primarySourceId'] ?? 0) : 0;
        $secondaryId = $field === 'source' ? (int)($payload['secondarySourceId'] ?? 0) : 0;
        $expectedPrimaryId = $field === 'source' ? (int)($payload['expectedPrimarySourceId'] ?? -1) : 0;
        $expectedSecondaryId = $field === 'source' ? (int)($payload['expectedSecondarySourceId'] ?? -1) : 0;
        $fingerprint = hash('sha256', json_encode([$type, $id, $field, $expected, $targetDate,
            $primaryId, $secondaryId, $expectedPrimaryId, $expectedSecondaryId], JSON_UNESCAPED_UNICODE));
        $replay = Db::name(self::LOG_TABLE)->where('tenant_id', $dataScope->tenantId())
            ->where('command_idempotency_key', $key)->lock(true)->find();
        if ($replay) {
            if ((string)$replay['request_fingerprint'] !== $fingerprint) {
                throw CashierV3CommandException::invalidContext('同一次更正请求的内容已变化，请刷新后重试。');
            }
            return ['operationId' => (string)$replay['operation_id'], 'recordId' => $id, 'replayed' => true];
        }
        // 补交先锁欠款，再锁补交，最后才解析并锁定来源目录；与收款事务
        // 保持同一锁顺序，避免来源更正与正在发生的还款互相等待。
        $this->lockRelatedDebts($type, $id, $operator, $dataScope, $field);
        $record = $this->record($type, $id, $operator, $dataScope, true);
        $source = $field === 'source'
            ? (new CashierV3BusinessConfigServices())->resolveSourceSnapshot($primaryId, $secondaryId, true)
            : [];
        $old = $field === 'business_date' ? (string)$record['business_date'] : $this->sourceLabel($record);
        $new = $field === 'business_date' ? $targetDate : $this->sourceLabel($this->sourceColumns($source));
        if ($old !== $expected || ($field === 'source' && (
            (int)$record['business_source_primary_id'] !== $expectedPrimaryId
            || (int)$record['business_source_secondary_id'] !== $expectedSecondaryId
        ))) throw CashierV3CommandException::versionConflict('日期或来源已被其他操作更改，请刷新后重试。');
        if ($field === 'business_date' ? $old === $new : (
            (int)$record['business_source_primary_id'] === $primaryId
            && (int)$record['business_source_secondary_id'] === $secondaryId
        )) throw CashierV3CommandException::invalidContext('更正后的值与当前记录相同。');
        $now = time();
        $operationId = 'OAC-' . strtoupper(substr(hash('sha256', $dataScope->tenantId() . '|' . $key), 0, 40));
        if ($field === 'source') {
            if ($type === 'supplement') {
                $this->correctSupplementSource($id, $record, $source, $dataScope);
            } elseif ($type === 'service') {
                $this->correctServiceSource($id, $record, $source, $operator, $dataScope, $now);
            } else {
                $this->correctSource($type, $id, $record, $source, $operator, $dataScope, $operationId, $key, $fingerprint, $now);
            }
        } else {
            $this->correctDate($type, $id, $record, $targetDate, $dataScope);
            // 物化集团日汇总按旧、新业务日重建，结果与事实层在提交时一同生效。
            $aggregate = new GroupManagementDashboardDailyAggregateServices();
            $aggregate->rebuild($dataScope->tenantId(), [$operator->storeId()], $old, $old);
            $aggregate->rebuild($dataScope->tenantId(), [$operator->storeId()], $new, $new);
        }
        $this->log($operationId, $key, $fingerprint, $dataScope->tenantId(), $operator, $type, $id,
            $type, $id, $this->recordNo($type, $record), $field, $old, $new, $now);
        $recorder->recordInTx($execution, (array)($scope['event_contract'] ?? []), [
            'event_type' => 'order_attribution.corrected', 'aggregate_type' => 'attribution_correction',
            'aggregate_id' => $operationId, 'aggregate_version' => 1, 'event_version' => 1,
            'source_type' => $action, 'source_id' => $operationId,
            'member_id' => (int)($record['member_id'] ?? $record['uid'] ?? 0),
            'business_date' => date('Y-m-d', $now), 'occurred_at' => $now,
            'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => $this->recordNo($type, $record),
            'payload' => ['operationId' => $operationId, 'recordType' => $type, 'recordId' => $id,
                'field' => $field, 'oldValue' => $old, 'newValue' => $new],
        ]);
        return ['operationId' => $operationId, 'recordId' => $id, 'replayed' => false];
    }

    private function assertAccess(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): void
    {
        if (!$scope->hasFeature('cashier.v3.order_center') || $scope->isReadOnlySession()
            || $operator->tenantId() !== $scope->tenantId() || $operator->storeId() !== $scope->forcedStoreId()) {
            throw CashierV3CommandException::invalidContext('当前账号无权更正该门店的订单。');
        }
    }

    /** 纯服务以已落库的权益组标识定位；来源关联不是预约写命令，展示 FW 号也不能代替主键。 */
    private function identity(array $payload): array
    {
        $type = trim((string)($payload['recordType'] ?? ''));
        $id = trim((string)($payload['recordId'] ?? ''));
        if ($type === 'recharge' && preg_match('/^recharge:([1-9][0-9]*)$/D', $id, $m)) $id = $m[1];
        if ($type === 'supplement' && preg_match('/^v3-(?:sales-)?supplement:(.+)$/D', $id, $m)) $id = $m[1];
        if ($type === 'refund' && preg_match('/^refund:(.+)$/D', $id, $m)) $id = $m[1];
        if (!in_array($type, ['sales', 'service', 'recharge', 'supplement', 'refund'], true) || $id === ''
            || ($type === 'recharge' && !preg_match('/^[1-9][0-9]*$/D', $id))
            || ($type === 'sales' && !preg_match('/^CSO-[a-f0-9]{40}$/Di', $id))
            || ($type === 'service' && !CashierV3EntitlementCheckoutIdentity::isValidRequestId($id))
            || ($type === 'supplement' && !preg_match('/^(?:DRP|RRP)-[a-f0-9]{40}$/Di', $id))
            || ($type === 'refund' && !preg_match('/^OLO-[a-f0-9]{40}$/Di', $id))) {
            throw CashierV3CommandException::invalidContext('记录标识无效，请刷新列表后重试。');
        }
        return [$type, $id];
    }

    private function record(string $type, string $id, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, bool $lock): array
    {
        // 不用展示单号回查；正式权威 ID、当前门店及已结算状态共同限定可写目标。
        if ($type === 'sales') {
            $query = Db::name('cashier_v3_sales_order')->where('order_id', $id)->where('tenant_id', $scope->tenantId())
                ->where('store_id', $operator->storeId())->where('order_direction', 'forward')->where('order_status', 'settled');
        } elseif ($type === 'service') {
            // 直通核销没有普通结账请求或完成凭证。其日期由已完成的权益服务事实负责，
            // reservation: 仅保留来源关联；不得读取、锁定或更改预约及预约占用。
            if (strpos($id, 'reservation:') === 0) {
                return $this->serviceFactRecord($id, $operator, $scope, $lock);
            }
            // 纯权益结账可能生成多条 FW 服务记录；更正只能作用于同一已完成请求的整组。
            $query = Db::name('cashier_v3_checkout_request')->where('request_id', $id)
                ->where('tenant_id', $scope->tenantId())->where('store_id', $operator->storeId())
                ->where('composition', 'entitlement_only')->where('request_status', 'succeeded');
            $request = $lock ? $query->lock(true)->find() : $query->find();
            if (!$request) throw CashierV3CommandException::invalidContext('找不到当前门店的可更正服务订单。');
            if (Db::name('cashier_v3_sales_order')->where('tenant_id', $scope->tenantId())
                ->where('checkout_request_id', $id)->where('order_direction', 'forward')->count()) {
                throw CashierV3CommandException::invalidContext('此结账已有销售主单，请从销售单更正。');
            }
            $facts = Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', $scope->tenantId())
                ->where('store_id', $operator->storeId())->where('checkout_request_id', $id)
                ->order('id', 'asc');
            $facts = ($lock ? $facts->lock(true) : $facts)->select()->toArray();
            if ($facts === [] || count(array_filter($facts, static function (array $fact) use ($request): bool {
                return (string)$fact['service_status'] !== 'completed'
                    || (string)$fact['business_date'] !== (string)$request['business_date'];
            })) > 0) {
                throw CashierV3CommandException::invalidContext('服务记录状态或日期不一致，暂不能整组更正。');
            }
            $receipt = Db::name('cashier_v3_entitlement_completion_receipt')->where('tenant_id', $scope->tenantId())
                ->where('store_id', $operator->storeId())->where('checkout_request_id', $id)->find();
            if (!$receipt || (string)$receipt['status'] !== 'completed'
                || (string)$receipt['business_date'] !== (string)$request['business_date']) {
                throw CashierV3CommandException::invalidContext('服务完成凭证与结账请求不一致，暂不能更正。');
            }
            // 普通纯权益结账的展示来源以选择表为权威；缺失行保持真正的空来源，
            // 后续更正可在同一事务创建来源快照，不能回读会员当前来源代填历史。
            $selectionQuery = Db::name('cashier_v3_checkout_business_source_selection')
                ->where('checkout_kind', 'sale')->where('checkout_request_id', $id)
                ->where('tenant_id', $scope->tenantId())->where('store_id', $operator->storeId());
            $selection = (array)($lock ? $selectionQuery->lock(true)->find() : $selectionQuery->find());
            return array_merge((array)$request, [
                'service_record_no' => (string)$facts[0]['service_record_no'],
                'business_event_no' => (string)$receipt['business_event_no'],
                'business_source_primary_id' => (int)($selection['primary_source_id'] ?? 0),
                'business_source_primary_name_snapshot' => (string)($selection['primary_source_name_snapshot'] ?? ''),
                'business_source_secondary_id' => (int)($selection['secondary_source_id'] ?? 0),
                'business_source_secondary_name_snapshot' => (string)($selection['secondary_source_name_snapshot'] ?? ''),
                'business_source_label_snapshot' => (string)($selection['source_label_snapshot'] ?? ''),
                'source_selection_id' => (int)($selection['id'] ?? 0),
            ]);
        } elseif ($type === 'recharge') {
            $query = Db::name('user_recharge')->where('id', (int)$id)->where('store_id', $operator->storeId())->where('paid', 1);
        } elseif ($type === 'refund') {
            // 手工退款（含无原单目录与余额退款）均有调整及支付事实；旧退款不允许改写。
            $query = Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)
                ->where('operation_id', $id)->where('tenant_id', $scope->tenantId())
                ->where('store_id', $operator->storeId())->where('operation_type', 'refund')
                ->whereIn('source_type', ['card', 'document', 'catalog', 'balance'])->where('status', 'succeeded');
        } else {
            $query = Db::name('cashier_v3_debt_repayment')->where('repayment_id', $id)->where('tenant_id', $scope->tenantId())
                ->where('store_id', $operator->storeId())->whereIn('status', ['succeeded', 'voided']);
            $row = $lock ? $query->lock(true)->find() : $query->find();
            if (!$row) {
                $query = Db::name('cashier_v3_recharge_debt_repayment')->where('repayment_id', $id)
                    ->where('tenant_id', $scope->tenantId())->where('store_id', $operator->storeId())
                    ->whereIn('status', ['succeeded', 'voided']);
            }
        }
        $row = $lock ? $query->lock(true)->find() : $query->find();
        if (!$row) throw CashierV3CommandException::invalidContext('找不到当前门店的可更正记录。');
        return (array)$row;
    }

    /**
     * 无结账主单的权益组以同租户、同门店的完成事实为权威，按稳定行序加锁。
     * 必须同一完成事件、会员及业务日，防止把无关核销或后续调整当作同次服务移动。
     */
    private function serviceFactRecord(string $id, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, bool $lock): array
    {
        $sale = Db::name('cashier_v3_sales_order')->where('tenant_id', $scope->tenantId())
            ->where('checkout_request_id', $id);
        if ($lock) $sale->lock(true);
        if ($sale->find()) throw CashierV3CommandException::invalidContext('此结账已有销售主单，请从销售单更正。');
        $query = Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', $scope->tenantId())
            ->where('store_id', $operator->storeId())->where('checkout_request_id', $id)
            ->order('id', 'asc')->limit(1001);
        $rows = ($lock ? $query->lock(true) : $query)->select()->toArray();
        if ($rows === [] || count($rows) > 1000) {
            throw CashierV3CommandException::invalidContext('找不到当前门店的可更正权益服务记录。');
        }
        $first = $rows[0];
        $eventNo = trim((string)$first['business_event_no']);
        foreach ($rows as $row) {
            if ($eventNo === '' || (string)$row['service_status'] !== 'completed'
                || (string)$row['business_date'] !== (string)$first['business_date']
                || (string)$row['business_event_no'] !== $eventNo
                || (int)$row['member_id'] !== (int)$first['member_id']) {
                throw CashierV3CommandException::invalidContext('权益服务记录状态或日期不一致，暂不能整组更正。');
            }
        }
        // 只校验原完成事件的核销/业绩事实；后续作废和人员调整保留独立事件和日期。
        foreach (['cashier_v3_entitlement_writeoff_fact', 'cashier_v3_performance_fact'] as $table) {
            $related = Db::name($table)->where('tenant_id', $scope->tenantId())
                ->where('store_id', $operator->storeId())->where('checkout_request_id', $id)
                ->where('business_event_no', $eventNo)->order('id', 'asc');
            $facts = ($lock ? $related->lock(true) : $related)->field('business_date')->select()->toArray();
            foreach ($facts as $fact) {
                if ((string)$fact['business_date'] !== (string)$first['business_date']) {
                    throw CashierV3CommandException::invalidContext('权益核销或业绩日期不一致，请先核对记录。');
                }
            }
        }
        return $first;
    }

    private function lockRelatedDebts(string $type, string $id, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, string $field): void
    {
        if (($field !== 'source' && $type !== 'supplement') || $type === 'service') return;
        if ($type === 'supplement') {
            $row = Db::name('cashier_v3_debt_repayment')->where('repayment_id', $id)->where('tenant_id', $scope->tenantId())->find();
            if (!$row) $row = Db::name('cashier_v3_recharge_debt_repayment')->where('repayment_id', $id)->where('tenant_id', $scope->tenantId())->find();
            $ids = $row ? [(int)$row['debt_id']] : [];
        } else {
            $authority = $type === 'sales' ? 'cashier_v3_debt_authority' : 'cashier_v3_recharge_debt_authority';
            $column = $type === 'sales' ? 'sales_order_id' : 'recharge_id';
            $ids = Db::name($authority)->where('tenant_id', $scope->tenantId())->where($column, $id)->column('debt_id');
        }
        if ($ids !== []) Db::name('store_debt')->whereIn('id', array_map('intval', $ids))
            ->where('store_id', $operator->storeId())->order('id', 'asc')->lock(true)->select();
    }

    private function validDate(string $value): string
    {
        // 经营归属日允许补录过去日期，但不能写未来日；实际发生时间不因此变化。
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m-d') !== $value || $value > (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d')) {
            throw CashierV3CommandException::invalidContext('请选择不晚于今天的有效业务日期。');
        }
        return $value;
    }

    private function sourceLabel(array $row): string
    {
        return trim((string)($row['business_source_secondary_name_snapshot'] ?? ''))
            ?: (trim((string)($row['business_source_primary_name_snapshot'] ?? ''))
                ?: trim((string)($row['business_source_label_snapshot'] ?? '')));
    }

    private function recordNo(string $type, array $row): string
    {
        return (string)($type === 'sales' ? $row['order_no'] : ($type === 'service' ? $row['service_record_no']
            : ($type === 'recharge' ? $row['order_id'] : ($type === 'refund' ? $row['operation_no'] : $row['repayment_no']))));
    }

    private function sourceColumns(array $source, bool $withAttribution = false): array
    {
        $values = array_combine(self::SOURCE_COLUMNS, [
            (int)$source['primarySourceId'], (string)$source['primarySourceNameSnapshot'],
            (int)$source['secondarySourceId'], (string)$source['secondarySourceNameSnapshot'],
            (string)$source['displayNameSnapshot'],
        ]);
        if ($withAttribution) $values['source_attribution_type_snapshot'] = (string)$source['attributionTypeSnapshot'];
        return $values;
    }

    /** 已结算补交可独立更正来源；只动这笔补交及其事实，不改原单或其他补交。 */
    private function correctSupplementSource(string $id, array $record, array $source,
        CashierV3DataScopeContext $scope): void
    {
        $tenant = $scope->tenantId();
        $table = isset($record['sales_order_id'])
            ? 'cashier_v3_debt_repayment' : 'cashier_v3_recharge_debt_repayment';
        Db::name($table)->where('tenant_id', $tenant)->where('repayment_id', $id)
            ->update($this->sourceColumns($source));
        // 经营报表从收款、余额与业绩事实读取来源；正向及对应冲销行同改，
        // 避免同一补交在不同报表按两个来源归类。事实金额与业务日保持原样。
        foreach (['cashier_v3_payment_fact', 'cashier_v3_balance_fact', 'cashier_v3_performance_fact'] as $table) {
            Db::name($table)->where('tenant_id', $tenant)->where('order_id', $id)
                ->update($this->sourceColumns($source, true));
        }
    }

    /**
     * 纯权益来源属于整次完成事件：普通结账更新选择表，预约直通完成只更新事实快照。
     * 两种路径都同步服务、劳动业绩和服务生命周期事实，金额、权益余额及人员分配保持不变。
     */
    private function correctServiceSource(string $id, array $record, array $source,
        CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now): void
    {
        $tenant = $scope->tenantId();
        $eventNo = (string)$record['business_event_no'];
        $factColumns = $this->sourceColumns($source, true);
        if (strpos($id, 'reservation:') !== 0) {
            $selection = [
                'primary_source_id' => (int)$source['primarySourceId'],
                'primary_source_name_snapshot' => (string)$source['primarySourceNameSnapshot'],
                'secondary_source_id' => (int)$source['secondarySourceId'],
                'secondary_source_name_snapshot' => (string)$source['secondarySourceNameSnapshot'],
                'source_label_snapshot' => (string)$source['displayNameSnapshot'],
                'updated_by_operator_id' => $operator->operatorId(), 'updated_at' => $now,
            ];
            if ((int)($record['source_selection_id'] ?? 0) > 0) {
                Db::name('cashier_v3_checkout_business_source_selection')
                    ->where('id', (int)$record['source_selection_id'])
                    ->update($selection + ['selection_version' => Db::raw('selection_version + 1')]);
            } else {
                Db::name('cashier_v3_checkout_business_source_selection')->insert($selection + [
                    'checkout_kind' => 'sale', 'checkout_request_id' => $id,
                    'tenant_id' => $tenant, 'store_id' => $operator->storeId(),
                    'selection_version' => 1, 'reward_amount_cents' => 0,
                ]);
            }
        }
        foreach (['cashier_v3_entitlement_service_fact', 'cashier_v3_performance_fact'] as $table) {
            Db::name($table)->where('tenant_id', $tenant)->where('store_id', $operator->storeId())
                ->where('checkout_request_id', $id)->where('business_event_no', $eventNo)
                ->update($factColumns);
        }
        $lifecycleColumns = [
            'source_primary_id' => (int)$source['primarySourceId'],
            'source_primary_name_snapshot' => (string)$source['primarySourceNameSnapshot'],
            'source_secondary_id' => (int)$source['secondarySourceId'],
            'source_secondary_name_snapshot' => (string)$source['secondarySourceNameSnapshot'],
            'source_label_snapshot' => (string)$source['displayNameSnapshot'],
            'source_attribution_type_snapshot' => (string)$source['attributionTypeSnapshot'],
        ];
        Db::name('cashier_v3_customer_lifecycle_fact')->where('tenant_id', $tenant)
            ->where('store_id', $operator->storeId())->where('natural_key', 'service-visit:' . $id)
            ->where('event_type', 'service_completed')->update($lifecycleColumns);
    }

    /** 原单来源联动所有已成功或已作废补交，冲销链也沿用更正后的来源。 */
    private function correctSource(string $type, string $id, array $record, array $source,
        CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, string $operationId,
        string $key, string $fingerprint, int $now): void
    {
        $tenant = $scope->tenantId();
        $columns = $this->sourceColumns($source);
        $factColumns = $this->sourceColumns($source, true);
        $orderId = $type === 'sales' ? $id : 'RCH:' . $id;
        $main = $type === 'sales' ? 'cashier_v3_sales_order' : 'user_recharge';
        $mainKey = $type === 'sales' ? 'order_id' : 'id';
        $query = Db::name($main)->where($mainKey, $id)->where('store_id', $operator->storeId());
        if ($type === 'sales') $query->where('tenant_id', $tenant);
        $query->update($columns);
        foreach (self::FACT_TABLES as $table) {
            Db::name($table)->where('tenant_id', $tenant)->where('order_id', $orderId)->update($factColumns);
        }
        if ($type === 'sales') {
            // 同次结账的服务来源来自原单；服务业务日和独立预约来源不随销售日期移动。
            Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', $tenant)
                ->where('checkout_request_id', (string)$record['checkout_request_id'])
                ->where('source_document_type', '<>', 'reservation')->update($factColumns);
            $this->correctLifecycleSource($id, $record, $source, $tenant);
        }
        $repaymentTable = $type === 'sales' ? 'cashier_v3_debt_repayment' : 'cashier_v3_recharge_debt_repayment';
        $relationKey = $type === 'sales' ? 'sales_order_id' : 'recharge_id';
        $repayments = Db::name($repaymentTable)->where('tenant_id', $tenant)->where($relationKey, $id)
            ->order('id', 'asc')->lock(true)->select()->toArray();
        foreach ($repayments as $repayment) {
            $repaymentId = (string)$repayment['repayment_id'];
            $oldSource = $this->sourceLabel($repayment);
            Db::name($repaymentTable)->where('repayment_id', $repaymentId)->where('tenant_id', $tenant)->update($columns);
            foreach (self::FACT_TABLES as $table) {
                Db::name($table)->where('tenant_id', $tenant)->where('order_id', $repaymentId)->update($factColumns);
            }
            if ($oldSource !== $this->sourceLabel($columns)) {
                $this->log($operationId, $key, $fingerprint, $tenant, $operator, $type, $id,
                    'supplement', $repaymentId, (string)$repayment['repayment_no'], 'source',
                    $oldSource, $this->sourceLabel($columns), $now);
            }
        }
    }

    private function correctLifecycleSource(string $orderId, array $order, array $source, string $tenant): void
    {
        $columns = [
            'source_primary_id' => (int)$source['primarySourceId'],
            'source_primary_name_snapshot' => (string)$source['primarySourceNameSnapshot'],
            'source_secondary_id' => (int)$source['secondarySourceId'],
            'source_secondary_name_snapshot' => (string)$source['secondarySourceNameSnapshot'],
            'source_label_snapshot' => (string)$source['displayNameSnapshot'],
            'source_attribution_type_snapshot' => (string)$source['attributionTypeSnapshot'],
        ];
        Db::name('cashier_v3_customer_lifecycle_fact')->where('tenant_id', $tenant)
            ->where('related_order_id', $orderId)->update($columns);
        $projection = [];
        foreach ($columns as $name => $value) {
            if ($name === 'source_attribution_type_snapshot') $projection['first_course_source_attribution_type_snapshot'] = $value;
            else $projection['first_course_' . $name] = $value;
        }
        Db::name('cashier_v3_customer_lifecycle_projection')->where('tenant_id', $tenant)
            ->where('first_course_order_id', $orderId)->update($projection);
    }

    /** 更正业务日只移动该笔原始经济事件；后来退款、作废和人员调整保留各自日期。 */
    private function correctDate(string $type, string $id, array $record, string $newDate, CashierV3DataScopeContext $scope): void
    {
        $tenant = $scope->tenantId();
        if ($type === 'refund') {
            // Move only this refund's business date, including its separate wallet
            // credit or debit fact. Never rewrite the original sale or a void event.
            $balanceType = (string)($record['source_type'] ?? '') === 'balance'
                ? 'manual_refund_debit' : 'manual_refund_credit';
            $adjustment = Db::name('cashier_v3_refund_adjustment')->where('tenant_id', $tenant)
                ->where('operation_id', $id)->where('status', 'effective')->lock(true)->find();
            $payment = Db::name('cashier_v3_payment_fact')->where('tenant_id', $tenant)
                ->where('order_id', $id)->where('fact_type', 'manual_refund_adjustment')
                ->where('source_document_type', 'manual_refund')->lock(true)->find();
            $balance = Db::name('cashier_v3_balance_fact')->where('tenant_id', $tenant)
                ->where('order_id', $id)->where('balance_change_type', $balanceType)
                ->where('source_document_type', 'manual_refund')->lock(true)->find();
            $personnelFacts = Db::name('cashier_v3_performance_fact')->where('tenant_id', $tenant)
                ->where('order_id', $id)->where('performance_type', 'refund_sales_deduction')
                ->where('source_document_type', 'manual_refund')->lock(true)->select()->toArray();
            if (!$adjustment || !$payment || (string)$adjustment['business_date'] !== (string)$record['business_date']
                || (string)$payment['business_date'] !== (string)$record['business_date']
                || ((int)($balanceType === 'manual_refund_debit'
                    ? $adjustment['balance_debit_cents'] : $adjustment['balance_credit_cents']) > 0) !== (bool)$balance
                || ($balance && (string)$balance['business_date'] !== (string)$record['business_date'])
                || ((int)($adjustment['salespeople_version'] ?? 0) > 0 && $personnelFacts === [])
                || count(array_filter($personnelFacts, static fn(array $fact): bool =>
                    (string)$fact['business_date'] !== (string)$record['business_date'])) > 0) {
                throw CashierV3CommandException::invalidContext('退款事实与记录日期不一致，暂不能更正。');
            }
            Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)->where('tenant_id', $tenant)
                ->where('operation_id', $id)->update(['business_date' => $newDate, 'updated_at' => time()]);
            Db::name('cashier_v3_refund_adjustment')->where('tenant_id', $tenant)
                ->where('operation_id', $id)->update(['business_date' => $newDate]);
            Db::name('cashier_v3_payment_fact')->where('tenant_id', $tenant)
                ->where('order_id', $id)->where('fact_type', 'manual_refund_adjustment')
                ->where('source_document_type', 'manual_refund')->update(['business_date' => $newDate]);
            Db::name('cashier_v3_balance_fact')->where('tenant_id', $tenant)
                ->where('order_id', $id)->where('balance_change_type', $balanceType)
                ->where('source_document_type', 'manual_refund')->update(['business_date' => $newDate]);
            // 更正经营日包括人员负向事实及其冲销链；原销售人员事实保持原日不变。
            Db::name('cashier_v3_performance_fact')->where('tenant_id', $tenant)
                ->where('order_id', $id)->where('performance_type', 'refund_sales_deduction')
                ->where('source_document_type', 'manual_refund')->update(['business_date' => $newDate]);
            return;
        }
        if ($type === 'service') {
            // 纯服务只移动原完成事件的业务日期，不重新核销或改权益余额/有效期。
            // 直通核销由服务事实负责，不依赖或补造结账凭证，更不能改预约数据。
            $eventNo = (string)$record['business_event_no'];
            if (strpos($id, 'reservation:') !== 0) {
                Db::name('cashier_v3_checkout_request')->where('tenant_id', $tenant)->where('request_id', $id)
                    ->update(['business_date' => $newDate]);
                Db::name('cashier_v3_entitlement_completion_receipt')->where('tenant_id', $tenant)
                    ->where('checkout_request_id', $id)->where('business_event_no', $eventNo)
                    ->update(['business_date' => $newDate]);
            }
            foreach (['cashier_v3_entitlement_service_fact', 'cashier_v3_entitlement_writeoff_fact',
                'cashier_v3_performance_fact'] as $table) {
                Db::name($table)->where('tenant_id', $tenant)->where('store_id', (int)$record['store_id'])
                    ->where('checkout_request_id', $id)
                    ->where('business_event_no', $eventNo)->update(['business_date' => $newDate]);
            }
            Db::name('cashier_v3_customer_lifecycle_fact')->where('tenant_id', $tenant)
                ->where('store_id', (int)$record['store_id'])
                ->where('natural_key', 'service-visit:' . $id)->where('event_type', 'service_completed')
                ->update(['business_date' => $newDate]);
            return;
        }
        if ($type === 'supplement') {
            $isSales = isset($record['sales_order_id']);
            $table = $isSales ? 'cashier_v3_debt_repayment' : 'cashier_v3_recharge_debt_repayment';
            Db::name($table)->where('tenant_id', $tenant)->where('repayment_id', $id)
                ->update(['business_date' => $newDate]);
            $documentType = $isSales ? 'debt_repayment' : 'recharge_debt_repayment';
            $paymentIds = Db::name('cashier_v3_payment_fact')->where('tenant_id', $tenant)
                ->where('order_id', $id)->where('source_document_type', $documentType)
                ->where('fact_direction', 'forward')->column('fact_id');
            foreach (['cashier_v3_payment_fact', 'cashier_v3_balance_fact', 'cashier_v3_performance_fact'] as $factTable) {
                Db::name($factTable)->where('tenant_id', $tenant)->where('order_id', $id)
                    ->where('source_document_type', $documentType)->where('fact_direction', 'forward')
                    ->update(['business_date' => $newDate]);
            }
            if ($paymentIds !== []) {
                Db::name('cashier_v3_payment_sale_allocation_fact')->where('tenant_id', $tenant)
                    ->whereIn('payment_fact_id', $paymentIds)->where('fact_direction', 'forward')
                    ->update(['business_date' => $newDate]);
            }
            if ($isSales) $this->correctRepaymentLifecycleDate($record, $newDate, $tenant);
            return;
        }
        if ($type === 'recharge') {
            Db::name('user_recharge')->where('id', (int)$id)->update(['business_date' => $newDate]);
            $eventNo = (string)Db::name('cashier_v3_business_event')->where('tenant_id', $tenant)
                ->where('source_type', 'submit-recharge')->where('aggregate_id', 'RCH:' . $id)
                ->value('event_no');
            if ($eventNo !== '') {
                foreach (['cashier_v3_payment_fact', 'cashier_v3_balance_fact', 'cashier_v3_performance_fact'] as $table) {
                    Db::name($table)->where('tenant_id', $tenant)->where('business_event_no', $eventNo)
                        ->where('fact_direction', 'forward')->update(['business_date' => $newDate]);
                }
                Db::name('cashier_v3_gift_fact')->where('tenant_id', $tenant)->where('recharge_id', (int)$id)
                    ->where('business_event_no', $eventNo)->update(['business_date' => $newDate]);
            }
            return;
        }
        $eventNo = (string)Db::name('cashier_v3_business_event')->where('tenant_id', $tenant)
            ->where('source_type', 'submit-checkout')->where('aggregate_id', $id)->value('event_no');
        if ($eventNo === '') throw CashierV3CommandException::invalidContext('该销售单缺少可核实的原结账事件，暂不能更正日期。');
        $saleIds = Db::name('cashier_v3_sale_fact')->where('tenant_id', $tenant)
            ->where('business_event_no', $eventNo)->where('fact_direction', 'forward')->column('fact_id');
        $paymentIds = Db::name('cashier_v3_payment_fact')->where('tenant_id', $tenant)
            ->where('business_event_no', $eventNo)->where('fact_direction', 'forward')->column('fact_id');
        Db::name('cashier_v3_sales_order')->where('tenant_id', $tenant)->where('order_id', $id)
            ->update(['business_date' => $newDate]);
        foreach (['cashier_v3_sale_fact', 'cashier_v3_payment_fact', 'cashier_v3_balance_fact'] as $table) {
            Db::name($table)->where('tenant_id', $tenant)->where('business_event_no', $eventNo)
                ->where('fact_direction', 'forward')->update(['business_date' => $newDate]);
        }
        Db::name('cashier_v3_performance_fact')->where('tenant_id', $tenant)
            ->where('business_event_no', $eventNo)->where('fact_direction', 'forward')
            ->whereIn('performance_type', ['sales_performance_allocated', 'actual_performance_recorded'])
            ->update(['business_date' => $newDate]);
        foreach (['cashier_v3_report_sale_dimension_fact', 'cashier_v3_card_sale_category_allocation_fact',
            'cashier_v3_card_sale_item_allocation_fact'] as $table) {
            if ($saleIds !== []) Db::name($table)->where('tenant_id', $tenant)->whereIn('sale_fact_id', $saleIds)
                ->update(['business_date' => $newDate]);
        }
        if ($paymentIds !== []) Db::name('cashier_v3_payment_sale_allocation_fact')->where('tenant_id', $tenant)
            ->whereIn('payment_fact_id', $paymentIds)->where('fact_direction', 'forward')
            ->update(['business_date' => $newDate]);
        $this->correctGuideDate($record, $id, $newDate, $tenant);
        Db::name('cashier_v3_sales_manager_fact')->where('tenant_id', $tenant)
            ->where('business_event_no', $eventNo)->update(['business_date' => $newDate]);
        Db::name('cashier_v3_payment_collection_batch')->where('tenant_id', $tenant)
            ->where('sales_order_id', $id)->where('batch_direction', 'forward')->update(['business_date' => $newDate]);
        Db::name('cashier_v3_payment_collection')->where('tenant_id', $tenant)
            ->where('sales_order_id', $id)->where('collection_direction', 'forward')->update(['business_date' => $newDate]);
        // 首护在后续补交才完成时，其日期属于补交；只移动原结账当时发生的生命周期事实。
        Db::name('cashier_v3_customer_lifecycle_fact')->where('tenant_id', $tenant)
            ->where('related_order_id', $id)->where('occurred_at', '<=', (int)$record['settled_at'])
            ->update(['business_date' => $newDate]);
        Db::name('cashier_v3_customer_lifecycle_projection')->where('tenant_id', $tenant)
            ->where('first_course_order_id', $id)->where('first_course_completed_at', '<=', (int)$record['settled_at'])
            ->update(['first_course_business_date' => $newDate]);
    }

    private function correctGuideDate(array $record, string $orderId, string $newDate, string $tenant): void
    {
        // 同一会员、门店的导购轮次不得横跨业务日；若会破坏已有轮次口径则整单回滚。
        $rounds = Db::name('cashier_v3_customer_guide_round_fact')->where('tenant_id', $tenant)
            ->where('order_id', $orderId)->where('status', 'effective')->column('guide_round_no');
        foreach ($rounds as $round) {
            if ((int)$round <= 0) continue;
            $conflict = Db::name('cashier_v3_customer_guide_round_fact')->where('tenant_id', $tenant)
                ->where('store_id', (int)$record['store_id'])->where('member_id', (int)$record['member_id'])
                ->where('guide_round_no', (int)$round)->where('order_id', '<>', $orderId)
                ->where('business_date', '<>', $newDate)->where('status', 'effective')->count();
            if ($conflict) throw CashierV3CommandException::invalidContext('更正日期会使同一会员的导购轮次跨日冲突，请先核对轮次记录。');
        }
        Db::name('cashier_v3_customer_guide_round_fact')->where('tenant_id', $tenant)
            ->where('order_id', $orderId)->update(['business_date' => $newDate]);
    }

    private function correctRepaymentLifecycleDate(array $repayment, string $newDate, string $tenant): void
    {
        // 首护若在本次补交才达成，经营归属跟随该补交；不改首次销售的实际完成时刻。
        Db::name('cashier_v3_customer_lifecycle_fact')->where('tenant_id', $tenant)
            ->where('related_order_id', (string)$repayment['sales_order_id'])
            ->where('event_type', 'first_course_completed')->where('occurred_at', (int)$repayment['settled_at'])
            ->update(['business_date' => $newDate]);
        Db::name('cashier_v3_customer_lifecycle_projection')->where('tenant_id', $tenant)
            ->where('first_course_order_id', (string)$repayment['sales_order_id'])
            ->where('first_course_completed_at', (int)$repayment['settled_at'])
            ->update(['first_course_business_date' => $newDate]);
    }

    private function log(string $operationId, string $key, string $fingerprint, string $tenantId,
        CashierV3OperatorScope $operator, string $rootType, string $rootId, string $recordType, string $recordId,
        string $recordNo, string $field, string $old, string $new, int $now): void
    {
        $operatorName = (string)Db::name('system_store_staff')->where('id', $operator->operatorId())->value('staff_name');
        Db::name(self::LOG_TABLE)->insert([
            'operation_id' => $operationId, 'command_idempotency_key' => $key, 'request_fingerprint' => $fingerprint,
            'tenant_id' => $tenantId, 'store_id' => $operator->storeId(),
            'root_record_type' => $rootType, 'root_record_id' => $rootId,
            'record_type' => $recordType, 'record_id' => $recordId, 'record_no_snapshot' => $recordNo,
            'field_key' => $field, 'old_value' => $old, 'new_value' => $new,
            'operator_id' => $operator->operatorId(), 'operator_name_snapshot' => $operatorName,
            'occurred_at' => $now,
        ]);
    }
}
