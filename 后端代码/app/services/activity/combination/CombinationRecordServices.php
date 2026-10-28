<?php
declare(strict_types=1);
namespace app\services\activity\combination;

use think\exception\ValidateException;
use think\facade\Db;

/** Participant grain is shared by detail and export. Virtual seats never impersonate paying members. */
class CombinationRecordServices
{
    public const HEADERS = ['门店名称','会员编号','会员姓名','手机号码','拼团编号','拼团名称','支付金额',
        '拼团总数','参加人数','状态','是否团长','支付时间','过期时间','订单号','序号','支付状态'];
    public const KEYS = ['store_name','member_no','member_name','phone','group_no','group_name','paid_amount',
        'target_people','joined_people','group_status','leader_role','paid_at','expires_at','order_id','participant_no','payment_status'];

    public function rows(int $groupId): array
    {
        $root = Db::name('store_pink')->where('id', $groupId)->where('k_id', 0)->find();
        if (!$root) throw new ValidateException('拼团不存在');
        $origin = Db::name('combination_group_origin')->where('group_id', $groupId)->find();
        $rows = Db::name('store_pink')->alias('p')
            ->leftJoin('store_order o', 'o.id=p.order_id_key')->leftJoin('user u', 'u.uid=p.uid')
            ->leftJoin('system_store s', 's.id=o.store_id')->leftJoin('combination_order_refund r', 'r.order_id=o.id')
            ->where('p.id|p.k_id', $groupId)->order('p.id')
            ->field('p.*,o.pay_price as paid_amount,o.real_name,o.user_phone,o.paid,o.pay_time,o.refund_status,o.trade_no,u.bar_code,u.real_name as member_real_name,u.phone as member_phone,u.delete_time,s.name as store_name,r.state as refund_state')
            ->select()->toArray();
        $joined = count(array_filter($rows, static function ($row) {
            return !(int)$row['is_virtual'] && (int)$row['paid'] === 1;
        }));
        // Old completed groups overwrote their deadline; do not invent one from today's edited activity settings.
        $expires = $origin ? (int)$origin['expires_at'] : ((int)$root['status'] !== 2 ? (int)$root['stop_time'] : 0);
        $activity = Db::name('store_combination')->where('id', $root['cid'])->value('title');
        foreach ($rows as &$row) {
            $row['member_no'] = (string)($row['bar_code'] ?? '');
            $row['member_name'] = (string)($row['real_name'] ?: ($row['member_real_name'] ?: $row['nickname']));
            $row['phone'] = (string)($row['user_phone'] ?: $row['member_phone']);
            $row['group_no'] = (string)$groupId;
            $row['group_name'] = (string)($origin['activity_name'] ?? $activity ?? '');
            $row['paid_amount'] = (int)$row['is_virtual'] ? 0 : (float)($row['paid_amount'] ?? 0);
            $row['target_people'] = (int)$root['people'];
            $row['joined_people'] = $joined;
            $row['group_status'] = [1=>'进行中',2=>'成功',3=>'失败'][(int)$root['status']] ?? '';
            $row['leader_role'] = (int)$row['id'] === $groupId ? '团长' : '团员';
            $row['paid_at'] = !empty($row['pay_time']) ? date('Y-m-d H:i:s', (int)$row['pay_time']) : '';
            $row['expires_at'] = $expires > 0 ? date('Y-m-d H:i:s', $expires) : '';
            $row['participant_no'] = (string)$row['id'];
            $row['payment_status'] = (int)$row['refund_status'] === 2 ? '已退款'
                : ((int)$row['refund_status'] === 1 ? '退款中' : ((int)$row['paid'] ? '已支付' : '未支付'));
        }
        unset($row);
        return $rows;
    }

    /** Exact 16-column user template. All real participants, including refunded/kicked rows, remain traceable. */
    public function export(int $groupId): array
    {
        $rows = array_values(array_filter($this->rows($groupId), static function ($row) { return !(int)$row['is_virtual']; }));
        $output = [];
        foreach ($rows as $row) $output[] = array_intersect_key($row, array_fill_keys(self::KEYS, true));
        return ['header'=>self::HEADERS,'filekey'=>self::KEYS,'export'=>$output,'filename'=>'拼团记录-'.$groupId];
    }

