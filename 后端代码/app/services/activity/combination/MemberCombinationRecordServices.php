<?php
declare(strict_types=1);

namespace app\services\activity\combination;

use think\facade\Db;

/**
 * 会员端“我的拼团”只读查询。
 *
 * 权威事实来自 store_pink；调用方只能传当前登录会员 UID，不能用请求参数代替身份，
 * 从而保证列表、总数和详情入口始终限定在会员自己的参与记录内。
 */
final class MemberCombinationRecordServices
{
    private const ALLOWED_STATUS = ['all', 'pending', 'success', 'failed', 'refunded'];

    /**
     * 按会员参与记录分页返回拼团；一位会员参加一个团对应一条稳定记录。
     *
     * @return array{list: array, count: int}
     */
    public function getList(int $uid, string $status, int $page, int $limit): array
    {
        if ($uid <= 0) {
            return ['list' => [], 'count' => 0];
        }
        $status = in_array($status, self::ALLOWED_STATUS, true) ? $status : 'all';
        $page = max(1, $page);
        $limit = min(50, max(1, $limit));
        $now = time();

        $query = Db::name('store_pink')->alias('p')
            // k_id=0 的记录是团长；团员通过 k_id 回到同一团的根记录。
            ->join('store_pink g', 'g.id=IF(p.k_id=0,p.id,p.k_id)')
            ->leftJoin('store_combination c', 'c.id=g.cid')
            ->leftJoin('store_order o', 'o.id=p.order_id_key')
            ->leftJoin('system_store s', 's.id=o.store_id')
            ->where('p.uid', $uid)
            ->where('p.uid', '>', 0);
        $this->applyStatus($query, $status, $now);

        $count = (int)(clone $query)->count('p.id');
        $rows = $query
            ->field('p.id as participant_id,p.k_id,p.is_refund,p.add_time,g.id as group_id,g.cid as activity_id,g.people,g.status as group_status,g.stop_time,c.title,c.image,o.order_id,o.store_id,s.name as store_name')
            ->page($page, $limit)
            ->order('p.add_time desc,p.id desc')
            ->select()->toArray();

        $joinedCounts = $this->joinedCounts(array_column($rows, 'group_id'));
        foreach ($rows as &$row) {
            $groupId = (int)$row['group_id'];
            $joined = (int)($joinedCounts[$groupId] ?? 0);
            [$statusCode, $statusName] = $this->statusLabel($row, $now);
            $row = [
                'record_id' => (int)$row['participant_id'],
                'group_id' => $groupId,
                'activity_id' => (int)$row['activity_id'],
                'title' => (string)($row['title'] ?? ''),
                'image' => (string)($row['image'] ?? ''),
                'role' => (int)$row['k_id'] === 0 ? '团长' : '团员',
                'target_people' => (int)$row['people'],
                'joined_people' => $joined,
                'missing_people' => max(0, (int)$row['people'] - $joined),
                'status' => $statusCode,
                'status_name' => $statusName,
                'started_at' => (int)$row['add_time'] > 0 ? date('Y-m-d H:i:s', (int)$row['add_time']) : '',
                'expires_at' => (int)$row['stop_time'] > 0 ? date('Y-m-d H:i:s', (int)$row['stop_time']) : '',
                'order_no' => (string)($row['order_id'] ?? ''),
                'store_id' => (int)($row['store_id'] ?? 0),
                'store_name' => (string)($row['store_name'] ?? ''),
            ];
        }
        unset($row);

        return ['list' => $rows, 'count' => $count];
    }

    /** SQL 状态筛选与下方展示口径必须保持一致，避免分页总数和页面状态漂移。 */
    private function applyStatus($query, string $status, int $now): void
    {
        if ($status === 'refunded') {
            $query->where('p.is_refund', '<>', 0);
            return;
        }
        if ($status !== 'all') {
            $query->where('p.is_refund', 0);
        }
        if ($status === 'pending') {
            $query->where('g.status', 1)->where('g.stop_time', '>', $now);
        } elseif ($status === 'success') {
            $query->where('g.status', 2);
        } elseif ($status === 'failed') {
            $query->whereRaw('(g.status=3 OR (g.status=1 AND g.stop_time<=' . $now . '))');
        }
    }

    /** 一次聚合当前页团人数，虚拟补位属于已成团人数，退款成员不再占用名额。 */
    private function joinedCounts(array $groupIds): array
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
        if (!$groupIds) {
            return [];
        }
        $ids = implode(',', $groupIds);
        $rows = Db::name('store_pink')
            ->whereRaw('(id IN (' . $ids . ') OR k_id IN (' . $ids . '))')
            ->where('is_refund', 0)
            ->field('IF(k_id=0,id,k_id) as group_id,COUNT(*) as joined_count')
            ->group('group_id')
            ->select()->toArray();

        // ThinkPHP 的 column() 会重置 SELECT 字段，把 joined_count 聚合别名误当成数据库真实列。
        // 先保留完整聚合查询结果，再在内存中建立团 ID 到人数的映射，避免线上触发 1054。
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row['group_id']] = (int)$row['joined_count'];
        }
        return $counts;
    }

    /** 退款优先于团状态；到期未成团按失败展示，但查询不会改写历史事实。 */
    private function statusLabel(array $row, int $now): array
    {
        if ((int)$row['is_refund'] !== 0) {
            return ['refunded', '已退款'];
        }
        if ((int)$row['group_status'] === 2) {
            return ['success', '已成团'];
        }
        if ((int)$row['group_status'] === 3 || ((int)$row['group_status'] === 1 && (int)$row['stop_time'] <= $now)) {
            return ['failed', '未成团'];
        }
        return ['pending', '拼团中'];
    }
}
