<?php
/** 010独立升级通道：固定3307/mohe_010_app，仅创建本轮两张元数据表，不授予应用账号DDL。 */
$backup=$argv[1]??'';if($backup!=='/www/backups/010/20261023-r28-18669cbf')throw new RuntimeException('FIXED_010_BACKUP_REQUIRED');
$m=json_decode(file_get_contents($backup.'/payload/manifest.json'),true,512,JSON_THROW_ON_ERROR);if($m['commit']!=='18669cbf303299b217f32d1d0a99727ba13dc1be')throw new RuntimeException('WRONG_COMMIT');
$prepared=json_decode(file_get_contents($backup.'/prepared.json'),true,512,JSON_THROW_ON_ERROR);if(hash_file('sha256',$backup.'/before/metadata.json.gz')!==$prepared['backupSha256'])throw new RuntimeException('BACKUP_CHANGED');
$env=parse_ini_file('/www/wwwroot/010.cc3798.com/.env',true,INI_SCANNER_RAW);$app=array_change_key_case($env['DATABASE']??$env['database']??[]);if($app['database']!=='mohe_010_app'||(string)$app['hostport']!=='3307')throw new RuntimeException('INSTANCE_MISMATCH');
$client=parse_ini_file('/root/.mysql57-008.cnf',true,INI_SCANNER_RAW)['client'];
$pdo=new PDO('mysql:host=127.0.0.1;port=3307;dbname=mohe_010_app;charset=utf8mb4',$client['user'],$client['password']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='mohe_010_app'||(int)$pdo->query('SELECT @@port')->fetchColumn()!==3307)throw new RuntimeException('DDL_TARGET_MISMATCH');
$runtime=new PDO('mysql:host=127.0.0.1;port=3307;dbname=mohe_010_app;charset=utf8mb4',$app['username'],$app['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$grantsBefore=$runtime->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
$sql=$backup.'/payload/02-upgrade.sql';if(hash_file('sha256',$sql)!==$m['migrationSha256'])throw new RuntimeException('SQL_CHANGED');$tables=[];
foreach(explode(';',file_get_contents($sql))as $statement)if(preg_match('~CREATE TABLE IF NOT EXISTS `(eb_store_v3_permission_(?:catalog|audit))`~',$statement,$match)){$pdo->exec($statement);$tables[$match[1]]=hash('sha256',json_encode($pdo->query('SHOW CREATE TABLE '.$match[1])->fetch(PDO::FETCH_ASSOC)));}
if(count($tables)!==2||$grantsBefore!==$runtime->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN))throw new RuntimeException('DDL_OR_PRIVILEGE_MISMATCH');
$result=['site'=>'010','database'=>'mohe_010_app','port'=>3307,'commit'=>$m['commit'],'tables'=>$tables,'applicationGrants'=>'unchanged','businessWrites'=>0,'result'=>'PASS'];file_put_contents($backup.'/ddl-ready.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));echo json_encode($result),PHP_EOL;
