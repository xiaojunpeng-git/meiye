<?php
/** 回读浏览器实际下载的同一XLSX，逐行与真实HTTP台账摘要对账；不输出业务原文。 */
require '/var/www/html/vendor/autoload.php';(new think\App('/var/www/html/'))->initialize();
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('LOCAL_ONLY');
$expected=json_decode(file_get_contents('/tmp/r29-fund-export-expected.json'),true);$service=new app\services\fund\StoreFundServices();$path=$service->exportFilePath($expected['fileKey']);
if(hash_file('sha256',$path)!==$expected['downloadSha256'])throw new RuntimeException('BROWSER_DOWNLOAD_BYTES_DIFFER');
$sheet=PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();if($sheet->getCell('H3')->getValue()!=='摘要')throw new RuntimeException('SUMMARY_HEADER_MISSING');
foreach($expected['rows']as $i=>$row){$summary=(string)($row['summary']??'')!==''?(string)$row['summary']:(string)($row['document_summary']??'');if((string)$sheet->getCell('H'.($i+5))->getValue()!==$summary)throw new RuntimeException('SUMMARY_DIFFERS_ROW_'.$i);}
echo 'PASS browser-downloaded XLSX bytes match generated file; '.count($expected['rows']).' real HTTP ledger summaries reconcile exactly'.PHP_EOL;
unlink($path);
$session=json_decode(file_get_contents('/tmp/r29-fund-session.json'),true);mohe\services\CacheService::clearToken(md5($session['token']));if(mohe\services\CacheService::hasToken(md5($session['token'])))throw new RuntimeException('TEST_SESSION_REVOKE_FAILED');
unlink('/tmp/r29-fund-session.json');unlink('/tmp/r29-fund-export-expected.json');echo "PASS isolated local session revoked; generated export and temporary business data removed\n";
