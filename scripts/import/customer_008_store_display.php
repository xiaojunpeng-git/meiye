<?php
/**
 * Restore only 008's missing store-display settings. The old Meiyue site has
 * no modern entry-rule setting: translate its explicit-store/nearby behaviour,
 * never infer member ownership, copy credentials or change business mode.
 */
declare(strict_types=1);

const SD008_KEY = '20261019-008-store-display-r2';
const SD008_BASE = 'f320f80415afe687d740da816552b32486a5c080205c22e6a7732748265e60ed';
const SD008_KEYS = "'store_func_status','shop_operation_type','user_entry_rules'";
const SD008_DIR = '/www/backups/008.cc3798.com/20261019-round2-store-display';

/** Keep the same JSON representation for fixed baselines, backup and readback. */
function sd008Json($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** These six records match the current editor schema, including disabled rules.
 * Explicit/nearby selection reproduces the old site; ownership, visit history,
 * forced default stores and region isolation remain disabled, not inferred.
 */
function sd008Rules(): array
{
    $ui = ['collapse'=>false, 'contentHeight'=>23];
    return [
        array_merge(['name'=>'user_param_store','is_use'=>1,'is_cache'=>0,'cache_time'=>0], $ui),
        array_merge(['name'=>'user_belong_store','is_use'=>0,'is_change_store'=>1], $ui),
        array_merge(['name'=>'user_visit_store','is_use'=>0,'is_alone'=>0], $ui),
        array_merge(['name'=>'user_locate_store','is_use'=>1,'is_region_alone'=>0], $ui),
        array_merge(['name'=>'default_store','is_use'=>0,'default_type'=>1,'store_id'=>0,'url'=>''], $ui),
        array_merge(['name'=>'region_recommend_store','is_use'=>0,'region_id'=>[],'store_id'=>0], $ui),
    ];
}

/** Reads only the three whitelisted rows; locking is used only in write modes. */
function sd008Rows(PDO $p, bool $lock = false): array
{
    return $p->query('SELECT * FROM eb_system_config WHERE menu_name IN ('.SD008_KEYS.') ORDER BY id'.($lock?' FOR UPDATE':''))->fetchAll();
}

/** Refuse missing, duplicated, store-scoped or no-longer-empty configurations. */
function sd008After(array $rows): array
{
    if (count($rows)!==3 || array_column($rows,'menu_name')!==['store_func_status','shop_operation_type','user_entry_rules']) {
        throw new RuntimeException('Store configuration identity changed');
    }
    foreach ($rows as $row) if ((int)$row['is_store']!==0 || (int)$row['status']!==1) throw new RuntimeException('Store config scope/status changed');
    if ($rows[0]['value']!=='' || $rows[1]['value']!=='2' || $rows[2]['value']!=='') throw new RuntimeException('Fixed empty settings or existing mode changed');
    $rows[0]['value']='1';
    $rows[2]['value']=sd008Json(sd008Rules());
    return $rows;
}

/** Bind remote credentials in memory to one known database and TCP port. */
function sd008Connect(string $file, string $name, int $port): PDO
{
    $e=parse_ini_file($file,true); $d=$e['DATABASE']??[];
    if (($d['DATABASE']??'')!==$name || (int)($d['HOSTPORT']??0)!==$port) throw new RuntimeException('Database environment mismatch');
    $p=new PDO('mysql:host='.$d['HOSTNAME'].';port='.$port.';dbname='.$name.';charset=utf8mb4',$d['USERNAME'],$d['PASSWORD'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES=>true, PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $id=$p->query('SELECT DATABASE() db,@@port port')->fetch();
    if ($id['db']!==$name || (int)$id['port']!==$port) throw new RuntimeException('Connected database mismatch');
    return $p;
}

/** Exclusive, private snapshots are mandatory before any SQL mutation. */
function sd008Save(string $file, array $data): void
{
    $dir=dirname($file);
    if (!is_dir($dir) || is_link($dir) || (fileperms($dir)&0077)!==0) throw new RuntimeException('Private backup directory required');
    $old=umask(0077);
    try {
        $f=fopen($file,'x');
        if (!$f) throw new RuntimeException('Exclusive backup unavailable');
        try {
            $bytes=sd008Json($data);
            if (fwrite($f,$bytes)!==strlen($bytes) || !fflush($f)) throw new RuntimeException('Incomplete backup');
        } finally { fclose($f); }
    } finally { umask($old); }
}

/** Both value changes and the audit are atomic. Any drift, failed readback or
 * audit error rolls back; the previous mode and every metadata field survive.
 * Tests supply synthetic baselines; production main pins SD008_BASE separately.
 */
function sd008Apply(PDO $p, array $before, string $dir, string $commit): array
{
    if (!preg_match('/^[a-f0-9]{40}$/D',$commit)) throw new RuntimeException('Accepted commit required');
    $after=sd008After($before);
    // A transaction cannot protect configuration or audit stored in MyISAM.
    $engines=$p->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('eb_system_config','eb_database_upgrade_log')")->fetchAll(PDO::FETCH_COLUMN);
    if (count($engines)!==2 || array_filter($engines,function($engine){return strtoupper($engine)!=='INNODB';})) throw new RuntimeException('Transactional configuration and audit tables required');
    $p->beginTransaction();
    try {
        if (sd008Rows($p,true)!==$before) throw new RuntimeException('Concurrent configuration change');
        $q=$p->prepare('SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key=?'); $q->execute([SD008_KEY]);
        if ((int)$q->fetchColumn()!==0) throw new RuntimeException('Already applied; verify instead');
        $snapshot=['instance'=>'008.cc3798.com','database'=>'mohe_008','commit'=>$commit,'before'=>$before,'after'=>$after];
        sd008Save($dir.'/before.json',$snapshot);
        $q=$p->prepare('UPDATE eb_system_config SET value=? WHERE id=? AND BINARY value=BINARY ?');
        foreach ([0,2] as $i) {
            $q->execute([$after[$i]['value'],$before[$i]['id'],$before[$i]['value']]);
            if ($q->rowCount()!==1) throw new RuntimeException('Value compare-and-set failed');
        }
        if (sd008Rows($p)!==$after) throw new RuntimeException('Unexpected configuration mutation');
        $receipt=['rows'=>2,'mode_preserved'=>2,'before_sha256'=>hash('sha256',sd008Json($before)),
            'after_sha256'=>hash('sha256',sd008Json($after)),'backup_sha256'=>hash_file('sha256',$dir.'/before.json')];
        $q=$p->prepare('INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note) VALUES (?,?,?,?,?,NOW(),?,?)');
        $q->execute([SD008_KEY,'008 store display configuration','scripts/import/customer_008_store_display.php',
            hash_file('sha256',__FILE__),$commit,'Codex',sd008Json($receipt)]);
        $p->commit();
        return $receipt;
    } catch (Throwable $e) { if ($p->inTransaction()) $p->rollBack(); throw $e; }
}

/** Restore only this batch, never overwrite later user changes or erase audit. */
function sd008Rollback(PDO $p, array $snapshot): void
{
    if (($snapshot['instance']??'')!=='008.cc3798.com' || ($snapshot['database']??'')!=='mohe_008'
        || sd008After($snapshot['before']??[])!==($snapshot['after']??null)) throw new RuntimeException('Invalid rollback snapshot');
    $p->beginTransaction();
    try {
        if (sd008Rows($p,true)!==$snapshot['after']) throw new RuntimeException('Later configuration edit prevents rollback');
        $q=$p->prepare('SELECT code_version,result_note FROM eb_database_upgrade_log WHERE upgrade_key=? FOR UPDATE');$q->execute([SD008_KEY]);$audit=$q->fetch();
        if (!$audit || $audit['code_version']!==$snapshot['commit'] || (json_decode($audit['result_note'],true)['after_sha256']??'')!==hash('sha256',sd008Json($snapshot['after']))) throw new RuntimeException('Rollback audit mismatch');
        $q=$p->prepare('UPDATE eb_system_config SET value=? WHERE id=?');
        foreach ([0,2] as $i) $q->execute([$snapshot['before'][$i]['value'],$snapshot['before'][$i]['id']]);
        if (sd008Rows($p)!==$snapshot['before']) throw new RuntimeException('Rollback readback failed');
        $q=$p->prepare('UPDATE eb_database_upgrade_log SET result_note=? WHERE upgrade_key=?');
        $q->execute([sd008Json(['status'=>'ROLLED_BACK','after_sha256'=>hash('sha256',sd008Json($snapshot['after']))]),SD008_KEY]);
        $p->commit();
    } catch (Throwable $e) { if ($p->inTransaction()) $p->rollBack(); throw $e; }
}

/** Expire exactly three global config entries, including the stale mode cache.
 * No sessions, member contexts, keys from other instances or Redis flushes.
 */
function sd008Invalidate(): void
{
    $e=parse_ini_file('/www/wwwroot/008.cc3798.com/.env',true);$c=$e['REDIS']??[];
    if (($e['CACHE']['PREFIX']??'')!=='008:' || ($e['CACHE']['TAG_PREFIX']??'')!=='008:'
        || (int)($c['PORT']??0)!==6388 || !in_array($c['REDIS_HOSTNAME']??'',['127.0.0.1','localhost'],true)) throw new RuntimeException('008 Redis isolation changed');
    $r=new Redis();$r->connect($c['REDIS_HOSTNAME'],6388);
    if (($c['REDIS_PASSWORD']??'')!=='') $r->auth($c['REDIS_PASSWORD']);
    $r->select((int)($c['SELECT']??0));
    $r->del('008:system_config:store_func_status','008:system_config:user_entry_rules','008:system_config:shop_operation_type');
    $r->close();
}

/** Check is read-only on both databases and never creates a server file. */
function sd008Main(array $argv): void
{
    $mode=$argv[1]??'';
    if (!in_array($mode,['check','apply','verify','rollback','invalidate'],true)) throw new RuntimeException('Use check/apply/verify/rollback/invalidate');
    $p=sd008Connect('/www/wwwroot/008.cc3798.com/.env','mohe_008',3307);
    if ($mode==='check' || $mode==='apply') {
        $s=sd008Connect('/www/wwwroot/morestore.cc3798.com/.env','morestore',3306);
        $s->exec('SET SESSION TRANSACTION READ ONLY');$s->beginTransaction();
        try {
            $old=$s->query("SELECT menu_name,value FROM eb_system_config WHERE menu_name IN (".SD008_KEYS.") ORDER BY id")->fetchAll();
            if ($old!==[['menu_name'=>'store_func_status','value'=>'1']]) throw new RuntimeException('Old Meiyue configuration changed');
        } finally { $s->rollBack(); }
        $p->exec('SET SESSION TRANSACTION READ ONLY');$p->beginTransaction();
        try {
            $before=sd008Rows($p);
            if (hash('sha256',sd008Json($before))!==SD008_BASE) throw new RuntimeException('Fixed production baseline changed');
            sd008After($before);
            if ((int)$p->query('SELECT COUNT(*) FROM eb_system_store WHERE is_del=0 AND is_show=1')->fetchColumn()!==130) throw new RuntimeException('Store baseline changed');
        } finally { $p->rollBack(); }
        if ($mode==='apply') {
            if (!is_dir(SD008_DIR) && !mkdir(SD008_DIR,0700)) throw new RuntimeException('Cannot create backup directory');
            $p->exec('SET SESSION TRANSACTION READ WRITE');
            $receipt=sd008Apply($p,$before,SD008_DIR,$argv[2]??'');
            sd008Invalidate();
            echo sd008Json(['status'=>'APPLIED','receipt'=>$receipt]).PHP_EOL;
        } else echo sd008Json(['status'=>'CHECKED','baseline'=>SD008_BASE,'changed_rows'=>2,'mode_preserved'=>2,'rules'=>sd008Rules()]).PHP_EOL;
        return;
    }
    $snapshot=json_decode(file_get_contents(SD008_DIR.'/before.json'),true,512,JSON_THROW_ON_ERROR);
    if (hash('sha256',sd008Json($snapshot['before']))!==SD008_BASE || sd008After($snapshot['before'])!==$snapshot['after']) throw new RuntimeException('Backup baseline mismatch');
    if ($mode==='rollback') { sd008Rollback($p,$snapshot);sd008Invalidate();echo "ROLLED_BACK\n";return; }
    $p->exec('SET SESSION TRANSACTION READ ONLY');$p->beginTransaction();
    try { if (sd008Rows($p)!==$snapshot['after']) throw new RuntimeException('Verification mismatch'); }
    finally { $p->rollBack(); }
    if ($mode==='invalidate') sd008Invalidate();
    echo "VERIFIED\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try { sd008Main($argv); }
    catch (Throwable $e) { fwrite(STDERR,$e instanceof RuntimeException?$e->getMessage().PHP_EOL:"Store configuration failed; inspect private diagnostics.\n");exit(1); }
}
