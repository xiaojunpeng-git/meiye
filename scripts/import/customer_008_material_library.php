<?php
/**
 * Old Meiyue image-picker import, never a whole attachment-table copy.
 * Content is transferred once; category references remain separate. Imported
 * files are immutable/root-owned so deleting one picker reference cannot break
 * another category, product or decoration that still uses the same file.
 */
declare(strict_types=1);

const MEDIA008_KEY = '20261019-008-old-media-library-r2';
const MEDIA008_NAMESPACE = '/uploads/import/meiyue-library-20261019';
const MEDIA008_REPORT_SHA = 'b559d1d0cea51d2eee9e08242b640a78ccc0deaee5514d0a258f873eeb4c1c9b';

/** Canonical JSON hashes bind records without exposing private upload tokens. */
function media008Sha(array $value): string
{
    return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** Derive content/category groups while retaining every old ID for reconciliation. */
function media008Plan(array $report): array
{
    $cats = array_column($report['categories'], null, 'id');
    $ordered = [];
    while (count($ordered) < count($cats)) {
        $progress = false;
        foreach ($cats as $id => $c) {
            if (isset($ordered[$id])) continue;
            if ((int)$c['pid'] !== 0 && !isset($ordered[$c['pid']])) continue;
            if ((int)$c['type'] !== 1 || (int)$c['relation_id'] !== 0 || (int)$c['file_type'] !== 1) throw new RuntimeException('Source category scope changed');
            $ordered[$id] = $c; $progress = true;
        }
        if (!$progress) throw new RuntimeException('Missing/cyclic category parent');
    }
    $existing = [];
    foreach ($report['objects'] as $o) if ($o['kind'] === 'target' && $o['ok']) $existing[$o['image']['sha256']][] = (int)$o['key'];
    $groups = $assets = $skipped = [];
    foreach ($report['objects'] as $o) {
        if ($o['kind'] !== 'source') continue;
        $catalog = $report['catalog'][$o['key']];
        if (!$o['ok']) {
            if ($catalog['source_ids'] !== [60] || $o['error'] !== 'missing_file') throw new RuntimeException('Unapproved unavailable picture');
            $skipped[] = 60; continue;
        }
        $pid = (int)$catalog['source_pid'];
        if ($pid !== 0 && !isset($cats[$pid])) throw new RuntimeException('Image category missing');
        $sha = $o['image']['sha256'];
        if (!preg_match('/^[a-f0-9]{64}$/', $sha)) throw new RuntimeException('Invalid image digest');
        $key = $sha . ':' . $pid;
        if (!isset($groups[$key])) $groups[$key] = ['sha256'=>$sha,'source_pid'=>$pid,'source_ids'=>[], 'metadata'=>$catalog['metadata']];
        $groups[$key]['source_ids'] = array_merge($groups[$key]['source_ids'], $catalog['source_ids']);
        if ((int)$catalog['metadata']['att_id'] > (int)$groups[$key]['metadata']['att_id']) $groups[$key]['metadata'] = $catalog['metadata'];
        // Prefer the already present old local bytes instead of downloading an identical CDN object.
        if (!isset($assets[$sha]) || $o['read_mode'] === 'local_file') $assets[$sha] = ['image'=>$o['image'],'source'=>$o];
    }
    ksort($groups); ksort($assets);
    $reuse = [];
    foreach ($groups as &$g) {
        sort($g['source_ids'], SORT_NUMERIC);
        $owners = $existing[$g['sha256']] ?? [];
        if (count($owners) > 1) throw new RuntimeException('Ambiguous existing target image');
        if ($owners) {
            if (isset($reuse[$owners[0]])) throw new RuntimeException('Existing image has conflicting source categories');
            $g['reuse_id'] = $owners[0]; $reuse[$owners[0]] = $g['source_pid'];
            unset($assets[$g['sha256']]);
        }
    } unset($g);
    $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/bmp'=>'bmp'];
    foreach ($assets as $sha => &$a) {
        if (!isset($ext[$a['image']['mime']])) throw new RuntimeException('Unsupported image format');
        $a['filename'] = $sha . '.' . $ext[$a['image']['mime']];
        $a['public_path'] = MEDIA008_NAMESPACE . '/' . $a['filename'];
    } unset($a);
    return ['schema'=>1,'instance'=>'008.cc3798.com','source_sha256'=>$report['source_sha256'],
        'source_categories_sha256'=>$report['source_categories_sha256'],'target_all_sha256'=>$report['target_all_sha256'],
        'target_categories_sha256'=>$report['target_categories_sha256'],'categories'=>array_values($ordered),
        'groups'=>array_values($groups),'assets'=>$assets,'reuse'=>$reuse,'skipped'=>$skipped];
}

/** Connect only the two pinned databases; old source callers always use READ ONLY. */
function media008Connect(string $path, string $name, int $port): PDO
{
    $e = parse_ini_file($path);
    if (!$e || ($e['DATABASE'] ?? '') !== $name || (int)($e['HOSTPORT'] ?? 0) !== $port) throw new RuntimeException('Database configuration mismatch');
    $p = new PDO('mysql:host='.$e['HOSTNAME'].';port='.$port.';dbname='.$name.';charset=utf8mb4', $e['USERNAME'], $e['PASSWORD'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STRINGIFY_FETCHES=>true,PDO::ATTR_EMULATE_PREPARES=>false]);
    $id = $p->query('SELECT DATABASE() db,@@port port')->fetch();
    if ($id['db'] !== $name || (int)$id['port'] !== $port) throw new RuntimeException('Connected DB mismatch');
    return $p;
}

/** Fail closed if a user uploads/moves a picture after the candidate was fixed. */
function media008Target(PDO $p, array $plan, bool $lock): array
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $rows = $p->query('SELECT * FROM eb_system_attachment ORDER BY att_id'.$suffix)->fetchAll();
    $cats = $p->query('SELECT * FROM eb_system_attachment_category ORDER BY id'.$suffix)->fetchAll();
    if (!hash_equals($plan['target_all_sha256'], media008Sha($rows)) || !hash_equals($plan['target_categories_sha256'],media008Sha($cats))) throw new RuntimeException('Target material baseline changed');
    return ['attachments'=>$rows,'categories'=>$cats];
}

