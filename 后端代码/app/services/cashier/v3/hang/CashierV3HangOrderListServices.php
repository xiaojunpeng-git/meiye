<?php

namespace app\services\cashier\v3\hang;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\hang\authority\CashierV3HangOrderPlanV1;
use app\services\cashier\v3\hang\authority\ThinkPhpCashierV3HangOrderRepository;
use think\facade\Db;

/**
 * Read model for the new-contract hang list.
 *
 * The hang header is authoritative. Historical legacy hangs are intentionally
 * excluded: only rows written by the current V3 hang authority appear here.
 */
final class CashierV3HangOrderListServices
{
    public const CONTRACT_VERSION = 'cashier-v3-hang-order-list-v1';

    /** @return array<string,mixed> */
    public function query(
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $page = $this->page($payload['page'] ?? null);
        $pageSize = $this->pageSize($payload['pageSize'] ?? $payload['page_size'] ?? null);
        $keyword = trim((string)($payload['keyword'] ?? $payload['search'] ?? ''));
        $roomName = $this->queryText($payload, 'room_name');
        $status = trim((string)($payload['businessStatus'] ?? $payload['business_status'] ?? ''));
        $scope = trim((string)($payload['dataScope'] ?? $payload['data_scope'] ?? 'normal'));
        $allowed = [
            CashierV3HangOrderPlanV1::STATUS_PENDING_CHECKOUT,
            CashierV3HangOrderPlanV1::STATUS_SERVICE_IN_PROGRESS,
            'resumed_checkout',
        ];

        $query = Db::name(ThinkPhpCashierV3HangOrderRepository::HEADER_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operatorScope->storeId())
            ->whereIn('hang_status', $allowed);
        if ($scope !== 'all') {
            $query->whereIn('hang_status', [
                CashierV3HangOrderPlanV1::STATUS_PENDING_CHECKOUT,
                CashierV3HangOrderPlanV1::STATUS_SERVICE_IN_PROGRESS,
            ]);
        }
        if (in_array($status, $allowed, true)) {
            $query->where('hang_status', $status);
        }
        if ($keyword !== '') {
            $query->where(function ($subQuery) use ($keyword) {
                $subQuery->whereLike('hang_order_no', '%' . $keyword . '%')
                    ->whereOr('member_name_snapshot', 'like', '%' . $keyword . '%')
                    ->whereOr('operator_name_snapshot', 'like', '%' . $keyword . '%')
                    ->whereOr('room_name_snapshot', 'like', '%' . $keyword . '%');
            });
        }
        if ($roomName !== '') {
            $query->whereLike('room_name_snapshot', '%' . $roomName . '%');
        }

        $total = (int)(clone $query)->count();
        $rows = $query
            ->field([
                'hang_order_id', 'hang_order_no', 'hang_mode', 'hang_status', 'hang_version',
                'member_name_snapshot', 'operator_name_snapshot', 'line_count',
                'room_id', 'room_name_snapshot',
                'sale_amount_cents', 'entitlement_actual_amount_cents', 'occurred_at', 'recorded_at',
                'resume_contract_version', 'resume_workspace_id', 'checkout_request_id',
                'sales_order_id', 'resumed_at', 'settled_at',
            ])
            ->order('occurred_at desc,id desc')
            ->page($page, $pageSize)
            ->select();
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }

        $records = [];
        $publicVersions = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hangStatus = (string)($row['hang_status'] ?? '');
            $records[] = [
                'id' => (string)($row['hang_order_id'] ?? ''),
                'revision' => (int)($row['hang_version'] ?? 0),
                'hangOrderNo' => (string)($row['hang_order_no'] ?? ''),
                'hangAt' => $this->timeLabel((int)($row['occurred_at'] ?? $row['recorded_at'] ?? 0)),
                'memberName' => (string)($row['member_name_snapshot'] ?? ''),
                'phone' => '',
                'itemCount' => (int)($row['line_count'] ?? 0),
                'receivableAmount' => $this->amount(
                    (int)($row['sale_amount_cents'] ?? 0)
                    + (int)($row['entitlement_actual_amount_cents'] ?? 0)
                ),
                'operator' => (string)($row['operator_name_snapshot'] ?? ''),
                'roomName' => (int)($row['room_id'] ?? 0) > 0
                    ? (string)($row['room_name_snapshot'] ?? '')
                    : '',
                'orderNote' => '',
                'status' => $this->statusLabel($hangStatus),
                'statusCode' => $hangStatus,
                // 草稿在结账成功前都可提取；提单后标记为“已提单”，
                // 原记录保留给结账绑定，成功结账后才物理清理。
                'canResume' => in_array((string)($row['hang_mode'] ?? ''), [
                    CashierV3HangOrderPlanV1::MODE_NORMAL,
                    CashierV3HangOrderPlanV1::MODE_START_SERVICE,
                ], true)
                    && in_array((string)($row['hang_status'] ?? ''), [
                        CashierV3HangOrderPlanV1::STATUS_PENDING_CHECKOUT,
                        CashierV3HangOrderPlanV1::STATUS_SERVICE_IN_PROGRESS,
                    ], true)
                    && (int)($row['hang_version'] ?? 0) > 0,
            ];
            $publicVersions[] = [
                'kind' => CashierV3HangOrderVersionProvider::KIND,
                'id' => (string)($row['hang_order_id'] ?? ''),
                'version' => (int)($row['hang_version'] ?? 0),
            ];
        }

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'availability' => ['status' => 'active', 'dataLoaded' => true, 'businessFactsIncluded' => true],
            'statusOptions' => [
                ['value' => CashierV3HangOrderPlanV1::STATUS_PENDING_CHECKOUT, 'label' => '待结账'],
                ['value' => CashierV3HangOrderPlanV1::STATUS_SERVICE_IN_PROGRESS, 'label' => '服务中'],
                ['value' => 'resumed_checkout', 'label' => '已提单'],
            ],
            'records' => $records,
            'publicVersions' => $publicVersions,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'dataAsOf' => date('c'),
        ];
    }

    private function page($value): int
    {
        return max(1, min(100000, (int)$value ?: 1));
    }

    private function queryText(array $payload, string $field): string
    {
        $direct = trim((string)($payload[$field] ?? ''));
        if ($direct !== '') {
            return mb_substr($direct, 0, 128);
        }
        foreach ((array)($payload['topFilters'] ?? []) as $filter) {
            if (!is_array($filter) || (string)($filter['field'] ?? '') !== $field) {
                continue;
            }
            $value = trim((string)($filter['value'] ?? ''));
            if ($value !== '') {
                return mb_substr($value, 0, 128);
            }
        }
        return '';
    }

    private function pageSize($value): int
    {
        $size = (int)$value ?: 20;
        return max(1, min(100, $size));
    }

    private function amount(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private function timeLabel(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '';
    }

    private function statusLabel(string $status): string
    {
        $labels = [
            CashierV3HangOrderPlanV1::STATUS_PENDING_CHECKOUT => '待结账',
            CashierV3HangOrderPlanV1::STATUS_SERVICE_IN_PROGRESS => '服务中',
            'resumed_checkout' => '已提单',
        ];
        return $labels[$status] ?? '状态异常';
    }
}
