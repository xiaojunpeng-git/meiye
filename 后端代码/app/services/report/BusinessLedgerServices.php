<?php
namespace app\services\report;

use app\services\BaseServices;
use think\facade\Db;

/** 第九阶段四类人工台账：独立于经营事实，仅保存人工输入及组织快照。 */
final class BusinessLedgerServices extends BaseServices
{
    public const TYPES = ['store_building', 'engineering_quality', 'engineering_repair', 'rent_renewal'];
    private const TABLES = [
        'store_building' => 'business_ledger_store_building',
        'engineering_quality' => 'business_ledger_engineering_quality',
        'engineering_repair' => 'business_ledger_engineering_repair',
        'rent_renewal' => 'business_ledger_rent_renewal',
    ];
    private const LABELS = [
        'store_building' => '建店明细', 'engineering_quality' => '工程质量',
        'engineering_repair' => '工程维修', 'rent_renewal' => '降租续签',
    ];
    private const FIELD_MAP = [
        'store_building' => ['province','branch_name','store_name','building_type','contract_date','license_date','design_start_date','design_end_date','bid_start_date','bid_end_date','construction_start_date','construction_end_date','acceptance_date','acquisition_note','recruitment_note','opening_date','building_duration','first_month_performance','second_month_performance','third_month_performance'],
        'engineering_quality' => ['branch_name','store_name','area','construction_start_date','construction_end_date','construction_duration','opening_date','building_duration','hard_decoration_cost','soft_decoration_cost','unit_cost','total_investment'],
        'engineering_repair' => ['report_date','reporter','repair_requirement','repair_start_date','repair_end_date','actual_duration','repair_cost'],
        'rent_renewal' => ['contract_start_date','contract_end_date','original_monthly_rent','original_annual_rent','rent_reduction_date','reduced_monthly_rent','reduced_annual_rent','monthly_reduction','annual_reduction'],
    ];
    private const MONEY_FIELDS = [
        'store_building' => ['first_month_performance','second_month_performance','third_month_performance'],
        'engineering_quality' => ['hard_decoration_cost','soft_decoration_cost','unit_cost','total_investment'],
        'engineering_repair' => ['repair_cost'],
        'rent_renewal' => ['original_monthly_rent','original_annual_rent','reduced_monthly_rent','reduced_annual_rent','monthly_reduction','annual_reduction'],
    ];
    private const FILTER_DATE_FIELDS = [
        'store_building' => 'contract_date',
        'engineering_quality' => 'construction_start_date',
        'engineering_repair' => 'report_date',
        'rent_renewal' => 'contract_end_date',
    ];

    public function catalog(): array
    {
        return array_map(static fn($type) => [
            'type' => $type, 'label' => self::LABELS[$type],
            'table_layout' => ['fixed' => true, 'page_size' => 20],
            'columns' => self::columns($type),
        ], self::TYPES);
    }