/** Source catalog identity and scope stay pinned independently of its storage location. */
function media008Source(PDO $p, array $plan): void
{
    $rows = $p->query('SELECT * FROM eb_system_attachment WHERE type=1 AND relation_id=0 AND file_type=1 AND module_type=1 ORDER BY att_id')->fetchAll();
    $cats = $p->query('SELECT * FROM eb_system_attachment_category WHERE type=1 AND relation_id=0 AND file_type=1 ORDER BY id')->fetchAll();
    if (!hash_equals($plan['source_sha256'],media008Sha($rows)) || !hash_equals($plan['source_categories_sha256'],media008Sha($cats))) throw new RuntimeException('Source material baseline changed');
}

/** Private artifacts are immutable receipts; never overwrite a previous attempt. */
function media008Save(string $path, array $value): string
{
    $mask = umask(0077);
    try {
        $bytes = json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        $f = fopen($path,'x'); if (!$f) throw new RuntimeException('Private artifact already exists/unavailable');
        try { if (fwrite($f,$bytes)!==strlen($bytes) || !fflush($f)) throw new RuntimeException('Private artifact write failed'); } finally { fclose($f); }
        $sha = hash('sha256',$bytes);
        if (!hash_equals($sha,hash_file('sha256',$path))) throw new RuntimeException('Private artifact readback failed');
        return $sha;
    } finally { umask($mask); }
}

