<?php
declare(strict_types=1);

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * 退款销售人只归属退款本身。原销售事实不变；改派通过冲销旧退款事实并
 * 追加新负向事实生效，薪资只读取当前有效分配。
 */
final class CashierV3RefundSalespersonServices
{
    private const FACT_TABLE = 'cashier_v3_performance_fact';
    private const REVISION_TABLE = 'cashier_v3_refund_salesperson_revision';

    /** 原单销售人仅用作默认草稿；金额以本次退款额重算，绝不沿用原单金额。 */
    public function selection(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $this->assertAccess($operator, $scope, false);
        $selection = $this->sourceSelection($payload, $operator, $scope);
        // 原单导购/经理只在新退款选择时充当默认草稿；编辑历史退款不反查覆盖已保存快照。
        $attribution = (new CashierV3RefundAttributionServices())->selection([
            'orderId' => $selection['sourceSalesOrderId'], 'lineId' => $selection['sourceSalesLineId'],
        ], (int)$payload['memberId'], $operator, $scope);
        return $selection + $attribution;
    }

    /** 编辑旧退款只读取原单作为候选草稿，不要求账号同时拥有新建退款权限。 */
    private function sourceSelection(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $mode = (string)($payload['mode'] ?? '');
        $memberId = (int)($payload['memberId'] ?? 0);
        if ($memberId <= 0 || !in_array($mode, ['card', 'document', 'catalog'], true)) throw self::fail('退款对象无效，请重新选择。');
        $source = $this->sourceIdentity($mode, $payload, $memberId, $scope->tenantId());
        $candidates = $this->candidates($operator);
        $byEmployee = [];
        foreach ($candidates as $candidate) $byEmployee[(int)$candidate['employeeId']] = $candidate;
        $selected = [];
        if ($source['orderId'] !== '' && $source['lineId'] !== '') {
            $rows = Db::name(self::FACT_TABLE)->where('tenant_id', $scope->tenantId())
                ->where('order_id', $source['orderId'])->where('source_line_id', $source['lineId'])
                ->where('performance_type', 'sales_performance_allocated')->where('status', 'effective')
                ->where('fact_direction', 'forward')->order('id asc')->select()->toArray();
            $active = $this->unreversed($rows, $scope->tenantId());
            foreach ($active as $row) {
                $employeeId = (int)$row['employee_id'];
                if (!isset($byEmployee[$employeeId])) continue;
                $person = $byEmployee[$employeeId];
                $selected[$employeeId] = $person + [
                    'allocationWeight' => (int)$row['allocation_weight_numerator'],
                    'isPreSale' => str_ends_with((string)$row['role_snapshot'], ':presale'),
                ];
            }
        }
        return ['sourceSalesOrderId' => $source['orderId'], 'sourceSalesLineId' => $source['lineId'],
            'salespeople' => $candidates, 'currentSalespeople' => array_values($selected)];
    }

    /** 编辑页以退款主表版本和保存的分配快照为准，历史原单人员不再回流。 */
    public function entry(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $this->assertAccess($operator, $scope, true);
        $row = $this->refundRow((string)($payload['recordId'] ?? ''), $operator, $scope, false);
        $selected = json_decode((string)($row['salespeople_snapshot_json'] ?? '[]'), true);
        if (!is_array($selected)) $selected = [];
        if ((int)$row['salespeople_version'] === 0 && (string)$row['target_type'] !== 'imported_card') {
            // 迁移前退款没有人员字段；编辑时只提供原单默认草稿，不追写旧事实。
            $defaults = $this->sourceSelection([
                'mode' => (string)$row['target_type'], 'memberId' => (int)$row['member_id'],
                'holderId' => (int)$row['card_holder_id'], 'lineId' => (string)$row['source_line_id'],
            ], $operator, $scope);
            $selected = $defaults['currentSalespeople'];
        }
        return ['recordId' => (string)$row['operation_id'], 'recordVersion' => (int)$row['salespeople_version'],
            'performanceBaseAmountCents' => (int)$row['refund_amount_cents'],
            'totalAmountCents' => array_sum(array_map(static fn(array $p): int => abs((int)($p['performanceAmountCents'] ?? 0)), $selected)),
            'salespeople' => $this->candidates($operator), 'currentSalespeople' => $selected,
            'lines' => [['orderLineId' => (string)$row['operation_id'], 'itemName' => (string)$row['target_name_snapshot'],
                'currentSalespeople' => $selected]]];
    }

