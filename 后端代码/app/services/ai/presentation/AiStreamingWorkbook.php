<?php
declare(strict_types=1);

namespace app\services\ai\presentation;

/**
 * Write an AI answer's visible tables and exact-value audit rows without
 * retaining spreadsheet cells in PHP memory. Callers own the frozen source,
 * authorization and final atomic publication; this class only serializes it.
 */
final class AiStreamingWorkbook
{
    private const EXCEL_ROWS = 1048576;

    /** Re-read every physical cell without loading the workbook into memory. */
    public function verify(string $path,array $fields,iterable $tables,iterable $auditRows): void
    {
        $zip=new \ZipArchive();
        if ($zip->open($path)!==true) throw new \RuntimeException('AI_EXPORT_CONTENT_MISMATCH');
        try {
            $manifest=$zip->getFromName('xl/workbook.xml');
            $dom=new \DOMDocument();
            if (!is_string($manifest)||!$dom->loadXML($manifest,LIBXML_NONET)) throw new \RuntimeException('AI_EXPORT_CONTENT_MISMATCH');
            $sheets=$dom->getElementsByTagNameNS('*','sheet');
            if ($sheets->length!==2||$sheets->item(0)->getAttribute('name')!=='查询结果'
                ||$sheets->item(1)->getAttribute('name')!=='精确数据'
                ||$sheets->item(1)->getAttribute('state')!=='hidden') throw new \RuntimeException('AI_EXPORT_CONTENT_MISMATCH');
            foreach ([
                ['xl/worksheets/sheet1.xml',$this->expectedVisible($tables)],
                ['xl/worksheets/sheet2.xml',$this->expectedExact($fields,$auditRows)],
            ] as [$entry,$expected]) {
                $stream=$zip->getStream($entry);
                if ($stream===false) throw new \RuntimeException('AI_EXPORT_CONTENT_MISMATCH');
                $temp=tempnam(dirname($path),'ai-verify-');
                if ($temp===false) { fclose($stream);throw new \RuntimeException('AI_EXPORT_CONTENT_MISMATCH'); }
                try {
                    $out=fopen($temp,'wb');
                    if ($out===false) throw new \RuntimeException('AI_EXPORT_CONTENT_MISMATCH');
                    try { if (stream_copy_to_stream($stream,$out)===false) throw new \RuntimeException('AI_EXPORT_CONTENT_MISMATCH'); }
                    finally { fclose($out); }
                    $reader=new \XMLReader();
                    if (!$reader->open($temp,null,LIBXML_NONET)) throw new \RuntimeException('AI_EXPORT_CONTENT_MISMATCH');
                    try {
                        foreach ($expected as $row) {
                            $physical=$this->nextRow($reader);
                            if ($physical===null || $physical['line']!==$row['line']
                                ||$physical['values']!==$row['values']) throw new \RuntimeException('AI_EXPORT_CONTENT_MISMATCH');
                        }
                        if ($this->nextRow($reader)!==null) throw new \RuntimeException('AI_EXPORT_CONTENT_MISMATCH');
                    } finally { $reader->close(); }
                } finally { fclose($stream);if (is_file($temp)) unlink($temp); }
            }
        } finally { $zip->close(); }
    }

    private function expectedVisible(iterable $tables): \Generator
    {
        $line=1;
        foreach ($tables as $table) {
            if (($table['title']??'')!=='') yield ['line'=>$line++,'values'=>[(string)$table['title']]];
            yield ['line'=>$line++,'values'=>[(string)$table['summary']]];
            yield ['line'=>$line++,'values'=>array_map(static fn(array $column):string=>(string)$column['label'],$table['columns'])];
            foreach ($table['rows'] as $row) {
                $values=[];
                foreach ($table['columns'] as $column) $values[]=(string)$row[$column['key']];
                yield ['line'=>$line++,'values'=>$values];
            }
            ++$line;
        }
    }