/** Fetch each distinct content hash at most once. Existing staged hashes are reused. */
function media008Stage(array $plan, string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('Cannot create private stage');
    foreach ($plan['assets'] as $sha => $a) {
        $path = $dir.'/'.$a['filename'];
        if (is_file($path)) {
            if (!hash_equals($sha,hash_file('sha256',$path))) throw new RuntimeException('Existing staged object differs');
            continue;
        }
        $src = $a['source'];
        if ($src['read_mode'] === 'local_file') {
            $root = realpath('/www/wwwroot/morestore.cc3798.com/public');
            $file = realpath($src['source_file']);
            if (!$root || !$file || strpos($file,$root.'/')!==0 || !is_file($file) || filesize($file)>32*1024*1024) throw new RuntimeException('Old local asset unavailable');
            $bytes = file_get_contents($file);
        } else {
            $url = parse_url($src['url']);
            if (($url['scheme']??'')!=='https' || ($url['host']??'')!=='myqiniu.cc3798.com' || isset($url['port']) || isset($url['user']) || isset($url['pass'])) throw new RuntimeException('Unapproved asset URL');
            $bytes = ''; $ch = curl_init($src['url']);
            curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>60,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION=>static function($h,$b)use(&$bytes){if(strlen($bytes)+strlen($b)>32*1024*1024)return 0;$bytes.=$b;return strlen($b);}]);
            $ok = curl_exec($ch); $http = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
            if (!$ok || $http!==200) throw new RuntimeException('Asset download failed; retry stage to reuse completed objects');
        }
        if (strlen($bytes)!==$a['image']['bytes'] || !hash_equals($sha,hash('sha256',$bytes))) throw new RuntimeException('Source asset bytes changed');
        $mask=umask(0077);
        try { $f=fopen($path,'x'); if(!$f)throw new RuntimeException('Stage path collision'); try{if(fwrite($f,$bytes)!==strlen($bytes)||!fflush($f))throw new RuntimeException('Stage write failed');}finally{fclose($f);} }
        finally { umask($mask); }
        if (!hash_equals($sha,hash_file('sha256',$path))) throw new RuntimeException('Stage readback failed');
    }
}

/** Seal imported assets against web-worker deletion; DB references may safely be shared. */
function media008Publish(array $plan, string $stage, string $public): array
{
    $dir=$public.MEDIA008_NAMESPACE;
    if (!is_dir($dir) && !mkdir($dir,0755,true)) throw new RuntimeException('Cannot create import namespace');
    if(realpath($dir)!==realpath($public).MEDIA008_NAMESPACE)throw new RuntimeException('Import namespace escaped its document root');
    if(function_exists('posix_geteuid')&&posix_geteuid()===0&&fileowner($dir)!==0)throw new RuntimeException('Import folder must be root-owned');
    $new=0;
    foreach($plan['assets'] as $sha=>$a){
        $src=$stage.'/'.$a['filename']; $dst=$dir.'/'.$a['filename'];
        if(is_link($src)||is_link($dst)||!is_file($src)||!hash_equals($sha,hash_file('sha256',$src)))throw new RuntimeException('Incomplete/tampered stage');
        if(!file_exists($dst)){if(!copy($src,$dst))throw new RuntimeException('Asset publication failed');$new++;}
        if(!is_file($dst)||!hash_equals($sha,hash_file('sha256',$dst))||!chmod($dst,0444))throw new RuntimeException('Published asset mismatch');
        if(function_exists('posix_geteuid')&&posix_geteuid()===0&&fileowner($dst)!==0)throw new RuntimeException('Imported file must be root-owned');
    }
    if(!chmod($dir,0555))throw new RuntimeException('Cannot seal imported assets');
    return ['files_created'=>$new,'files_total'=>count($plan['assets']),'directory'=>$dir,'directory_mode'=>'0555','file_mode'=>'0444'];
}

