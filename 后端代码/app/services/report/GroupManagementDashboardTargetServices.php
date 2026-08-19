<?php

declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * Manual monthly store targets used only by the group management dashboard.
 * They intentionally do not share the legacy target domain or immutable facts.
 */
final class GroupManagementDashboardTargetServices
{
    /**
     * This namespace is deliberately dashboard-only. Do not substitute an
     * existing store/report target table here: those features have different
     * ownership, permission and reporting semantics.
     */
    public const TARGET_SUBJECT = 'group_management_dashboard_store_month';
    public const TARGET_TABLE = 'cashier_v3_group_dashboard_target';
    public const AUDIT_TABLE = 'cashier_v3_group_dashboard_target_audit';

    /** @return array{year:int,stores:array<int,array<string,mixed>>,source_explanation:string} */
    public function readYear(array $context, int $year): array
    {
        $scope = $this->scope($context);
        $year = $this->year($year);
        $stores = $this->stores($scope['store_ids']);
        if ($stores === []) {
            return [
                'year' => $year,
                'target_subject' => self::TARGET_SUBJECT,
                'stores' => [],
                'source_explanation' => '仅在集团管理看板中由拥有看板访问权限的账号按门店、年度和月份手动填写的目标业绩。保存后按门店上级组织自动汇总；不会修改订单、收款、服务或其他目标功能。',
            ];
        }
        $records = [];
        foreach (Db::name(self::TARGET_TABLE)->where('tenant_id', $scope['tenant_id'])
            ->where('target_year', $year)->whereIn('store_id', array_keys($stores))
            ->order('store_id', 'asc')->order('target_month', 'asc')->select()->toArray() as $row) {
            $records[(int)$row['store_id']][(int)$row['target_month']] = $row;
        }
        $rows = [];
        foreach ($stores as $storeId => $storeName) {
            $months = [];
            $total = 0;
            for ($month = 1; $month <= 12; $month++) {
                $record = $records[$storeId][$month] ?? null;
                $cleared = (int)($record['is_cleared'] ?? 0) === 1;
                $cents = $cleared ? 0 : (int)($record['target_amount_cents'] ?? 0);
                $total += $cents;
                $months[] = [
                    'month' => $month,
                    'target_amount_cents' => $cleared ? '' : $cents,
                    'is_cleared' => $cleared,
                    'expected_version' => (int)($record['version'] ?? 0),
                    'subject_key' => $this->subjectKey($year, $storeId, $month),
                ];
            }
            $rows[] = ['store_id' => $storeId, 'store_name' => $storeName, 'months' => $months, 'annual_target_cents' => $total];
        }
        return [
            'year' => $year,
            'target_subject' => self::TARGET_SUBJECT,
            'stores' => $rows,
            'source_explanation' => '仅在集团管理看板中由拥有看板访问权限的账号按门店、年度和月份手动填写的目标业绩。保存后按门店上级组织自动汇总；不会修改订单、收款、服务或其他目标功能。',
        ];
    }

