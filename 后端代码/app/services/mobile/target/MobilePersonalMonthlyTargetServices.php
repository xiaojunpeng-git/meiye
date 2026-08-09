<?php

declare(strict_types=1);

namespace app\services\mobile\target;

use app\services\mobile\protocol\MobileApiException;
use think\facade\Db;

/**
 * Mobile V3 personal monthly target aggregate.
 *
 * It intentionally does not read or write legacy store_target. A target is a
 * staff member's declared plan for one authorised store/month; actual metric
 * completion remains a future read from the unified V3 metric dictionary.
 */
final class MobilePersonalMonthlyTargetServices
{
    private const METRICS = [
        'SALES_PERFORMANCE' => ['label' => '销售业绩', 'unitCode' => 'CNY_FEN', 'displayUnit' => '元'],
        'CONSUMPTION_PERFORMANCE' => ['label' => '消耗业绩', 'unitCode' => 'CNY_FEN', 'displayUnit' => '元'],
        'SERVICE_VISITS' => ['label' => '服务客次', 'unitCode' => 'COUNT', 'displayUnit' => '次'],
    ];

    public function current(array $context): array
    {
        $monthKey = $this->currentMonth();
        $target = Db::name('mobile_personal_monthly_target')
            ->where('employee_id', (int)$context['employeeId'])
            ->where('staff_id', (int)$context['staffId'])
            ->where('store_id', (int)$context['storeId'])
            ->where('month_key', $monthKey)->where('state', 'ACTIVE')->find();

        return [
            'monthKey' => $monthKey,
            'metricCatalog' => $this->metricCatalog(),
            'personalTarget' => is_array($target) ? $this->targetProjection($target) : null,
            'teamSummary' => $this->teamSummary($context, $monthKey),
            'actualProgress' => [
                'state' => 'METRIC_INTERFACE_PENDING',
                'message' => '完成值将只从统一 V3 指标接口读取，当前不使用旧报表或前端计算。',
            ],
        ];
    }

