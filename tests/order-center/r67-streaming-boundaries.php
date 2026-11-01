<?php
/** XLSX物理边界、金额精度、异常清理；只写测试临时目录，不触碰业务库。 */
require (getenv('R67_BACKEND') ?: dirname(__DIR__,2).'/后端代码').'/vendor/autoload.php';
use app\services\query\UnifiedQueryExternalRows;use app\services\query\UnifiedQueryStreamingWorkbook;
function verify($condition,$message){if(!$condition)throw new RuntimeException($message);}
$rows=new UnifiedQueryExternalRows();$sample=['id'=>'1','float'=>2.0,'large'=>'9007199254740993','nested'=>['amount'=>100.0]];$rows->append($sample);
verify(iterator_to_array($rows)[0]===$sample,'spool scalar types changed');
unset($rows);$rows=new UnifiedQueryExternalRows();for($i=0;$i<300;++$i)$rows->append(['id'=>(string)$i]);$cancelled=false;
$p=new ReflectionProperty($rows,'directory');$p->setAccessible(true);$dir=$p->getValue($rows);
try{$rows->sort(static fn()=>0,static function(){throw new RuntimeException('cancelled');});}catch(RuntimeException $e){$cancelled=$e->getMessage()==='cancelled';}verify($cancelled,'sort checkpoint did not cancel');
unset($rows);verify(!is_dir($dir),'row spool leaked');
$dir=sys_get_temp_dir().'/r67-xlsx-'.bin2hex(random_bytes(8));mkdir($dir,0700);$file=$dir.'/boundary.xlsx';$start=microtime(true);
try{
 $writer=new UnifiedQueryStreamingWorkbook();
 $source=(function(){for($i=0;$i<1048577;++$i)yield ['id'=>(string)$i];})();
 verify($writer->write($file,[['key'=>'id','label'=>'编号','type'=>'text']],$source)===1048577,'physical count returned');
 $zip=new ZipArchive();verify($zip->open($file)===true,'zip invalid');
 $counts=[];$last=[];
 for($sheet=1;$sheet<=2;++$sheet){$stream=$zip->getStream('xl/worksheets/sheet'.$sheet.'.xml');verify(is_resource($stream),'missing sheet');$tmp=$dir.'/verify.xml';$out=fopen($tmp,'wb');stream_copy_to_stream($stream,$out);fclose($stream);fclose($out);
  $xml=new XMLReader();$xml->open($tmp);$count=0;$value='';while($xml->read()){if($xml->nodeType===XMLReader::ELEMENT&&$xml->localName==='row')++$count;if($xml->nodeType===XMLReader::ELEMENT&&$xml->localName==='t')$value=$xml->readString();} $xml->close();unlink($tmp);$counts[]=$count;$last[]=$value;
 }
 $zip->close();verify($counts===[1048576,3]&&$last===['1048574','1048576'],'sheet boundary lost/duplicated rows');unlink($file);
 $cancelled=false;try{$writer->write($file,[['key'=>'id','type'=>'text']],(function(){for($i=0;$i<101;++$i)yield ['id'=>$i];})(),[],static function(){throw new RuntimeException('cancelled');});}catch(RuntimeException $e){$cancelled=$e->getMessage()==='cancelled';}
 verify($cancelled&&!glob($dir.'/query-sheet-*')&&!is_file($file),'cancelled export leaked partial file');
 echo json_encode(['status'=>'PASS','rows'=>1048577,'sheet_rows'=>$counts,'cancel_cleanup'=>true,'seconds'=>round(microtime(true)-$start,2),'peak'=>memory_get_peak_usage(true)])."\n";
}finally{foreach(glob($dir.'/*')?:[] as $p)unlink($p);rmdir($dir);}
