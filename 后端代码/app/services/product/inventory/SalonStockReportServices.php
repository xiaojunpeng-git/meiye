<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\BaseServices;
use mohe\traits\ServicesTrait;
use think\facade\Db;

/**
 * 院装领用/退回明细 + 统计（D5）
 *
 * 方案 A：直接聚合院装流水（store_salon_stock_usage / _detail）快照，
 * 不实时回查当前配方，配方后续修改不影响历史报表。
 */
class SalonStockReportServices extends BaseServices
{
    use ServicesTrait;

    public $statusName = [
        1 => '领用',
        2 => '退回',
    ];

    /**
     * 领用/退回明细列表
     *
     * @param array $where 过滤：store_id/status/start_time/end_time/consumable_product_id/writeoff_id
     * @param int $storeScope 门店端传自身 store_id，仅看本店
     */
    public function usageDetailList(array $where, int $storeScope = 0): array
    {
        [$page, $limit] = $this->getPageValue();
        $count = $this->buildDetailQuery($where, $storeScope)->count();
        $list = $this->buildDetailQuery($where, $storeScope)
            ->field('d.*, u.store_id, u.status, u.writeoff_id, u.add_time AS usage_time, u.recipe_snapshot, u.out_stock_order_id, u.in_stock_order_id')
            ->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })
            ->order('d.id desc')
            ->select()
            ->toArray();

        // 门店名不属于配方快照，可查（不影响历史耗材/规格名固化语义）
        $storeNames = $this->storeNameMap(array_column($list, 'store_id'));
        foreach ($list as &$row) {
            $snapshot = $this->decodeSnapshot($row['recipe_snapshot'] ?? '');
            // 优先取快照 lines 内固化的历史耗材名/规格名；缺失回退明细固化列；禁止查询当前商品覆盖
            $snapLine = $this->matchSnapshotLine($snapshot, (int)$row['consumable_product_id'], (string)($row['consumable_unique'] ?? ''));
            $row['status_name'] = $this->statusName[(int)$row['status']] ?? '';
            $row['consumable_name'] = (string)($snapLine['consumable_name'] ?? '') ?: (string)($row['consumable_name'] ?? '');
            $row['sku_name'] = (string)($snapLine['sku_name'] ?? '') ?: (string)($row['sku_name'] ?? '');
            $row['store_name'] = $storeNames[(int)$row['store_id']] ?? '';
            $row['project_name'] = (string)($snapshot['project_name'] ?? '');
            $row['project_sku_name'] = (string)($snapshot['project_sku_name'] ?? '');
            $row['project_unique'] = (string)($snapshot['project_unique'] ?? '');
            // 核销单号：直接暴露核销记录ID，便于前端定位单据
            $row['writeoff_id'] = (int)($row['writeoff_id'] ?? 0);
            $row['qty'] = $this->trimQty((string)($row['qty'] ?? '0'));
            $row['balance_stock'] = $this->trimQty((string)($row['balance_stock'] ?? '0'));
            $row['usage_time'] = !empty($row['usage_time']) ? date('Y-m-d H:i:s', (int)$row['usage_time']) : '';
            unset($row['recipe_snapshot']);
        }
        unset($row);
        return compact('list', 'count');
    }

    /**
     * 统计：按耗材聚合领用/退回/净消耗（数据库级分组 + 分页）
     *
     * 净消耗、领用/退回量与次数全部由 SQL 条件聚合，一次分页查询返回当前页；
     * 去重耗材总数用子查询统计。耗材名/规格名取明细固化列，禁止查询当前商品覆盖。
     */
    public function usageStatistics(array $where, int $storeScope = 0): array
    {
        // 去重耗材总数（子查询包裹分组）
        $countSub = $this->buildDetailQuery($where, $storeScope)
            ->field('d.consumable_product_id')
            ->group('d.consumable_product_id, d.consumable_unique, d.stock_unit')
            ->buildSql();
        $countRow = Db::query('SELECT COUNT(*) AS c FROM ' . $countSub . ' AS stat_tmp');
        $count = (int)($countRow[0]['c'] ?? 0);

        [$page, $limit] = $this->getPageValue();
        $takenExpr = 'SUM(CASE WHEN u.status = 1 THEN d.qty ELSE 0 END)';
        $returnedExpr = 'SUM(CASE WHEN u.status = 2 THEN d.qty ELSE 0 END)';
        $list = $this->buildDetailQuery($where, $storeScope)
            ->field(
                'd.consumable_product_id, d.consumable_unique, d.stock_unit'
                . ', MIN(d.consumable_name) AS consumable_name, MIN(d.sku_name) AS sku_name'
                . ', ' . $takenExpr . ' AS taken_qty'
                . ', ' . $returnedExpr . ' AS returned_qty'
                . ', SUM(CASE WHEN u.status = 1 THEN 1 ELSE 0 END) AS taken_count'
                . ', SUM(CASE WHEN u.status = 2 THEN 1 ELSE 0 END) AS returned_count'
                . ', (' . $takenExpr . ' - ' . $returnedExpr . ') AS net_qty'
            )
            ->group('d.consumable_product_id, d.consumable_unique, d.stock_unit')
            ->orderRaw('net_qty DESC, d.consumable_product_id ASC, d.consumable_unique ASC')
            ->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })
            ->select()
            ->toArray();

        foreach ($list as &$row) {
            $row['consumable_product_id'] = (int)$row['consumable_product_id'];
            $row['consumable_unique'] = (string)$row['consumable_unique'];
            $row['stock_unit'] = (string)$row['stock_unit'];
            $row['consumable_name'] = (string)($row['consumable_name'] ?? '');
            $row['sku_name'] = (string)($row['sku_name'] ?? '');
            $row['net_qty'] = $this->trimQty((string)($row['net_qty'] ?? '0'));
            $row['taken_qty'] = $this->trimQty((string)($row['taken_qty'] ?? '0'));
            $row['returned_qty'] = $this->trimQty((string)($row['returned_qty'] ?? '0'));
            $row['taken_count'] = (int)($row['taken_count'] ?? 0);
            $row['returned_count'] = (int)($row['returned_count'] ?? 0);
        }
        unset($row);
        return ['list' => $list, 'count' => $count];
    }

    /** 默认时间范围（天）：未传起止时间时兜底，避免全表扫描 */
    public const DEFAULT_RANGE_DAYS = 31;

    /**
     * 解析生效时间范围：未传起止则默认最近 N 天，返回 [startTs, endTs]
     */
    protected function resolveTimeRange(array $where): array
    {
        $hasStart = !empty($where['start_time']);
        $hasEnd = !empty($where['end_time']);
        $start = $hasStart ? (is_numeric($where['start_time']) ? (int)$where['start_time'] : (int)strtotime((string)$where['start_time'])) : 0;
        $end = $hasEnd ? (is_numeric($where['end_time']) ? (int)$where['end_time'] : (int)strtotime((string)$where['end_time'] . ' 23:59:59')) : 0;
        if (!$hasStart && !$hasEnd) {
            $end = time();
            $start = $end - self::DEFAULT_RANGE_DAYS * 86400;
        }
        return [$start, $end];
    }

    protected function buildDetailQuery(array $where, int $storeScope)
    {
        $query = Db::name('store_salon_stock_usage_detail')->alias('d')
            ->join('store_salon_stock_usage u', 'u.id = d.usage_id');
        if ($storeScope > 0) {
            $query->where('u.store_id', $storeScope);
        } elseif (isset($where['store_id']) && $where['store_id'] !== '' && (int)$where['store_id'] > 0) {
            $query->where('u.store_id', (int)$where['store_id']);
        }
        if (isset($where['status']) && $where['status'] !== '') {
            $query->where('u.status', (int)$where['status']);
        }
        if (isset($where['writeoff_id']) && $where['writeoff_id'] !== '' && (int)$where['writeoff_id'] > 0) {
            $query->where('u.writeoff_id', (int)$where['writeoff_id']);
        }
        if (isset($where['consumable_product_id']) && $where['consumable_product_id'] !== '' && (int)$where['consumable_product_id'] > 0) {
            $query->where('d.consumable_product_id', (int)$where['consumable_product_id']);
        }
        // 时间范围：未传起止则默认最近 N 天，避免全表扫描
        [$startTs, $endTs] = $this->resolveTimeRange($where);
        if ($startTs > 0) {
            $query->where('u.add_time', '>=', $startTs);
        }
        if ($endTs > 0) {
            $query->where('u.add_time', '<=', $endTs);
        }
        return $query;
    }

    protected function decodeSnapshot($json): array
    {
        if (is_array($json)) {
            return $json;
        }
        if (!is_string($json) || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * 从快照 lines 中按 (最终耗材商品ID, unique) 匹配对应固化名称行
     */
    protected function matchSnapshotLine(array $snapshot, int $consumableProductId, string $consumableUnique): array
    {
        $lines = $snapshot['lines'] ?? [];
        if (!is_array($lines)) {
            return [];
        }
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            if ((int)($line['consumable_product_id'] ?? 0) === $consumableProductId
                && (string)($line['consumable_unique'] ?? '') === $consumableUnique) {
                return $line;
            }
        }
        return [];
    }

    protected function storeNameMap(array $storeIds): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if (!$storeIds) {
            return [];
        }
        $rows = Db::name('system_store')->whereIn('id', $storeIds)->column('name', 'id');
        $map = [];
        foreach ($rows as $id => $name) {
            $map[(int)$id] = (string)$name;
        }
        return $map;
    }

    protected function trimQty(string $qty): string
    {
        $formatted = number_format((float)$qty, 4, '.', '');
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }
        return $formatted === '' ? '0' : $formatted;
    }
}
