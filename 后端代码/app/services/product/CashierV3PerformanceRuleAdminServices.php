<?php

namespace app\services\product;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel;
use think\facade\Db;

/**
 * 平台商品编辑使用的 V3 项目业绩与人员默认值配置。
 *
 * 项目手工费和手艺人业绩分别保存；已配置的手艺人默认值参与
 * 本次人员分配与未手改金额的最终结算，不改变项目消耗业绩或历史事实。
 */
final class CashierV3PerformanceRuleAdminServices
{
    private const TABLE = 'cashier_v3_project_performance_rule';
    private const MAX_CENTS = 100000000000;

    public function read(int $projectId): array
    {
        $this->assertProject($projectId);
        $row = Db::name(self::TABLE)->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('project_id', $projectId)->find();
        if (!is_array($row)) {
            return $this->projection([
                'project_id' => $projectId,
                'consumption_mode' => CashierV3EntitlementCompletionKernel::PERFORMANCE_ACTUAL,
                'consumption_configured_unit_amount_cents' => 0,
                'labor_mode' => CashierV3EntitlementCompletionKernel::PERFORMANCE_CONFIGURED,
                'labor_configured_unit_amount_cents' => 0,
                'craftsman_default_unit_amount_cents' => null,
                'current_version' => 1,
            ]);
        }
        return $this->projection($row);
    }

    public function save(int $projectId, array $payload, int $operatorId): array
    {
        $this->assertProject($projectId);
        $consumptionMode = $this->mode($payload['consumption_mode'] ?? CashierV3EntitlementCompletionKernel::PERFORMANCE_ACTUAL);
        // 旧 labor 字段只供手工费读取；手艺人业绩改用独立的新字段。
        $laborMode = CashierV3EntitlementCompletionKernel::PERFORMANCE_CONFIGURED;
        $consumptionAmount = $this->moneyToCents($payload['consumption_configured_unit_amount'] ?? 0);
        $laborAmount = $this->moneyToCents($payload['labor_configured_unit_amount'] ?? 0);
        $hasCraftsmanDefault = array_key_exists('craftsman_default_unit_amount', $payload);
        $craftsmanDefault = $hasCraftsmanDefault
            ? $this->nullableCraftsmanDefaultCents($payload['craftsman_default_unit_amount']) : null;
        $now = time();
        return Db::transaction(function () use ($projectId, $consumptionMode, $laborMode, $consumptionAmount, $laborAmount, $hasCraftsmanDefault, $craftsmanDefault, $operatorId, $now): array {
            $query = Db::name(self::TABLE)->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->where('project_id', $projectId)->lock(true);
            $row = $query->find();
            $data = [
                'consumption_mode' => $consumptionMode,
                'consumption_configured_unit_amount_cents' => $consumptionAmount,
                'labor_mode' => $laborMode,
                'labor_configured_unit_amount_cents' => $laborAmount,
                'updated_at' => $now,
            ];
            // Older admin clients omit this field; only an explicit new value
            // (including 0) or an explicit clear changes the staff default.
            if ($hasCraftsmanDefault) {
                $data['craftsman_default_unit_amount_cents'] = $craftsmanDefault;
            }
            if (is_array($row)) {
                $data['current_version'] = (int)$row['current_version'] + 1;
                Db::name(self::TABLE)->where('id', (int)$row['id'])->update($data);
                $row = array_merge($row, $data);
            } else {
                $data += [
                    'tenant_id' => CashierV3ScopeResolver::TENANT_SCOPE_ID,
                    'project_id' => $projectId,
                    'current_version' => 1,
                    'created_at' => $now,
                ];
                $data['craftsman_default_unit_amount_cents'] = $craftsmanDefault;
                Db::name(self::TABLE)->insert($data);
                $row = $data;
            }
            return $this->projection($row);
        });
    }

    private function assertProject(int $projectId): void
    {
        $row = Db::name('store_product')->where('id', $projectId)->where('is_del', 0)
            ->where('product_type', 6)->field('id')->find();
        if (!is_array($row)) throw new \InvalidArgumentException('仅预约项目支持销售设置');
    }

    private function mode($value): string
    {
        $mode = trim((string)$value);
        if (!in_array($mode, [CashierV3EntitlementCompletionKernel::PERFORMANCE_ACTUAL, CashierV3EntitlementCompletionKernel::PERFORMANCE_CONFIGURED], true)) {
            throw new \InvalidArgumentException('业绩计算模式无效');
        }
        return $mode;
    }

    private function moneyToCents($value): int
    {
        $text = trim((string)$value);
        if ($text === '') $text = '0';
        // 允许接口回填的整数等价金额（例如 `13.00`），但仍拒绝真正的小数（例如 `13.5`）。
        if (!preg_match('/^(?:0|[1-9]\d*)(?:\.0+)?$/D', $text)) {
            throw new \InvalidArgumentException('手工费必须是非负整数');
        }
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $cents = ((int)$whole * 100) + (int)str_pad($fraction, 2, '0');
        if ($cents > self::MAX_CENTS) throw new \InvalidArgumentException('固定手工费超出允许范围');
        return $cents;
    }

    private function nullableCraftsmanDefaultCents($value): ?int
    {
        if ($value === null || trim((string)$value) === '') return null;
        $text = trim((string)$value);
        if (!preg_match('/^(?:0|[1-9]\d*)(?:\.0+)?$/D', $text)) {
            throw new \InvalidArgumentException('默认手艺人业绩必须是非负整数');
        }
        $whole = explode('.', $text, 2)[0];
        if (strlen($whole) > 10 || (strlen($whole) === 10 && strcmp($whole, '1000000000') > 0)) {
            throw new \InvalidArgumentException('默认手艺人业绩超出允许范围');
        }
        $cents = (int)$whole * 100;
        return $cents;
    }

    private function projection(array $row): array
    {
        return [
            'project_id' => (int)($row['project_id'] ?? 0),
            'consumption_mode' => (string)($row['consumption_mode'] ?? CashierV3EntitlementCompletionKernel::PERFORMANCE_ACTUAL),
            'consumption_configured_unit_amount' => $this->centsToMoney((int)($row['consumption_configured_unit_amount_cents'] ?? 0)),
            'labor_mode' => (string)($row['labor_mode'] ?? CashierV3EntitlementCompletionKernel::PERFORMANCE_CONFIGURED),
            'labor_configured_unit_amount' => $this->centsToMoney((int)($row['labor_configured_unit_amount_cents'] ?? 0)),
            'craftsman_default_unit_amount' => ($row['craftsman_default_unit_amount_cents'] ?? null) === null
                ? null : $this->centsToMoney((int)$row['craftsman_default_unit_amount_cents']),
            'version' => (int)($row['current_version'] ?? 1),
        ];
    }

    private function centsToMoney(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
