<?php
namespace app\services\query;

/**
 * 普通查询XLSX流式写入器。只消费已验证行集/字段快照，租约和原子发布由worker负责。
 * 每张工作表最多1048576行，超量自动续表并重复表头；文本永不写成Excel公式。
 */
final class UnifiedQueryStreamingWorkbook
{
    private const ROWS_PER_SHEET = 1048576;
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function write(string $path, array $fields, iterable $rows, array $summaries = [], ?callable $checkpoint = null): int
    {
        if (!$fields || count($fields) > 100) throw new \RuntimeException('UNIFIED_QUERY_EXPORT_FIELDS_INVALID');
        $files = []; $out = null; $count = 0; $sheet = 0; $line = 0;
        $open = function () use (&$files, &$out, &$sheet, &$line, $path, $fields): void {
            $file = tempnam(dirname($path), 'query-sheet-');
            if ($file === false) throw new \RuntimeException('UNIFIED_QUERY_EXPORT_TEMP_FAILED');
            chmod($file, 0600); $files[] = $file; $out = fopen($file, 'wb');
            if ($out === false) throw new \RuntimeException('UNIFIED_QUERY_EXPORT_TEMP_FAILED');
            ++$sheet; $line = 1;
            $this->put($out, '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="'.self::NS.'"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" state="frozen"/></sheetView></sheetViews><sheetData>');
            $header = []; foreach ($fields as $field) $header[] = ['value'=>$field['label'] ?? $field['key'],'type'=>'text','style'=>4];
            $this->row($out, 1, $header);
        };
        $close = function () use (&$out, $fields): void {
            $this->put($out, '</sheetData><autoFilter ref="A1:'.$this->column(count($fields)).'1"/></worksheet>');
            fclose($out); $out = null;
        };
        try {
            $open();
            foreach ($rows as $row) {
                if ($line >= self::ROWS_PER_SHEET) { $close(); $open(); }
                $cells = []; foreach ($fields as $field) $cells[] = ['value'=>$row[$field['key']] ?? null,'type'=>$field['type'] ?? 'text'];
                $this->row($out, ++$line, $cells);
                if (++$count % 100 === 0 && $checkpoint) $checkpoint();
            }
            if ($summaries) {
                if ($line + 2 > self::ROWS_PER_SHEET) { $close(); $open(); }
                ++$line; $cells = [];
                foreach ($fields as $index => $field) {
                    $values = [];
                    foreach ($summaries as $key => $value) if (strpos($key, $field['key'] . ':') === 0) $values[] = substr($key, strlen($field['key']) + 1) . ': ' . (string)$value;
                    $cells[] = ['value'=>$values ? implode('; ', $values) : ($index === 0 ? '合计' : ''),'type'=>'text'];
                }
                $this->row($out, ++$line, $cells);
            }
            $close();
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('UNIFIED_QUERY_EXPORT_ZIP_FAILED');
            try {
                $types = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
                $book = '<workbook xmlns="'.self::NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
                $rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
                foreach ($files as $index => $file) {
                    $n = $index + 1;
                    if (!$zip->addFile($file, 'xl/worksheets/sheet'.$n.'.xml')) throw new \RuntimeException('UNIFIED_QUERY_EXPORT_ZIP_FAILED');
                    $types .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                    $book .= '<sheet name="查询结果'.($n === 1 ? '' : '-'.$n).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
                    $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
                }
                $this->entry($zip, '[Content_Types].xml', $types.'</Types>');
                $this->entry($zip, 'xl/workbook.xml', $book.'</sheets></workbook>');
                $this->entry($zip, 'xl/_rels/workbook.xml.rels', $rels.'<Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
                $this->entry($zip, '_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="book" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
                $this->entry($zip, 'xl/styles.xml', '<styleSheet xmlns="'.self::NS.'"><numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="0.######"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="5"><xf/><xf numFmtId="1" applyNumberFormat="1"/><xf numFmtId="164" applyNumberFormat="1"/><xf numFmtId="165" applyNumberFormat="1"/><xf fontId="1" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
            } finally { if (!$zip->close()) throw new \RuntimeException('UNIFIED_QUERY_EXPORT_ZIP_FAILED'); }
            if ($checkpoint) $checkpoint(true);
            return $count;
        } finally {
            if (is_resource($out)) fclose($out);
            foreach ($files as $file) @unlink($file);
        }
    }

    private function row($out, int $line, array $cells): void
    {
        $xml = '<row r="'.$line.'">';
        foreach ($cells as $index => $cell) {
            $value = $cell['value']; $type = $cell['type']; $coordinate = $this->column($index + 1).$line;
            if ($value !== null && in_array($type, ['integer','amount','decimal'], true)
                && preg_match('/^-?(\d+)(?:\.(\d+))?$/D', (string)$value, $parts)) {
                $fraction = rtrim($parts[2] ?? '', '0');
                if (strlen(ltrim($parts[1], '0')) + strlen($fraction) <= ($fraction === '' ? 15 : 14)) {
                    $style = $type === 'integer' ? 1 : ($type === 'amount' ? 2 : 3);
                    $xml .= '<c r="'.$coordinate.'" s="'.$style.'" t="n"><v>'.$value.'</v></c>'; continue;
                }
            }
            if ($value === null) $value = '';
            elseif ($type === 'boolean') $value = $value ? '是' : '否';
            elseif (is_array($value)) $value = implode('、', array_map('strval', $value));
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$value);
            $value = mb_substr($value, 0, 32767, 'UTF-8');
            $value = preg_replace('/_x([0-9a-fA-F]{4})_/', '_x005F_x$1_', $value);
            $xml .= '<c r="'.$coordinate.'"'.(isset($cell['style']) ? ' s="'.(int)$cell['style'].'"' : '').' t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
        }
        $this->put($out, $xml.'</row>');
    }
    private function column(int $number): string
    {
        $label = ''; do { --$number; $label = chr(65 + $number % 26).$label; $number = intdiv($number, 26); } while ($number > 0);
        return $label;
    }
    private function put($out, string $value): void
    {
        $length = strlen($value); $offset = 0;
        while ($offset < $length) { $n = fwrite($out, substr($value, $offset)); if (!$n) throw new \RuntimeException('UNIFIED_QUERY_EXPORT_WRITE_FAILED'); $offset += $n; }
    }
    private function entry(\ZipArchive $zip, string $name, string $xml): void
    {
        if (!$zip->addFromString($name, '<?xml version="1.0" encoding="UTF-8"?>'.$xml)) throw new \RuntimeException('UNIFIED_QUERY_EXPORT_ZIP_FAILED');
    }
}
