<?php
declare(strict_types=1);
namespace app\services\store\roster;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices;
use think\facade\Db;
use think\exception\ValidateException;

/** 员工排班唯一读写服务。门店与平台只在控制器解析身份及范围，业务处理完全相同。 */
final class StaffRosterServices
{
    private function people(int $storeId, string $keyword = '')
    {
        $q = Db::name('system_store_staff')->where('store_id', $storeId)->where('status', 1)->where('is_del', 0);
        if ($keyword !== '') $q->whereLike('staff_name|phone', '%' . $keyword . '%');
        return $q;
    }

    public static function fingerprint(array $rows): string
    {
        return hash('sha256', json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function month(string $month): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month) || date('Y-m', strtotime($month . '-01')) !== $month) throw new ValidateException('请选择有效月份。');
        return [$month . '-01', date('Y-m-t', strtotime($month . '-01'))];
    }

    public function query(int $storeId, array $input, bool $export = false): array
    {
        [$from, $to] = $this->month((string)($input['month'] ?? date('Y-m')));
        $keyword = mb_substr(trim((string)($input['keyword'] ?? '')), 0, 64);
        $total = (int)$this->people($storeId, $keyword)->count();
        $pages = max(1, (int)ceil($total / 20)); $page = min($pages, max(1, (int)($input['page'] ?? 1)));
        $q = $this->people($storeId, $keyword)->field('id,employee_id,staff_name,phone')->order('id asc');
        if (!$export) $q->page($page, 20);
        $people = $q->select()->toArray(); $ids = array_column($people, 'id');
        $rows = $ids ? Db::name('store_staff_schedule')->where('store_id', $storeId)->whereIn('staff_id', $ids)->whereBetween('schedule_date', [$from, $to])->order('id asc')->select()->toArray() : [];
        $shifts = Db::name('store_staff_shift')->whereIn('store_id', [0, $storeId])->where('is_del', 0)->order('sort desc,id asc')->select()->toArray();
        foreach ($shifts as &$shift) { $shift['fingerprint'] = self::fingerprint([$shift]); $shift['editable'] = (int)$shift['store_id'] === $storeId; } unset($shift);
        $referencedIds = array_values(array_unique(array_filter(array_column($rows, 'shift_id'))));
        $referenced = $referencedIds ? array_column(Db::name('store_staff_shift')->whereIn('id', $referencedIds)->select()->toArray(), null, 'id') : [];
        $map = [];
        foreach ($rows as $row) $map[$row['staff_id']][$row['schedule_date']][] = $row;
        $dates = []; for ($ts = strtotime($from); date('Y-m-d', $ts) <= $to; $ts = strtotime('+1 day', $ts)) $dates[] = date('Y-m-d', $ts);
        foreach ($people as &$person) {
            $person['days'] = [];
            foreach ($dates as $date) {
                $day = $map[$person['id']][$date] ?? [];
                $fingerprint = self::fingerprint($day);
                foreach ($day as &$entry) {
                    $shift = $referenced[$entry['shift_id']] ?? [];
                    // 展示快照与并发指纹分离；旧引用仍可读已停用模板，但不产生可编辑的第二份排班。
                    $entry['display_name'] = $entry['shift_name_snapshot'] ?: ($shift['name'] ?? '上班');
                    $entry['display_start'] = $entry['start_time'] ?: ((int)$entry['schedule_type'] === 1 ? ($shift['start_time'] ?? '') : '');
                    $entry['display_end'] = $entry['end_time'] ?: ((int)$entry['schedule_type'] === 1 ? ($shift['end_time'] ?? '') : '');
                }
                unset($entry);
                $person['days'][$date] = ['rows' => $day, 'fingerprint' => $fingerprint];
            }
        } unset($person);
        return ['enabled' => StaffRosterSwitch::enabled(), 'staff' => $people, 'dates' => $dates, 'shifts' => $shifts, 'page' => $page, 'pages' => $pages, 'total' => $total, 'pageSize' => 20];
    }

    /** 导出只展开后端命中的员工与日期，不让浏览器补查或扩大组织范围。 */
    public function exportRows(int $storeId, array $input): array
    {
        $data = $this->query($storeId, $input, ($input['range'] ?? 'all') !== 'page'); $rows = [];
        foreach ($data['staff'] as $person) foreach ($person['days'] as $date => $day) {
            if (!$day['rows']) $rows[] = [$person['staff_name'], $person['phone'], $date, '未排班', '', '', ''];
            foreach ($day['rows'] as $row) {
                $rows[] = [$person['staff_name'], $person['phone'], $date, (int)$row['schedule_type'] === 1 ? '上班' : '休息', (int)$row['schedule_type'] === 1 ? $row['display_name'] : '', $row['display_start'], $row['display_end']];
            }
        }
        return ['columns' => ['员工', '手机号码', '日期', '安排', '班次', '开始时间', '结束时间'], 'rows' => $rows];
    }

    /** 所有写入携带同一请求令牌重试。store/actor 来自登录态，不接受正文指定身份。 */
    public function command(int $storeId, array $actor, string $action, array $input): array
    {
        $token = (string)($input['request_token'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9-]{16,64}$/', $token)) throw new ValidateException('请求标识无效，请刷新后重试。');
        $hash = self::fingerprint([$action, $input]);
        return Db::transaction(function () use ($storeId, $actor, $action, $input, $token, $hash): array {
            StaffRosterSwitch::enabled();
            // 同店命令先锁门店，再以固定顺序锁员工；预约只锁员工，不反向索取门店锁。
            if (!Db::name('system_store')->where('id', $storeId)->where('is_del', 0)->lock(true)->find()) throw new ValidateException('门店不存在。');
            $key = ['store_id' => $storeId, 'actor_type' => $actor['type'], 'actor_id' => (int)$actor['id'], 'request_token' => $token];
            $receipt = Db::name('staff_roster_command')->where($key)->lock(true)->find();
            if ($receipt) {
                if (!hash_equals((string)$receipt['request_hash'], $hash)) throw new ValidateException('该请求已用于其他操作，请刷新后重试。');
                return json_decode((string)$receipt['result_json'], true);
            }
            if ($action === 'schedule') [$before, $after, $result] = $this->saveDays($storeId, $input);
            elseif ($action === 'shift' || $action === 'delete-shift') [$before, $after, $result] = $this->saveShift($storeId, $action, $input);
            else throw new ValidateException('排班操作不存在。');
            Db::name('staff_roster_command')->insert($key + ['request_hash' => $hash, 'action' => $action,
                'before_json' => json_encode($before, JSON_UNESCAPED_UNICODE), 'after_json' => json_encode($after, JSON_UNESCAPED_UNICODE),
                'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE), 'created_at' => time()]);
            return $result;
        });
    }

    private function clock(string $value): string
    {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::00)?$/', $value)) throw new ValidateException('请选择有效时间。');
        return substr($value, 0, 5);
    }

    private function saveDays(int $storeId, array $input): array
    {
        $ids = array_values(array_unique(array_map('intval', (array)($input['staff_ids'] ?? [])))); sort($ids);
        $dates = array_values(array_unique((array)($input['dates'] ?? []))); sort($dates);
        if (!$ids || count($ids) > 20 || !$dates || count($dates) > 31) throw new ValidateException('请选择员工和日期，每次最多20名员工、31天。');
        foreach ($dates as $date) if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || date('Y-m-d', strtotime($date)) !== $date) throw new ValidateException('排班日期无效。');
        foreach ($dates as $date) if (substr($date, 0, 7) !== substr($dates[0], 0, 7)) throw new ValidateException('每次请在同一个月份内安排排班。');
        $staff = StaffRosterAvailability::lock($ids);
        $byId = array_column($staff, null, 'id');
        foreach ($ids as $id) if (empty($byId[$id]) || (int)$byId[$id]['store_id'] !== $storeId || (int)$byId[$id]['status'] !== 1 || (int)$byId[$id]['is_del'] !== 0) throw new ValidateException('员工已离职或不属于当前门店，请刷新后重试。');
        $kind = (string)($input['kind'] ?? '');
        if (!in_array($kind, ['WORK', 'REST', 'CLEAR'], true)) throw new ValidateException('请选择上班或休息。');
        $template = [];
        if ($kind === 'WORK') {
            $shift = Db::name('store_staff_shift')->where('id', (int)($input['shift_id'] ?? 0))->whereIn('store_id', [0, $storeId])->where('is_del', 0)->lock(true)->find();
            if (!$shift) throw new ValidateException('请选择可用班次。');
            $start = $this->clock((string)$shift['start_time']); $end = $this->clock((string)$shift['end_time']);
            if ($start >= $end) throw new ValidateException('该班次时间无效，请先在班次管理中调整。');
            $template = ['shift_id' => (int)$shift['id'], 'schedule_type' => 1, 'start_time' => $start, 'end_time' => $end, 'shift_name_snapshot' => $shift['name'], 'breaks_snapshot' => $shift['break_periods']];
        } elseif ($kind === 'REST') {
            $start = !empty($input['partial']) ? $this->clock((string)($input['start_time'] ?? '')) : '';
            $end = !empty($input['partial']) ? $this->clock((string)($input['end_time'] ?? '')) : '';
            if ($start !== '' && $start >= $end) throw new ValidateException('休息结束时间须晚于开始时间。');
            $template = ['shift_id' => 0, 'schedule_type' => 0, 'start_time' => $start, 'end_time' => $end, 'shift_name_snapshot' => '', 'breaks_snapshot' => ''];
        }
        $before = Db::name('store_staff_schedule')->where('store_id', $storeId)->whereIn('staff_id', $ids)->whereIn('schedule_date', $dates)->order('id asc')->lock(true)->select()->toArray();
        $after = []; $expected = (array)($input['expected'] ?? []);
        foreach ($ids as $id) foreach ($dates as $date) {
            $day = array_values(array_filter($before, static fn($row) => (int)$row['staff_id'] === $id && $row['schedule_date'] === $date));
            if (!isset($expected[$id][$date]) || !hash_equals(self::fingerprint($day), (string)$expected[$id][$date])) throw new ValidateException('排班已被其他人修改，请刷新后重试。');
            // 部分休息叠加于原上班，不把当天班次误删；其他设置明确替换这个员工当天安排。
            if ($kind === 'REST' && !empty($input['partial'])) {
                foreach ($day as $row) if ((int)$row['schedule_type'] === 1) { unset($row['id']); $after[] = $row; }
            }
            if ($template) $after[] = $template + ['store_id' => $storeId, 'staff_id' => $id, 'schedule_date' => $date, 'mark' => '', 'roster_managed' => 1, 'add_time' => time(), 'update_time' => time()];
        }
        // 同一员工不能在两家门店同时上班；跨店任职已经按统一锁序锁定。
        if ($kind === 'WORK') foreach ($after as $work) {
            [$start, $end] = StaffRosterRules::range($work['schedule_date'], $work['start_time'], $work['end_time']);
            foreach ((new StaffRosterAvailability())->rows((int)$work['staff_id'], $work['schedule_date']) as $other) {
                if ((int)$other['store_id'] === $storeId || (int)$other['schedule_type'] !== 1) continue;
                [$a, $b] = StaffRosterRules::range($other['schedule_date'], $other['start_time'], $other['end_time']);
                if (StaffRosterRules::overlaps($start, $end, $a, $b)) throw new ValidateException('员工「' . $byId[$work['staff_id']]['staff_name'] . '」在其他门店已有同时间排班，请先调整。');
            }
        }
        $this->assertNoConflicts($storeId, $ids, $dates, $after, $byId);
        Db::name('store_staff_schedule')->where('store_id', $storeId)->whereIn('staff_id', $ids)->whereIn('schedule_date', $dates)->delete();
        foreach ($after as $row) Db::name('store_staff_schedule')->insert($row);
        return [$before, $after, ['message' => '排班已保存。']];
    }

    /** 只阻止使现有预约不可服务的调整；取消/完成预约不占时段，不会被误报。 */
    public function assertNoConflicts(int $storeId, array $ids, array $dates, array $after, array $staff): void
    {
        \app\services\cashier\v3\CashierV3TransactionGuard::assertInTransaction('staffRoster.conflicts');
        $from = strtotime($dates[0] . ' -1 day'); $to = strtotime(end($dates) . ' +2 days');
        $headers = StaffRosterAvailability::reservations($ids, (int)$from, (int)$to);
        if (!$headers) return;
        $reservationIds = array_column($headers, 'id'); $assigned = [];
        foreach (Db::name('cashier_v3_reservation_staff_schedule')->whereIn('reservation_id', $reservationIds)->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->lock(true)->select()->toArray() as $row) $assigned[$row['reservation_id']][(int)$row['staff_id']] = true;
        $lines = Db::name('cashier_v3_reservation_line')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('reservation_id', $reservationIds)->lock(true)->select()->toArray();
        $fallback = [];
        foreach ($lines as $line) foreach ((array)json_decode((string)$line['artisan_staff_ids_json'], true) as $sid) $fallback[$line['reservation_id']][(int)$sid] = true;
        $conflicts = []; $availability = new StaffRosterAvailability();
        foreach ($ids as $id) {
            $aliases = StaffRosterAvailability::aliases([$id]); $rows = [];
            foreach ($dates as $date) $rows = array_merge($rows, $availability->rows($id, $date), $availability->rows($id, date('Y-m-d', strtotime($date . ' +1 day'))));
            // 按主键去重后替换本次范围，其他门店及相邻日期原样参加冲突检查。
            $unique = []; foreach ($rows as $row) $unique[$row['id']] = $row;
            $rows = array_values(array_filter($unique, static fn($row) => !((int)$row['store_id'] === $storeId && (int)$row['staff_id'] === $id && in_array($row['schedule_date'], $dates, true))));
            $rows = array_merge($rows, array_values(array_filter($after, static fn($row) => (int)$row['staff_id'] === $id)));
            foreach ($headers as $header) {
                if (!array_intersect($aliases, array_keys($assigned[$header['id']] ?? $fallback[$header['id']] ?? []))) continue;
                $start = (int)$header['appointment_start_at']; $end = (int)$header['appointment_end_at'];
                // 无关日期的预约不阻止本次保存。
                $relevant = false; foreach ($dates as $date) if (StaffRosterRules::overlaps($start, $end, strtotime($date), strtotime($date . ' +1 day'))) $relevant = true;
                if (!$relevant || StaffRosterRules::allows($rows, (int)$header['store_id'], $start, $end)) continue;
                $conflicts[] = ['staff_name' => $staff[$id]['staff_name'], 'time' => date('Y-m-d H:i', $start) . '–' . date('H:i', $end),
                    'reservation_no' => (int)$header['store_id'] === $storeId ? (string)($header['reservation_no'] ?? $header['id']) : '其他门店预约'];
            }
        }
        if ($conflicts) throw new StaffRosterConflict($conflicts);
    }

    private function saveShift(int $storeId, string $action, array $input): array
    {
        $id = (int)($input['id'] ?? 0); $before = [];
        if ($id > 0) {
            $row = Db::name('store_staff_shift')->where('id', $id)->where('store_id', $storeId)->where('is_del', 0)->lock(true)->find();
            if (!$row) throw new ValidateException('班次不存在或不属于当前门店。');
            $before = [$row];
            if (!hash_equals(self::fingerprint($before), (string)($input['expected'] ?? ''))) throw new ValidateException('班次已被修改，请刷新后重试。');
            // 先冻结历史引用，再编辑/停用模板；删除模板绝不能删除排班。
            Db::name('store_staff_schedule')->where('shift_id', $id)->where('store_id', $storeId)->where('schedule_type', 1)->where('shift_name_snapshot', '')
                ->update(['shift_name_snapshot' => $row['name'], 'start_time' => $row['start_time'], 'end_time' => $row['end_time'], 'breaks_snapshot' => $row['break_periods']]);
        }
        if ($action === 'delete-shift') {
            if (!$id) throw new ValidateException('请选择班次。');
            Db::name('store_staff_shift')->where('id', $id)->update(['is_del' => 1, 'update_time' => time()]);
            return [$before, [], ['message' => '班次已删除，已有排班保留。']];
        }
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 64) throw new ValidateException('请填写64字以内的班次名称。');
        $start = $this->clock((string)($input['start_time'] ?? '')); $end = $this->clock((string)($input['end_time'] ?? ''));
        if ($start >= $end) throw new ValidateException('下班时间须晚于上班时间。');
        $save = ['store_id' => $storeId, 'name' => $name, 'start_time' => $start, 'end_time' => $end, 'update_time' => time()];
        if ($id) Db::name('store_staff_shift')->where('id', $id)->update($save);
        else $id = (int)Db::name('store_staff_shift')->insertGetId($save + ['is_del' => 0, 'sort' => 0, 'break_periods' => '', 'add_time' => time()]);
        return [$before, [$save + ['id' => $id]], ['message' => '班次已保存。', 'id' => $id]];
    }
}
