<?php

declare(strict_types=1);

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\report\CashierV3GuideRoundFactServices;
use think\facade\Db;

/**
 * Correct the guide identity on one completed pure-entitlement service line.
 * The original guide facts remain as reversed audit rows. A round change is
 * applied to every active guide line in the same checkout, while service
 * completion, entitlement balance and financial facts never change.
 */
final class CashierV3ServiceGuideCorrectionServices
{
    private const FACT_TABLE = 'cashier_v3_customer_guide_round_fact';
    private const LOG_TABLE = 'cashier_v3_order_attribution_change';

    /** The editor reads current effective facts, never checkout draft snapshots. */
    public function entry(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $this->assertAccess($operator, $scope);
        $source = $this->source($payload, $operator, $scope, false);
        $allFacts = $this->facts($source, false);
        $facts = array_values(array_filter($allFacts, static fn (array $row): bool =>
            (string)$row['source_line_id'] === (string)$source['source_line_id']));
        return [
            'checkoutRequestId' => (string)$source['checkout_request_id'],
            'serviceFactId' => (int)$source['id'],
            'serviceRecordNo' => (string)$source['service_record_no'],
            'itemName' => (string)$source['project_name_snapshot'],
            'memberId' => (int)$source['member_id'],
            'sourceLineId' => (string)$source['source_line_id'],
            // All active lines share one round, so a stale sibling line must also block this editor.
            'expectedFactIds' => array_map(static fn (array $row): string => (string)$row['fact_id'], $allFacts),
            'currentGuides' => array_map(static fn (array $row): array => [
                'employeeId' => (int)$row['guide_employee_id'],
                'name' => (string)$row['guide_employee_name_snapshot'],
                'guideRoundNo' => (int)$row['guide_round_no'],
            ], $facts),
        ];
    }

