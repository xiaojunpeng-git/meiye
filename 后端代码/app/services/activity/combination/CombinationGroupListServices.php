<?php
declare(strict_types=1);

namespace app\services\activity\combination;

use app\services\organization\EmployeeDataScopeServices;
use app\services\organization\OrganizationScopeService;
use think\exception\ValidateException;
use think\facade\Db;

/** One row is one group, owned by its leader's transaction store. Reads never change group/payment facts. */
class CombinationGroupListServices
{
    /** The new write route must not inherit the legacy middleware's unregistered-route default allow. */
    public function assertManagement(array $admin): void
    {
        if (empty($admin['id'])) throw new ValidateException('请重新登录');
        if (app()->make(EmployeeDataScopeServices::class)->isSuperAdmin($admin)) return;
        $grants = app()->make(\app\services\system\SystemRoleServices::class)
            ->getRolesByAuth((array)($admin['roles'] ?? []), 1);
        foreach ((array)$grants as $grant) {
            if (($grant['unique_auth'] ?? '') === 'marketing-store_combination-combina_list') return;
        }
        throw new ValidateException('无拼团记录管理权限');
    }

    /** Resolve server-side grants once; [] is deny-all and null is unrestricted, never interchangeable. */
    public function allowedStores(array $admin): ?array
    {
        if (empty($admin['id'])) throw new ValidateException('请重新登录');
        $employee = (int)($admin['employee_id'] ?? 0);
        $scope = app()->make(EmployeeDataScopeServices::class);
        $ids = $scope->resolveEffectiveStoreIds($employee, 0, $admin);
        if ((int)($admin['admin_type'] ?? 0) === 3) {
            $agent = app()->make(OrganizationScopeService::class)
                ->getResolvedStoreIdsByLegacyAgentId((int)($admin['relation_id'] ?? 0));
            $ids = $ids === null ? $agent : array_intersect($ids, $agent);
        }
        return $ids === null ? null : array_values(array_unique(array_map('intval', $ids)));
    }

    /** Picker options come from the same grant boundary as rows and exports. */
    public function options(array $admin): array
    {
        $ids = $this->allowedStores($admin);
        $stores = Db::name('system_store')->where('is_del', 0);
        if ($ids !== null) $stores->whereIn('id', $ids ?: [0]);
        $ids = array_map('intval', $stores->column('id'));
        $activities = $this->query([], $ids)->field('g.cid,a.title')->distinct(true)->order('g.cid desc')->select()->toArray();
        return ['tree' => app()->make(OrganizationScopeService::class)->buildPickerTree($ids),
            'activities' => $activities, 'allowed_store_ids' => $ids];
    }

    /** Scope applies to leader AND all participant orders before detail or a whole-group financial command. */
    public function assertGroup(int $id, array $admin, bool $allMembers = false): array
    {
        $ids = $this->allowedStores($admin);
        $root = $this->query(['group_id' => $id], $ids)->field('g.*')->find();
        if (!$root) throw new ValidateException('拼团不存在或无权访问');
        if ($ids !== null && $allMembers) {
            $orders = Db::name('store_pink')->alias('p')->leftJoin('store_order o', 'o.id=p.order_id_key')
                ->where('p.id|p.k_id', $id)->where('p.is_virtual', 0)->field('o.store_id')->select()->toArray();
            foreach ($orders as $order) if (!in_array((int)($order['store_id'] ?? 0), $ids, true))
                throw new ValidateException('团内有不在当前权限范围的订单');
        }
        return $root;
    }