    /** 同退款命令写入人员快照；留空不生成员工扣减，经营退款仍按整笔支付调整统计。 */
    public function createInTx(array $operation, array $payload, int $adjustmentRowId, array $event,
        CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): void
    {
        CashierV3TransactionGuard::assertInTransaction('refundSalesperson.createInTx');
        $people = $this->normalize((array)($payload['salespeople'] ?? []), $operator);
        $shares = $this->allocate($people, (int)$operation['cash_refund_cents']);
        $source = $this->sourceIdentity((string)$payload['mode'], $payload, (int)$operation['member_id'], $scope->tenantId());
        $snapshot = $this->withShares($people, $shares);
        Db::name(CashierV3UnifiedRefundServices::TABLE)->where('id', $adjustmentRowId)
            ->where('tenant_id', $scope->tenantId())->update([
                'salespeople_snapshot_json' => self::json($snapshot), 'salespeople_version' => 1,
                'source_sales_order_id' => $source['orderId'], 'source_sales_line_id' => $source['lineId'],
            ]);
        $this->writeFacts($operation, $snapshot, (string)$event['event_no'],
            (string)$operation['command_idempotency_key'], $operator, $scope, 'create');
        // 导购/销售经理仅是本次退款的独立归属快照，不写回原销售人员事实。
        (new CashierV3RefundAttributionServices())->createInTx($operation, $payload, $source,
            $event, $operator, $scope);
    }