    public static function columns(string $type): array
    {
        $common = [['key' => 'id', 'label' => '记录编号', 'source' => '系统生成', 'editable' => false],
            ['key' => 'version', 'label' => '版本', 'source' => '系统生成', 'editable' => false]];
        $fields = [
            'store_building' => ['province' => '省', 'branch_name' => '分公司', 'store_name' => '门店', 'building_type' => '建店类型', 'contract_date' => '签约日期', 'license_date' => '营业执照办结日期', 'design_start_date' => '图纸设计开始日期', 'design_end_date' => '图纸设计结束日期', 'bid_start_date' => '工程比价开始日期', 'bid_end_date' => '工程比价结束日期', 'construction_start_date' => '工程施工开始日期', 'construction_end_date' => '工程施工结束日期', 'acceptance_date' => '验收日期', 'acquisition_note' => '拓客', 'recruitment_note' => '招聘', 'opening_date' => '开业日期', 'building_duration' => '建店时长', 'first_month_performance' => '首月业绩', 'second_month_performance' => '次月业绩', 'third_month_performance' => '第三个月业绩'],
            'engineering_quality' => ['branch_name' => '分公司', 'store_name' => '门店', 'area' => '面积', 'construction_start_date' => '施工开始时间', 'construction_end_date' => '施工结束时间', 'construction_duration' => '施工时长', 'opening_date' => '开业日期', 'building_duration' => '建店时长', 'hard_decoration_cost' => '硬装造价', 'soft_decoration_cost' => '软装造价', 'unit_cost' => '平米工程造价', 'total_investment' => '总投资金额'],
            'engineering_repair' => ['branch_name' => '分公司（系统）', 'store_name' => '门店（系统）', 'report_date' => '提报日期', 'reporter' => '提报人', 'repair_requirement' => '维修需求', 'repair_start_date' => '维修开始日期', 'repair_end_date' => '维修完成日期', 'actual_duration' => '实际完成时长', 'repair_cost' => '维修费用'],
            'rent_renewal' => ['branch_name' => '分公司（系统）', 'store_name' => '门店名称（系统）', 'contract_start_date' => '最新合同起始时间', 'contract_end_date' => '最新合同到期时间', 'original_monthly_rent' => '原月租管费', 'original_annual_rent' => '原年租管费', 'rent_reduction_date' => '降租完成时间', 'reduced_monthly_rent' => '降租后月租管费', 'reduced_annual_rent' => '降租后年租管费', 'monthly_reduction' => '月降租金额', 'annual_reduction' => '年降租金额'],
        ];
        foreach ($fields[$type] ?? [] as $key => $label) $common[] = ['key' => $key, 'label' => $label, 'source' => str_contains($key, 'system') || in_array($key, ['branch_name','store_name'], true) && in_array($type, ['engineering_repair','rent_renewal'], true) ? '系统组织架构' : '人工填写', 'editable' => !($type === 'engineering_repair' || $type === 'rent_renewal') || !in_array($key, ['branch_name','store_name'], true)];
        if ($type === 'rent_renewal') {
            $common[] = ['key' => 'contract_expiry_month', 'label' => '合同到期月份', 'source' => '合同到期时间', 'editable' => false];
            $common[] = ['key' => 'reminder_month', 'label' => '提醒月份', 'source' => '系统待办', 'editable' => false];
            $common[] = ['key' => 'todo_status', 'label' => '待办状态', 'source' => '系统待办', 'editable' => false];
        }
        return $common;
    }

    private function table(string $type): string
    {
        if (!isset(self::TABLES[$type])) throw new \InvalidArgumentException('台账类型无效');
        return self::TABLES[$type];
    }