    /** Page/count/export all execute this same SQL boundary; no browser-side filtering or count arithmetic. */
    protected function query(array $filters, ?array $allowed)
    {
        $query = Db::name('store_pink')->alias('g')->leftJoin('store_order o', 'o.id=g.order_id_key')
            ->leftJoin('user u', 'u.uid=g.uid')->leftJoin('system_store s', 's.id=o.store_id')
            ->leftJoin('store_combination a', 'a.id=g.cid')
            ->leftJoin('combination_group_origin og', 'og.group_id=g.id')->where('g.k_id', 0)->where('g.is_virtual', 0);
        if ($allowed !== null) $query->whereIn('o.store_id', $allowed ?: [0])->where('o.store_id', '>', 0);
        $selected = $filters['store_ids'] ?? [];
        if (is_string($selected)) $selected = $selected === '' ? [] : explode(',', $selected);
        if (!is_array($selected)) throw new ValidateException('门店筛选无效');
        foreach ($selected as $id) if (!ctype_digit((string)$id) || (int)$id <= 0) throw new ValidateException('门店筛选无效');
        if ($selected) $query->whereIn('o.store_id', array_map('intval', $selected));
        foreach (['cid' => 'g.cid', 'group_id' => 'g.id'] as $key => $field) {
            if (($filters[$key] ?? '') !== '') {
                if (!ctype_digit((string)$filters[$key]) || (int)$filters[$key] <= 0) throw new ValidateException('拼团编号无效');
                $query->where($field, (int)$filters[$key]);
            }
        }
        if (($filters['status'] ?? '') !== '') {
            if (!in_array((string)$filters['status'], ['1', '2', '3'], true)) throw new ValidateException('拼团状态无效');
            $query->where('g.status', (int)$filters['status']);
        }
        $keyword = trim((string)($filters['keyword'] ?? ''));
        if (mb_strlen($keyword) > 80) throw new ValidateException('搜索内容过长');
        if ($keyword !== '') {
            // ORM quoting/binding protects SQL; wildcards in names/phones remain literal text.
            $like = '%' . addcslashes($keyword, '\\%_') . '%';
            // Materialize unique matching group IDs once: multiple members must not multiply
            // rows/counts, and a missing legacy k_id index must not cause a full scan per group.
            // Virtual seats never identify customers; refunded real participation stays searchable.
            $members = Db::name('store_pink')->alias('search_member')
                ->leftJoin('user search_user', 'search_user.uid=search_member.uid')
                ->leftJoin('store_order search_order', 'search_order.id=search_member.order_id_key')
                ->field('search_member.k_id')->distinct(true)->where('search_member.k_id', '>', 0)
                ->where('search_member.is_virtual', 0)->where('search_member.uid', '>', 0)
                ->whereLike('search_user.real_name|search_user.nickname|search_member.nickname|search_order.real_name|search_user.phone|search_order.user_phone', $like)
                ->buildSql();
            $query->leftJoin([$members => 'matched_members'], 'matched_members.k_id=g.id')
                ->where(function ($q) use ($like) {
                    // Keep both OR branches inside the existing leader-store permission boundary.
                    $q->whereLike('u.real_name|u.nickname|g.nickname|o.real_name|u.phone|o.user_phone', $like)
                        ->whereNotNull('matched_members.k_id', 'OR');
                });
        }
        $range = (string)($filters['data'] ?? '');
        if ($range !== '') {
            if (!preg_match('~^(\d{4}/\d{2}/\d{2})-(\d{4}/\d{2}/\d{2})$~', $range, $match)) throw new ValidateException('时间范围无效');
            $start = strtotime($match[1] . ' 00:00:00'); $end = strtotime($match[2] . ' 23:59:59');
            if (!$start || !$end || $start > $end) throw new ValidateException('时间范围无效');
            $query->whereBetween('g.add_time', [$start, $end]);
        }
        return $query;
    }