/** Atomically map categories and insert references; preserve all original columns except approved pids. */
function media008Apply(PDO $p, array $plan, string $stage, string $public, string $backupDir, string $commit, bool $rehearse=false): array
{
    if(!preg_match('/^[a-f0-9]{40}$/',$commit))throw new RuntimeException('Fixed commit required');
    $lock=$p->query("SELECT GET_LOCK('008_material_library_import',10)")->fetchColumn();
    if((int)$lock!==1)throw new RuntimeException('Material import lock unavailable');
    try{
        $p->beginTransaction();
        try{
            $m=$p->prepare('SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key=?');$m->execute([MEDIA008_KEY]);
            if((int)$m->fetchColumn()!==0)throw new RuntimeException('Material batch already applied');
            $before=media008Target($p,$plan,true);
            $snapshot=['instance'=>'008.cc3798.com','plan_sha256'=>media008Sha($plan),'commit'=>$commit,'before'=>$before];
            $backup=$backupDir.'/before.json';$backupSha=media008Save($backup,$snapshot);
            $published=$rehearse?['rehearsal'=>true]:media008Publish($plan,$stage,$public);
            $map=[0=>0];
            foreach($plan['categories'] as $c){
                $q=$p->prepare('INSERT INTO eb_system_attachment_category (type,file_type,relation_id,pid,name,enname) VALUES (0,1,0,?,?,?)');
                $q->execute([$map[(int)$c['pid']],$c['name'],$c['enname']]);$map[(int)$c['id']]=(int)$p->lastInsertId();
            }
            $ids=[];
            foreach($plan['groups'] as $g){
                $pid=$map[$g['source_pid']];
                if(isset($g['reuse_id'])){
                    $q=$p->prepare('UPDATE eb_system_attachment SET pid=? WHERE att_id=? AND type=0 AND relation_id=0 AND file_type=1 AND module_type=1');
                    $q->execute([$pid,$g['reuse_id']]);if($q->rowCount()!==1)throw new RuntimeException('Approved recategorization failed');
                    $ids[]=['att_id'=>$g['reuse_id'],'pid'=>$pid,'sha256'=>$g['sha256'],'reused'=>true];continue;
                }
                $a=$plan['assets'][$g['sha256']];$q=$p->prepare('INSERT INTO eb_system_attachment (type,file_type,relation_id,name,att_dir,satt_dir,att_size,att_type,pid,time,image_type,module_type,real_name) VALUES (0,1,0,?,?,?,?,?,?,?,1,1,?)');
                $q->execute([$a['filename'],$a['public_path'],$a['public_path'],(string)$a['image']['bytes'],$a['image']['mime'],$pid,(int)$g['metadata']['time'],$g['metadata']['real_name']]);
                $ids[]=['att_id'=>(int)$p->lastInsertId(),'pid'=>$pid,'sha256'=>$g['sha256'],'reused'=>false];
            }
            $receipt=['plan_sha256'=>media008Sha($plan),'categories'=>$map,'references'=>$ids,'published'=>$published,'backup'=>$backup,'backup_sha256'=>$backupSha];
            // Keep result_note compact for the real production varchar(500), with details in a private receipt.
            $note=json_encode(['plan'=>media008Sha($plan),'backup'=>basename($backup),'backup_sha256'=>$backupSha,'new'=>count($ids)-count($plan['reuse']),'reused'=>count($plan['reuse']),'categories'=>count($plan['categories'])],JSON_UNESCAPED_SLASHES);
            if(strlen($note)>500)throw new RuntimeException('Upgrade audit exceeds production limit');
            $q=$p->prepare('INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note) VALUES (?,?,?,?,?,NOW(),?,?)');
            $q->execute([MEDIA008_KEY,'008 old Meiyue material library','scripts/import/customer_008_material_library.php',media008Sha($plan),$commit,'Codex',$note]);
            media008Verify($p,$plan,$before,$receipt);
            media008Save($backupDir.'/receipt.json',$receipt);
            if($rehearse)$p->rollBack();else$p->commit();
            return $receipt;
        }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
    }finally{$p->query("SELECT RELEASE_LOCK('008_material_library_import')");}
}

