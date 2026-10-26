<?php

declare(strict_types=1);

namespace app\services\user;

use app\services\order\ValidCashOrderServices;
use think\facade\Db;

/**
 * 用户列表：现金消费、核销到店统计与筛选
 * 消费现金/次数从 store_order 统计（order_type: 0普通 1充值），取 cash_pay_price；核销订单(2)不计入
 */
class UserListStatServices
{
    /**
     * 列表筛选条件
     * @param mixed $model
     * @param array $where
     * @param string $userAlias 如 u.
     * @param int|null $storeId 门店后台限定本店
     * @return mixed
     */
    public static function applySearchFilters($model, array $where, string $userAlias = 'u.', ?int $storeId = null)
    {
        $uidField = $userAlias . 'uid';

        if (!empty($where['cash_consume_price']) && $where['cash_consume_price'] !== '-') {
            $range = self::parseRange($where['cash_consume_price']);
            if ($range) {
                [$min, $max] = $range;
                $uids = self::uidsInCashAmountRange($min, $max, $storeId);
                $model = $model->whereIn($uidField, $uids ?: [-1]);
            }
        }

        if (!empty($where['recent_consume_type'])) {
            $uids = self::uidsWithConsume($where, $storeId, true);
            if ($uids !== null) {
                $model = $model->whereIn($uidField, $uids ?: [-1]);
            }
        }

        if (!empty($where['no_consume_type'])) {
            $uids = self::uidsWithConsume($where, $storeId, false);
            if ($uids !== null && $uids) {
                $model = $model->whereNotIn($uidField, $uids);
            }
        }

        // 消费次数：与列表 cash_consume_count 一致，按有效消费订单数统计
        if (isset($where['pay_count']) && $where['pay_count'] !== '' && $where['pay_count'] !== '-') {
            $range = self::parseRange((string)$where['pay_count']);
            if ($range !== null) {
                [$min, $max] = $range;
                $uids = self::uidsInCashCountRange($min, $max, $storeId);
                $model = $model->whereIn($uidField, $uids ?: [-1]);
            }
        }

        if (isset($where['writeoff_count']) && $where['writeoff_count'] !== '' && $where['writeoff_count'] !== '-') {
            $range = self::parseRange((string)$where['writeoff_count']);
            if ($range !== null) {
                [$min, $max] = $range;
                $uids = self::uidsInWriteoffCountRange($min, $max, $storeId);
                $model = $model->whereIn($uidField, $uids ?: [-1]);
            }
        }

        if (!empty($where['recent_writeoff_type'])) {
            $uids = self::uidsWithWriteoff($where, $storeId, true);
            if ($uids !== null) {
                $model = $model->whereIn($uidField, $uids ?: [-1]);
            }
        }

        if (!empty($where['no_writeoff_type'])) {
            $uids = self::uidsWithWriteoff($where, $storeId, false);
            if ($uids !== null && $uids) {
                $model = $model->whereNotIn($uidField, $uids);
            }
        }

        return $model;
    }

    /**
     * @param array $uids
     * @param int|null $storeId
     * @return array
     */
    public static function getStatsByUids(array $uids, $storeIdOrIds = null, array $where = []): array
    {
        if (!$uids) {
            return [];
        }
        $result = [];
        foreach ($uids as $uid) {
            $result[(int)$uid] = [
                'cash_consume_amount' => '0.00',
                'cash_consume_count' => 0,
                'writeoff_count' => 0,
            ];
        }

        $storeIds = self::normalizeStoreIds($storeIdOrIds);
        $orderQuery = self::orderConsumeQuery($storeIds)->whereIn('uid', $uids);
        self::applySaleDateTimeFilter($orderQuery, $where);
        $cashExpr = ValidCashOrderServices::buildAmountExpr('o', 'o.cash_pay_price');
        $orderRows = $orderQuery
            ->field("uid, SUM({$cashExpr}) as cash_amount, COUNT(*) as cnt")
            ->group('uid')
            ->select()
            ->toArray();

        foreach ($orderRows as $row) {
            $uid = (int)$row['uid'];
            $result[$uid]['cash_consume_amount'] = number_format((float)($row['cash_amount'] ?? 0), 2, '.', '');
            $result[$uid]['cash_consume_count'] = (int)($row['cnt'] ?? 0);
        }

        $writeoffQuery = Db::name('store_order_writeoff')->whereIn('uid', $uids);
        if ($storeIds) {
            if (count($storeIds) === 1) {
                $writeoffQuery->where('relation_id', $storeIds[0]);
            } else {
                $writeoffQuery->whereIn('relation_id', $storeIds);
            }
        }
        $writeoffRows = $writeoffQuery
            ->field('uid, COUNT(*) as cnt')
            ->group('uid')
            ->select()
            ->toArray();
        foreach ($writeoffRows as $row) {
            $uid = (int)$row['uid'];
            $result[$uid]['writeoff_count'] = (int)($row['cnt'] ?? 0);
        }

        return $result;
    }