    /** Gateway owns the transaction; optimistic fact IDs protect against stale editors. */
    public function executeInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('serviceGuideCorrection.executeInTx');
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        $recorder = $scope['event_recorder'] ?? null;
        $execution = $scope['event_execution'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext
            || !$recorder instanceof CashierV3BusinessEventRecorder || !$execution instanceof CashierV3BusinessEventExecution) {
            throw CashierV3CommandException::invalidContext('导购更正服务未就绪，请刷新订单中心。');
        }
        $this->assertAccess($operator, $dataScope);
        $payload = (array)($scope['payload'] ?? []);
        $reason = trim((string)($payload['reason'] ?? ''));
        $key = trim((string)($scope['idempotency_key'] ?? ''));
        $ids = $this->employeeIds($payload['guideEmployeeIds'] ?? null);
        $newRound = $payload['guideRoundNo'] ?? null;
        $expected = $payload['expectedFactIds'] ?? null;
        if (!is_int($newRound) || $newRound < 0 || $newRound > 3
            || !is_array($expected) || count($expected) > 100
            || count(array_filter($expected, static fn ($id): bool => !is_string($id) || !preg_match('/^[A-Za-z0-9-]{1,64}$/D', $id))) > 0
            || $reason === '' || mb_strlen($reason) > 255 || $key === '') {
            throw CashierV3CommandException::invalidContext('导购更正参数不完整，请重新打开订单。');
        }
        $expected = array_values($expected);
        sort($expected, SORT_STRING);
        $fingerprint = hash('sha256', json_encode([$payload['checkoutRequestId'] ?? '', $payload['serviceFactId'] ?? '', $expected, $ids, $newRound, $reason], JSON_UNESCAPED_UNICODE));
        $replay = Db::name(self::LOG_TABLE)->where('tenant_id', $dataScope->tenantId())
            ->where('command_idempotency_key', $key)->lock(true)->find();
        if ($replay) {
            if ((string)$replay['request_fingerprint'] !== $fingerprint
                || !in_array((string)$replay['field_key'], ['guide', 'guide_round'], true)) {
                throw CashierV3CommandException::invalidContext('同一次更正请求内容已变化，请刷新后重试。');
            }
            return ['operationId' => (string)$replay['operation_id'], 'replayed' => true];
        }
        $source = $this->source($payload, $operator, $dataScope, true);
        $oldFacts = $this->facts($source, true);
        $current = array_map(static fn (array $row): string => (string)$row['fact_id'], $oldFacts);
        sort($current, SORT_STRING);
        if ($current !== $expected) throw CashierV3CommandException::versionConflict('导购已被其他人修改，请刷新后重试。');
        $rounds = array_values(array_unique(array_map(static fn (array $row): int => (int)$row['guide_round_no'], $oldFacts)));
        $orderIds = array_unique(array_map(static fn (array $row): string => (string)$row['order_id'], $oldFacts));
        if (count($rounds) > 1 || count($orderIds) > 1
            || ($orderIds !== [] && (string)reset($orderIds) !== (string)$source['completion_receipt_id'])) {
            throw CashierV3CommandException::invalidContext('原导购轮次不一致，暂不能修改。');
        }
        $oldRound = $rounds[0] ?? 0;
        $targetFacts = array_values(array_filter($oldFacts, static fn (array $row): bool =>
            (string)$row['source_line_id'] === (string)$source['source_line_id']));
        $beforeIds = array_map(static fn (array $row): int => (int)$row['guide_employee_id'], $targetFacts);
        sort($beforeIds, SORT_NUMERIC);
        if ($beforeIds === $ids && $oldRound === $newRound) {
            throw CashierV3CommandException::invalidContext('导购和轮次与原记录相同。');
        }
        // A successful checkout occupies one member round across all service lines.
        try {
            (new CashierV3GuideRoundFactServices())->assertCorrectionRoundAvailableInTx(
                $dataScope->tenantId(), (int)$source['member_id'], (string)$source['checkout_request_id'],
                $newRound, (string)$source['business_date']
            );
        } catch (\InvalidArgumentException $error) {
            // The shared fact validator emits machine reasons for checkout;
            // correction exposes the occupied day without leaking an internal code.
            if (preg_match('/^guide_round_date_conflict:(\d{4}-\d{2}-\d{2})$/D', $error->getMessage(), $match)) {
                throw CashierV3CommandException::invalidContext('该会员已于 ' . $match[1] . ' 使用第' . $newRound . '轮，不能在另一业务日期重复使用。');
            }
            throw CashierV3CommandException::invalidContext('所选导购轮次不适用于当前会员。');
        }
        $employees = $this->employees($ids);
        $now = time();
        $operationId = 'SGC-' . strtoupper(substr(hash('sha256', $dataScope->tenantId() . '|' . $key), 0, 40));
        $operatorName = (string)Db::name('system_store_staff')->where('id', $operator->operatorId())->value('staff_name');
        $eventVersion = 1 + (int)Db::name(CashierV3BusinessEventRecorder::EVENT_TABLE)
            ->where('tenant_id', $dataScope->tenantId())->where('aggregate_type', 'service_record')
            ->where('aggregate_id', (string)$source['id'])->count();
        $event = $recorder->recordInTx($execution, (array)($scope['event_contract'] ?? []), [
            'event_type' => 'service_record.guide_corrected', 'aggregate_type' => 'service_record',
            'aggregate_id' => (string)$source['id'], 'aggregate_version' => $eventVersion, 'event_version' => 1,
            'source_type' => 'correct-service-order-guide', 'source_id' => $operationId,
            'member_id' => (int)$source['member_id'], 'business_date' => (string)$source['business_date'],
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => (string)$source['service_record_no'],
            'payload' => ['operationId' => $operationId, 'serviceFactId' => (int)$source['id'],
                'reason' => $reason, 'oldGuideIds' => $beforeIds, 'newGuideIds' => $ids,
                'oldGuideRoundNo' => $oldRound, 'newGuideRoundNo' => $newRound],
        ]);
        foreach ($oldFacts as $old) {
            $changed = (int)Db::name(self::FACT_TABLE)->where('id', (int)$old['id'])
                ->where('status', 'effective')->update(['status' => 'reversed']);
            if ($changed !== 1) throw CashierV3CommandException::versionConflict('导购已被其他人修改，请刷新后重试。');
        }
        $byLine = [];
        foreach ($oldFacts as $fact) $byLine[(string)$fact['source_line_id']][] = $fact;
        // A completed service may predate guide capture. Its completion receipt
        // supplies the order identity so a missing guide can be repaired safely.
        $byLine[(string)$source['source_line_id']] ??= [];
        foreach ($byLine as $lineId => $lineFacts) {
            $template = $lineFacts[0] ?? [
                'organization_id' => $source['organization_id'],
                'member_id' => $source['member_id'],
                'member_name_snapshot' => $source['member_name_snapshot'],
                'order_id' => $source['completion_receipt_id'],
                'order_no_snapshot' => $source['service_record_no'],
                'business_date' => $source['business_date'],
            ];
            $selected = $lineId === (string)$source['source_line_id']
                ? array_map(static fn (int $id): array => [
                    'id' => $id, 'name' => (string)$employees[$id]['name'],
                    'type' => (string)$employees[$id]['employment_type_code'],
                ], $ids)
                : array_map(static fn (array $old): array => [
                    'id' => (int)$old['guide_employee_id'],
                    'name' => (string)$old['guide_employee_name_snapshot'],
                    'type' => (string)$old['guide_employee_type_snapshot'],
                ], $lineFacts);
            foreach ($selected as $employee) {
                $natural = 'service-guide-correct:' . $operationId . ':' . $lineId . ':' . $employee['id'];
                $row = [
                'fact_id' => 'SGF-' . strtoupper(substr(hash('sha256', $dataScope->tenantId() . '|' . $natural), 0, 40)),
                'natural_key' => $natural, 'tenant_id' => $dataScope->tenantId(),
                'organization_id' => (string)$template['organization_id'], 'store_id' => $operator->storeId(),
                'member_id' => (int)$template['member_id'], 'member_name_snapshot' => (string)$template['member_name_snapshot'],
                'order_id' => (string)$template['order_id'], 'order_no_snapshot' => (string)$template['order_no_snapshot'],
                'checkout_request_id' => (string)$source['checkout_request_id'], 'source_line_id' => $lineId,
                // Date correction may have moved the service while an older guide fact
                // retained its original snapshot; new effective attribution follows the service.
                'business_date' => (string)$source['business_date'], 'guide_round_no' => $newRound === 0 ? null : $newRound,
                'guide_employee_id' => (int)$employee['id'], 'guide_employee_name_snapshot' => mb_substr($employee['name'], 0, 128),
                'guide_employee_type_snapshot' => $employee['type'],
                'operator_id' => $operator->operatorId(), 'operator_name_snapshot' => mb_substr($operatorName, 0, 128),
                'business_event_no' => (string)$event['event_no'], 'command_idempotency_key' => $key,
                'occurred_at' => $now, 'recorded_at' => $now, 'status' => 'effective',
                ];
                $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                Db::name(self::FACT_TABLE)->insert($row);
            }
        }
        $before = implode('、', array_map(static fn (array $row): string => (string)$row['guide_employee_name_snapshot'], $targetFacts));
        $after = implode('、', array_map(static fn (int $id): string => (string)$employees[$id]['name'], $ids));
        if ($beforeIds !== $ids) {
            $this->log($source, $operator, $operationId, $key, $fingerprint, 'guide', $before, $after, $operatorName, $now);
        }
        if ($oldRound !== $newRound) {
            $serviceRows = Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', $dataScope->tenantId())
                ->where('checkout_request_id', (string)$source['checkout_request_id'])
                ->field('id,source_line_id,service_record_no')->select()->toArray();
            $serviceByLine = [];
            foreach ($serviceRows as $row) $serviceByLine[(string)$row['source_line_id']] = $row;
            foreach (array_keys($byLine) as $lineId) {
                if (!isset($serviceByLine[$lineId])) throw CashierV3CommandException::invalidContext('服务明细已变化，请刷新后重试。');
                $this->log($serviceByLine[$lineId] + ['checkout_request_id' => $source['checkout_request_id']],
                    $operator, $operationId, $key, $fingerprint, 'guide_round',
                    $this->roundLabel($oldRound), $this->roundLabel($newRound), $operatorName, $now);
            }
        }
        return ['operationId' => $operationId, 'replayed' => false];
    }

    private function assertAccess(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): void
    {
        if (!$scope->hasFeature('cashier.v3.order.staff_adjust') || $scope->isReadOnlySession()
            || $operator->tenantId() !== $scope->tenantId() || $operator->storeId() !== $scope->forcedStoreId()) {
            throw CashierV3CommandException::invalidContext('当前账号无权修改该门店的导购。');
        }
    }

    /** The service ID and request ID are both required; display order numbers cannot select a write target. */
    private function source(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, bool $lock): array
    {
        $requestId = trim((string)($payload['checkoutRequestId'] ?? ''));
        $factId = trim((string)($payload['serviceFactId'] ?? ''));
        if (!CashierV3EntitlementCheckoutIdentity::isValidRequestId($requestId)
            || preg_match('/^[1-9][0-9]*$/D', $factId) !== 1) {
            throw CashierV3CommandException::invalidContext('服务记录标识无效，请刷新列表。');
        }
        $requestQuery = Db::name('cashier_v3_checkout_request')->where('tenant_id', $scope->tenantId())
            ->where('store_id', $operator->storeId())->where('request_id', $requestId)
            ->where('composition', 'entitlement_only')->where('request_status', 'succeeded');
        $request = $lock ? $requestQuery->lock(true)->find() : $requestQuery->find();
        if (!$request || Db::name('cashier_v3_sales_order')->where('tenant_id', $scope->tenantId())
            ->where('checkout_request_id', $requestId)->count()) {
            throw CashierV3CommandException::invalidContext('此单不是可修改导购的纯权益订单。');
        }
        $query = Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', $scope->tenantId())
            ->where('store_id', $operator->storeId())->where('checkout_request_id', $requestId)
            ->where('id', (int)$factId)->where('service_status', 'completed');
        $source = $lock ? $query->lock(true)->find() : $query->find();
        if (!$source || Db::name('cashier_v3_service_record_void_operation')->where('tenant_id', $scope->tenantId())
            ->where('service_fact_id', (int)$factId)->where('status', 'succeeded')->count()) {
            throw CashierV3CommandException::invalidContext('服务记录已作废或不可修改。');
        }
        $receipt = Db::name('cashier_v3_entitlement_completion_receipt')->where('tenant_id', $scope->tenantId())
            ->where('checkout_request_id', $requestId)->where('status', 'completed')->find();
        if (!$receipt || (string)$receipt['receipt_id'] === ''
            || (int)$receipt['store_id'] !== $operator->storeId()
            || (int)$receipt['member_id'] !== (int)$source['member_id']
            || (string)$receipt['business_date'] !== (string)$source['business_date']) {
            throw CashierV3CommandException::invalidContext('服务完成凭证不完整，暂不能修改导购。');
        }
        return (array)$source + ['completion_receipt_id' => (string)$receipt['receipt_id']];
    }

    /** Only non-voided lines participate in the checkout-wide round and stale-value check. */
    private function facts(array $source, bool $lock): array
    {
        $activeLines = Db::name('cashier_v3_entitlement_service_fact')->alias('sf')
            ->leftJoin('cashier_v3_service_record_void_operation vo',
                "vo.tenant_id=sf.tenant_id AND vo.service_fact_id=sf.id AND vo.status='succeeded'")
            ->where('sf.tenant_id', (string)$source['tenant_id'])
            ->where('sf.checkout_request_id', (string)$source['checkout_request_id'])
            ->where('sf.service_status', 'completed')->whereNull('vo.id')->column('sf.source_line_id');
        $query = Db::name(self::FACT_TABLE)->where('tenant_id', (string)$source['tenant_id'])
            ->where('checkout_request_id', (string)$source['checkout_request_id'])
            ->whereIn('source_line_id', $activeLines)->where('status', 'effective')->order('id', 'asc');
        $facts = ($lock ? $query->lock(true) : $query)->select()->toArray();
        if (count($facts) > 100) {
            throw CashierV3CommandException::invalidContext('本订单导购记录过多，暂不能修改。');
        }
        return $facts;
    }

    private function roundLabel(int $round): string
    {
        return $round === 0 ? '无轮次' : '第' . $round . '轮';
    }

    /** One audit row per changed field and service line, grouped by operation ID. */
    private function log(array $service, CashierV3OperatorScope $operator, string $operationId, string $key,
        string $fingerprint, string $field, string $before, string $after, string $operatorName, int $now): void
    {
        Db::name(self::LOG_TABLE)->insert([
            'operation_id' => $operationId, 'command_idempotency_key' => $key, 'request_fingerprint' => $fingerprint,
            'tenant_id' => $operator->tenantId(), 'store_id' => $operator->storeId(),
            'root_record_type' => 'service', 'root_record_id' => (string)$service['checkout_request_id'],
            'record_type' => 'service_guide', 'record_id' => (string)$service['id'],
            'record_no_snapshot' => (string)$service['service_record_no'], 'field_key' => $field,
            'old_value' => mb_substr($before, 0, 191), 'new_value' => mb_substr($after, 0, 191),
            'operator_id' => $operator->operatorId(), 'operator_name_snapshot' => mb_substr($operatorName, 0, 128),
            'occurred_at' => $now,
        ]);
    }

    /** Only current, typed employees may become guides; never trust display names from the browser. */
    private function employees(array $ids): array
    {
        $rows = Db::name('employee')->whereIn('id', $ids)->where('status', 1)->where('is_del', 0)
            ->where('employment_type_version', '>', 0)->lock(true)
            ->field('id,name,employment_type_code')->select()->toArray();
        $result = [];
        foreach ($rows as $row) {
            if (trim((string)$row['name']) !== '') $result[(int)$row['id']] = $row;
        }
        if (count($result) !== count($ids)) throw CashierV3CommandException::invalidContext('所选导购已不可用，请重新选择。');
        return $result;
    }

    private function employeeIds($raw): array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > 20) {
            throw CashierV3CommandException::invalidContext('请至少选择一名导购。');
        }
        $ids = [];
        foreach ($raw as $value) {
            if (!is_int($value) && (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1)) {
                throw CashierV3CommandException::invalidContext('导购标识无效。');
            }
            $id = (int)$value;
            if ($id <= 0 || isset($ids[$id])) throw CashierV3CommandException::invalidContext('导购重复或无效。');
            $ids[$id] = $id;
        }
        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