/** Original automatic uploads and user-selected images must remain byte-for-byte metadata equivalent. */
function media008Verify(PDO $p,array $plan,array $before,array $receipt):void
{
    foreach($plan['categories'] as $c){
        $q=$p->prepare('SELECT * FROM eb_system_attachment_category WHERE id=?');$q->execute([$receipt['categories'][(int)$c['id']]]);$row=$q->fetch();
        if(!$row||(int)$row['type']!==0||(int)$row['file_type']!==1||(int)$row['relation_id']!==0||(int)$row['pid']!==$receipt['categories'][(int)$c['pid']]||$row['name']!==$c['name']||$row['enname']!==$c['enname'])throw new RuntimeException('Category mapping mismatch');
    }
    foreach($before['attachments'] as $old){
        $q=$p->prepare('SELECT * FROM eb_system_attachment WHERE att_id=?');$q->execute([$old['att_id']]);$now=$q->fetch();
        if(!$now)throw new RuntimeException('Existing attachment removed');
        if(isset($plan['reuse'][(int)$old['att_id']]))$old['pid']=(string)$receipt['categories'][$plan['reuse'][(int)$old['att_id']]];
        if($now!==$old)throw new RuntimeException('Unapproved existing attachment change');
    }
    foreach($receipt['references'] as $r){
        $q=$p->prepare('SELECT * FROM eb_system_attachment WHERE att_id=?');$q->execute([$r['att_id']]);$row=$q->fetch();
        if(!$row||(int)$row['pid']!==$r['pid']||(int)$row['type']!==0||(int)$row['file_type']!==1||(int)$row['module_type']!==1)throw new RuntimeException('Imported reference mismatch');
        if(!$r['reused']&&($row['att_dir']!==$plan['assets'][$r['sha256']]['public_path']||$row['satt_dir']!==$row['att_dir']||(int)$row['image_type']!==1||(int)$row['relation_id']!==0||$row['scan_token']!==''))throw new RuntimeException('Imported file path/scope mismatch');
    }
    if((int)$p->query('SELECT COUNT(*) FROM eb_system_attachment')->fetchColumn()!==count($before['attachments'])+count($plan['groups'])-count($plan['reuse']))throw new RuntimeException('Attachment count mismatch');
    if((int)$p->query('SELECT COUNT(*) FROM eb_system_attachment_category')->fetchColumn()!==count($before['categories'])+count($plan['categories']))throw new RuntimeException('Category count mismatch');
}

/** Read-only preparation is distinct from the separately accepted stage/apply steps. */
function media008Main(array $argv):void
{
    $mode=$argv[1]??'';if(!in_array($mode,['check','stage','apply'],true))throw new RuntimeException('Use check/stage/apply');
    $reportFile='/tmp/008-material-library-validated-20261019.json';
    if(!hash_equals(MEDIA008_REPORT_SHA,hash_file('sha256',$reportFile)))throw new RuntimeException('Validated report changed');
    $report=json_decode(file_get_contents($reportFile),true,512,JSON_THROW_ON_ERROR);$plan=media008Plan($report);
    $reuse=$plan['reuse'];ksort($reuse);
    if(count($plan['categories'])!==17||count($plan['groups'])!==926||count($plan['assets'])!==915||$reuse!==[25=>18,26=>18,27=>18]||$plan['skipped']!==[60])throw new RuntimeException('Approved material scope changed');
    $s=media008Connect('/www/wwwroot/morestore.cc3798.com/.env','morestore',3306);
    $p=media008Connect('/www/wwwroot/008.cc3798.com/.env','mohe_008',3307);
    $s->exec('SET SESSION TRANSACTION READ ONLY');$s->beginTransaction();
    try{
        media008Source($s,$plan);$p->exec('SET SESSION TRANSACTION READ ONLY');$p->beginTransaction();media008Target($p,$plan,false);$p->rollBack();
        $dir=$argv[2]??'/www/backups/008.cc3798.com/20261019-round2-material-library';$stage=$dir.'/stage';
        if(!preg_match('#^/www/backups/008\.cc3798\.com/[A-Za-z0-9_-]+$#',$dir))throw new RuntimeException('Backup directory must remain private to 008');
        $out=['plan_sha256'=>media008Sha($plan),'categories'=>17,'new_references'=>923,'reused_references'=>3,'unique_files'=>915,'skipped'=>[60]];
        if($mode!=='check'){
            if(!function_exists('posix_geteuid')||posix_geteuid()!==0)throw new RuntimeException('Immutable import requires root-owned CLI publication');
            media008Stage($plan,$stage);
            if($mode==='apply'){$p->exec('SET SESSION TRANSACTION READ WRITE');$out['receipt']=media008Apply($p,$plan,$stage,'/www/wwwroot/008.cc3798.com/public',$dir,$argv[3]??'');}
        }
        echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    }finally{if($s->inTransaction())$s->rollBack();if($p->inTransaction())$p->rollBack();}
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){try{media008Main($argv);}catch(Throwable $e){file_put_contents('php://stderr',$e instanceof RuntimeException?$e->getMessage()."\n":"Material operation failed; inspect private diagnostics.\n");exit(1);}}
