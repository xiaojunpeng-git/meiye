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
 * 补交自己的导购、轮次和销售经理归属。每次修改追加完整版本，原单人员和
 * 收款/销售业绩事实不改；补交不是新到店，轮次仅为本笔收款的展示归属。
 */
final class CashierV3SupplementAttributionServices
{
    public const REVISION_TABLE = 'cashier_v3_supplement_attribution_revision';
    public const PERSON_TABLE = 'cashier_v3_supplement_attribution_person';

    /** 首次成功补交与收款同事务保存，即使无人选中也建立可辨认的空版本。 */
    public function persistInitialInTx(string $repaymentId, string $repaymentNo, int $memberId,
        string $businessDate, int $amountCents, array $guides, array $managers,
        string $commandKey, string $eventNo, CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope): void
    {
        CashierV3TransactionGuard::assertInTransaction('supplementAttribution.persistInitialInTx');
        $this->assertScope($operator, $scope, false);
        $snapshot = $this->normalize($guides, $managers);
        $this->insertVersion($repaymentId, $repaymentNo, $memberId, $businessDate,
            $amountCents, 1, $snapshot, [], '补交确认', $commandKey, $eventNo, $operator, $scope);
    }

    /** 编辑投影只返回补交当前版本和在职候选，不再借用原销售人员。 */
    public function entry(array $payload, CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope): array
    {
        $this->assertScope($operator, $scope, true);
        $source = $this->source((string)($payload['recordId'] ?? ''), $operator, $scope, false);
        $version = $this->version($scope->tenantId(), $source['id']);
        $current = $this->currentRows($scope->tenantId(), [$source['id']])[$source['id']] ?? [];
        $candidates = Db::name('system_store_staff')->alias('staff')
            ->join('employee employee', 'employee.id=staff.employee_id')
            ->where('staff.store_id', $operator->storeId())->where('staff.status', 1)->where('staff.is_del', 0)
            ->where('employee.status', 1)->where('employee.is_del', 0)
            ->field('employee.id,employee.name,employee.employment_type_version')->order('employee.id asc')->select()->toArray();
        $guides = $managers = [];
        foreach ($candidates as $candidate) {
            $person = ['staffId' => (int)$candidate['id'], 'employeeId' => (int)$candidate['id'],
                'name' => (string)$candidate['name'],
                'attributionRole' => (int)$candidate['employment_type_version'] > 0
                    ? 'guide_and_sales_manager' : 'sales_manager'];
            $managers[] = $person;
            if ((int)$candidate['employment_type_version'] > 0) $guides[] = $person;
        }
        return ['recordId' => $source['id'], 'recordVersion' => $version,
            'memberId' => $source['member_id'], 'repaymentNo' => $source['no'],
            'guides' => $guides, 'salesManagers' => $managers,
            'currentGuides' => $this->selection($current, 'guide'),
            'currentSalesManagers' => $this->selection($current, 'sales_manager')];
    }

