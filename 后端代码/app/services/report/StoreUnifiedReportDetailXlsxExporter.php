<?php

namespace app\services\report;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** 将统一弹窗已授权的完整明细结果写成 XLSX，不重新查询或计算报表指标。 */
final class StoreUnifiedReportDetailXlsxExporter
{
    public const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

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

    public function filename(string $report): string
    {
        if (!self::supports($report)) throw new \InvalidArgumentException('该报表不支持明细 XLSX 导出');
        return self::DETAIL_REPORTS[$report] . '-' . date('YmdHis') . '.xlsx';
    }

    /** 列、行、合计与取值说明只能来自同一次服务端导出结果。 */
    public function render(string $report, array $export): string
    {
        if (!self::supports($report)) throw new \InvalidArgumentException('该报表不支持明细 XLSX 导出');
        $columns = array_values($export['columns'] ?? []);
        $records = array_values($export['records'] ?? []);
        if (!$columns || count($columns) > 16384 || count($records) > 1048573) {
            throw new \InvalidArgumentException('明细 XLSX 导出超出工作簿容量');
        }
        $keys = [];
        foreach ($columns as $column) {
            $key = trim((string)($column['key'] ?? ''));
            if ($key === '' || isset($keys[$key]) || trim((string)($column['label'] ?? '')) === '') {
                throw new \InvalidArgumentException('明细 XLSX 导出列定义无效');
            }
            $keys[$key] = true;
        }

        $book = new Spreadsheet();
        try {
            $sheet = $book->getActiveSheet();
            $sheet->setTitle(self::DETAIL_REPORTS[$report]);
            $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
            foreach ($columns as $index => $column) {
                $letter = Coordinate::stringFromColumnIndex($index + 1);
                $this->textCell($sheet, $letter . '1', (string)$column['label']);
                $sheet->getColumnDimension($letter)->setWidth(22);
            }
            foreach ($records as $index => $record) {
                $this->writeRow($sheet, $index + 2, $columns, $record);
            }
            $summary = $export['summary_row'] ?? [];
            if (is_array($summary) && $summary) {
                $summaryRow = count($records) + 2;
                $this->writeRow($sheet, $summaryRow, $columns, $summary);
                $sheet->getStyle('A' . $summaryRow . ':' . $lastColumn . $summaryRow)->getFont()->setBold(true);
            }
            $sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true);
            $sheet->getStyle('A1:' . $lastColumn . '1')->getFill()
                ->setFillType('solid')->getStartColor()->setARGB('FFE3EDF8');
            $sheet->freezePane('A2');
            $sheet->setAutoFilter('A1:' . $lastColumn . max(1, count($records) + 1));

            $guide = $book->createSheet();
            $guide->setTitle('列名取值来源');
            $this->textCell($guide, 'A1', '口径版本');
            $this->textCell($guide, 'B1', (string)($export['metric_version'] ?? '-'));
            $this->textCell($guide, 'A2', '明细记录数');
            $guide->setCellValueExplicit('B2', count($records), DataType::TYPE_NUMERIC);
            $this->textCell($guide, 'A4', '列名');
            $this->textCell($guide, 'B4', '取值来源');
            foreach ($columns as $index => $column) {
                $this->textCell($guide, 'A' . ($index + 5), (string)$column['label']);
                $this->textCell($guide, 'B' . ($index + 5), (string)($column['source_explanation'] ?? $column['logic'] ?? '-'));
            }
            $guide->getStyle('A4:B4')->getFont()->setBold(true);
            $guide->getColumnDimension('A')->setWidth(30);
            $guide->getColumnDimension('B')->setWidth(100);

            $temporary = tempnam(sys_get_temp_dir(), 'report-detail-xlsx-');
            if ($temporary === false) throw new \RuntimeException('明细 XLSX 导出文件创建失败');
            try {
                (new Xlsx($book))->save($temporary);
                $bytes = file_get_contents($temporary);
                if ($bytes === false || substr($bytes, 0, 2) !== 'PK') {
                    throw new \RuntimeException('明细 XLSX 文件生成失败');
                }
                return $bytes;
            } finally {
                @unlink($temporary);
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /** 单号和手机号即使全为数字也保持文本，且不允许业务文本成为 Excel 公式。 */
    private function writeRow($sheet, int $rowNumber, array $columns, array $record): void
    {
        foreach ($columns as $index => $column) {
            $coordinate = Coordinate::stringFromColumnIndex($index + 1) . $rowNumber;
            $key = (string)$column['key'];
            $value = $record[$key] ?? '-';
            $type = (string)($column['value_type'] ?? '');
            $numeric = in_array($type, ['integer', 'money', 'number'], true)
                || in_array($key, ['amount', 'receipt_amount', 'detail_value', 'performance_amount'], true);
            if ($numeric && is_numeric($value)) {
                $sheet->setCellValueExplicit($coordinate, (float)$value, DataType::TYPE_NUMERIC);
                // Some count metrics reuse receipt_amount; without an explicit money type, preserve whole counts.
                $format = $type === 'integer' ? '0' : ($type === 'money' ? '0.00' : '0.########');
                $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode($format);
            } else {
                $this->textCell($sheet, $coordinate, (string)$value);
            }
        }
    }

    private function textCell($sheet, string $coordinate, string $value): void
    {
        $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
    }
}