    public function save(array $context, array $payload): array
    {
        $monthKey = $this->monthKey($payload['monthKey'] ?? '');
        if ($monthKey !== $this->currentMonth()) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '一期仅允许创建或修改本月个人目标。', 'monthKey');
        }
        $idempotencyKey = $this->opaque($payload['idempotencyKey'] ?? null, 'idempotencyKey');
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') $name = $this->defaultName($monthKey);
        if (mb_strlen($name, 'UTF-8') > 96) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '目标名称不能超过 96 个字符。', 'name');
        }
        $metrics = $this->metrics($payload['metrics'] ?? null);
        $requestHash = hash('sha256', json_encode([
            'monthKey' => $monthKey, 'name' => $name, 'metrics' => $metrics,
            'expectedVersion' => $payload['expectedVersion'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Db::transaction(function () use ($context, $monthKey, $idempotencyKey, $name, $metrics, $payload, $requestHash): array {
            $employeeId = (int)$context['employeeId'];
            $receipt = Db::name('mobile_personal_monthly_target_command')
                ->where('employee_id', $employeeId)->where('command_code', 'SAVE_CURRENT_MONTH')
                ->where('idempotency_key_hash', hash('sha256', $idempotencyKey))->lock(true)->find();
            if (is_array($receipt)) {
                if (!hash_equals((string)$receipt['request_hash'], $requestHash)) {
                    throw MobileApiException::protocol('IDEMPOTENCY_KEY_CONFLICT', '本次目标操作标识已用于其他内容。', 'idempotencyKey');
                }
                $replay = json_decode((string)$receipt['response_payload'], true);
                if (!is_array($replay)) {
                    throw MobileApiException::business('AUTH_REQUIRED', '目标操作回执损坏，请重新进入商家端。');
                }
                return $replay;
            }

            $where = [
                ['employee_id', '=', $employeeId], ['staff_id', '=', (int)$context['staffId']],
                ['store_id', '=', (int)$context['storeId']], ['month_key', '=', $monthKey], ['state', '=', 'ACTIVE'],
            ];
            $existing = Db::name('mobile_personal_monthly_target')->where($where)->lock(true)->find();
            $now = time();
            $created = !is_array($existing);
            if ($created) {
                $targetId = $this->uuid();
                try {
                    Db::name('mobile_personal_monthly_target')->insert([
                        'target_id' => $targetId, 'employee_id' => $employeeId, 'staff_id' => (int)$context['staffId'],
                        'store_id' => (int)$context['storeId'], 'organization_id' => (int)$context['organizationId'],
                        'month_key' => $monthKey, 'name' => $name, 'state' => 'ACTIVE', 'revision' => 1,
                        'created_by_employee_id' => $employeeId, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                } catch (\Throwable $exception) {
                    throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '本月个人目标已在另一台设备创建，请刷新后再修改。', 'expectedVersion');
                }
                $existing = Db::name('mobile_personal_monthly_target')->where('target_id', $targetId)->lock(true)->find();
                if (!is_array($existing)) throw MobileApiException::business('AUTH_REQUIRED', '个人目标创建后读取失败，请重试。');
            } else {
                $expectedVersion = (int)($payload['expectedVersion'] ?? 0);
                if ($expectedVersion <= 0 || $expectedVersion !== (int)$existing['revision']) {
                    throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '个人目标已更新，请刷新后再提交。', 'expectedVersion');
                }
                Db::name('mobile_personal_monthly_target')->where('id', (int)$existing['id'])->update([
                    'name' => $name, 'revision' => (int)$existing['revision'] + 1, 'updated_at' => $now,
                ]);
                $existing['name'] = $name;
                $existing['revision'] = (int)$existing['revision'] + 1;
                $existing['updated_at'] = $now;
            }

            $targetPrimaryId = (int)$existing['id'];
            foreach ($metrics as $metricCode => $value) {
                $definition = self::METRICS[$metricCode];
                Db::name('mobile_personal_monthly_target_line')->where('target_id', $targetPrimaryId)
                    ->where('metric_code', $metricCode)->delete();
                Db::name('mobile_personal_monthly_target_line')->insert([
                    'target_id' => $targetPrimaryId, 'metric_code' => $metricCode, 'unit_code' => $definition['unitCode'],
                    'target_value' => $value, 'metric_version' => 'v1', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $target = Db::name('mobile_personal_monthly_target')->where('id', $targetPrimaryId)->find();
            if (!is_array($target)) throw MobileApiException::business('AUTH_REQUIRED', '个人目标保存后读取失败，请重试。');
            $projection = [
                'monthKey' => $monthKey, 'metricCatalog' => $this->metricCatalog(),
                'personalTarget' => $this->targetProjection($target),
                'teamSummary' => $this->teamSummary($context, $monthKey),
                'actualProgress' => ['state' => 'METRIC_INTERFACE_PENDING', 'message' => '完成值待统一 V3 指标接口接入。'],
            ];
            Db::name('mobile_personal_monthly_target_audit')->insert([
                'target_id' => $targetPrimaryId, 'employee_id' => $employeeId, 'actor_employee_id' => $employeeId,
                'action' => $created ? 'CREATED' : 'UPDATED', 'revision' => (int)$target['revision'],
                'snapshot' => json_encode($projection['personalTarget'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'request_id' => (string)$context['requestMetadata']['requestId'], 'occurred_at' => $now, 'recorded_at' => $now,
            ]);
            Db::name('mobile_personal_monthly_target_command')->insert([
                'employee_id' => $employeeId, 'command_code' => 'SAVE_CURRENT_MONTH',
                'idempotency_key_hash' => hash('sha256', $idempotencyKey), 'request_hash' => $requestHash,
                'target_id' => $targetPrimaryId, 'state' => 'SUCCEEDED',
                'response_payload' => json_encode($projection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            return $projection;
        });
    }

    private function targetProjection(array $target): array
    {
        $values = array_fill_keys(array_keys(self::METRICS), 0);
        $lines = Db::name('mobile_personal_monthly_target_line')->where('target_id', (int)$target['id'])->select()->toArray();
        foreach ($lines as $line) {
            $code = (string)$line['metric_code'];
            if (array_key_exists($code, $values)) $values[$code] = (int)$line['target_value'];
        }
        return [
            'targetId' => (string)$target['target_id'], 'monthKey' => (string)$target['month_key'],
            'name' => (string)$target['name'], 'version' => (int)$target['revision'],
            'metrics' => $this->metricValues($values), 'updatedAt' => (int)$target['updated_at'],
        ];
    }

    private function teamSummary(array $context, string $monthKey): array
    {
        if ((string)$context['dataScopeMode'] !== 'STORES') {
            return ['available' => false, 'message' => '当前为本人数据范围，不展示团队汇总。'];
        }
        $storeIds = array_values(array_filter(array_map('intval', (array)$context['visibleStoreIds'])));
        if ($storeIds === []) return ['available' => false, 'message' => '当前没有可汇总的门店范围。'];
        $targets = Db::name('mobile_personal_monthly_target')->whereIn('store_id', $storeIds)
            ->where('month_key', $monthKey)->where('state', 'ACTIVE')->field('id,employee_id')->select()->toArray();
        $targetIds = array_values(array_filter(array_map(static function (array $row): int { return (int)$row['id']; }, $targets)));
        $values = array_fill_keys(array_keys(self::METRICS), 0);
        if ($targetIds !== []) {
            $lines = Db::name('mobile_personal_monthly_target_line')->whereIn('target_id', $targetIds)->select()->toArray();
            foreach ($lines as $line) {
                $code = (string)$line['metric_code'];
                if (array_key_exists($code, $values)) $values[$code] += (int)$line['target_value'];
            }
        }
        $employees = [];
        foreach ($targets as $target) $employees[(int)$target['employee_id']] = true;
        return [
            'available' => true, 'scope' => 'AUTHORIZED_STORES', 'employeeCount' => count($employees),
            'targetCount' => count($targets), 'metrics' => $this->metricValues($values),
        ];
    }

    private function metricCatalog(): array
    {
        $result = [];
        foreach (self::METRICS as $code => $definition) {
            $result[] = ['code' => $code, 'label' => $definition['label'], 'unitCode' => $definition['unitCode'], 'displayUnit' => $definition['displayUnit'], 'metricVersion' => 'v1'];
        }
        return $result;
    }

    private function metricValues(array $values): array
    {
        $result = [];
        foreach (self::METRICS as $code => $definition) {
            $result[] = ['code' => $code, 'label' => $definition['label'], 'unitCode' => $definition['unitCode'], 'displayUnit' => $definition['displayUnit'], 'targetValue' => (int)$values[$code]];
        }
        return $result;
    }

    private function metrics($input): array
    {
        if (!is_array($input)) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请填写三项个人目标。', 'metrics');
        $result = [];
        foreach (self::METRICS as $code => $_definition) {
            if (!array_key_exists($code, $input) || !is_int($input[$code]) || $input[$code] < 0) {
                throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '个人目标数值无效。', 'metrics.' . $code);
            }
            $result[$code] = $input[$code];
        }
        if (count($input) !== count(self::METRICS)) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '一期个人目标仅支持三项固定指标。', 'metrics');
        }
        return $result;
    }

    private function currentMonth(): string
    {
        return date('Y-m');
    }

    private function monthKey($value): string
    {
        $monthKey = trim((string)$value);
        if (!preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D', $monthKey)) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '目标月份无效。', 'monthKey');
        }
        return $monthKey;
    }

    private function defaultName(string $monthKey): string
    {
        return (int)substr($monthKey, 0, 4) . '年' . (int)substr($monthKey, 5, 2) . '月个人目标';
    }

    private function opaque($value, string $field): string
    {
        $text = trim((string)$value);
        if ($text === '' || strlen($text) > 128 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $text)) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '本次目标操作标识无效。', $field);
        }
        return $text;
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