    private function expectedExact(array $fields,iterable $rows): \Generator
    {
        $line=1;
        yield ['line'=>$line++,'values'=>array_map(static fn(array $field):string=>(string)$field['label'],$fields)];
        foreach ($rows as $row) {
            $values=[];
            foreach ($fields as $field) $values[]=(string)$row[$field['key']];
            yield ['line'=>$line++,'values'=>$values];
        }
    }

    private function nextRow(\XMLReader $reader): ?array
    {
        while ($reader->read()) {
            if ($reader->nodeType!==\XMLReader::ELEMENT||$reader->localName!=='row') continue;
            $dom=new \DOMDocument();
            if (!$dom->loadXML($reader->readOuterXml(),LIBXML_NONET)) throw new \RuntimeException('AI_EXPORT_CONTENT_MISMATCH');
            $node=$dom->documentElement;$line=(int)$node->getAttribute('r');$values=[];$column=1;
            foreach ($node->getElementsByTagNameNS('*','c') as $cell) {
                $letter=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column++);
                if ($cell->getAttribute('r')!==$letter.$line || $cell->getAttribute('t')!=='str'
                    ||$cell->getElementsByTagNameNS('*','f')->length!==0) throw new \RuntimeException('AI_EXPORT_CONTENT_MISMATCH');
                $value=$cell->getElementsByTagNameNS('*','v');
                if ($value->length!==1) throw new \RuntimeException('AI_EXPORT_CONTENT_MISMATCH');
                $values[]=$value->item(0)->textContent;
            }
            return ['line'=>$line,'values'=>$values];
        }
        return null;
    }

    /**
     * @param iterable<array<string,mixed>> $tables Each table has ordered columns and iterable rows.
     * @param iterable<array<string,mixed>> $auditRows Exact rows from the same verified answer.
     * @return int Number of exact-value rows physically written.
     */
    public function write(string $path,array $fields,iterable $tables,iterable $auditRows,?callable $checkpoint=null): int
    {
        if ($fields===[] || !class_exists(\ZipArchive::class)) throw new \RuntimeException('AI_EXPORT_WRITER_UNAVAILABLE');
        $directory=dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) throw new \RuntimeException('AI_EXPORT_PATH_INVALID');
        $visible=tempnam($directory,'ai-visible-');
        $exact=tempnam($directory,'ai-exact-');
        if ($visible===false || $exact===false) throw new \RuntimeException('AI_EXPORT_TEMP_FAILED');
        try {
            $this->visibleSheet($visible,$tables,$checkpoint);
            $count=$this->exactSheet($exact,$fields,$auditRows,$checkpoint);
            $zip=new \ZipArchive();
            if ($zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true) throw new \RuntimeException('AI_EXPORT_ZIP_FAILED');
            try {
                $this->entry($zip,'[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?>'
                    .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                    .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                    .'<Default Extension="xml" ContentType="application/xml"/>'
                    .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                    .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                    .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                    .'</Types>');
                $this->entry($zip,'_rels/.rels','<?xml version="1.0" encoding="UTF-8"?>'
                    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                    .'</Relationships>');
                $this->entry($zip,'xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?>'
                    .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                    .'<sheets><sheet name="查询结果" sheetId="1" r:id="rId1"/>'
                    .'<sheet name="精确数据" sheetId="2" state="hidden" r:id="rId2"/></sheets></workbook>');
                $this->entry($zip,'xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?>'
                    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                    .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
                    .'</Relationships>');
                if (!$zip->addFile($visible,'xl/worksheets/sheet1.xml')
                    ||!$zip->addFile($exact,'xl/worksheets/sheet2.xml')) throw new \RuntimeException('AI_EXPORT_ZIP_FAILED');
            } finally {
                if (!$zip->close()) throw new \RuntimeException('AI_EXPORT_ZIP_FAILED');
            }
            return $count;
        } finally {
            if (is_file($visible)) unlink($visible);
            if (is_file($exact)) unlink($exact);
        }
    }

    /** Only inline strings are emitted: a member name beginning with '=' is data, never a formula. */
    private function visibleSheet(string $path,iterable $tables,?callable $checkpoint): void
    {
        $file=$this->begin($path);$line=1;
        try {
            foreach ($tables as $table) {
                $columns=$table['columns']??null;$rows=$table['rows']??null;
                if (!is_array($columns)||$columns===[]||!is_iterable($rows)) throw new \RuntimeException('AI_EXPORT_PRESENTATION_INVALID');
                if (($table['title']??'')!=='') $this->row($file,$line++,[(string)$table['title']]);
                $this->row($file,$line++,[(string)($table['summary']??'')]);
                $this->row($file,$line++,array_map(static fn(array $column):string=>(string)$column['label'],$columns));
                foreach ($rows as $row) {
                    if (!is_array($row)) throw new \RuntimeException('AI_EXPORT_PRESENTATION_INVALID');
                    $values=[];
                    foreach ($columns as $column) {
                        $key=$column['key']??null;
                        if (!is_string($key)||!isset($row[$key])) throw new \RuntimeException('AI_EXPORT_PRESENTATION_INVALID');
                        $values[]=(string)$row[$key];
                    }
                    $this->row($file,$line++,$values);
                    if ($checkpoint!==null && $line%100===0) $checkpoint();
                }
                ++$line;
            }
            $this->end($file);
        } finally { fclose($file); }
    }

    private function exactSheet(string $path,array $fields,iterable $rows,?callable $checkpoint): int
    {
        $file=$this->begin($path);$line=1;
        try {
            $this->row($file,$line++,array_map(static fn(array $field):string=>(string)$field['label'],$fields));
            foreach ($rows as $row) {
                if (!is_array($row)) throw new \RuntimeException('AI_EXPORT_AUDIT_INVALID');
                $values=[];
                foreach ($fields as $field) {
                    $key=$field['key']??null;
                    if (!is_string($key)||!array_key_exists($key,$row)||!is_scalar($row[$key])) throw new \RuntimeException('AI_EXPORT_AUDIT_INVALID');
                    $values[]=(string)$row[$key];
                }
                $this->row($file,$line++,$values);
                if ($checkpoint!==null && $line%100===0) $checkpoint();
            }
            $this->end($file);
            return $line-2;
        } finally { fclose($file); }
    }

    private function begin(string $path)
    {
        $file=fopen($path,'wb');
        if ($file===false) throw new \RuntimeException('AI_EXPORT_TEMP_FAILED');
        $this->bytes($file,'<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
        return $file;
    }

    private function end($file): void { $this->bytes($file,'</sheetData></worksheet>'); }

    private function row($file,int $line,array $values): void
    {
        if ($line<1||$line>self::EXCEL_ROWS) throw new \RuntimeException('AI_EXPORT_EXCEL_SHEET_FULL');
        $xml='<row r="'.$line.'">';
        foreach ($values as $index=>$value) {
            $column=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index+1);
            // Excel and XML both have cell restrictions; trimming invalid XML
            // code points is safer than letting a malformed workbook publish.
            $clean=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/','',(string)$value);
            if ($clean===null || mb_strlen($clean,'UTF-8')>32767) throw new \RuntimeException('AI_EXPORT_CELL_INVALID');
            $xml.='<c r="'.$column.$line.'" t="str"><v>'
                .htmlspecialchars($clean,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</v></c>';
        }
        $this->bytes($file,$xml.'</row>');
    }

    private function entry(\ZipArchive $zip,string $name,string $value): void
    { if (!$zip->addFromString($name,$value)) throw new \RuntimeException('AI_EXPORT_ZIP_FAILED'); }

    private function bytes($file,string $value): void
    { if (fwrite($file,$value)!==strlen($value)) throw new \RuntimeException('AI_EXPORT_WRITE_FAILED'); }
}
