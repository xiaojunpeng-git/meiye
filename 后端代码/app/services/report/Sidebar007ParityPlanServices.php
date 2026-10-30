<?php
declare(strict_types=1);

namespace app\services\report;

/**
 * 007 侧栏四表的 V3 迁移合同。这里只投影已获授权的门店和已核实的基础指标，
 * 不读取旧报表 SQL，也不把缺少事实的单元格伪装成零。正式 Provider 须在后端
 * 裁剪门店后提供同一统计期的 V3 基础指标，并负责导出与数据来源说明。
 */
final class Sidebar007ParityPlanServices
{
    private const ROWS = [
        'sales_data' => [
            '散客', '散客业绩', '新客', '新客业绩', '大众散客', '大众散客业绩',
            '大众新客', '大众新客业绩', '抖音散客', '抖音散客业绩',
            '抖音新客', '抖音新客业绩', '会员续卡', '会员续卡业绩',
            '售后客数', '售后业绩', '合作项目', '合作项目业绩', '总客数', '门店总业绩',
        ],
        'sales_analysis' => [
            '新客数', '新客总客数', '成交率', '流失新客数', '指定客数', '总客数', '指定率',
        ],
        'store_efficiency' => [
            '总消耗', '总客数', '客单价', '日均服务客数', '床位数',
            '床位日均数', '床位使用率', '员工数', '人均服务数', '人效使用率',
        ],
    ];

    private const PK_COLUMNS = [
        '门店', '本月目标', '本月底标', '本月业绩', '业绩分成款',
        '本月实际分成', '本月增长业绩', '完成率',
    ];

    /** 007 真人页面字段顺序；PK 是门店纵行，其余三张为指标纵行。 */
    public static function layout(string $report): array
    {
        if ($report === 'sales_pk') return ['orientation' => 'store_rows', 'columns' => self::PK_COLUMNS];
        if (!isset(self::ROWS[$report])) throw new \InvalidArgumentException('未登记该侧栏报表');
        return ['orientation' => 'metric_rows', 'rows' => self::ROWS[$report]];
    }

    /** 每个纵向指标的业务取值说明供“列名取值来源”使用，不暴露技术表名。 */
    public static function rowSources(string $report): array
    {
        $notes = [
            'sales_data' => [
                '非大众、非抖音等指定渠道的散客人数；会员同店同日去重，朋友逐笔计数。',
                '上述散客的有效现金销售业绩。',
                '非大众、非抖音等指定渠道中，符合原单实收门槛的新客人数。',
                '上述新客的有效现金销售业绩。',
                '大众来源的散客人数；会员同店同日去重，朋友逐笔计数。',
                '大众来源散客的有效现金销售业绩。',
                '大众来源且符合原单实收门槛的新客人数。',
                '大众来源新客的有效现金销售业绩。',
                '抖音来源的散客人数；会员同店同日去重，朋友逐笔计数。',
                '抖音来源散客的有效现金销售业绩。',
                '抖音来源且符合原单实收门槛的新客人数。',
                '抖音来源新客的有效现金销售业绩。',
                '会员续卡的有效成交客数。',
                '会员续卡的有效现金销售业绩，不含余额支付。',
                '售后服务的有效成交客数。',
                '售后服务的有效现金销售业绩。',
                '有合作方人员参与销售分配的有效成交客数。',
                '成交时人员类型为合作方的人员实际分配所得销售业绩。',
                '统计期间本店有效服务客数；同一会员同日去重，朋友逐笔计数。',
                '以上各客源类别有效现金销售业绩之和。',
            ],
            'sales_analysis' => [
                '符合原单实收门槛的新客成交人数。',
                '新客成交人数与散客人数之和。',
                '新客成交人数占新客总人数的百分比。',
                '新客总人数减去新客成交人数。',
                '统计期间本店指定服务人员的有效客户数。',
                '统计期间本店有效服务客数；同一会员同日去重，朋友逐笔计数。',
                '指定客数占有效服务客数的百分比。',
            ],
            'store_efficiency' => [
                '统计期间本店已完成的有效服务消耗金额。',
                '统计期间本店有效服务客数。',
                '总消耗除以总客数，按原页规则显示整元。',
                '总客数除以统计起止日的零点间隔天数，结果取整数。',
                '本店当前设置的床位数量。',
                '日均服务客数除以床位数，结果取整数。',
                '床位日均数除以每日七客的基准，换算百分比后取整数。',
                '统计期间有指定客服务的不同员工人数。',
                '日均服务客数除以员工数，结果取整数。',
                '人均服务数除以每人每日 4.5 客的基准，按原页规则截为整数。',
            ],
        ];
        if (!isset($notes[$report])) throw new \InvalidArgumentException('未登记该侧栏报表来源');
        $out=[];
        foreach ($notes[$report] as $index=>$note) $out[]=[
            'row_id'=>$report.':'.$index,
            'label'=>self::ROWS[$report][$index],
            'description'=>$note,
        ];
        return $out;
    }

