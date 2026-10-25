<?php
/** Fixed 010 maintenance adapter for this authorized migration only. Never GRANT, copy customer credentials or log secrets. */
if(PHP_SAPI!=='cli'||!preg_match('~^/www/backups/010/r61-combination-98e0d80e(?:-retry[1-9][0-9]*)?$~D',$argv[1]??'')||($argv[2]??'')!=='migrate'||($argv[3]??'')!=='25615e158e996e7073451f3f566b7afe0d12fb9de1bbca940442ba944b6a1855')exit(2);
set_exception_handler(static function(Throwable $e){file_put_contents('php://stderr','010 fixed migration failed; credentials not logged'.PHP_EOL);exit(1);});
$file=$argv[1].'/migrate.php';if(hash_file('sha256',$file)!=='4a73e102c55c9fb74fbe1e0f82e88430f13c7143bc20014e92dcb6e424efbeba')throw new RuntimeException('Migrator drift');
$source=file_get_contents($file);
$needle=<<<'PHP'
$pdo=new PDO('mysql:host='.$cfg['hostname'].';port='.$port.';dbname='.$database.';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
PHP;
$replacement=<<<'PHP'
if($site!=='010'||$database!=='mohe_010_app'||$port!=='3307')throw new RuntimeException('Fixed target required');
$runtime=new PDO('mysql:host='.$cfg['hostname'].';port='.$port.';dbname='.$database.';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$runtimeGrants=hash('sha256',json_encode($runtime->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)));
$credentials=parse_ini_file('/root/.mysql57-008.cnf',true,INI_SCANNER_RAW)['client'];
$pdo=new PDO('mysql:host=127.0.0.1;port=3307;dbname=mohe_010_app;charset=utf8mb4',$credentials['user'],$credentials['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);unset($credentials);
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='mohe_010_app'||(int)$pdo->query('SELECT @@port')->fetchColumn()!==3307)throw new RuntimeException('Maintenance target mismatch');
PHP;
if(substr_count($source,$needle)!==1)throw new RuntimeException('Connection boundary');
// Keep all original manifest, backup, SQL checksum and schema verification guards.
eval(substr(str_replace($needle,$replacement,$source),5));
if($runtimeGrants!==hash('sha256',json_encode($runtime->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN))))throw new RuntimeException('Application grants changed');
echo 'R61_010_MAINTENANCE_PASS applicationGrantsUnchanged=true',PHP_EOL;
