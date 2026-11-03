<?php

namespace app\services\report;


/** 将统一弹窗已授权的完整明细结果写成 XLSX，不重新查询或计算报表指标。 */
final class StoreUnifiedReportDetailXlsxExporter
{
    private const DETAIL_REPORTS = [
        'member_visit_detail' => '会员进店明细',
        'operations_pre_sale_bdegh_detail' => '售前明细',
        'operations_referral_beautician_pre_sale_detail' => '售前来源明细',
        'operations_cash_fact_detail' => '收款明细',
        'operations_service_fact_detail' => '服务明细',
        'phase_six_monthly_featured_item' => '月主推明细',
        'phase_six_headquarters_acquisition' => '总部拓客明细',
        'phase_six_acquisition_source' => '客户来源明细',
        'store_salesperson_performance_detail' => '销售人业绩明细',
        'store_item_analysis_detail' => '门店品项明细',
    ];

    public static function supports(string $report): bool
    {
        return isset(self::DETAIL_REPORTS[$report]);
    }

    /** 列、行、合计与取值说明只能来自同一次服务端导出结果。 */
    public function render(string $report, array $export): string
    {
        if (!self::supports($report)) throw new \InvalidArgumentException('该报表不支持明细 XLSX 导出');
        // 保留已验收明细的合计位置与说明页行号，文件编码统一交给共享流式写入器。
        foreach ($export['columns'] ?? [] as $index => $column) {
            if (in_array((string)($column['key'] ?? ''), ['amount','receipt_amount','detail_value','performance_amount'], true)) {
                $export['columns'][$index]['_xlsx_numeric'] = true;
            }
        }
        return (new StoreReportXlsxWriter())->render($export,(string)($export['title']??self::DETAIL_REPORTS[$report]),
            ['summary_position'=>'last','guide_header_row'=>4,'fixed_columns'=>0]);
    }
}