    /**
     * 仅销售分析和门店效能可先做纯计算投影。$authorizedStoreIds 必须由后端权限
     * 服务给出，$stores/$baseByStore 只能是该范围的子集；浏览器不得传入这三项。
     * 基础值都是同一期、同一 V3 事实口径的非负整数；金额以分为单位。
     *
     * @param int[] $authorizedStoreIds
     * @param array<int,array{id:int,name:string}> $stores
     * @param array<int,array<string,int>> $baseByStore
     * @return array{columns:array,rows:array,gaps:array,complete:bool,days:int}
     */
    public static function projectMatrix(
        string $report,
        array $authorizedStoreIds,
        array $stores,
        array $baseByStore,
        string $startDate,
        string $endDate
    ): array {
        if (!in_array($report, ['sales_analysis', 'store_efficiency'], true)) {
            throw new \InvalidArgumentException('此报表尚无可验证的 V3 矩阵计算');
        }
        $days = self::legacyDayDivisor($startDate, $endDate);
        $allowed = array_fill_keys(array_map('intval', $authorizedStoreIds), true);
        if (!$allowed || isset($allowed[0])) throw new \InvalidArgumentException('门店授权范围为空或无效');
        $columns = [];
        $seen = [];
        foreach ($stores as $store) {
            $id = (int)($store['id'] ?? 0);
            if ($id < 1 || !isset($allowed[$id]) || isset($seen[$id]) || trim((string)($store['name'] ?? '')) === '') {
                throw new \InvalidArgumentException('门店列超出授权范围或身份不完整');
            }
            $seen[$id] = true;
            $columns[] = ['key' => 'store_' . $id, 'store_id' => $id, 'label' => (string)$store['name']];
        }
        foreach ($baseByStore as $id => $values) {
            if (!isset($seen[(int)$id]) || !is_array($values)) throw new \InvalidArgumentException('基础指标超出当前门店列');
        }
        $rows = [];
        $gaps = [];
        foreach (self::ROWS[$report] as $index => $label) {
            $row = ['row_id' => $report . ':' . $index, 'metric' => $label, 'unit' => self::unit($report, $index), 'cells' => []];
            foreach ($columns as $column) {
                $id = $column['store_id'];
                $value = self::metric($report, $index, $baseByStore[$id] ?? [], $days);
                $row['cells'][$column['key']] = $value;
                if ($value === null) {
                    $gaps[] = ['store_id' => $id, 'metric' => $label,
                        'reason' => '该指标缺少已核实的 V3 同口径基础事实'];
                }
            }
            $rows[] = $row;
        }
        return ['columns' => $columns, 'rows' => $rows, 'gaps' => $gaps, 'complete' => !$gaps, 'days' => $days];
    }