    private function normalizeStores(array $storeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $storeIds)))); sort($ids); return $ids;
    }

    public function list(string $type, array $scope, array $input): array
    {
        $stores = $this->normalizeStores($scope['store_ids'] ?? []); if (!$stores) return $this->emptyResult($type, $input);
        $query = Db::name($this->table($type))->where('is_deleted', 0)->whereIn('store_id', $stores);
        $dateField = self::FILTER_DATE_FIELDS[$type];
        $startDate = trim((string)($input['start_date'] ?? ''));
        $endDate = trim((string)($input['end_date'] ?? ''));
        if ($startDate !== '') $query->where($dateField, '>=', $this->validatedDate($startDate, '开始日期'));
        if ($endDate !== '') $query->where($dateField, '<=', $this->validatedDate($endDate, '结束日期'));
        $keyword = trim((string)($input['keyword'] ?? '')); if ($keyword !== '') $query->where(function($q) use ($keyword, $type) { foreach (self::FIELD_MAP[$type] as $i => $field) { if ($i === 0) $q->whereLike($field, '%' . addcslashes($keyword, '%_') . '%'); else $q->whereOrLike($field, '%' . addcslashes($keyword, '%_') . '%'); } });
        $page = max(1, (int)($input['page'] ?? 1)); $limit = min(100, max(1, (int)($input['limit'] ?? 20)));
        if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) throw new \InvalidArgumentException('开始日期不能晚于结束日期');
        $total = (int)(clone $query)->count(); $rows = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $reminders = $type === 'rent_renewal' ? $this->syncRentReminders($stores) : [];
        foreach ($rows as &$row) { $data = json_decode((string)$row['data_json'], true) ?: []; unset($row['data_json']); $row = array_merge($row, $data); $this->formatMoneyFields($type, $row); }
        if ($type === 'rent_renewal') foreach ($rows as &$row) {
            $status = $reminders[(int)$row['id']] ?? null;
            $row['contract_expiry_month'] = !empty($row['contract_end_date']) ? substr((string)$row['contract_end_date'], 0, 7) : '';
            $row['reminder_month'] = $status['reminder_month'] ?? '';
            $row['todo_status'] = $status['status'] ?? (!empty($row['rent_reduction_date']) ? '已完成' : '未到提醒期');
        }
        return ['type' => $type, 'label' => self::LABELS[$type], 'columns' => self::columns($type), 'records' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit, 'summary' => ['total' => $total], 'data_as_of' => date('c'), 'metric_version' => 'ledger-v1'];
    }

    private function emptyResult(string $type, array $input): array
    { return ['type' => $type, 'label' => self::LABELS[$type], 'columns' => self::columns($type), 'records' => [], 'total' => 0, 'page' => max(1, (int)($input['page'] ?? 1)), 'limit' => min(100, max(1, (int)($input['limit'] ?? 20))), 'summary' => ['total' => 0], 'data_as_of' => date('c'), 'metric_version' => 'ledger-v1']; }

    public function save(string $type, array $scope, array $payload, array $actor): array
    {
        $table = $this->table($type); $stores = $this->normalizeStores($scope['store_ids'] ?? []);
        $id = (int)($payload['id'] ?? 0); $expected = (int)($payload['version'] ?? 0); $storeId = (int)($payload['store_id'] ?? ($scope['active_store_id'] ?? 0));
        if (!$stores || $storeId <= 0 || !in_array($storeId, $stores, true)) throw new \InvalidArgumentException('门店不在当前权限范围');
        $data = []; foreach (self::FIELD_MAP[$type] as $field) if (array_key_exists($field, $payload)) $data[$field] = $payload[$field];
        $data = $this->normalizeMoneyFields($type, $data);
        $this->validate($type, $data);
        $snapshot = $storeId > 0 ? $this->storeSnapshot($storeId) : ['org_id' => 0, 'branch_name' => '', 'store_name' => ''];
        if (in_array($type, ['engineering_repair', 'rent_renewal'], true)) { $data['branch_name'] = $snapshot['branch_name']; $data['store_name'] = $snapshot['store_name']; }
        $now = time(); $token = trim((string)($payload['request_token'] ?? '')); if ($token === '') $token = $type . ':' . ($id ?: 'new') . ':' . hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE));
        $replay = Db::name($table)->where('request_token', $token)->where('is_deleted', 0)->find(); if ($replay) return ['id' => (int)$replay['id'], 'version' => (int)$replay['version'], 'store_id' => (int)$replay['store_id'], 'branch_name' => (string)$replay['branch_name'], 'store_name' => (string)$replay['store_name'], 'replay' => true];
        Db::startTrans();
        try {
            if ($id > 0) {
                $row = Db::name($table)->where('id', $id)->where('is_deleted', 0)->lock(true)->find();
                if (!$row || !in_array((int)$row['store_id'], $stores, true)) throw new \InvalidArgumentException('记录不存在或无权限');
                if ($expected <= 0 || $expected !== (int)$row['version']) throw new \InvalidArgumentException('记录已被其他人修改，请刷新后重试');
                $version = $expected + 1; $update = array_merge($data, ['data_json' => json_encode($data, JSON_UNESCAPED_UNICODE), 'version' => $version, 'request_token' => $token, 'operator_id' => (int)($actor['id'] ?? 0), 'operator_name' => (string)($actor['name'] ?? ''), 'update_time' => $now]); Db::name($table)->where('id', $id)->update($update);
            } else { $version = 1; $insert = array_merge($data, ['store_id' => $storeId, 'org_id' => (int)$snapshot['org_id'], 'branch_name' => $type === 'engineering_repair' || $type === 'rent_renewal' ? $snapshot['branch_name'] : (string)($data['branch_name'] ?? ''), 'store_name' => $type === 'engineering_repair' || $type === 'rent_renewal' ? $snapshot['store_name'] : (string)($data['store_name'] ?? ''), 'data_json' => json_encode($data, JSON_UNESCAPED_UNICODE), 'version' => 1, 'request_token' => $token, 'operator_id' => (int)($actor['id'] ?? 0), 'operator_name' => (string)($actor['name'] ?? ''), 'is_deleted' => 0, 'add_time' => $now, 'update_time' => $now]); $id = (int)Db::name($table)->insertGetId($insert); }
            Db::name('business_ledger_audit')->insert(['ledger_type' => $type, 'record_id' => $id, 'action' => $expected ? 'update' : 'create', 'before_json' => isset($row) ? (string)$row['data_json'] : null, 'after_json' => json_encode($data, JSON_UNESCAPED_UNICODE), 'request_token' => $token, 'operator_id' => (int)($actor['id'] ?? 0), 'operator_name' => (string)($actor['name'] ?? ''), 'created_at' => $now]);
            Db::commit(); return ['id' => $id, 'version' => $version, 'store_id' => $storeId, 'branch_name' => $snapshot['branch_name'], 'store_name' => $snapshot['store_name']];
        } catch (\Throwable $e) { Db::rollback(); throw $e; }
    }

    private function validate(string $type, array $data): void
    {
        foreach ($data as $key => $value) { if (is_string($value) && mb_strlen($value) > 2000) throw new \InvalidArgumentException($key . '长度超限'); }
        foreach (self::FIELD_MAP[$type] as $field) {
            if (str_ends_with($field, '_date') && !empty($data[$field])) $this->validatedDate((string)$data[$field], $field);
        }
        if ($type === 'engineering_quality' && array_key_exists('area', $data) && $data['area'] !== '' && $data['area'] !== null && (!is_numeric($data['area']) || (float)$data['area'] < 0)) throw new \InvalidArgumentException('面积格式错误');
    }

    private function validatedDate(string $value, string $label): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException($label . '格式错误');
        return $value;
    }

    private function normalizeMoneyFields(string $type, array $data): array
    {
        foreach (self::MONEY_FIELDS[$type] ?? [] as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) { $data[$field] = null; continue; }
            if (!is_numeric($data[$field]) || preg_match('/^-/', (string)$data[$field])) throw new \InvalidArgumentException($field . '金额格式错误');
            $data[$field] = (int)round(((float)$data[$field]) * 100);
        }
        return $data;
    }

    private function formatMoneyFields(string $type, array &$row): void
    {
        foreach (self::MONEY_FIELDS[$type] ?? [] as $field) {
            if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') continue;
            $row[$field] = number_format(((int)$row[$field]) / 100, 2, '.', '');
        }
    }

    private function storeSnapshot(int $storeId): array
    {
        $store = Db::name('system_store')->where('id', $storeId)->where('is_del', 0)->field('id,name')->find(); if (!$store) throw new \InvalidArgumentException('门店不存在');
        $org = Db::name('organization_store')->alias('os')->leftJoin('organization o', 'o.id=os.org_id')->where('os.store_id', $storeId)->field('os.org_id,o.name')->find();
        return ['org_id' => (int)($org['org_id'] ?? 0), 'branch_name' => (string)($org['name'] ?? ''), 'store_name' => (string)$store['name']];
    }

    public function reminders(array $scope): array
    {
        $stores = $this->normalizeStores($scope['store_ids'] ?? []); if (!$stores) return [];
        $states = $this->syncRentReminders($stores); $out = [];
        foreach ($states as $recordId => $state) $out[] = array_merge(['id' => $recordId], $state);
        return $out;
    }

    /** Each due month has one persisted reminder, making retries safe across requests. */
    private function syncRentReminders(array $stores): array
    {
        $rows = Db::name(self::TABLES['rent_renewal'])->whereIn('store_id', $stores)->where('is_deleted', 0)->select()->toArray();
        $currentMonth = new \DateTimeImmutable('first day of this month');
        foreach ($rows as $row) {
            $data = json_decode((string)($row['data_json'] ?? ''), true) ?: [];
            $recordId = (int)$row['id'];
            if (!empty($data['rent_reduction_date'])) {
                Db::name('business_ledger_rent_reminder')->where('ledger_id', $recordId)->where('status', 'pending')->update(['status' => 'completed', 'update_time' => time()]);
                continue;
            }
            if (empty($data['contract_end_date'])) continue;
            try { $end = new \DateTimeImmutable((string)$data['contract_end_date']); } catch (\Throwable $e) { continue; }
            $monthsBeforeExpiry = ((int)$end->format('Y') - (int)$currentMonth->format('Y')) * 12 + (int)$end->format('m') - (int)$currentMonth->format('m');
            if ($monthsBeforeExpiry < 1 || $monthsBeforeExpiry > 4) continue;
            $month = $currentMonth->format('Y-m-d');
            $prefix = (string)(config('database.connections.mysql.prefix') ?: 'eb_');
            Db::execute(
                "INSERT IGNORE INTO `{$prefix}business_ledger_rent_reminder` (`ledger_id`,`store_id`,`reminder_month`,`months_before_expiry`,`status`,`add_time`,`update_time`) VALUES (?,?,?,?,?,?,?)",
                [$recordId, (int)$row['store_id'], $month, $monthsBeforeExpiry, 'pending', time(), time()]
            );
        }
        $reminders = Db::name('business_ledger_rent_reminder')->whereIn('store_id', $stores)->order('id', 'desc')->select()->toArray();
        $states = [];
        foreach ($reminders as $reminder) {
            $recordId = (int)$reminder['ledger_id'];
            if (!isset($states[$recordId])) $states[$recordId] = ['store_id' => (int)$reminder['store_id'], 'reminder_month' => (string)$reminder['reminder_month'], 'months_before_expiry' => (int)$reminder['months_before_expiry'], 'status' => (string)$reminder['status']];
        }
        return $states;
    }

    public function export(string $type, array $scope, array $input): array { $input['page'] = 1; $input['limit'] = 10000; $result = $this->list($type, $scope, $input); $path = tempnam(sys_get_temp_dir(), 'ledger_'); $fh = fopen($path, 'wb'); fwrite($fh, "\xEF\xBB\xBF"); fputcsv($fh, array_map(static fn($c) => $c['label'], $result['columns'])); foreach ($result['records'] as $row) { $line = []; foreach ($result['columns'] as $column) $line[] = $row[$column['key']] ?? ''; fputcsv($fh, $line); } fclose($fh); return ['path' => $path, 'filename' => self::LABELS[$type] . '-' . date('YmdHis') . '.csv']; }

    public function read(string $type, int $id, array $scope): array
    {
        $stores = $this->normalizeStores($scope['store_ids'] ?? []); $row = Db::name($this->table($type))->where('id', $id)->where('is_deleted', 0)->find();
        if (!$stores || !$row || !in_array((int)$row['store_id'], $stores, true)) throw new \InvalidArgumentException('记录不存在或无权限');
        $data = json_decode((string)$row['data_json'], true) ?: []; unset($row['data_json']); $result = array_merge($row, $data); $this->formatMoneyFields($type, $result); return $result;
    }

    public function void(string $type, int $id, int $version, array $scope, array $actor): array
    {
        $stores = $this->normalizeStores($scope['store_ids'] ?? []); $table = $this->table($type); Db::startTrans();
        try { $row = Db::name($table)->where('id', $id)->where('is_deleted', 0)->lock(true)->find(); if (!$stores || !$row || !in_array((int)$row['store_id'], $stores, true)) throw new \InvalidArgumentException('记录不存在或无权限'); if ($version <= 0 || $version !== (int)$row['version']) throw new \InvalidArgumentException('记录已被其他人修改，请刷新后重试'); $newVersion = $version + 1; Db::name($table)->where('id', $id)->update(['is_deleted' => 1, 'version' => $newVersion, 'operator_id' => (int)($actor['id'] ?? 0), 'operator_name' => (string)($actor['name'] ?? ''), 'update_time' => time()]); Db::name('business_ledger_audit')->insert(['ledger_type' => $type, 'record_id' => $id, 'action' => 'void', 'before_json' => (string)$row['data_json'], 'after_json' => null, 'request_token' => 'void:' . $id . ':' . $newVersion, 'operator_id' => (int)($actor['id'] ?? 0), 'operator_name' => (string)($actor['name'] ?? ''), 'created_at' => time()]); Db::commit(); return ['id' => $id, 'version' => $newVersion, 'status' => 'void']; } catch (\Throwable $e) { Db::rollback(); throw $e; }
    }
}
