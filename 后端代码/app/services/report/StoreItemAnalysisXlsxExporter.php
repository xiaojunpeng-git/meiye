<?php

namespace app\services\report;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** 将已授权的门店品项分析导出结果写为工作簿，不重新查询或计算经营指标。 */
class StoreItemAnalysisXlsxExporter
{
    public const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * 列和分组只读取同一次导出响应，保证动态分类、合计及说明与实际数据同版。
     * 文本显式写为字符串，防止门店名等业务资料被 Excel 当作公式执行。
     */
    public function render(array $export): string
    {
        $columns = array_values($export['columns'] ?? []);
        $groups = array_values($export['column_groups'] ?? []);
        if (!$columns || !$groups || count($columns) > 16384) {
            throw new \InvalidArgumentException('门店品项分析导出列定义无效');
        }
        $positions = [];
        foreach ($columns as $index => $column) {
            $key = (string)($column['key'] ?? '');
            if ($key === '' || isset($positions[$key])) throw new \InvalidArgumentException('门店品项分析导出列键无效');
            $positions[$key] = $index;
        }
        $groupByPosition = [];
        foreach ($groups as $groupIndex => $group) {
            foreach (($group['column_keys'] ?? []) as $key) {
                if (!isset($positions[$key])) continue;
                $position = $positions[$key];
                if (isset($groupByPosition[$position])) throw new \InvalidArgumentException('门店品项分析导出列分组重复');
                $groupByPosition[$position] = [
                    'id' => $groupIndex,
                    'label' => (string)($group['label'] ?? ''),
                    'rowspan' => (int)($group['rowspan'] ?? 0),
                    'tone' => (string)($group['tone'] ?? ''),
                ];
            }
        }
        if (count($groupByPosition) !== count($columns)) {
            throw new \InvalidArgumentException('门店品项分析导出列分组不完整');
        }

        $book = new Spreadsheet();
        try {
            $sheet = $book->getActiveSheet();
            $sheet->setTitle('门店品项分析');
            $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
            for ($index = 0; $index < count($columns);) {
                $group = $groupByPosition[$index];
                $end = $index;
                while ($end + 1 < count($columns) && $groupByPosition[$end + 1]['id'] === $group['id']) $end++;
                $firstLetter = Coordinate::stringFromColumnIndex($index + 1);
                $lastLetter = Coordinate::stringFromColumnIndex($end + 1);
                $this->textCell($sheet, $firstLetter . '1', $group['label']);
                if ($end > $index) $sheet->mergeCells($firstLetter . '1:' . $lastLetter . '1');
                if ($end === $index && $group['rowspan'] > 1) {
                    $sheet->mergeCells($firstLetter . '1:' . $firstLetter . '2');
                } else {
                    for ($leaf = $index; $leaf <= $end; $leaf++) {
                        $this->textCell($sheet, Coordinate::stringFromColumnIndex($leaf + 1) . '2', (string)($columns[$leaf]['label'] ?? ''));
                    }
                }
                $index = $end + 1;
            }

            $summary = $export['summary_row'] ?? [];
            if (is_array($summary) && $summary) $this->writeDataRow($sheet, 3, $columns, $groupByPosition, $summary);
            foreach (array_values($export['records'] ?? []) as $index => $record) {
                $this->writeDataRow($sheet, $index + 4, $columns, $groupByPosition, $record);
            }
            $sheet->getStyle('A1:' . $lastColumn . '2')->getFont()->setBold(true);
            $sheet->getStyle('A1:' . $lastColumn . '1')->getFill()
                ->setFillType('solid')->getStartColor()->setARGB('FFE3EDF8');
            $sheet->getStyle('A3:' . $lastColumn . '3')->getFont()->setBold(true);
            $fixedColumns = 0;
            while ($fixedColumns < count($columns) && $groupByPosition[$fixedColumns]['tone'] === 'basic') $fixedColumns++;
            $sheet->freezePane(Coordinate::stringFromColumnIndex($fixedColumns + 1) . '4');
            foreach ($columns as $index => $column) {
                $letter = Coordinate::stringFromColumnIndex($index + 1);
                $isMetric = $groupByPosition[$index]['tone'] !== 'basic';
                $sheet->getColumnDimension($letter)->setWidth($isMetric ? 17 : 22);
                if ($isMetric) $sheet->getStyle($letter . '3:' . $letter . max(3, count($export['records'] ?? []) + 3))
                    ->getNumberFormat()->setFormatCode('0.##');
            }

            $guide = $book->createSheet();
            $guide->setTitle('列名取值来源');
            $this->textCell($guide, 'A1', '口径版本');
            $this->textCell($guide, 'B1', (string)($export['metric_version'] ?? '-'));
            $this->textCell($guide, 'A3', '列名');
            $this->textCell($guide, 'B3', '取值来源');
            foreach ($columns as $index => $column) {
                $group = $groupByPosition[$index];
                $label = (string)($column['label'] ?? '');
                $fullLabel = $group['rowspan'] > 1 || $group['label'] === $label
                    ? $group['label'] : $group['label'] . '/' . $label;
                $this->textCell($guide, 'A' . ($index + 4), $fullLabel);
                $this->textCell($guide, 'B' . ($index + 4), (string)($column['source_explanation'] ?? $column['logic'] ?? ''));
            }
            $guide->getStyle('A3:B3')->getFont()->setBold(true);
            $guide->getColumnDimension('A')->setWidth(36);
            $guide->getColumnDimension('B')->setWidth(110);

            $temporary = tempnam(sys_get_temp_dir(), 'store-item-xlsx-');
            if ($temporary === false) throw new \RuntimeException('门店品项分析导出文件创建失败');
            try {
                (new Xlsx($book))->save($temporary);
                $bytes = file_get_contents($temporary);
                if ($bytes === false || substr($bytes, 0, 2) !== 'PK') {
                    throw new \RuntimeException('门店品项分析 XLSX 文件生成失败');
                }
                return $bytes;
            } finally {
                @unlink($temporary);
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /** 金额列保持服务端原值；业务识别列和非数字占位值保持文本。 */
    private function writeDataRow($sheet, int $rowNumber, array $columns, array $groups, array $record): void
    {
        foreach ($columns as $index => $column) {
            $coordinate = Coordinate::stringFromColumnIndex($index + 1) . $rowNumber;
            $value = $record[$column['key']] ?? '-';
            if ($groups[$index]['tone'] !== 'basic' && is_numeric($value) && is_finite((float)$value)) {
                $sheet->setCellValue($coordinate, (float)$value);
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
