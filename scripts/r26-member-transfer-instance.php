<?php
declare(strict_types=1);

/** 逐实例固定包发布：只建批次表/索引、更新本轮后端与平台资源，不执行真实会员调店。 */
$instance = $argv[1] ?? '';
$mode = $argv[2] ?? 'probe';
$directory = $argv[3] ?? '';
$sites = ['rh' => ['/www/wwwroot/rh.cc3798.com', 'ruihao', '3306'], '008' => ['/www/wwwroot/008.cc3798.com', 'mohe_008', '3307']];
if (!isset($sites[$instance]) || !in_array($mode, ['probe','prepare','install','verify','rollback'], true)) throw new RuntimeException('Explicit instance/mode required');
[$root, $database, $port] = $sites[$instance];
$assert = static function (bool $okay, string $message): void { if (!$okay) throw new RuntimeException($message); };
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL); exit(1); });
// 发布检查只读取本实例配置，不启动业务容器，避免CLI触发应用初始化副作用。
$environment = parse_ini_file($root.'/.env', true, INI_SCANNER_RAW);
$cfg = array_change_key_case($environment['DATABASE'] ?? $environment['database'] ?? []);
$assert($cfg['database'] === $database && (string)$cfg['hostport'] === $port && $cfg['prefix'] === 'eb_'
    && in_array($cfg['hostname'], ['localhost','127.0.0.1'], true), 'Wrong instance database');