    /** Original order snapshots own item names/prices; editable activity names are only a legacy fallback. */
    public function page(array $filters, array $admin, bool $export = false): array
    {
        $allowed = $this->allowedStores($admin);
        $query = $this->query($filters, $allowed);
        $count = (clone $query)->count('g.id');
        $limit = 20; $page = min(max(1, (int)($filters['page'] ?? 1)), max(1, (int)ceil($count / $limit)));
        if ($export && $count > 10000) throw new ValidateException('导出超过10000条，请缩小筛选范围');
        $rows = $query->field('g.*,s.name as store_name,u.real_name as user_name,u.phone as member_phone,u.user_type,u.adminid,o.real_name,o.user_phone,a.title,og.activity_name,og.expires_at')
            ->order('g.id desc')->page($export ? 1 : $page, $export ? max(1, $count) : $limit)->select()->toArray();
        $groupIds = array_column($rows, 'id'); $orderIds = array_column($rows, 'order_id_key');
        $counts = $groupIds ? Db::name('store_pink')->alias('p')->join('store_order paid_order', 'paid_order.id=p.order_id_key')
            ->where(function ($q) use ($groupIds) {
                $q->whereIn('p.id', $groupIds)->whereOr('p.k_id', 'in', $groupIds);
            })->where('p.is_virtual', 0)->where('p.is_refund', 0)->where('paid_order.paid', 1)->field('p.id,p.k_id')->select()->toArray() : [];
        $joined = [];
        foreach ($counts as $seat) { $id = (int)$seat['k_id'] ?: (int)$seat['id']; $joined[$id] = ($joined[$id] ?? 0) + 1; }
        $items = $orderIds ? Db::name('store_order_cart_info')->whereIn('oid', $orderIds)->field('oid,cart_info')->select()->toArray() : [];
        $names = [];
        foreach ($items as $item) {
            $snapshot = json_decode((string)$item['cart_info'], true) ?: [];
            $name = (string)($snapshot['productInfo']['store_name'] ?? $snapshot['productInfo']['title'] ?? '');
            if ($name !== '') $names[(int)$item['oid']][] = $name;
        }
        // The origin evidence distinguishes self-registration from admin-created H5 accounts;
        // it is existing immutable provenance, not the current spend/newcomer flag.
        $origins = $rows ? Db::name('cashier_v3_report_member_origin_evidence')->where('tenant_id', '0')
            ->where('evidence_status', 'PROVEN')
            ->whereIn('member_id', array_column($rows, 'uid'))->column('evidence_source_type', 'member_id') : [];
        foreach ($rows as &$row) {
            $row['leader_name'] = (string)($row['real_name'] ?: ($row['user_name'] ?: $row['nickname']));
            $row['phone'] = (string)($row['user_phone'] ?: $row['member_phone']);
            // Channel alone cannot distinguish admin-created H5 users from self-registration: leave ambiguous history explicit.
            $row['member_type'] = in_array($row['user_type'], ['cashier', 'import'], true) || (int)$row['adminid'] > 0
                ? '会员' : (($origins[(int)$row['uid']] ?? '') === 'USER_SET_INFO'
                    || in_array($row['user_type'], ['routine', 'wechat', 'app', 'pc'], true) ? '新客' : '待核实');
            $row['group_name'] = (string)($row['activity_name'] ?? $row['title'] ?? '');
            $row['product_name'] = implode('、', array_unique($names[(int)$row['order_id_key']] ?? []));
            $row['joined_people'] = $joined[(int)$row['id']] ?? 0;
            $row['deadline'] = $row['expires_at'] !== null ? (int)$row['expires_at'] : ((int)$row['status'] === 2 ? 0 : (int)$row['stop_time']);
            $row['status_name'] = [1 => '拼团中', 2 => '拼团成功', 3 => '拼团失败'][(int)$row['status']] ?? '未知';
        }
        unset($row);
        $columns = $this->columns();
        if ($export) return ['header' => array_column($columns, 'title'), 'filekey' => array_column($columns, 'key'),
            'filename' => '拼团列表', 'export' => array_map(static function ($row) use ($columns) {
                $row['deadline'] = $row['deadline'] ? date('Y-m-d H:i', $row['deadline']) : '';
                return array_intersect_key($row, array_fill_keys(array_column($columns, 'key'), true));
            }, $rows)];
        return ['list' => $rows, 'count' => $count, 'page' => $page, 'limit' => $limit, 'columns' => $columns,
            'table_layout' => ['fixed' => true], 'data_as_of' => date('c')];
    }

    /** Business meanings travel with columns so exports and the visible explanation cannot drift. */
    public function columns(): array
    {
        $specs = [
            ['门店名称','store_name',125,'团长开团订单的交易门店。'],
            ['团长','leader_name',80,'开团订单姓名，缺失时显示会员姓名或昵称。'], ['类型','member_type',70,'注册进入的新客户为新客；系统建档或导入为会员；历史来源不明显示待核实。'],
            ['手机号码','phone',120,'开团订单预留手机，缺失时读取团长档案。'], ['拼团名称','group_name',190,'开团时保存的活动名称，旧记录缺失时读取当前活动名称。'],
            ['品项名称','product_name',180,'团长原订单保存的品项名称；无快照不按现商品猜测。'], ['拼团单价','price',85,'开团记录保存的单价；展示整元，导出保留原精度。'],
            ['拼团总数','people',75,'该团成团所需人数。'], ['参加人数','joined_people',80,'当前团内未退款的真实成员数，不含虚拟名额。'],
            ['到期时间','deadline',140,'开团时保存的到期时间，旧已成团记录缺失时留空。'], ['状态','status_name',90,'拼团业务保存的当前状态。'],
        ];
        return array_map(static function ($s) { return ['title'=>$s[0], 'key'=>$s[1], 'minWidth'=>$s[2], 'source'=>$s[3],
            'fixed' => $s[1] === 'store_name' ? 'left' : '']; }, $specs);
    }
}
