<?php
/** 发布前只读核对目标方案结构与事实摘要；不输出目标内容，不修改业务数据。 */
$site = $argv[1] ?? '';
$sites = ['rh'=>['rh.cc3798.com','ruihao','3306'], '008'=>['008.cc3798.com','mohe_008','3307'], '010'=>['010.cc3798.com','mohe_010_app','3307']];
if (!isset($sites[$site])) throw new RuntimeException('Explicit instance required');
[$domain,$name,$port] = $sites[$site];
$root = '/www/wwwroot/'.$domain;
$env = parse_ini_file($root.'/.env', true, INI_SCANNER_RAW);
$cfg = array_change_key_case($env['DATABASE'] ?? $env['database'] ?? []);
if ($cfg['database'] !== $name || (string)$cfg['hostport'] !== $port || $cfg['prefix'] !== 'eb_') throw new RuntimeException('Wrong instance');
$db = new PDO('mysql:host='.$cfg['hostname'].';port='.$port.';dbname='.$name.';charset=utf8mb4', $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('SET TRANSACTION READ ONLY'); $db->beginTransaction();
try {
    $out = ['site'=>$site,'tables'=>[],'adminAssets'=>[]];
    $tables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'eb_target_%' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (!preg_match('/^eb_target_[a-z_]+$/D', $table)) throw new RuntimeException('Unexpected table');
        $rows = $db->query('SELECT * FROM `'.$table.'` ORDER BY id')->fetchAll();
        $out['tables'][$table] = ['rows'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_UNESCAPED_UNICODE)), 'schema'=>$db->query('SHOW CREATE TABLE `'.$table.'`')->fetch()];
    }
    foreach ([$root, ...($site === 'rh' ? [$root.'-8080'] : [])] as $front) {
        $assets = [];
        if (is_dir($front.'/public/view_admin')) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($front.'/public/view_admin',FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) $assets[substr($file->getPathname(),strlen($front.'/public/') )] = hash_file('sha256',$file->getPathname());
            }
        }
        ksort($assets); $out['adminAssets'][$front] = $assets;
    }
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;
} finally { $db->rollBack(); }
