<?php
declare(strict_types=1);

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** 退款导购/销售经理是退款自身的人员归属；不更改原销售归属或占用会员导购轮次。 */
final class CashierV3RefundAttributionServices
{
    public const TABLE = 'cashier_v3_refund_attribution';
    public const REVISION_TABLE = 'cashier_v3_refund_attribution_revision';
    public const REVISION_PERSON_TABLE = 'cashier_v3_refund_attribution_revision_person';

    /** 原单只提供可修改的默认草稿；没有可信正式销售行时保持空白。 */
    public function selection(array $source, int $memberId, CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope): array
    {
        $candidates = Db::name('system_store_staff')->alias('s')
            ->join('employee e', 'e.id=s.employee_id')
            ->where('s.store_id', $operator->storeId())->where('s.status', 1)->where('s.is_del', 0)
            ->where('e.status', 1)->where('e.is_del', 0)
            ->field('s.employee_id,s.staff_name,e.name,e.employment_type_version')->order('s.id asc')->select()->toArray();
        $people = []; $seen = [];
        foreach ($candidates as $row) {
            $id = (int)$row['employee_id'];
            if ($id <= 0 || isset($seen[$id])) continue;
            $seen[$id] = true;
            $people[] = ['staffId' => $id, 'employeeId' => $id,
                'name' => trim((string)$row['name']) ?: (string)$row['staff_name'],
                'attributionRole' => (int)$row['employment_type_version'] > 0
                    ? 'guide_and_sales_manager' : 'sales_manager'];
        }
        $current = ['currentGuides' => [], 'currentSalesManagers' => []];
        $line = $this->sourceLine($source, $memberId, $scope->tenantId());
        if ($line !== []) {
            $lineIds = array_values(array_unique(array_filter([
                (string)$line['order_line_id'], (string)($line['checkout_line_id'] ?? ''),
            ])));
            foreach (Db::name('cashier_v3_customer_guide_round_fact')->where('tenant_id', $scope->tenantId())
                ->where('order_id', (string)$source['orderId'])->whereIn('source_line_id', $lineIds)
                ->where('status', 'effective')->order('id asc')->select()->toArray() as $fact) {
                $current['currentGuides'][] = ['staffId' => (int)$fact['guide_employee_id'],
                    'employeeId' => (int)$fact['guide_employee_id'], 'name' => (string)$fact['guide_employee_name_snapshot']];
            }
            foreach (Db::name('cashier_v3_sales_manager_fact')->where('tenant_id', $scope->tenantId())
                ->where('order_id', (string)$source['orderId'])->whereIn('source_line_id', $lineIds)
                ->where('status', 'effective')->order('id asc')->select()->toArray() as $fact) {
                $current['currentSalesManagers'][] = ['staffId' => (int)$fact['sales_manager_employee_id'],
                    'employeeId' => (int)$fact['sales_manager_employee_id'], 'name' => (string)$fact['sales_manager_name_snapshot']];
            }
        }
        return ['guides' => $people, 'salesManagers' => $people] + $current;
    }

    /** 与退款事件同事务冻结所选人员；所有名称和资格由服务端重读。 */
    public function createInTx(array $operation, array $payload, array $source, array $event,
        CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): void
    {
        CashierV3TransactionGuard::assertInTransaction('refundAttribution.createInTx');
        $guides = $this->normalize((array)($payload['guideSelections'] ?? []), 'guide');
        $managers = $this->normalize((array)($payload['salesManagerSelections'] ?? []), 'sales_manager');
        if (count($managers) > 1) throw self::fail('退款只能选择一位销售经理。');
        foreach (['guide' => $guides, 'sales_manager' => $managers] as $role => $people) {
            foreach ($people as $person) {
                $id = 'RAT-' . strtoupper(substr(hash('sha256', $scope->tenantId() . '|'
                    . $operation['operation_id'] . '|' . $role . '|' . $person['id']), 0, 40));
                Db::name(self::TABLE)->insert([
                    'attribution_id' => $id, 'tenant_id' => $scope->tenantId(),
                    'store_id' => $operator->storeId(), 'member_id' => (int)$operation['member_id'],
                    'operation_id' => (string)$operation['operation_id'], 'role' => $role,
                    'employee_id' => $person['id'], 'employee_name_snapshot' => $person['name'],
                    'employee_type_snapshot' => $person['type'],
                    'source_sales_order_id' => (string)$source['orderId'],
                    'source_sales_line_id' => (string)$source['lineId'],
                    'business_event_no' => (string)$event['event_no'],
                    'command_idempotency_key' => (string)$operation['command_idempotency_key'],
                    'business_date' => (string)$operation['business_date'],
                    'occurred_at' => (int)$operation['occurred_at'], 'recorded_at' => (int)$operation['recorded_at'],
                ]);
            }
        }
    }