    /** Activity grain: export all its groups and real payment participants, including refunded history. */
    public function exportActivity(int $activityId): array
    {
        if ($activityId <= 0 || !Db::name('store_combination')->where('id', $activityId)->count())
            throw new ValidateException('拼团商品不存在');
        $records = [];
        $joined = [];
        $lastId = 0;
        do {
            // Chunk the authoritative participant table once; joins follow the promoted root, not a stale group ID.
            $batch = Db::name('store_pink')->alias('p')
                ->join('store_pink g', 'g.id=IF(p.k_id=0,p.id,p.k_id)')
                ->leftJoin('combination_group_origin og', 'og.group_id=g.id')
                ->leftJoin('store_combination a', 'a.id=g.cid')
                ->leftJoin('store_order o', 'o.id=p.order_id_key')
                ->leftJoin('user u', 'u.uid=p.uid')
                ->leftJoin('system_store s', 's.id=o.store_id')
                ->where('g.cid', $activityId)->where('p.is_virtual', 0)->where('p.id', '>', $lastId)
                ->order('p.id asc')->limit(500)
                ->field('p.*,g.id as root_id,g.people as target_people,g.status as root_status,g.stop_time as root_stop_time,og.activity_name as origin_activity_name,og.expires_at as origin_expires_at,a.title as current_activity_name,o.pay_price as paid_amount,o.real_name,o.user_phone,o.paid,o.pay_time,o.refund_status,u.bar_code,u.real_name as member_real_name,u.phone as member_phone,s.name as store_name')
                ->select()->toArray();
            foreach ($batch as $row) {
                $rootId = (int)$row['root_id'];
                if ((int)$row['paid'] === 1) $joined[$rootId] = ($joined[$rootId] ?? 0) + 1;
                $records[] = $row;
                $lastId = (int)$row['id'];
            }
        } while (count($batch) === 500);
        $output = [];
        foreach ($records as $row) {
            $rootId = (int)$row['root_id'];
            $expires = $row['origin_expires_at'] !== null ? (int)$row['origin_expires_at']
                : ((int)$row['root_status'] === 2 ? 0 : (int)$row['root_stop_time']);
            $row['member_no'] = (string)($row['bar_code'] ?? '');
            $row['member_name'] = (string)($row['real_name'] ?: ($row['member_real_name'] ?: $row['nickname']));
            $row['phone'] = (string)($row['user_phone'] ?: $row['member_phone']);
            $row['group_no'] = (string)$rootId;
            $row['group_name'] = (string)($row['origin_activity_name'] ?? $row['current_activity_name'] ?? '');
            $row['paid_amount'] = (float)($row['paid_amount'] ?? 0);
            $row['target_people'] = (int)$row['target_people'];
            $row['joined_people'] = $joined[$rootId] ?? 0;
            $row['group_status'] = [1=>'进行中',2=>'成功',3=>'失败'][(int)$row['root_status']] ?? '';
            $row['leader_role'] = (int)$row['id'] === $rootId ? '团长' : '团员';
            $row['paid_at'] = !empty($row['pay_time']) ? date('Y-m-d H:i:s', (int)$row['pay_time']) : '';
            $row['expires_at'] = $expires > 0 ? date('Y-m-d H:i:s', $expires) : '';
            $row['order_id'] = (string)$row['order_id'];
            $row['participant_no'] = (string)$row['id'];
            $row['payment_status'] = (int)$row['refund_status'] === 2 ? '已退款'
                : ((int)$row['refund_status'] === 1 ? '退款中' : ((int)$row['paid'] ? '已支付' : '未支付'));
            $output[] = array_intersect_key($row, array_fill_keys(self::KEYS, true));
        }
        return ['header'=>self::HEADERS,'filekey'=>self::KEYS,'export'=>$output,'filename'=>'拼团活动明细-'.$activityId];
    }
}