    /** 007 按两个日期零点之差计日，10 月 1 日至 30 日的除数为 29。 */
    private static function legacyDayDivisor(string $start, string $end): int
    {
        $tz = new \DateTimeZone('Asia/Shanghai');
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $start, $tz);
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $end, $tz);
        if (!$from || !$to || $from->format('Y-m-d') !== $start || $to->format('Y-m-d') !== $end || $from > $to) {
            throw new \InvalidArgumentException('统计日期范围无效');
        }
        return max(1, (int)$from->diff($to)->format('%a'));
    }

    /** 只允许调用方传入经事实 Reader 认证的基础值；缺值始终传播为 null。 */
    private static function base(array $values, string $key): ?int
    {
        if (!array_key_exists($key, $values)) return null;
        $value = $values[$key];
        if (!is_int($value) || $value < 0) throw new \InvalidArgumentException('基础指标必须是非负整数');
        return $value;
    }

    private static function metric(string $report, int $index, array $values, int $days): ?int
    {
        if ($report === 'sales_analysis') {
            $new = self::base($values, 'new_customer_count');
            $casual = self::base($values, 'casual_customer_count');
            $designated = self::base($values, 'designated_customer_count');
            $service = self::base($values, 'service_customer_count');
            $newTotal = $new !== null && $casual !== null ? $new + $casual : null;
            // 生产最低版本为 PHP 7.4；整数行索引用 switch 保持原投影与缺值传播口径。
            switch ($index) {
                case 0: return $new;
                case 1: return $newTotal;
                case 2: return $newTotal !== null && $newTotal > 0 ? intdiv($new * 100, $newTotal) : ($newTotal === 0 ? 0 : null);
                case 3: return $newTotal !== null ? $newTotal - $new : null;
                case 4: return $designated;
                case 5: return $service;
                case 6: return $designated !== null && $service !== null && $service > 0 ? intdiv($designated * 100, $service)
                    : ($service === 0 && $designated !== null ? 0 : null);
                default: return null;
            }
        }
        $consumption = self::base($values, 'consumption_amount_cents');
        $customers = self::base($values, 'service_customer_count');
        $beds = self::base($values, 'bed_count');
        $staff = self::base($values, 'staff_count');
        $daily = $customers !== null ? intdiv($customers, $days) : null;
        $bedDaily = $daily !== null && $beds !== null ? ($beds > 0 ? intdiv($daily, $beds) : 0) : null;
        switch ($index) {
            case 0: return $consumption;
            case 1: return $customers;
            // 007 用 bcdiv(元金额, 客数) 且未指定精度：默认 scale=0，先截到整元。
            // 输入仍是 V3 的分整数，乘回 100 仅表示这一派生展示指标的整元结果。
            case 2: return $consumption !== null && $customers !== null ? ($customers > 0 ? intdiv($consumption, $customers * 100) * 100 : 0) : null;
            case 3: return $daily;
            case 4: return $beds;
            case 5: return $bedDaily;
            case 6: return $bedDaily !== null ? intdiv($bedDaily * 100, 7) : null;
            case 7: return $staff;
            case 8: return $daily !== null && $staff !== null ? ($staff > 0 ? intdiv($daily, $staff) : 0) : null;
            // 007 bcdiv(人均服务数,4.5) 默认 scale=0，显示纯整数，
            // 即人均数乘2再除9截断；旧页没有另乘100或百分号。
            case 9: return $daily !== null && $staff !== null
                ? ($staff > 0 ? intdiv(intdiv($daily,$staff)*2,9) : 0) : null;
            default: return null;
        }
    }

    /** 单位随指标行变化，不能把一列统一标成金额或人数。百分比值为整数百分点。 */
    private static function unit(string $report, int $index): string
    {
        if ($report === 'sales_analysis') return in_array($index, [2, 6], true) ? 'percent_integer' : 'count';
        if (in_array($index, [0, 2], true)) return 'money_cents';
        return $index === 6 ? 'percent_integer' : 'count';
    }
}