    /** 编辑页只读取这笔已成功退款的当前归属，不把原销售人员再次当作默认值。 */
    public function entry(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $this->assertAdjustmentAccess($operator, $scope);
        $refund = $this->refundRow((string)($payload['recordId'] ?? ''), $operator, $scope, false);
        $version = $this->latestVersion($scope->tenantId(), (string)$refund['operation_id']);
        $current = $this->currentRows($scope->tenantId(), (string)$refund['operation_id'], $version);
        $candidates = $this->selection(['orderId' => '', 'lineId' => ''], (int)$refund['member_id'], $operator, $scope);
        return ['recordId' => (string)$refund['operation_id'], 'recordVersion' => $version,
            'memberId' => (int)$refund['member_id'], 'sourceSalesLineId' => (string)$refund['source_line_id'],
            'guides' => $candidates['guides'], 'salesManagers' => $candidates['salesManagers'],
            'currentGuides' => $this->selectedRows($current, 'guide'),
            'currentSalesManagers' => $this->selectedRows($current, 'sales_manager')];
    }

    /** 行锁、版本和幂等键共同保护改派；只追加退款归属版本，不修改现金和原单事实。 */
    public function adjustInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('refundAttribution.adjustInTx');
        $operator = $scope['operator_scope'] ?? null;
        $data = $scope['data_scope'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$data instanceof CashierV3DataScopeContext) {
            throw self::fail('退款归属上下文无效。');
        }
        $this->assertAdjustmentAccess($operator, $data);
        $payload = (array)($scope['payload'] ?? []);
        $refund = $this->refundRow((string)($payload['recordId'] ?? ''), $operator, $data, true);
        $guides = $this->normalize((array)($payload['guideSelections'] ?? []), 'guide');
        $managers = $this->normalize((array)($payload['salesManagerSelections'] ?? []), 'sales_manager');
        if (count($managers) > 1) throw self::fail('退款只能选择一位销售经理。');
        $reason = trim((string)($payload['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 255) throw self::fail('请填写不超过255字的修改原因。');
        $key = trim((string)($scope['idempotency_key'] ?? ''));
        if ($key === '') throw self::fail('请求编号无效。');
        $new = $this->snapshot($guides, $managers);
        $fingerprint = hash('sha256', self::json([(string)$refund['operation_id'], $new, $reason]));
        $oldCommand = Db::name(self::REVISION_TABLE)->where('tenant_id', $data->tenantId())
            ->where('command_idempotency_key', $key)->lock(true)->find();
        if ($oldCommand) {
            if ((string)$oldCommand['immutable_fingerprint'] !== $fingerprint) throw self::fail('请求编号已用于其他修改。');
            return ['operationNo' => (string)$refund['operation_no'], 'replayed' => true,
                'message' => '退款导购/销售经理已修改。'];
        }
        $version = $this->latestVersion($data->tenantId(), (string)$refund['operation_id']);
        if (!array_key_exists('recordVersion', $payload) || !is_numeric($payload['recordVersion'])
            || (int)$payload['recordVersion'] !== $version) {
            throw CashierV3CommandException::invalidContext('退款导购/销售经理已被修改，请刷新后重试。');
        }
        $old = $this->snapshotFromRows($this->currentRows($data->tenantId(), (string)$refund['operation_id'], $version));
        if ($this->identities($old) === $this->identities($new)) throw self::fail('退款导购/销售经理未变化。');
        $now = time();
        $revisionId = 'RAR-' . strtoupper(substr(hash('sha256', $data->tenantId() . '|' . $key), 0, 40));
        // 同一个退款聚合还可能发生销售人改派，事件版本必须接续最后一条事件。
        $aggregateVersion = (int)Db::name('cashier_v3_business_event')->where('tenant_id', $data->tenantId())
            ->where('aggregate_type', 'manual_refund')->where('aggregate_id', (string)$refund['operation_id'])
            ->max('aggregate_version') + 1;
        $event = $scope['event_recorder']->recordInTx($scope['event_execution'], (array)($scope['event_contract'] ?? []), [
            'event_type' => 'manual_refund.attribution_adjusted', 'aggregate_type' => 'manual_refund',
            'aggregate_id' => (string)$refund['operation_id'], 'aggregate_version' => $aggregateVersion,
            'event_version' => 1, 'source_type' => 'adjust-refund-attribution', 'source_id' => $revisionId,
            'member_id' => (int)$refund['member_id'], 'business_date' => (string)$refund['business_date'],
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => (string)$refund['target_name_snapshot'],
            'store_name_snapshot' => (string)Db::name('system_store')->where('id', $operator->storeId())->value('name'),
            'payload' => ['reason' => $reason, 'oldAttribution' => $old, 'newAttribution' => $new],
        ]);
        $nextVersion = $version + 1;
        Db::name(self::REVISION_TABLE)->insert([
            'revision_id' => $revisionId, 'tenant_id' => $data->tenantId(),
            'store_id' => $operator->storeId(), 'member_id' => (int)$refund['member_id'],
            'operation_id' => (string)$refund['operation_id'], 'revision_no' => $nextVersion,
            'operator_id' => $operator->operatorId(), 'command_idempotency_key' => $key,
            'immutable_fingerprint' => $fingerprint, 'old_snapshot_json' => self::json($old),
            'new_snapshot_json' => self::json($new), 'reason' => $reason,
            'business_event_no' => (string)$event['event_no'], 'created_at' => $now,
        ]);
        foreach ($new as $person) {
            Db::name(self::REVISION_PERSON_TABLE)->insert([
                'tenant_id' => $data->tenantId(), 'store_id' => $operator->storeId(),
                'operation_id' => (string)$refund['operation_id'], 'revision_no' => $nextVersion,
                'role' => $person['role'], 'employee_id' => $person['employee_id'],
                'employee_name_snapshot' => $person['employee_name_snapshot'],
                'employee_type_snapshot' => $person['employee_type_snapshot'],
                'business_date' => (string)$refund['business_date'], 'business_event_no' => (string)$event['event_no'],
                'occurred_at' => $now,
            ]);
        }
        return ['operationNo' => (string)$refund['operation_no'], 'recordVersion' => $nextVersion,
            'replayed' => false, 'message' => '退款导购/销售经理已修改。'];
    }

    private function refundRow(string $id, CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope, bool $lock): array
    {
        if (str_starts_with($id, 'refund:')) $id = substr($id, 7);
        if (!preg_match('/^OLO-[A-F0-9]{40}$/Di', $id)) throw self::fail('退款记录无效。');
        $query = Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)->where('operation_id', $id)
            ->where('tenant_id', $scope->tenantId())->where('store_id', $operator->storeId())
            ->where('operation_type', 'refund')->where('status', 'succeeded')
            ->whereIn('source_type', ['card', 'document', 'catalog']);
        if ($lock) $query->lock(true);
        $operation = (array)$query->find();
        if ($operation === []) throw self::fail('找不到当前门店的有效退款记录。');
        $adjustment = (array)Db::name(CashierV3UnifiedRefundServices::TABLE)
            ->where('tenant_id', $scope->tenantId())->where('operation_id', $id)
            ->where('store_id', $operator->storeId())->where('status', 'effective')
            ->field('source_line_id,target_name_snapshot')->find();
        if ($adjustment === []) throw self::fail('退款记录已变化，请刷新列表。');
        return $operation + $adjustment;
    }

    private function latestVersion(string $tenant, string $operationId): int
    {
        return (int)Db::name(self::REVISION_TABLE)->where('tenant_id', $tenant)
            ->where('operation_id', $operationId)->max('revision_no');
    }

    /** 版本 0 是退款落账快照；后续版本是完整人员集，空集不能回退到初始归属。 */
    private function currentRows(string $tenant, string $operationId, int $version): array
    {
        $query = Db::name($version > 0 ? self::REVISION_PERSON_TABLE : self::TABLE)
            ->where('tenant_id', $tenant)->where('operation_id', $operationId);
        if ($version > 0) $query->where('revision_no', $version);
        return $query->field('role,employee_id,employee_name_snapshot,employee_type_snapshot')
            ->order('id asc')->select()->toArray();
    }

    private function selectedRows(array $rows, string $role): array
    {
        return array_values(array_map(static fn(array $row): array => [
            'staffId' => (int)$row['employee_id'], 'employeeId' => (int)$row['employee_id'],
            'name' => (string)$row['employee_name_snapshot'],
        ], array_filter($rows, static fn(array $row): bool => (string)$row['role'] === $role)));
    }

    private function snapshot(array $guides, array $managers): array
    {
        $rows = [];
        foreach (['guide' => $guides, 'sales_manager' => $managers] as $role => $people) {
            foreach ($people as $person) $rows[] = [
                'role' => $role, 'employee_id' => $person['id'],
                'employee_name_snapshot' => $person['name'], 'employee_type_snapshot' => $person['type'],
            ];
        }
        usort($rows, static fn(array $a, array $b): int => [$a['role'], $a['employee_id']] <=> [$b['role'], $b['employee_id']]);
        return $rows;
    }

    private function snapshotFromRows(array $rows): array
    {
        usort($rows, static fn(array $a, array $b): int => [$a['role'], $a['employee_id']] <=> [$b['role'], $b['employee_id']]);
        return array_map(static fn(array $row): array => [
            'role' => (string)$row['role'], 'employee_id' => (int)$row['employee_id'],
            'employee_name_snapshot' => (string)$row['employee_name_snapshot'],
            'employee_type_snapshot' => (string)$row['employee_type_snapshot'],
        ], $rows);
    }

    private function identities(array $rows): array
    {
        return array_map(static fn(array $row): string => $row['role'] . ':' . $row['employee_id'], $rows);
    }

    private function assertAdjustmentAccess(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): void
    {
        if ($operator->tenantId() !== $scope->tenantId() || $operator->storeId() !== $scope->forcedStoreId()
            || !$scope->allowsStore($operator->storeId()) || $scope->isReadOnlySession()
            || !$scope->hasFeature('cashier.v3.order.staff_adjust')) {
            throw self::fail('当前账号没有修改退款归属的权限。');
        }
    }

    private static function json($value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 与收银集团人员选择器一致，只接受在职员工；退款导购不接受轮次。 */
    private function normalize(array $rows, string $role): array
    {
        if (count($rows) > 20) throw self::fail('退款归属人员过多。');
        $result = []; $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw self::fail('退款归属人员无效。');
            $id = (int)($row['staffId'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) throw self::fail('退款归属人员无效或重复。');
            if ($role === 'guide' && (int)($row['guideRoundNo'] ?? 0) !== 0) {
                throw self::fail('退款导购不记录轮次。');
            }
            $seen[$id] = true;
            $person = Db::name('employee')->where('id', $id)->where('status', 1)->where('is_del', 0)
                ->field('id,name,employment_type_code,employment_type_version')->lock(true)->find();
            if (!$person || trim((string)$person['name']) === ''
                || ($role === 'guide' && (int)$person['employment_type_version'] <= 0)) {
                throw self::fail('所选导购或销售经理已不具备在职资格。');
            }
            $result[] = ['id' => $id, 'name' => mb_substr(trim((string)$person['name']), 0, 128),
                'type' => (string)$person['employment_type_code']];
        }
        return $result;
    }

    private function sourceLine(array $source, int $memberId, string $tenant): array
    {
        if ((string)($source['orderId'] ?? '') === '' || (string)($source['lineId'] ?? '') === '') return [];
        return (array)Db::name('cashier_v3_sales_order_line')->where('tenant_id', $tenant)
            ->where('order_id', (string)$source['orderId'])->where('order_line_id', (string)$source['lineId'])
            ->where('member_id', $memberId)->where('line_direction', 'forward')->where('line_status', 'settled')
            ->field('order_line_id,checkout_line_id')->find();
    }

    private static function fail(string $message): CashierV3CommandException
    {
        return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, $message,
            CashierV3ResultCode::STATUS_FAILED);
    }
}