    /** @return array<string,mixed> */
    public function saveMonthly(array $context, array $input): array
    {
        $scope = $this->scope($context);
        $storeId = (int)($input['store_id'] ?? 0);
        if ($storeId <= 0 || !in_array($storeId, $scope['store_ids'], true)) {
            throw new \InvalidArgumentException('无权编辑该门店的看板目标');
        }
        $year = $this->year((int)($input['year'] ?? 0));
        $month = (int)($input['month'] ?? 0);
        if ($month < 1 || $month > 12) throw new \InvalidArgumentException('目标月份无效');
        $target = $this->amount($input['target_amount_cents'] ?? null);
        $amount = $target['amount'];
        $cleared = $target['cleared'];
        $expected = (int)($input['expected_version'] ?? -1);
        $idempotency = $this->token($input['idempotency_key'] ?? '', 'idempotency_key', 128);
        $operatorId = max(0, (int)($context['admin_id'] ?? $context['operator_id'] ?? 0));
        $operatorName = mb_substr(trim((string)($context['admin_name'] ?? $context['operator_name'] ?? '')), 0, 128);
        $now = time();

        return Db::transaction(function () use ($scope, $storeId, $year, $month, $amount, $cleared, $expected, $idempotency, $operatorId, $operatorName, $now): array {
            $replay = Db::name(self::AUDIT_TABLE)->where('tenant_id', $scope['tenant_id'])
                ->where('idempotency_key', $idempotency)->lock(true)->find();
            if (is_array($replay)) {
                if ((int)$replay['store_id'] !== $storeId || (int)$replay['target_year'] !== $year
                    || (int)$replay['target_month'] !== $month || (int)$replay['after_amount_cents'] !== $amount
                    || (int)($replay['after_is_cleared'] ?? 0) !== (int)$cleared) {
                    throw new \InvalidArgumentException('幂等标识已用于其他看板目标');
                }
                $row = Db::name(self::TARGET_TABLE)->where('id', (int)$replay['target_id'])->find();
                if (!is_array($row)) throw new \RuntimeException('看板目标审计存在但目标记录缺失');
                return $this->projection($row, true);
            }

            $existing = Db::name(self::TARGET_TABLE)->where('tenant_id', $scope['tenant_id'])
                ->where('store_id', $storeId)->where('target_year', $year)->where('target_month', $month)->lock(true)->find();
            if (is_array($existing)) {
                if ($expected <= 0 || $expected !== (int)$existing['version']) {
                    throw new \InvalidArgumentException('看板目标已更新，请刷新后再保存');
                }
                $beforeAmount = (int)$existing['target_amount_cents'];
                $beforeCleared = (int)($existing['is_cleared'] ?? 0) === 1;
                $beforeVersion = (int)$existing['version'];
                $version = $beforeVersion + 1;
                Db::name(self::TARGET_TABLE)->where('id', (int)$existing['id'])->update([
                    'target_amount_cents' => $amount, 'is_cleared' => (int)$cleared, 'version' => $version,
                    'updated_by' => $operatorId, 'updated_by_name_snapshot' => $operatorName, 'updated_at' => $now,
                ]);
                $targetId = (int)$existing['id'];
                $action = $cleared ? 'CLEARED' : 'UPDATED';
            } else {
                if ($expected !== 0) throw new \InvalidArgumentException('新看板目标的版本必须为 0');
                $beforeAmount = 0;
                $beforeCleared = false;
                $beforeVersion = 0;
                $version = 1;
                $targetId = (int)Db::name(self::TARGET_TABLE)->insertGetId([
                    'tenant_id' => $scope['tenant_id'], 'store_id' => $storeId, 'target_year' => $year,
                    'target_month' => $month, 'target_amount_cents' => $amount, 'is_cleared' => (int)$cleared, 'version' => $version,
                    'created_by' => $operatorId, 'created_by_name_snapshot' => $operatorName,
                    'updated_by' => $operatorId, 'updated_by_name_snapshot' => $operatorName,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $action = $cleared ? 'CLEARED' : 'CREATED';
            }
            Db::name(self::AUDIT_TABLE)->insert([
                'tenant_id' => $scope['tenant_id'], 'target_id' => $targetId, 'store_id' => $storeId,
                'target_year' => $year, 'target_month' => $month, 'action' => $action,
                'idempotency_key' => $idempotency, 'before_amount_cents' => $beforeAmount,
                'after_amount_cents' => $amount, 'before_is_cleared' => (int)$beforeCleared,
                'after_is_cleared' => (int)$cleared, 'before_version' => $beforeVersion, 'after_version' => $version,
                'operator_id' => $operatorId, 'operator_name_snapshot' => $operatorName, 'occurred_at' => $now,
            ]);
            $row = Db::name(self::TARGET_TABLE)->where('id', $targetId)->find();
            if (!is_array($row)) throw new \RuntimeException('看板目标保存后读取失败');
            return $this->projection($row, false);
        });
    }

    /** @return array<int,array{store_id:int,target_amount_cents:int}> */
    public function totals(array $context, int $year, array $months): array
    {
        $scope = $this->scope($context);
        $year = $this->year($year);
        $months = array_values(array_unique(array_filter(array_map('intval', $months), static function (int $month): bool {
            return $month >= 1 && $month <= 12;
        })));
        if ($months === [] || $scope['store_ids'] === []) return [];
        $rows = Db::name(self::TARGET_TABLE)->where('tenant_id', $scope['tenant_id'])->where('target_year', $year)
            ->whereIn('target_month', $months)->whereIn('store_id', $scope['store_ids'])->where('is_cleared', 0)
            ->fieldRaw('store_id,COALESCE(SUM(target_amount_cents),0) AS target_amount_cents')->group('store_id')->select()->toArray();
        $totals = [];
        foreach ($rows as $row) $totals[(int)$row['store_id']] = (int)$row['target_amount_cents'];
        return $totals;
    }

    private function scope(array $context): array
    {
        $tenant = $this->token($context['tenant_id'] ?? '', 'tenant_id', 32);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($context['store_ids'] ?? [])))));
        sort($ids);
        if ($ids === []) throw new \InvalidArgumentException('当前账号没有可查看的门店范围');
        return ['tenant_id' => $tenant, 'store_ids' => $ids];
    }

    /** @return array<int,string> */
    private function stores(array $ids): array
    {
        $rows = Db::name('system_store')->whereIn('id', $ids)->where('is_del', 0)->where('name', '<>', '总部')
            ->order('id', 'asc')->column('name', 'id');
        $out = [];
        foreach ($ids as $id) if (isset($rows[$id])) $out[$id] = (string)$rows[$id];
        return $out;
    }

    private function year(int $year): int
    {
        if ($year < 2000 || $year > 2100) throw new \InvalidArgumentException('目标年度无效');
        return $year;
    }

    /** @return array{amount:int,cleared:bool} */
    private function amount($value): array
    {
        if (!is_int($value) && !is_string($value) && !is_float($value)) throw new \InvalidArgumentException('目标金额必须为整数分');
        $text = trim((string)$value);
        if ($text === '') return ['amount' => 0, 'cleared' => true];
        if (preg_match('/^-?\d+$/D', $text) !== 1) throw new \InvalidArgumentException('目标金额必须为整数分');
        $amount = (int)$text;
        if ($amount < 0) throw new \InvalidArgumentException('目标金额不能小于 0');
        return ['amount' => $amount, 'cleared' => false];
    }

    private function token($value, string $field, int $max): string
    {
        $value = trim((string)$value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $max) throw new \InvalidArgumentException($field . '无效');
        return $value;
    }

    private function subjectKey(int $year, int $storeId, int $month): string
    {
        return sprintf('gd:%d:store:%d:month:%02d', $year, $storeId, $month);
    }

    private function projection(array $row, bool $replayed): array
    {
        $cleared = (int)($row['is_cleared'] ?? 0) === 1;
        return [
            'store_id' => (int)$row['store_id'], 'year' => (int)$row['target_year'], 'month' => (int)$row['target_month'],
            'target_amount_cents' => $cleared ? '' : (int)$row['target_amount_cents'], 'is_cleared' => $cleared,
            'expected_version' => (int)$row['version'],
            'subject_key' => $this->subjectKey((int)$row['target_year'], (int)$row['store_id'], (int)$row['target_month']),
            'replayed' => $replayed, 'updated_at' => (int)$row['updated_at'],
        ];
    }

    // Compatibility wrappers for the dashboard reader while it moves to the explicit names.
    public function yearTargets(array $context, int $year): array
    {
        return $this->readYear($context, $year);
    }

    /** @return array<string,mixed> */
    public function save(array $context, array $input): array
    {
        return $this->saveMonthly($context, $input);
    }
}
