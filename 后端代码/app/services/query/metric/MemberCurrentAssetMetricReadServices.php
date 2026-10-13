<?php
declare(strict_types=1);

namespace app\services\query\metric;

use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;

/**
 * Reads registered current member assets at the same authorized member grain.
 * Every call is bounded to one batch and runs inside the caller's consistent
 * report read transaction; missing authorities fail the whole calculation.
 */
final class MemberCurrentAssetMetricReadServices
{
    /**
     * @param array<int,int> $stores authenticated report stores
     * @param array<int,int> $memberIds already selected member IDs
     * @param array<int,string> $metricCodes registered current asset metrics
     * @return array<int,array<string,int>> exact cents by member and metric
     */
    public function values(string $tenantId, array $stores, array $memberIds, array $metricCodes): array
    {
        $memberIds = array_values(array_unique($memberIds));
        $stores = array_values(array_unique($stores));
        $metricCodes = array_values(array_unique($metricCodes));
        if ($tenantId === '' || !$stores || !$memberIds || count($memberIds) > 100 || !$metricCodes) {
            throw new MetricQueryContractException('METRIC_QUERY_SHAPE_UNAVAILABLE', '当前会员资产范围不可用。');
        }
        foreach ($memberIds as $id) if (!is_int($id) || $id < 1) {
            throw new MetricQueryContractException('METRIC_QUERY_SHAPE_UNAVAILABLE', '当前会员资产范围不可用。');
        }
        $components = [];
        foreach ($metricCodes as $code) {
            if (!is_string($code)) throw new MetricQueryContractException('METRIC_NOT_REGISTERED', '当前指标尚未注册。');
            $definition = MetricDefinitionRegistry::get($code);
            $strategy = $definition['reader_strategy'] ?? null;
            $parts = $strategy === 'member_current_rights_amount'
                ? ['rights'] : ($definition['source']['asset_components'] ?? null);
            if (!in_array($strategy, ['member_current_rights_amount', 'member_current_assets'], true)
                || !is_array($parts) || !$parts || array_diff($parts, ['balance', 'rights'])) {
                throw new MetricQueryContractException('METRIC_QUERY_SHAPE_UNAVAILABLE', '当前会员资产指标不可用。');
            }
            foreach ($parts as $part) $components[$part] = true;
        }

        $balance = isset($components['balance'])
            ? (new CashierV3MemberBalanceProvider())->readAiBalanceCents($memberIds, $stores) : [];
        $rights = [];
        if (isset($components['rights'])) {
            $summaries = (new AiMemberRightsAmountIndex())->summaryForMembers($tenantId, $stores, $memberIds);
            if ($summaries === null) {
                $rights = (new CashierV3MemberDetailQueryServices())
                    ->readAiRemainingProjectAmounts($memberIds, $tenantId, $stores);
            } else {
                foreach ($summaries as $id => $row) $rights[(int)$id] = $row['amount_cents'] ?? null;
            }
        }

        $values = [];
        foreach ($memberIds as $id) {
            if ((isset($components['balance']) && !is_int($balance[$id] ?? null))
                || (isset($components['rights']) && !is_int($rights[$id] ?? null))) {
                throw new MetricQueryContractException('METRIC_SOURCE_RESULT_INVALID', '当前会员资产读取不完整。');
            }
            foreach ($metricCodes as $code) {
                $definition = MetricDefinitionRegistry::get($code);
                $parts = $definition['reader_strategy'] === 'member_current_rights_amount'
                    ? ['rights'] : $definition['source']['asset_components'];
                $amount = 0;
                foreach ($parts as $part) {
                    $next = $part === 'balance' ? $balance[$id] : $rights[$id];
                    if ($next < 0 || $amount > PHP_INT_MAX - $next) {
                        throw new MetricQueryContractException('METRIC_SOURCE_RESULT_INVALID', '当前会员资产金额无效。');
                    }
                    $amount += $next;
                }
                $values[$id][$code] = $amount;
            }
        }
        return $values;
    }
}