    /**
     * @param int|int[]|null $storeIdOrIds
     * @return int[]|null
     */
    protected static function normalizeStoreIds($storeIdOrIds): ?array
    {
        if ($storeIdOrIds === null || $storeIdOrIds === '' || $storeIdOrIds === []) {
            return null;
        }
        if (is_array($storeIdOrIds)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $storeIdOrIds))));
            return $ids ?: null;
        }
        $id = (int)$storeIdOrIds;
        return $id > 0 ? [$id] : null;
    }

    /**
     * 有效消费订单：普通订单 + 充值订单（不含核销 order_type=2）
     * @param int|int[]|null $storeIdOrIds
     * @return \think\db\Query
     */
    protected static function orderConsumeQuery($storeIdOrIds = null)
    {
        $query = Db::name('store_order')->alias('o')
            ->where('paid', 1)
            ->where('refund_status', 0)
            ->where('is_system_del', 0)
            ->whereIn('pid', [0, -2])
            ->whereIn('order_type', [0, 1]);
        ValidCashOrderServices::applyScope($query, 'o');
        ValidCashOrderServices::applyHasValidCash($query, 'o');
        $storeIds = self::normalizeStoreIds($storeIdOrIds);
        if ($storeIds) {
            if (count($storeIds) === 1) {
                $query->where('store_id', $storeIds[0]);
            } else {
                $query->whereIn('store_id', $storeIds);
            }
        }
        return $query;
    }

    protected static function parseRange(string $value): ?array
    {
        $value = trim($value);
        if ($value === '' || $value === '-') {
            return null;
        }
        $pos = strpos($value, '-');
        if ($pos === false) {
            return null;
        }
        $minPart = trim(substr($value, 0, $pos));
        $maxPart = trim(substr($value, $pos + 1));
        $min = $minPart === '' ? 0 : (float)$minPart;
        $max = $maxPart === '' ? 9999999999 : (float)$maxPart;
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }
        return [$min, $max];
    }

    protected static function uidsInCashAmountRange(float $min, float $max, ?int $storeId): array
    {
        $cashExpr = ValidCashOrderServices::buildAmountExpr('o', 'o.cash_pay_price');
        $rows = self::orderConsumeQuery($storeId)
            ->field("uid, SUM({$cashExpr}) as total")
            ->group('uid')
            ->select()
            ->toArray();
        return self::filterRowsByRange($rows, 'total', $min, $max, 'uid');
    }

    protected static function uidsInCashCountRange(float $min, float $max, ?int $storeId): array
    {
        $rows = self::orderConsumeQuery($storeId)
            ->field('uid, COUNT(*) as cnt')
            ->group('uid')
            ->select()
            ->toArray();
        return self::filterRowsByRange($rows, 'cnt', $min, $max, 'uid');
    }

    protected static function uidsInWriteoffCountRange(float $min, float $max, ?int $storeId): array
    {
        $query = Db::name('store_order_writeoff');
        if ($storeId) {
            $query->where('relation_id', $storeId);
        }
        $rows = $query->field('uid, COUNT(*) as cnt')->group('uid')->select()->toArray();
        return self::filterRowsByRange($rows, 'cnt', $min, $max, 'uid');
    }

    /**
     * 按聚合结果区间过滤（避免 having 别名在部分环境下不生效）
     */
    protected static function filterRowsByRange(array $rows, string $valueKey, float $min, float $max, string $uidKey = 'uid'): array
    {
        $uids = [];
        foreach ($rows as $row) {
            $val = (float)($row[$valueKey] ?? 0);
            if ($val >= $min && $val <= $max) {
                $uids[] = (int)$row[$uidKey];
            }
        }
        return $uids;
    }

    protected static function uidsWithConsume(array $where, ?int $storeId, bool $forRecent): ?array
    {
        $typeKey = $forRecent ? 'recent_consume_type' : 'no_consume_type';
        $daysKey = $forRecent ? 'recent_consume_days' : 'no_consume_days';
        $timeKey = $forRecent ? 'recent_consume_time' : 'no_consume_time';

        $type = $where[$typeKey] ?? '';
        if (!$type) {
            return null;
        }

        [$start, $end] = self::resolveTimeRange($type, (int)($where[$daysKey] ?? 0), (string)($where[$timeKey] ?? ''));
        if (!$start && !$end) {
            return null;
        }

        return self::queryConsumeUids($start, $end, $storeId);
    }

    protected static function uidsWithWriteoff(array $where, ?int $storeId, bool $forRecent): ?array
    {
        $typeKey = $forRecent ? 'recent_writeoff_type' : 'no_writeoff_type';
        $daysKey = $forRecent ? 'recent_writeoff_days' : 'no_writeoff_days';
        $timeKey = $forRecent ? 'recent_writeoff_time' : 'no_writeoff_time';

        $type = $where[$typeKey] ?? '';
        if (!$type) {
            return null;
        }

        [$start, $end] = self::resolveTimeRange($type, (int)($where[$daysKey] ?? 0), (string)($where[$timeKey] ?? ''));
        if (!$start && !$end) {
            return null;
        }

        $query = Db::name('store_order_writeoff');
        if ($storeId) {
            $query->where('relation_id', $storeId);
        }
        if ($start) {
            $query->where('add_time', '>=', $start);
        }
        if ($end) {
            $query->where('add_time', '<=', $end);
        }
        return array_values(array_unique(array_map('intval', $query->column('uid'))));
    }

    protected static function queryConsumeUids(int $start, int $end, ?int $storeId): array
    {
        $orderQuery = self::orderConsumeQuery($storeId);
        if ($start) {
            $orderQuery->where('pay_time', '>=', $start);
        }
        if ($end) {
            $orderQuery->where('pay_time', '<=', $end);
        }
        return array_values(array_unique(array_map('intval', $orderQuery->column('uid'))));
    }

    /**
     * @return array{0:int,1:int}
     */
    protected static function resolveTimeRange(string $type, int $days, string $timeRange): array
    {
        if ($type === 'days' && $days > 0) {
            return [time() - $days * 86400, time()];
        }
        if ($type === 'range' && $timeRange !== '' && strpos($timeRange, '-') !== false) {
            [$start, $end] = explode('-', $timeRange, 2);
            $startTs = $start ? strtotime(trim($start)) : 0;
            $endTs = $end ? strtotime(trim($end)) + 86399 : 0;
            return [$startTs ?: 0, $endTs ?: 0];
        }
        return [0, 0];
    }

    /**
     * 销售日期时间范围（仅影响消费现金金额/次数统计）
     * 与收银台销售日一致：pay_time 为空时用 add_time
     */
    protected static function applySaleDateTimeFilter($query, array $where): void
    {
        if (empty($where['sale_date_time']) || $where['sale_date_time'] === '-') {
            return;
        }
        [$start, $end] = self::parseSaleDateTimeRange((string)$where['sale_date_time']);
        $saleExpr = '(CASE WHEN pay_time > 0 THEN pay_time ELSE add_time END)';
        if ($start > 0) {
            $query->whereRaw("$saleExpr >= ?", [$start]);
        }
        if ($end > 0) {
            $query->whereRaw("$saleExpr <= ?", [$end]);
        }
    }

    /**
     * 销售日期时间范围（仅影响消费现金金额/次数统计）
     * 格式：2025/01/01 00:00:00~2025/01/31 23:59:59
     * @return array{0:int,1:int}
     */
    protected static function parseSaleDateTimeRange(string $timeRange): array
    {
        $timeRange = urldecode(trim($timeRange));
        if ($timeRange === '' || $timeRange === '-') {
            return [0, 0];
        }
        $timeRange = str_replace(['～', '—'], '~', $timeRange);
        $start = '';
        $end = '';
        if (strpos($timeRange, '~') !== false) {
            [$start, $end] = explode('~', $timeRange, 2);
        } elseif (preg_match('/^(.+?\d{1,2}:\d{2}:\d{2})\s*-\s*(.+)$/', $timeRange, $matches)) {
            $start = $matches[1];
            $end = $matches[2];
        } elseif (strpos($timeRange, ',') !== false) {
            [$start, $end] = explode(',', $timeRange, 2);
        } else {
            return [0, 0];
        }
        $startTs = self::parseDateTimeToTimestamp(trim($start));
        $endTs = self::parseDateTimeToTimestamp(trim($end));
        if ($endTs > 0 && preg_match('/(?:^|\s)00:00:00\s*$/', trim($end))) {
            $endTs = strtotime(date('Y-m-d 23:59:59', $endTs)) ?: $endTs;
        }
        return [$startTs, $endTs];
    }

    protected static function parseDateTimeToTimestamp(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        $value = preg_replace('/\.\d{3}Z?$/', '', $value);
        $value = str_replace('T', ' ', $value);
        $value = str_replace('/', '-', $value);
        $ts = strtotime($value);
        return $ts !== false ? (int)$ts : 0;
    }
}
