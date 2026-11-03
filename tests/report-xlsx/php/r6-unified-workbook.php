<?php

declare(strict_types=1);

require (getenv('CASHIER_V3_BACKEND_ROOT')?:'/var/www/html').'/vendor/autoload.php';

use app\services\report\StoreReportXlsxWriter;
use app\services\report\BasicReportXlsxExportServices;
use app\services\report\StoreItemAnalysisXlsxExporter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

// Pure file tests: no database/checkout writes; the independent workbook reader must read exact source values.
set_exception_handler(static function(Throwable $error):void{fwrite(STDERR,$error->getMessage()."\n");exit(1);});
$checks=0;
$assert=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;echo "PASS $message\n";};
$writer=new StoreReportXlsxWriter();
$load=static function(string $bytes){$p=tempnam(sys_get_temp_dir(),'r6-read-');file_put_contents($p,$bytes);try{return IOFactory::load($p);}finally{unlink($p);}};
$columns=[['key'=>'code','label'=>'单号','source_explanation'=>'原业务单号'],
    ['key'=>'name','label'=>'备注','source_explanation'=>'收银明细备注'],
    ['key'=>'amount','label'=>'金额','value_type'=>'money','summable'=>true,'source_explanation'=>'本次已确认金额']];
$rows=[];
for($i=0;$i<45;$i++)$rows[]=['code'=>str_pad((string)$i,12,'0',STR_PAD_LEFT),'name'=>$i===30?"中文🙂\n=HYPERLINK(\"x\")\x0B":'=1+1','amount'=>$i===30?'-1500.39':'1.01'];
$export=['columns'=>$columns,'records'=>$rows,'summary_row'=>['code'=>'合计','amount'=>'-1455.95'],'metric_version'=>'r6-no-recalculation'];
$bytes=$writer->render($export,'薪资明细');$book=$load($bytes);$sheet=$book->getSheet(0);
$assert(substr($bytes,0,2)==='PK'&&$book->getSheetCount()===2,'real XLSX with data and source worksheets');
$assert($sheet->getHighestRow()===47&&$sheet->getCell('A47')->getValue()==='000000000044','complete result beyond first 20 rows');
$assert($sheet->getCell('A33')->getValue()==='000000000030'&&$sheet->getCell('A33')->getDataType()===DataType::TYPE_STRING,'leading-zero identity remains text');
$assert($sheet->getCell('B3')->getValue()==='=1+1'&&$sheet->getCell('B3')->getDataType()===DataType::TYPE_STRING,'business text cannot become Excel formula');
$assert($sheet->getCell('B33')->getValue()===$rows[30]['name'],'Unicode/newline/control characters round trip without loss');
$assert($sheet->getCell('C33')->getValue()===-1500.39&&$sheet->getCell('C2')->getValue()===-1455.95,'signed cent precision and authoritative total preserved');
$assert($sheet->getFreezePane()==='A3','header and first summary row frozen');
$assert($book->getSheet(1)->getCell('B1')->getValue()==='r6-no-recalculation'&&$book->getSheet(1)->getCell('B6')->getValue()==='收银明细备注','column sources and version preserved');
$book->disconnectWorksheets();
$tempDownload=tempnam(sys_get_temp_dir(),'r6-download-');file_put_contents($tempDownload,$bytes);
$response=$writer->fileResponse(['path'=>$tempDownload,'filename'=>'测试.xlsx']);
$assert(!file_exists($tempDownload)&&substr($response->getContent(),0,2)==='PK','download response preserves workbook and removes private temporary file');
$grouped=['columns'=>[
    ['key'=>'store','label'=>'门店'],['key'=>'a','label'=>'现金','header_path'=>['六维','合作','现金'],'summable'=>true],
    ['key'=>'b','label'=>'分成','header_path'=>['六维','合作','分成'],'summable'=>true],
    ['key'=>'c','label'=>'现金','header_path'=>['生美','卡项','现金'],'summable'=>true]],
    'records'=>[['store'=>'扬州','a'=>'12.34','b'=>'4.32','c'=>'5.67']]];