$db = new PDO('mysql:host='.$cfg['hostname'].';port='.$port.';dbname='.$database.';charset=utf8mb4', $cfg['username'], $cfg['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$hash = static function (string $file): ?string { return is_file($file) ? hash_file('sha256',$file) : null; };
$backend = ['route/admin.php','app/services/user/UserBelongStoreServices.php','app/services/user/UserStoreTransferBatchServices.php',
    'app/controller/admin/v1/user/MemberStoreTransfer.php','app/jobs/user/UserStoreTransferBatchJob.php'];
$protectedPaths = ['.env','public/index.html','public/view_cashier_v3/index.html'];
if ($instance === 'rh') $protectedPaths[] = '../rh.cc3798.com-8080/public/view_cashier_v3/index.html';
$snapshot = static function () use ($root,$instance,$database,$port,$backend,$protectedPaths,$hash,$db): array {
    $result = ['instance'=>$instance,'database'=>$database,'port'=>$port,'backend'=>[],'protected'=>[],'admin'=>[]];
    foreach ($backend as $file) $result['backend'][$file] = $hash($root.'/'.$file);
    foreach ($protectedPaths as $file) $result['protected'][$file] = $hash($root.'/'.$file);
    $html = file_get_contents($root.'/public/system.html');
    $result['admin']['public/system.html'] = hash('sha256',$html);
    preg_match_all('~(?:src|href)=["\']?([^"\'\s>]+\.(?:js|css))~',$html,$matches);
    foreach ($matches[1] as $file) $result['admin']['public/'.ltrim($file,'/')] = $hash($root.'/public/'.ltrim($file,'/'));
    $result['latestUpgrades'] = $db->query('SELECT upgrade_key,code_version,executed_at FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 3')->fetchAll();
    $result['batchTables'] = $db->query("SHOW TABLES LIKE 'eb_user_store_transfer_batch%'")->fetchAll();
    $result['memberIndexes'] = $db->query("SHOW INDEX FROM eb_user WHERE Key_name='idx_belong_active_uid'")->fetchAll();
    $result['batchQueue'] = 'verify instance worker configuration separately';
    return $result;
};
if ($mode === 'probe') { echo json_encode($snapshot(),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL; exit; }
$assert($directory === '/www/backups/'.$instance.'/20261022-r26-bulk-802490dc', 'Wrong backup directory');
$manifest = json_decode(file_get_contents($directory.'/payload/manifest.json'),true,512,JSON_THROW_ON_ERROR);
$assert($manifest['commit'] === '802490dcf4facafd1a29bad7fb6191d33698d772', 'Wrong fixed source commit');
foreach ($manifest['files'] as $file=>$expected) {
    $assert(preg_match('~^(app/|route/|public/)~',$file)===1 && strpos($file,'..')===false, 'Unsafe payload path');
    $assert($hash($directory.'/payload/'.$file)===$expected,'Payload drift '.$file);
}
$save = static function (string $file,array $data) use ($directory): void { file_put_contents($directory.'/'.$file,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL); };
if ($mode === 'prepare') {
    $assert(!file_exists($directory.'/before.json'),'Backup already exists');
    $current = $snapshot();
    $assert($current===$manifest['before'][$instance],'Instance advanced since preflight');
    $assert($current['batchTables']===[] && $current['memberIndexes']===[], 'Migration requires reconciliation');
    $before = ['snapshot'=>$current,'files'=>[],'schema'=>[]];
    foreach (['eb_user','eb_database_upgrade_log'] as $table) $before['schema'][$table] = array_values($db->query('SHOW CREATE TABLE '.$table)->fetch())[1];
    foreach ($manifest['files'] as $file=>$expected) {
        $old = $hash($root.'/'.$file); $before['files'][$file]=$old;
        if ($old !== null && $old !== $expected) {
            $dest=$directory.'/before/'.$file;
            if(!is_dir(dirname($dest)))mkdir(dirname($dest),0700,true);
            $assert(copy($root.'/'.$file,$dest) && $hash($dest)===$old,'Backup failure '.$file);
        }
    }
    $save('before.json',$before); chmod($directory.'/before.json',0600);
    echo json_encode(['result'=>'PREPARED','instance'=>$instance,'files'=>count($before['files']),'schemaBackup'=>true]),PHP_EOL; exit;
}
$before = json_decode(file_get_contents($directory.'/before.json'),true,512,JSON_THROW_ON_ERROR);
if ($mode === 'rollback') {
    // 恢复逐文件备份；新增DDL保留为空审计结构，不删除潜在已产生的业务事实。
    foreach ($before['files'] as $file=>$expected) {
        if (strpos($file,'public/')===0 && $file!=='public/system.html') continue;
        $dest=$root.'/'.$file;
        if ($expected===null) { if(is_file($dest))unlink($dest); continue; }
        if ($hash($dest)===$expected) continue;
        $source=$directory.'/before/'.$file;
        $assert($hash($source)===$expected,'Rollback backup mismatch');
        $assert(copy($source,$dest),'Rollback copy failure');chmod($dest,0644);chown($dest,'www');chgrp($dest,'www');
    }
    $save('rollback.json',['time'=>date(DATE_ATOM),'schemaRetained'=>true,'instance'=>$instance]);
    echo 'R26_CODE_ROLLBACK_RESTORED',PHP_EOL; exit;
}
if ($mode === 'install') {
    $assert(!file_exists($directory.'/installed.json'),'Already installed');
    $assert($snapshot()===$before['snapshot'],'Baseline drift before install');
    foreach ($before['files'] as $file=>$expected) $assert($hash($root.'/'.$file)===$expected,'File advanced '.$file);
    // 迁移仅添加空表和在线索引，不改会员业务值；中断时保留现有审计表供核对，不盲目删表。
    foreach (['01-schema.sql','02-member-index.sql'] as $name) {
        $sql=file_get_contents($directory.'/payload/schema/'.$name);
        $assert(hash('sha256',$sql)===$manifest['schema'][$name],'Schema payload drift');
        foreach(explode(';',preg_replace('/^--.*$/m','',$sql)) as $statement)if(trim($statement)!=='')$db->exec($statement);
    }
    $indexes=$db->query("SHOW INDEX FROM eb_user WHERE Key_name='idx_belong_active_uid'")->fetchAll();
    $assert(array_column($indexes,'Column_name')===['belong_store_id','is_del','delete_time','uid'],'Wrong index definition');
    foreach (['eb_user_store_transfer_batch','eb_user_store_transfer_batch_item'] as $table) {
        $assert((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'Unexpected existing batch data');
    }
    // 资源先入库，入口最后切换；保留旧哈希资源供已打开页面继续使用。
    $files=array_keys($manifest['files']);usort($files,static function($a,$b){return (int)($a==='public/system.html')<=>(int)($b==='public/system.html');});
    foreach($files as $file){$dest=$root.'/'.$file;if(!is_dir(dirname($dest)))mkdir(dirname($dest),0755,true);
        $next=$dest.'.r26-next';$assert(copy($directory.'/payload/'.$file,$next),'Stage failure');chmod($next,0644);chown($next,'www');chgrp($next,'www');
        $assert(rename($next,$dest),'Switch failure '.$file);}
    $stmt=$db->prepare('INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note) VALUES (?,?,?,?,?,NOW(),?,?)');
    $stmt->execute(['20261022-r26-member-store-transfer','门店所属会员批量转移及归属索引','后端代码/database/upgrades/2026-10-22-门店所属会员批量转移/',
        hash('sha256',implode('',$manifest['schema'])),$manifest['commit'],'Codex R26','仅DDL，无真实会员调店；批次表初始为空']);
    $save('installed.json',['instance'=>$instance,'commit'=>$manifest['commit'],'time'=>date(DATE_ATOM)]);
}
foreach($manifest['files'] as $file=>$expected)$assert($hash($root.'/'.$file)===$expected,'Installed mismatch '.$file);
$after=$snapshot();
$assert($after['protected']===$before['snapshot']['protected'],'Other terminal/configuration changed');
$assert(count($after['batchTables'])===2 && count($after['memberIndexes'])===4,'Missing schema');
$assert($after['latestUpgrades'][0]['upgrade_key']==='20261022-r26-member-store-transfer','Migration log mismatch');
$save('verified.json',['instance'=>$instance,'commit'=>$manifest['commit'],'result'=>'PASS','snapshot'=>$after,'files'=>count($manifest['files']),'businessTransfersExecuted'=>0]);
echo json_encode(['instance'=>$instance,'result'=>'PASS','files'=>count($manifest['files']),'businessTransfersExecuted'=>0]),PHP_EOL;