    /** 调整是独立退款事件，幂等及版本锁保护多人分配；原销售分配无任何写路径。 */
    public function adjustInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('refundSalesperson.adjustInTx');
        $operator = $scope['operator_scope']; $data = $scope['data_scope'];
        if (!$operator instanceof CashierV3OperatorScope || !$data instanceof CashierV3DataScopeContext) throw self::fail('退款人员上下文无效。');
        $this->assertAccess($operator, $data, true);
        $payload = (array)($scope['payload'] ?? []);
        $row = $this->refundRow((string)($payload['recordId'] ?? ''), $operator, $data, true);
        $importedCard = (string)$row['target_type'] === 'imported_card';
        $action = $importedCard ? 'adjust-imported-refund-personnel' : 'adjust-refund-personnel';
        if ((string)($scope['action'] ?? '') !== $action) {
            throw CashierV3CommandException::invalidContext('退款销售人操作类型与退款记录不一致。');
        }
        $people = $this->normalize((array)($payload['personnel'] ?? []), $operator);
        $snapshot = $this->withShares($people, $this->allocate($people, (int)$row['refund_amount_cents']));
        $reason = trim((string)($payload['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 255) throw self::fail('请填写不超过255字的修改原因。');
        $key = trim((string)($scope['idempotency_key'] ?? ''));
        if ($key === '') throw self::fail('请求编号无效。');
        $fingerprint = hash('sha256', self::json([$row['operation_id'], $snapshot, $reason]));
        $oldCommand = Db::name(self::REVISION_TABLE)->where('tenant_id', $data->tenantId())
            ->where('command_idempotency_key', $key)->lock(true)->find();
        if ($oldCommand) {
            if ((string)$oldCommand['immutable_fingerprint'] !== $fingerprint) throw self::fail('请求编号已用于其他修改。');
            return ['operationNo' => (string)$row['operation_no'], 'replayed' => true, 'message' => '退款销售人已修改。'];
        }
        if (!array_key_exists('recordVersion', $payload)
            || (int)$payload['recordVersion'] !== (int)$row['salespeople_version']) {
            throw CashierV3CommandException::invalidContext('退款销售人已被修改，请刷新后重试。');
        }
        $oldSnapshot = (string)($row['salespeople_snapshot_json'] ?? '[]');
        // 迁入旧卡没有可信的 V3 原销售明细；只给退款自身分配负向业绩。
        $source = $importedCard ? ['orderId' => '', 'lineId' => ''] : $this->sourceIdentity((string)$row['target_type'], [
            'holderId' => (int)$row['card_holder_id'], 'lineId' => (string)$row['source_line_id'],
        ], (int)$row['member_id'], $data->tenantId());
        $now = time();
        $revisionId = 'RSP-' . strtoupper(substr(hash('sha256', $data->tenantId() . '|' . $key), 0, 40));
        // 退款导购/经理改派和销售人改派共用退款聚合；不能各按自身版本生成重复事件序号。
        $aggregateType = $importedCard ? 'imported_card_refund' : 'manual_refund';
        $aggregateVersion = (int)Db::name('cashier_v3_business_event')->where('tenant_id', $data->tenantId())
            ->where('aggregate_type', $aggregateType)->where('aggregate_id', (string)$row['operation_id'])
            ->max('aggregate_version') + 1;
        $event = $scope['event_recorder']->recordInTx($scope['event_execution'], (array)($scope['event_contract'] ?? []), [
            'event_type' => $importedCard ? 'imported_card_refund.personnel_adjusted' : 'manual_refund.personnel_adjusted',
            'aggregate_type' => $aggregateType,
            'aggregate_id' => (string)$row['operation_id'], 'aggregate_version' => $aggregateVersion,
            'event_version' => 1, 'source_type' => $action, 'source_id' => $revisionId,
            'member_id' => (int)$row['member_id'], 'business_date' => (string)$row['business_date'],
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => (string)$row['target_name_snapshot'],
            'store_name_snapshot' => (string)Db::name('system_store')->where('id', $operator->storeId())->value('name'),
            'payload' => ['reason' => $reason, 'oldSalespeople' => json_decode($oldSnapshot, true), 'newSalespeople' => $snapshot],
        ]);
        $oldFacts = Db::name(self::FACT_TABLE)->where('tenant_id', $data->tenantId())
            ->where('order_id', (string)$row['operation_id'])->where('performance_type', 'refund_sales_deduction')
            ->where('fact_direction', 'forward')->where('status', 'effective')->select()->toArray();
        foreach ($this->unreversed($oldFacts, $data->tenantId()) as $fact) {
            $reversal = $fact;
            unset($reversal['id']);
            $reversal['fact_id'] = 'RSPR-' . strtoupper(substr(hash('sha256', $revisionId . '|' . $fact['fact_id']), 0, 40));
            $reversal['natural_key'] = 'refund_personnel_reversal:' . $revisionId . ':' . $fact['fact_id'];
            $reversal['fact_direction'] = 'reversal'; $reversal['reversal_of'] = (string)$fact['fact_id'];
            $reversal['business_event_no'] = (string)$event['event_no'];
            $reversal['command_idempotency_key'] = $key;
            $reversal['occurred_at'] = $now; $reversal['settled_at'] = $now; $reversal['recorded_at'] = $now;
            unset($reversal['immutable_fingerprint']);
            $reversal['immutable_fingerprint'] = hash('sha256', self::json($reversal));
            Db::name(self::FACT_TABLE)->insert($reversal);
        }
        $this->writeFacts($row + ['cash_refund_cents' => (int)$row['refund_amount_cents']],
            $snapshot, (string)$event['event_no'], $key, $operator, $data, $revisionId);
        if (!$importedCard) {
            Db::name(CashierV3UnifiedRefundServices::TABLE)->where('id', (int)$row['id'])
                ->where('salespeople_version', (int)$row['salespeople_version'])->update([
                    'salespeople_snapshot_json' => self::json($snapshot),
                    'salespeople_version' => (int)$row['salespeople_version'] + 1,
                    'source_sales_order_id' => $source['orderId'],
                    'source_sales_line_id' => $source['lineId'],
                ]);
        }
        // 旧卡退款无 adjustment 主行；操作行锁序列化写入，末条改派审计即当前人员快照。
        Db::name(self::REVISION_TABLE)->insert([
            'revision_id' => $revisionId, 'operation_id' => (string)$row['operation_id'],
            'tenant_id' => $data->tenantId(), 'store_id' => $operator->storeId(), 'operator_id' => $operator->operatorId(),
            'command_idempotency_key' => $key, 'immutable_fingerprint' => $fingerprint,
            'old_snapshot_json' => $oldSnapshot, 'new_snapshot_json' => self::json($snapshot),
            'reason' => $reason, 'business_event_no' => (string)$event['event_no'], 'created_at' => $now,
        ]);
        return ['operationNo' => (string)$row['operation_no'], 'replayed' => false, 'message' => '退款销售人已修改。'];
    }

    private function sourceIdentity(string $mode, array $payload, int $memberId, string $tenant): array
    {
        // 无原单退款只归属退款销售人；绝不把目录商品伪装成原销售明细。
        if ($mode === 'catalog') return ['orderId' => '', 'lineId' => ''];
        if ($mode === 'document') {
            $line = Db::name('cashier_v3_sales_order_line')->where('tenant_id', $tenant)
                ->where('order_line_id', (string)($payload['lineId'] ?? ''))->where('member_id', $memberId)
                ->field('order_id,order_line_id')->find();
            if (!$line) throw self::fail('原销售明细已变化，请重新选择。');
            return ['orderId' => (string)$line['order_id'], 'lineId' => (string)$line['order_line_id']];
        }
        $holderId = (int)($payload['holderId'] ?? 0);
        $holder = Db::name('user_card_holder')->where('id', $holderId)->where('uid', $memberId)->field('oid')->find();
        if (!$holder) throw self::fail('原卡归属已变化，请重新选择。');
        $receipt = Db::name('cashier_v3_card_purchase_receipt')->where('tenant_id', $tenant)
            ->where('card_holder_id', $holderId)->where('legacy_order_id', (int)$holder['oid'])
            ->where('status', 'completed')->field('sales_order_id,sales_order_line_id')->find();
        return ['orderId' => (string)($receipt['sales_order_id'] ?? ''),
            'lineId' => (string)($receipt['sales_order_line_id'] ?? '')];
    }

    private function candidates(CashierV3OperatorScope $operator): array
    {
        $rows = Db::name('system_store_staff')->alias('s')->join('employee e', 'e.id=s.employee_id')
            ->leftJoin('staff_job_position sjp', 'sjp.staff_id=s.id AND sjp.status=1 AND sjp.is_del=0 AND sjp.end_time=0')
            ->leftJoin('position p', 'p.id=sjp.position_id AND p.status=1')
            ->where('s.store_id', $operator->storeId())->where('s.status', 1)->where('s.is_del', 0)
            ->where('s.cashier_salesperson_enabled', 1)->where('e.status', 1)->where('e.is_del', 0)
            ->field('s.id,s.employee_id,s.staff_name,e.name,e.employment_type_code,e.employment_type_version,sjp.position_id,p.name position_name,p.performance_independent')
            ->order('s.id asc')->select()->toArray();
        return array_values(array_map(static function (array $row): array {
            $positionId = (int)($row['position_id'] ?? 0);
            $independent = (int)($row['performance_independent'] ?? 0) === 1;
            return ['staffId' => (int)$row['id'], 'employeeId' => (int)$row['employee_id'],
                'name' => trim((string)$row['name']) ?: (string)$row['staff_name'],
                'employeeTypeCode' => (string)$row['employment_type_code'],
                'employeeTypeAuthorityVersion' => (int)$row['employment_type_version'],
                'positionId' => $positionId, 'positionName' => (string)($row['position_name'] ?? ''),
                'performanceIndependent' => $independent,
                'allocationGroupKey' => $independent ? 'independent:' . ($positionId > 0 ? $positionId : (int)$row['id']) : 'normal'];
        }, $rows));
    }

    /** 空分配代表本次退款不扣员工；一旦选人仍须通过身份、比例和整元金额校验。 */
    private function normalize(array $rows, CashierV3OperatorScope $operator): array
    {
        if ($rows === []) return [];
        if (count($rows) > 20) throw self::fail('退款销售人不能超过20人。');
        $candidates = $this->candidates($operator);
        $byStaff = [];
        foreach ($candidates as $candidate) $byStaff[(int)$candidate['staffId']] = $candidate;
        $out = []; $seen = []; $weights = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw self::fail('退款销售人分配无效。');
            $staffId = (int)($row['staffId'] ?? $row['id'] ?? 0);
            $weight = (int)($row['allocationWeight'] ?? 0);
            if (!isset($byStaff[$staffId]) || isset($seen[$staffId]) || $weight <= 0 || $weight > 100) throw self::fail('退款销售人或分配比例无效。');
            $seen[$staffId] = true;
            $candidate = $byStaff[$staffId];
            if (!in_array($candidate['employeeTypeCode'], ['internal', 'partner', 'outsourced'], true)
                || (int)$candidate['employeeTypeAuthorityVersion'] <= 0) throw self::fail('退款销售人人员类型无效。');
            $manual = !empty($row['performanceAmountManual']);
            $amount = $row['performanceAmountCents'] ?? 0;
            if ($manual && ((!is_int($amount) && !is_string($amount))
                || !preg_match('/^(?:0|[1-9][0-9]*)$/D', (string)$amount)
                || (int)$amount % 100 !== 0)) throw self::fail('退款销售人业绩只能填写整数元。');
            $out[] = $candidate + ['allocationWeight' => $weight, 'isPreSale' => !empty($row['isPreSale']) || !empty($row['marked']),
                'performanceAmountManual' => $manual, 'performanceAmountCents' => $manual ? (int)$amount : 0];
            $group = (string)$candidate['allocationGroupKey'];
            $weights[$group] = ($weights[$group] ?? 0) + $weight;
        }
        foreach ($weights as $weight) if ($weight !== 100) throw self::fail('每个销售人业绩分配组必须合计100%。');
        return $out;
    }

    /** 手填最终金额不改他人金额；自动组用最后一人承接整元尾差。 */
    private function allocate(array $people, int $baseCents): array
    {
        if ($baseCents < 0 || $baseCents % 100 !== 0) throw self::fail('本次退款金额必须是整数元。');
        // A zero-cash card refund may retain personnel attribution, but it cannot
        // allocate a negative salary fact or invent a one-yuan deduction.
        if ($baseCents === 0) return array_fill(0, count($people), 0);
        $groups = [];
        foreach ($people as $index => $person) $groups[(string)$person['allocationGroupKey']][] = $index;
        $shares = array_fill(0, count($people), 0);
        foreach ($groups as $indexes) {
            $manual = array_values(array_filter($indexes, static fn(int $i): bool => $people[$i]['performanceAmountManual']));
            $auto = array_values(array_filter($indexes, static fn(int $i): bool => !$people[$i]['performanceAmountManual']));
            $autoWeight = array_sum(array_map(static fn(int $i): int => (int)$people[$i]['allocationWeight'], $auto));
            $autoBase = $manual ? intdiv(intdiv($baseCents, 100) * $autoWeight, 100) * 100 : $baseCents;
            $allocated = 0; $last = $auto[count($auto) - 1] ?? null;
            foreach ($auto as $i) {
                $share = $i === $last ? $autoBase - $allocated : intdiv($baseCents * (int)$people[$i]['allocationWeight'], 10000) * 100;
                if ($share <= 0) throw self::fail('退款金额过小，无法给每位销售人分配整元业绩。');
                $shares[$i] = $share; $allocated += $share;
            }
            foreach ($manual as $i) $shares[$i] = (int)$people[$i]['performanceAmountCents'];
        }
        return $shares;
    }

    private function withShares(array $people, array $shares): array
    {
        foreach ($people as $index => &$person) $person['performanceAmountCents'] = $shares[$index];
        unset($person);
        return $people;
    }

    private function writeFacts(array $operation, array $people, string $eventNo, string $key,
        CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, string $revision): void
    {
        if ((int)$operation['cash_refund_cents'] === 0) return;
        $now = time();
        $storeName = (string)Db::name('system_store')->where('id', $operator->storeId())->value('name');
        $member = (array)Db::name('user')->where('uid', (int)$operation['member_id'])->field('real_name,nickname')->find();
        $staffName = (string)Db::name('system_store_staff')->where('id', $operator->operatorId())->value('staff_name');
        foreach ($people as $index => $person) {
            $fact = [
                'fact_id' => 'RSPF-' . strtoupper(substr(hash('sha256', $operation['operation_id'] . '|' . $revision . '|' . $index), 0, 40)),
                'business_event_no' => $eventNo, 'fact_type' => 'refund_salesperson_allocation',
                'fact_direction' => 'forward', 'natural_key' => 'refund_personnel:' . $operation['operation_id'] . ':' . $revision . ':' . $index,
                'command_idempotency_key' => $key, 'fact_version' => 1, 'reversal_of' => '', 'status' => 'effective',
                'tenant_id' => $scope->tenantId(), 'tenant_name_snapshot' => '',
                'organization_id' => (string)$operator->organizationId(), 'organization_name_snapshot' => '',
                'organization_path_snapshot' => (string)$operator->organizationId(),
                'store_id' => $operator->storeId(), 'store_name_snapshot' => $storeName,
                'member_id' => (int)$operation['member_id'],
                'member_name_snapshot' => (string)(($member['real_name'] ?? '') ?: ($member['nickname'] ?? '')),
                'operator_id' => $operator->operatorId(), 'operator_name_snapshot' => $staffName,
                'business_date' => (string)$operation['business_date'], 'business_timezone' => 'Asia/Shanghai',
                'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
                'checkout_request_id' => (string)$operation['operation_id'], 'order_id' => (string)$operation['operation_id'],
                'order_no_snapshot' => (string)$operation['operation_no'],
                // New refunds pass a lifecycle operation (source_type); later reassignment passes an adjustment (target_type).
                'source_document_type' => (string)($operation['target_type'] ?? $operation['source_type'] ?? '') === 'imported_card'
                    ? 'imported_card_refund' : 'manual_refund',
                'source_line_id' => (string)$operation['operation_id'], 'performance_type' => 'refund_sales_deduction',
                'employee_id' => (int)$person['employeeId'], 'employee_name_snapshot' => (string)$person['name'],
                'employee_type_snapshot' => (string)$person['employeeTypeCode'],
                'employee_type_authority_version' => (int)$person['employeeTypeAuthorityVersion'],
                'role_snapshot' => !empty($person['isPreSale']) ? 'salesperson:presale' : 'salesperson:postsale',
                'allocation_weight_numerator' => (int)$person['allocationWeight'], 'allocation_weight_denominator' => 100,
                'allocation_base_amount_cents' => (int)$operation['cash_refund_cents'],
                'amount_cents' => -(int)$person['performanceAmountCents'],
                'rule_code_snapshot' => 'REFUND-SALESPERSON-DEDUCTION-V1', 'rule_name_snapshot' => '退款销售人扣减',
                'rule_version_snapshot' => '1',
            ];
            $fact['immutable_fingerprint'] = hash('sha256', self::json($fact));
            Db::name(self::FACT_TABLE)->insert($fact);
        }
    }

    private function refundRow(string $id, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, bool $lock): array
    {
        if (str_starts_with($id, 'refund:')) $id = substr($id, 7);
        if (!preg_match('/^OLO-[A-F0-9]{40}$/Di', $id)) throw self::fail('退款记录无效。');
        $query = Db::name(CashierV3UnifiedRefundServices::TABLE)->alias('ra')
            ->join(CashierV3OrderLifecycleServices::OPERATION_TABLE . ' op', 'op.operation_id=ra.operation_id AND op.tenant_id=ra.tenant_id')
            ->where('ra.operation_id', $id)->where('ra.tenant_id', $scope->tenantId())
            ->where('ra.store_id', $operator->storeId())->where('ra.status', 'effective')
            ->where('op.operation_type', 'refund')->where('op.status', 'succeeded')
            ->field('ra.*,op.operation_no');
        $row = $lock ? $query->lock(true)->find() : $query->find();
        if ($row) return (array)$row;
        // 历史迁入旧卡退款只存在于生命周期主表；不伪造一条统一退款 adjustment。
        $query = Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)
            ->where('operation_id', $id)->where('tenant_id', $scope->tenantId())
            ->where('store_id', $operator->storeId())->where('source_type', 'imported_card')
            ->where('operation_type', 'refund')->where('status', 'succeeded');
        if ($lock) $query->lock(true);
        $operation = (array)$query->find();
        if ($operation === []) throw self::fail('找不到当前门店的退款记录。');
        $revisionQuery = Db::name(self::REVISION_TABLE)->where('tenant_id', $scope->tenantId())
            ->where('operation_id', $id);
        $version = (int)(clone $revisionQuery)->count();
        $revision = (array)$revisionQuery->order('id desc')->field('new_snapshot_json')->find();
        $request = json_decode((string)($operation['request_json'] ?? ''), true);
        $request = is_array($request) ? $request : [];
        return $operation + [
            'target_type' => 'imported_card',
            'target_name_snapshot' => (string)($request['cardName'] ?? $operation['source_order_no_snapshot']),
            'refund_amount_cents' => (int)$operation['cash_refund_cents'],
            'salespeople_version' => $version,
            'salespeople_snapshot_json' => (string)($revision['new_snapshot_json'] ?? '[]'),
        ];
    }

    private function unreversed(array $rows, string $tenant): array
    {
        $ids = array_values(array_filter(array_map(static fn(array $r): string => (string)$r['fact_id'], $rows)));
        if ($ids === []) return [];
        $reversed = Db::name(self::FACT_TABLE)->where('tenant_id', $tenant)
            ->where('fact_direction', 'reversal')->whereIn('reversal_of', $ids)->column('reversal_of');
        $skip = array_fill_keys(array_map('strval', $reversed), true);
        return array_values(array_filter($rows, static fn(array $r): bool => !isset($skip[(string)$r['fact_id']])));
    }

    private function assertAccess(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, bool $editing): void
    {
        if ($operator->tenantId() !== $scope->tenantId() || $operator->storeId() !== $scope->forcedStoreId()
            || !$scope->allowsStore($operator->storeId()) || $scope->isReadOnlySession()
            || !$scope->hasFeature($editing ? 'cashier.v3.order.staff_adjust' : 'cashier.v3.order.refund')) {
            throw self::fail('当前账号没有退款销售人权限。');
        }
    }

    private static function json($value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function fail(string $message): CashierV3CommandException
    {
        return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, $message,
            CashierV3ResultCode::STATUS_FAILED);
    }
}