$book=$load($writer->render($grouped,'分组报表'));$sheet=$book->getSheet(0);$merges=$sheet->getMergeCells();
$assert(isset($merges['A1:A3'],$merges['B1:C1'],$merges['B2:C2'])&&$sheet->getCell('D3')->getValue()==='现金','three-level grouped headers and repeated leaf labels stay distinct');
$assert($sheet->getCell('B4')->getValue()===12.34&&$sheet->getCell('D4')->getValue()===5.67,'grouping never changes values or columns');$book->disconnectWorksheets();
$item=$grouped;$item['columns']=array_slice($item['columns'],0,3);
foreach($item['columns'] as &$column)unset($column['header_path']);unset($column);
$item['column_groups']=[['label'=>'门店','column_keys'=>['store'],'rowspan'=>2,'tone'=>'basic'],['label'=>'六维/合作','column_keys'=>['a','b'],'tone'=>'category']];
$item['summary_row']=['store'=>'合计','a'=>'12.34','b'=>'4.32'];
$book=$load((new StoreItemAnalysisXlsxExporter())->render($item));$sheet=$book->getSheet(0);
$assert($sheet->getCell('B1')->getValue()==='六维/合作'&&$sheet->getCell('A3')->getValue()==='合计'&&$sheet->getFreezePane()==='B4','existing item-analysis groups and frozen total layout retained');$book->disconnectWorksheets();
$basicResult=['queryFields'=>[['key'=>'phone','label'=>'手机号','type'=>'text'],['key'=>'value','label'=>'金额','money'=>true]],
    'exportRows'=>[['phone'=>'013300000001','value'=>'1.09']], 'summaries'=>['value:sum'=>'1.09'],
    'field_sources'=>['phone'=>'会员保存的手机号','value'=>'该笔已确认金额'],
    'row_sources'=>[['label'=>'指标行','description'=>'后端行说明']], 'summary_cards'=>[['label'=>'本期金额','value'=>'1.09']]];
$basic=BasicReportXlsxExportServices::payload($basicResult,['pageCode'=>'basic_foo','export'=>['scope'=>'query','fields'=>['phone','value']]]);
$book=$load($writer->render($basic,'基础报表',['summary_position'=>'last']));$sheet=$book->getSheet(0);
$assert($sheet->getCell('A2')->getValue()==='013300000001'&&$sheet->getCell('B2')->getValue()===1.09&&$sheet->getCell('A3')->getValue()==='合计','basic report fields and authoritative summaries retain types');
$assert($sheet->getCell('A5')->getValue()==='当前查询汇总'&&$book->getSheet(1)->getCell('B7')->getValue()==='后端行说明','basic summary cards and matrix row sources retained');$book->disconnectWorksheets();
$bad=false;try{BasicReportXlsxExportServices::payload($basicResult,['export'=>['scope'=>'query','fields'=>['secret']]]);}catch(InvalidArgumentException $e){$bad=true;}
$assert($bad,'unavailable basic report field cannot be exported');
$sections=['sections'=>[['label'=>'同名/页','columns'=>$columns,'records'=>[]],['label'=>'同名:页','columns'=>$columns,'records'=>[['code'=>'last','name'=>'客户','amount'=>'2.01']]]]];
$book=$load($writer->render($sections,'客户分析'));
$assert($book->getSheetCount()===3&&$book->getSheet(0)->getTitle()!==$book->getSheet(1)->getTitle()&&$book->getSheet(1)->getCell('A2')->getValue()==='last','customer sections exported as distinct complete sheets including empty section');$book->disconnectWorksheets();
$path=tempnam(sys_get_temp_dir(),'r6-fail-');$bad=false;
try{$writer->writeFile($path,['columns'=>$columns,'records'=>[['name'=>str_repeat('🙂',17000)]]],'无效');}catch(InvalidArgumentException $e){$bad=true;}
$assert($bad&&!file_exists($path),'oversize UTF-16 cell fails cleanly without publishing partial workbook');
// This is intentionally an iterator; materializing these records would break the memory assertion.
$path=tempnam(sys_get_temp_dir(),'r6-stream-');$baseMemory=memory_get_usage(true);
$iterator=(static function():Generator{for($i=0;$i<100000;$i++)yield ['code'=>'ID-'.$i,'amount'=>'1.01'];})();
$writer->writeFile($path,['columns'=>[$columns[0],$columns[2]],'records'=>$iterator],'流式导出');
$growth=memory_get_peak_usage(true)-$baseMemory;$zip=new ZipArchive();$zip->open($path);
$assert($growth<24*1024*1024&&$zip->statName('xl/worksheets/sheet1.xml')['size']>1000000,'100000-row iterator stays within bounded writer memory');
$zip->close();
$reader=new XMLReader();$reader->open('zip://'.$path.'#xl/worksheets/sheet1.xml');$streamRows=0;
while($reader->read())if($reader->nodeType===XMLReader::ELEMENT&&$reader->localName==='row')$streamRows++;
$reader->close();$assert($streamRows===100001,'independent streaming XML reader finds all 100000 rows and header');
if(getenv('R6_XLSX_OUTPUT'))copy($path,getenv('R6_XLSX_OUTPUT'));unlink($path);
echo "PASS R6 unified XLSX checks=$checks peak_growth=$growth\n";
