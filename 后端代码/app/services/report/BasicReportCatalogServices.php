<?php
namespace app\services\report;

/**
 * R51 基础报表的稳定业务目录，不代替查询授权，也不重命名或删除任何既有报表。
 * legacy_name 只用于旧配置核对，不作为新系统查询、门店映射或权限判断的身份。
 */
final class BasicReportCatalogServices
{
    /** 原二十张表加产品随后点名的实时工资表；PK、销售数据等其他旧入口不属于本目录。 */
    public static function entries(): array
    {
        $groups = [
            '门店统计' => [
                ['store_summary', '门店统计表', 'store_period', 'period'],
                ['cross_store_settlement', '跨店结算表', 'store_pair_period', 'period'],
                ['brush_registration', '刷单登记', 'registration', 'registration_date'],
                ['store_item_sales', '门店品项销售分析', 'store_item_period', 'period'],
                ['item_consumption', '项目消耗分析表', 'store_item_period', 'period'],
                ['writeoff_detail', '核销明细表', 'writeoff_line', 'business_date'],
                ['product_sales', '产品销售表', 'sale_line_participant_payment', 'business_date'],
            ],
            '员工统计' => [
                ['salary_realtime', '工资表', 'employee_store_month', 'realtime_month'],
                ['employee_summary', '员工统计表', 'employee_store_period', 'period'],
                ['employee_item_sales', '员工项目销售数分析', 'employee_item_period', 'period'],
                ['employee_service_home_store', '员工项目服务数-所属门店', 'employee_home_store_item_period', 'period'],
                ['employee_service_store', '员工项目服务数-服务门店', 'employee_service_store_item_period', 'period'],
                ['employee_performance_detail', '员工业绩明细表', 'performance_allocation', 'business_date'],
            ],
            '其他报表' => [
                ['balance_order_detail', '余额订单明细表', 'member_store_day', 'business_date'],
                ['balance_order_summary', '余额订单汇总表', 'store_period', 'period'],
                ['daily_reconciliation', '每日对账单汇总表', 'store_day', 'business_date'],
                ['unconsumed_summary', '门店未消耗统计表', 'member_store_current', 'current'],
                ['unconsumed_detail', '门店未消耗明细表', 'member_card_current', 'current'],
                ['followup_employee_detail', '跟单人员明细表', 'followup_participant', 'business_date'],
                ['customer_entitlements', '客户权益报表', 'member_card_project_current', 'current'],
                // 产品已确认只交付当天实时权益；稳定code保留，但不提供历史日期入口。
                ['unconsumed_as_of_detail', '门店未消耗实时明细表', 'member_card_current', 'current'],
            ],
        ];
        $entries = [];
        foreach ($groups as $group => $reports) {
            foreach ($reports as [$key, $name, $grain, $time]) {
                $entries[] = [
                    'code' => 'basic_' . $key, 'name' => $name, 'folder' => '基础报表',
                    'group' => $group, 'legacy_name' => $name, 'grain' => $grain,
                    'time_mode' => $time, 'scope' => 'platform_and_store',
                    'historical_import' => $key === 'brush_registration',
                ];
            }
        }
        return $entries;
    }

    /** 未知 code 必须失败，不能回退到另一张表或依赖某个客户的旧数字 ID。 */
    public static function definition(string $code): array
    {
        foreach (self::entries() as $entry) {
            if ($entry['code'] === $code) return $entry;
        }
        throw new \InvalidArgumentException('未配置该基础报表');
    }
}
