<?php
/**
 * Repair only the accepted import's URL columns. Full same-origin URLs avoid
 * the existing site_url=/admin prefix without changing global configuration,
 * original pictures, category mappings, files or decoration references.
 */
declare(strict_types=1);
require_once __DIR__.'/customer_008_material_library.php';

const MEDIA008_URL_KEY = '20261019-008-media-absolute-urls-r2';
const MEDIA008_ORIGIN = 'https://008.cc3798.com';

/** Only immutable, content-addressed paths from this import may become URLs. */
function media008AbsoluteUrl(string $path): string
{
    if (!preg_match('#^'.preg_quote(MEDIA008_NAMESPACE,'#').'/[a-f0-9]{64}\.(jpg|png|gif|webp|bmp)$#D',$path)) {
        throw new RuntimeException('Unapproved imported image path');
    }
    return MEDIA008_ORIGIN.$path;
}

/**
 * Compare the entire catalog under lock, then change precisely two URL columns.
 * All other fields and categories must match the snapshot after real writes;
 * audit failure rolls back every URL. Caller pins the production batch first.
 */
function media008RepairUrls(PDO $p,array $rows,array $cats,array $paths,string $dir,string $commit):array
{
    if (!preg_match('/^[a-f0-9]{40}$/D',$commit) || !$paths) throw new RuntimeException('Fixed URL repair required');
    $expected=array_column($rows,null,'att_id');
    foreach ($paths as $id=>$path) {
        media008AbsoluteUrl($path);
        $row=$expected[$id]??null;
        if (!$row || $row['att_dir']!==$path || $row['satt_dir']!==$path || (int)$row['type']!==0 || (int)$row['relation_id']!==0 || (int)$row['module_type']!==1 || (int)$row['file_type']!==1 || (int)$row['image_type']!==1) throw new RuntimeException('Imported URL baseline changed');
    }
    if ((int)$p->query("SELECT GET_LOCK('008_material_library_import',10)")->fetchColumn()!==1) throw new RuntimeException('Catalog lock unavailable');
    try {
        $p->beginTransaction();
        try {
            $now=$p->query('SELECT * FROM eb_system_attachment ORDER BY att_id FOR UPDATE')->fetchAll();
            $currentCats=$p->query('SELECT * FROM eb_system_attachment_category ORDER BY id FOR UPDATE')->fetchAll();
            if ($now!==$rows || $currentCats!==$cats) throw new RuntimeException('Catalog changed before URL repair');
            $q=$p->prepare('SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key=?');$q->execute([MEDIA008_URL_KEY]);
            if ((int)$q->fetchColumn()!==0) throw new RuntimeException('URL repair already applied');
            $backupSha=media008Save($dir.'/before-urls.json',['commit'=>$commit,'attachments'=>$rows,'categories'=>$cats,'paths'=>$paths]);
            $q=$p->prepare('UPDATE eb_system_attachment SET att_dir=?,satt_dir=? WHERE att_id=? AND att_dir=? AND satt_dir=?');
            foreach ($paths as $id=>$path) {
                $url=media008AbsoluteUrl($path);$q->execute([$url,$url,$id,$path,$path]);
                if ($q->rowCount()!==1) throw new RuntimeException('URL compare-and-set failed');
                $expected[$id]['att_dir']=$url;$expected[$id]['satt_dir']=$url;
            }
            if ($p->query('SELECT * FROM eb_system_attachment ORDER BY att_id')->fetchAll()!==array_values($expected) || $p->query('SELECT * FROM eb_system_attachment_category ORDER BY id')->fetchAll()!==$cats) throw new RuntimeException('Non-URL metadata changed');
            $note=json_encode(['rows'=>count($paths),'backup_sha256'=>$backupSha,'before_sha256'=>media008Sha($rows),'after_sha256'=>media008Sha(array_values($expected))],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if (strlen($note)>500) throw new RuntimeException('URL audit too long');
            $q=$p->prepare('INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note) VALUES (?,?,?,?,?,NOW(),?,?)');
            $q->execute([MEDIA008_URL_KEY,'008 imported material absolute URLs','scripts/import/customer_008_material_library_urls.php',media008Sha($paths),$commit,'Codex',$note]);
            $result=['rows'=>count($paths),'commit'=>$commit,'backup_sha256'=>$backupSha,'before_sha256'=>media008Sha($rows),'after_sha256'=>media008Sha(array_values($expected)),'categories_sha256'=>media008Sha($cats)];
            media008Save($dir.'/url-receipt.json',$result);
            $p->commit();return $result;
        } catch (Throwable $e) { if ($p->inTransaction()) $p->rollBack();throw $e; }
    } finally { $p->query("SELECT RELEASE_LOCK('008_material_library_import')"); }
}

/** Production mode binds the exact completed import; check never changes DB/files. */
function media008UrlMain(array $argv):void
{
    $mode=$argv[1]??'';
    if (!in_array($mode,['check','apply'],true)) throw new RuntimeException('Use check/apply');
    $dir='/www/backups/008.cc3798.com/20261019-round2-material-library';
    $plan=json_decode(file_get_contents($dir.'/accepted-plan.json'),true,512,JSON_THROW_ON_ERROR);
    if (media008Sha($plan)!=='e2c7fc24d5ef9215b317e729431feab8ae465dc94fe7f3d409d7f43567842590') throw new RuntimeException('Accepted material plan changed');
    if (hash_file('sha256',$dir.'/before.json')!=='70729de73b35057562729ac83e729a7ff90c38f5c9d01553171024381ec11c60' || hash_file('sha256',$dir.'/receipt.json')!=='7c9076f119fb96f21e290fe8c02043b2d36c6d5719b73676e89f3942c272a5f5') throw new RuntimeException('Original import receipts changed');
    $before=json_decode(file_get_contents($dir.'/before.json'),true,512,JSON_THROW_ON_ERROR);
    $receipt=json_decode(file_get_contents($dir.'/receipt.json'),true,512,JSON_THROW_ON_ERROR);
    $p=media008Connect('/www/wwwroot/008.cc3798.com/.env','mohe_008',3307);
    $p->exec('SET SESSION TRANSACTION READ ONLY');$p->beginTransaction();
    try {
        media008Verify($p,$plan,$before['before'],$receipt);
        $marker=$p->query('SELECT upgrade_key,code_version FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 1')->fetch();
        if ($marker!==['upgrade_key'=>MEDIA008_KEY,'code_version'=>'9595d5197895fa05ed07a92ad02d9ba6bf530c8a']) throw new RuntimeException('Import migration baseline changed');
        $rows=$p->query('SELECT * FROM eb_system_attachment ORDER BY att_id')->fetchAll();
        $cats=$p->query('SELECT * FROM eb_system_attachment_category ORDER BY id')->fetchAll();
        $paths=[];
        foreach ($receipt['references'] as $r) if (!$r['reused']) $paths[$r['att_id']]=$plan['assets'][$r['sha256']]['public_path'];
        if (count($paths)!==923 || count($rows)!==946 || count($cats)!==17) throw new RuntimeException('URL repair scope changed');
        // Pin post-import metadata too: later user uploads, renames or category
        // edits need review rather than becoming an unaccepted repair baseline.
        if (media008Sha($rows)!=='1ed7cfbf6231d5c1c81e7ffe769623bf5285db39e949f862e42b9cca9349f34b' || media008Sha($cats)!=='e2784f44d8211334b81b77f6947adcd07a854bfce437e62a6bac632c2b4dd4dc' || media008Sha($paths)!=='e787f755373de3ba7c060a3a22289ef7ad041c677f9ce0c2141b09a349687997') throw new RuntimeException('Fixed URL candidate metadata changed');
        foreach ($plan['assets'] as $sha=>$a) if (!hash_equals($sha,hash_file('sha256','/www/wwwroot/008.cc3798.com/public'.$a['public_path']))) throw new RuntimeException('Published file changed');
        $p->rollBack();
        $result=['status'=>'CHECKED','rows'=>923,'files_transferred'=>0,'categories_changed'=>0,'existing_rows_changed'=>0,'catalog_sha256'=>media008Sha($rows),'categories_sha256'=>media008Sha($cats),'paths_sha256'=>media008Sha($paths)];
        if ($mode==='apply') {
            $attempt=$dir.'/absolute-urls';
            if (!is_dir($attempt) && !mkdir($attempt,0700)) throw new RuntimeException('URL backup unavailable');
            if (is_link($attempt) || (fileperms($attempt)&0077)!==0) throw new RuntimeException('Unsafe URL backup directory');
            $p->exec('SET SESSION TRANSACTION READ WRITE');
            $result['receipt']=media008RepairUrls($p,$rows,$cats,$paths,$attempt,$argv[2]??'');
            $result['status']='APPLIED';
        }
        echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    } finally { if ($p->inTransaction()) $p->rollBack(); }
}

if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try { media008UrlMain($argv); }
    catch (Throwable $e) { file_put_contents('php://stderr',$e instanceof RuntimeException?$e->getMessage().PHP_EOL:"URL repair failed; inspect private diagnostics.\n");exit(1); }
}
