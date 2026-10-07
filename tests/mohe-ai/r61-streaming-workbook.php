<?php
declare(strict_types=1);

/** Physical XLSX contract: streamed visible/audit rows keep order and do not turn member text into formulas. */
require getcwd().'/vendor/autoload.php';

use app\services\ai\presentation\AiStreamingWorkbook;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

$path=tempnam(sys_get_temp_dir(),'r61-xlsx-');
if ($path===false) throw new RuntimeException('TEST_TEMP_FAILED');
$fields=[['key'=>'row_id','label'=>'行号'],['key'=>'metric_value','label'=>'数值']];
$tables=[['title'=>'权益汇总','summary'=>'共 2 人','columns'=>[
    ['key'=>'name','label'=>'会员'],['key'=>'value','label'=>'剩余金额']],
    'rows'=>[['name'=>'=HYPERLINK("bad")','value'=>'50,000'],['name'=>'正常会员','value'=>'1,234']]]];
try {
    $count=(new AiStreamingWorkbook())->write($path,$fields,$tables,[
        ['row_id'=>'1','metric_value'=>'50000.25'],['row_id'=>'2','metric_value'=>'1234.50']]);
    if ($count!==2) throw new RuntimeException('AI_STREAM_COUNT_WRONG');
    (new AiStreamingWorkbook())->verify($path,$fields,$tables,[
        ['row_id'=>'1','metric_value'=>'50000.25'],['row_id'=>'2','metric_value'=>'1234.50']]);
    $book=IOFactory::load($path);
    try {
        $actual=[$book->getSheetCount(),$book->getSheet(0)->getTitle(),
            $book->getSheet(1)->getSheetState(),$book->getSheet(0)->getCell('A4')->getValue(),
            $book->getSheet(0)->getCell('A4')->getDataType(),$book->getSheet(1)->getCell('B3')->getValue()];
        $expected=[2,'查询结果',Worksheet::SHEETSTATE_HIDDEN,'=HYPERLINK("bad")','s','1234.50'];
        if ($actual!==$expected) throw new RuntimeException('AI_STREAM_XLSX_MISMATCH '.json_encode($actual));
    } finally { $book->disconnectWorksheets(); }
} finally { if (is_file($path)) unlink($path); }
$large=tempnam(sys_get_temp_dir(),'r61-large-');
if ($large===false) throw new RuntimeException('TEST_TEMP_FAILED');
try {
    $audit=(static function(): Generator {
        for ($i=1;$i<=15000;++$i) yield ['row_id'=>(string)$i,'metric_value'=>'0.25'];
    })();
    $count=(new AiStreamingWorkbook())->write($large,$fields,$tables,$audit);
    if ($count!==15000) throw new RuntimeException('AI_STREAM_LARGE_COUNT_WRONG');
    $expected=(static function(): Generator {
        for ($i=1;$i<=15000;++$i) yield ['row_id'=>(string)$i,'metric_value'=>'0.25'];
    })();
    (new AiStreamingWorkbook())->verify($large,$fields,$tables,$expected);
    $zip=new ZipArchive();
    if ($zip->open($large)!==true) throw new RuntimeException('AI_STREAM_LARGE_ZIP_INVALID');
    try {
        $xml=$zip->getFromName('xl/worksheets/sheet2.xml');
        if (!is_string($xml)||substr_count($xml,'<row r=')!==15001) {
            throw new RuntimeException('AI_STREAM_LARGE_ROWS_MISSING');
        }
    } finally { $zip->close(); }
} finally { if (is_file($large)) unlink($large); }
echo "r61 streaming workbook physical contract PASS\n";