    /** 行锁、幂等键和版本保护整份归属替换；旧版本永远可审计。 */
    public function adjustInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('supplementAttribution.adjustInTx');
        $operator = $scope['operator_scope'] ?? null;
        $data = $scope['data_scope'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$data instanceof CashierV3DataScopeContext) {
            throw self::fail('补交归属上下文无效。');
        }
        $this->assertScope($operator, $data, true);
        $payload = (array)($scope['payload'] ?? []);
        $source = $this->source((string)($payload['recordId'] ?? ''), $operator, $data, true);
        $snapshot = $this->normalize((array)($payload['guideSelections'] ?? []),
            (array)($payload['salesManagerSelections'] ?? []));
        $reason = trim((string)($payload['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 255) throw self::fail('请填写不超过255字的修改原因。');
        $key = trim((string)($scope['idempotency_key'] ?? ''));
        if ($key === '') throw self::fail('请求编号无效。');
        $fingerprint = hash('sha256', self::json([$source['id'], $snapshot, $reason]));
        $prior = Db::name(self::REVISION_TABLE)->where('tenant_id', $data->tenantId())
            ->where('command_idempotency_key', $key)->lock(true)->find();
        if ($prior) {
            if ((string)$prior['immutable_fingerprint'] !== $fingerprint) throw self::fail('请求编号已用于其他修改。');
            return ['operationNo' => $source['no'], 'replayed' => true, 'message' => '补交导购/销售经理已修改。'];
        }
        $version = $this->version($data->tenantId(), $source['id']);
        if (!array_key_exists('recordVersion', $payload) || (int)$payload['recordVersion'] !== $version) {
            throw CashierV3CommandException::invalidContext('补交导购/销售经理已被修改，请刷新后重试。');
        }
        $old = $this->currentRows($data->tenantId(), [$source['id']])[$source['id']] ?? [];
        if ($this->identities($old) === $this->identities($snapshot)) throw self::fail('补交导购/销售经理未变化。');
        $now = time();
        $eventVersion = (int)Db::name('cashier_v3_business_event')->where('tenant_id', $data->tenantId())
            ->where('aggregate_type', $source['kind'])->where('aggregate_id', $source['id'])
            ->max('aggregate_version') + 1;
        $event = $scope['event_recorder']->recordInTx($scope['event_execution'],
            (array)($scope['event_contract'] ?? []), [
                'event_type' => 'supplement.attribution_adjusted', 'aggregate_type' => $source['kind'],
                'aggregate_id' => $source['id'], 'aggregate_version' => $eventVersion,
                'event_version' => 1, 'source_type' => 'adjust-supplement-attribution',
                'source_id' => 'SAR-' . strtoupper(substr(hash('sha256', $data->tenantId() . '|' . $key), 0, 40)),
                'member_id' => $source['member_id'], 'business_date' => $source['business_date'],
                'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
                'aggregate_name_snapshot' => $source['no'],
                'store_name_snapshot' => (string)Db::name('system_store')->where('id', $operator->storeId())->value('name'),
                'payload' => ['reason' => $reason, 'oldAttribution' => $old, 'newAttribution' => $snapshot],
            ]);
        $this->insertVersion($source['id'], $source['no'], $source['member_id'], $source['business_date'],
            $source['amount_cents'], $version + 1, $snapshot, $old, $reason, $key,
            (string)$event['event_no'], $operator, $data);
        return ['operationNo' => $source['no'], 'recordVersion' => $version + 1,
            'replayed' => false, 'message' => '补交导购/销售经理已修改。'];
    }

    /** 同页/导出批量投影最新版本，历史旧补交自然返回空归属。 */
    public function currentRows(string $tenantId, array $repaymentIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $repaymentIds))));
        if ($ids === []) return [];
        $latest = Db::name(self::REVISION_TABLE)->where('tenant_id', $tenantId)
            ->whereIn('repayment_id', $ids)->fieldRaw('repayment_id,MAX(revision_no) revision_no')
            ->group('repayment_id')->select()->toArray();
        $versions = array_column($latest, 'revision_no', 'repayment_id');
        if ($versions === []) return [];
        $rows = Db::name(self::PERSON_TABLE)->where('tenant_id', $tenantId)
            ->whereIn('repayment_id', array_keys($versions))
            ->field('repayment_id,revision_no,role,employee_id,employee_name_snapshot,guide_round_no,amount_cents')
            ->order('id asc')->select()->toArray();
        $result = [];
        foreach ($rows as $row) {
            $id = (string)$row['repayment_id'];
            if ((int)$row['revision_no'] === (int)$versions[$id]) $result[$id][] = $row;
        }
        return $result;
    }

    private function source(string $recordId, CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope, bool $lock): array
    {
        $id = trim($recordId);
        if (strpos($id, ':') !== false) $id = substr($id, strrpos($id, ':') + 1);
        foreach ([['cashier_v3_debt_repayment', 'repayment_no', 'repayment_amount_cents', 'debt_repayment'],
            ['cashier_v3_recharge_debt_repayment', 'repayment_no', 'amount_cents', 'recharge_debt_repayment']] as $table) {
            $query = Db::name($table[0])->where('tenant_id', $scope->tenantId())
                ->where('store_id', $operator->storeId())->where('repayment_id', $id)->where('status', 'succeeded');
            if ($lock) $query->lock(true);
            $row = $query->field('repayment_id,repayment_no,member_id,business_date,' . $table[2])->find();
            if ($row) return ['id' => (string)$row['repayment_id'], 'no' => (string)$row[$table[1]],
                'member_id' => (int)$row['member_id'], 'business_date' => (string)$row['business_date'],
                'amount_cents' => (int)$row[$table[2]], 'kind' => $table[3]];
        }
        throw self::fail('当前门店找不到有效补交记录。');
    }

    private function normalize(array $guides, array $managers): array
    {
        if (count($guides) > 20 || count($managers) > 1) throw self::fail('补交人员数量无效。');
        $out = []; $round = null;
        foreach (['guide' => $guides, 'sales_manager' => $managers] as $role => $rows) {
            $seen = [];
            foreach ($rows as $row) {
                if (!is_array($row)) throw self::fail('补交人员资料无效。');
                $id = (int)($row['staffId'] ?? $row['employeeId'] ?? 0);
                $guideRound = $role === 'guide' ? (int)($row['guideRoundNo'] ?? 0) : 0;
                if ($id <= 0 || isset($seen[$id]) || $guideRound < 0 || $guideRound > 3
                    || ($role === 'guide' && $round !== null && $round !== $guideRound)) {
                    throw self::fail('补交人员或导购轮次无效。');
                }
                $seen[$id] = true;
                if ($role === 'guide') $round = $guideRound;
                $employee = Db::name('employee')->where('id', $id)->where('status', 1)->where('is_del', 0)
                    ->field('id,name,employment_type_code,employment_type_version')->lock(true)->find();
                if (!$employee || trim((string)$employee['name']) === ''
                    || ($role === 'guide' && (int)$employee['employment_type_version'] <= 0)) {
                    throw self::fail('所选导购或销售经理已不具备在职资格。');
                }
                $out[] = ['role' => $role, 'employee_id' => $id,
                    'employee_name_snapshot' => mb_substr(trim((string)$employee['name']), 0, 128),
                    'employee_type_snapshot' => (string)$employee['employment_type_code'],
                    'guide_round_no' => $guideRound];
            }
        }
        return $out;
    }

    private function insertVersion(string $id, string $no, int $memberId, string $date,
        int $amountCents, int $version, array $people, array $old, string $reason,
        string $key, string $eventNo, CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope): void
    {
        $now = time();
        Db::name(self::REVISION_TABLE)->insert([
            'revision_id' => 'SAR-' . strtoupper(substr(hash('sha256', $scope->tenantId() . '|' . $key), 0, 40)),
            'tenant_id' => $scope->tenantId(), 'store_id' => $operator->storeId(),
            'repayment_id' => $id, 'repayment_no_snapshot' => $no, 'member_id' => $memberId,
            'revision_no' => $version, 'operator_id' => $operator->operatorId(),
            'command_idempotency_key' => $key,
            'immutable_fingerprint' => hash('sha256', self::json([$id, $people, $reason])),
            'old_snapshot_json' => self::json($old), 'new_snapshot_json' => self::json($people),
            'reason' => $reason, 'business_event_no' => $eventNo, 'created_at' => $now,
        ]);
        foreach ($people as $person) Db::name(self::PERSON_TABLE)->insert([
            'tenant_id' => $scope->tenantId(), 'store_id' => $operator->storeId(),
            'repayment_id' => $id, 'revision_no' => $version, 'member_id' => $memberId,
            'role' => $person['role'], 'employee_id' => $person['employee_id'],
            'employee_name_snapshot' => $person['employee_name_snapshot'],
            'employee_type_snapshot' => $person['employee_type_snapshot'],
            'guide_round_no' => $person['role'] === 'guide' ? ($person['guide_round_no'] ?: null) : null,
            'amount_cents' => $person['role'] === 'sales_manager' ? $amountCents : 0,
            'business_date' => $date, 'business_event_no' => $eventNo, 'recorded_at' => $now,
        ]);
    }

    private function version(string $tenant, string $id): int
    {
        return (int)Db::name(self::REVISION_TABLE)->where('tenant_id', $tenant)
            ->where('repayment_id', $id)->max('revision_no');
    }

    private function selection(array $rows, string $role): array
    {
        return array_values(array_map(static fn(array $row): array => [
            'staffId' => (int)$row['employee_id'], 'employeeId' => (int)$row['employee_id'],
            'name' => (string)$row['employee_name_snapshot'],
            'guideRoundNo' => (int)($row['guide_round_no'] ?? 0),
        ], array_values(array_filter($rows, static fn(array $row): bool => (string)$row['role'] === $role))));
    }

    private function identities(array $rows): array
    {
        $keys = array_map(static fn(array $row): string => (string)$row['role'] . ':'
            . (int)$row['employee_id'] . ':' . (int)($row['guide_round_no'] ?? 0), $rows);
        sort($keys, SORT_STRING);
        return $keys;
    }

    private function assertScope(CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope, bool $adjust): void
    {
        if ($operator->tenantId() !== $scope->tenantId() || $operator->storeId() !== $scope->forcedStoreId()
            || !$scope->allowsStore($operator->storeId()) || $scope->isReadOnlySession()
            || ($adjust && !$scope->hasFeature('cashier.v3.order.staff_adjust'))) {
            throw self::fail('当前账号没有补交人员归属操作权限。');
        }
    }

    private static function json($value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function fail(string $message): CashierV3CommandException
    {
        return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            $message, CashierV3ResultCode::STATUS_FAILED);
    }
}
