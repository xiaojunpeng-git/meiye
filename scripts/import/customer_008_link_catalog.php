<?php
/** Copy the reviewed Ruihao navigation directory into empty 008 tables only.
 * This executable never connects to Ruihao and never copies business records,
 * credentials, decoration or customer configuration. IDs and bytes are kept.
 */
declare(strict_types=1);

const LC008_KEY = '20261019-008-link-catalog-r2';
const LC008_SHA = '3d5dcec2dd5adf2c808137ec13633f88282f089475cf2533b8b938d57ed5690b';
const LC008_DIR = '/www/backups/008.cc3798.com/20261019-round2-link-catalog';
const LC008_TABLES = ['eb_page_category', 'eb_page_link'];

/** Stable serialization makes snapshots and readbacks comparable byte-for-byte. */
function lc008Json($v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** Accept only the fixed read-only source export, not arbitrary SQL or URLs. */
function lc008Catalog(string $file): array
{
    if (hash_file('sha256', $file) !== LC008_SHA) throw new RuntimeException('Source fingerprint changed');
    $s = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if ($s['database'] !== ['db'=>'ruihao', 'port'=>'3306']) throw new RuntimeException('Wrong source');
    $rows = array_intersect_key($s, array_flip(LC008_TABLES));
    if (count($rows['eb_page_category']) !== 17 || count($rows['eb_page_link']) !== 60) throw new RuntimeException('Source counts changed');
    $ids = array_column($rows['eb_page_category'], 'id');
    foreach ($rows['eb_page_category'] as $r) {
        if ($r['pid'] !== '0' && !in_array($r['pid'], $ids, true)) throw new RuntimeException('Orphan category');
    }
    foreach ($rows['eb_page_link'] as $r) {
        if (!in_array($r['cate_id'], $ids, true)) throw new RuntimeException('Orphan link');
        foreach (['url','example'] as $k) {
            if (strpos($r[$k], '/pages/') !== 0 || preg_match('/[\\\\\x00-\x1f]/', $r[$k])) throw new RuntimeException('Non-local link');
        }
    }
    return $rows;
}

/** Read only the two allowlisted tables; write paths lock their complete ranges
 * in REPEATABLE READ, including the empty insertion gaps, before checking drift.
 */
function lc008Rows(PDO $p, bool $lock = false): array
{
    $out = [];
    foreach (LC008_TABLES as $t) $out[$t] = $p->query('SELECT * FROM '.$t.' ORDER BY id'.($lock ? ' FOR UPDATE' : ''))->fetchAll();
    return $out;
}

/** Refuse nontransactional tables: both catalogs and audit must roll back. */
function lc008Begin(PDO $p): void
{
    $engines = $p->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('eb_page_category','eb_page_link','eb_database_upgrade_log')")->fetchAll(PDO::FETCH_COLUMN);
    if (count($engines) !== 3 || array_filter($engines, function ($e) { return strtoupper($e) !== 'INNODB'; })) throw new RuntimeException('InnoDB catalogs and audit required');
    $p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $p->beginTransaction();
}

/** An exclusive 0600 snapshot in a 0700 directory precedes the first SQL write. */
function lc008Save(string $file, array $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir) || is_link($dir) || (fileperms($dir) & 0077) !== 0) throw new RuntimeException('Private backup directory required');
    $old = umask(0077);
    try {
        $f = fopen($file, 'x');
        if (!$f) throw new RuntimeException('Exclusive backup unavailable');
        try {
            $bytes = lc008Json($data);
            if (fwrite($f, $bytes) !== strlen($bytes) || !fflush($f)) throw new RuntimeException('Incomplete backup');
        } finally { fclose($f); }
    } finally { umask($old); }
}

/** Atomic empty-table import; no upsert, deletion or replacement is permitted.
 * Audit failure and concurrent drift roll back both directories together.
 */
function lc008Apply(PDO $p, array $catalog, string $dir, string $commit): array
{
    if (!preg_match('/^[a-f0-9]{40}$/D', $commit)) throw new RuntimeException('Accepted commit required');
    lc008Begin($p);
    try {
        $before = lc008Rows($p, true);
        if ($before !== ['eb_page_category'=>[], 'eb_page_link'=>[]]) throw new RuntimeException('Target no longer empty; do not overwrite');
        $q = $p->prepare('SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key=?');
        $q->execute([LC008_KEY]);
        if ((int)$q->fetchColumn() !== 0) throw new RuntimeException('Already executed; verify instead');
        lc008Save($dir.'/before.json', ['instance'=>'008.cc3798.com', 'database'=>'mohe_008', 'commit'=>$commit, 'source_sha256'=>LC008_SHA, 'before'=>$before, 'after'=>$catalog]);
        foreach (LC008_TABLES as $t) {
            $cols = array_keys($catalog[$t][0]);
            $q = $p->prepare('INSERT INTO '.$t.' (`'.implode('`,`', $cols).'`) VALUES ('.implode(',', array_fill(0, count($cols), '?')).')');
            foreach ($catalog[$t] as $r) $q->execute(array_values($r));
        }
        if (lc008Rows($p) !== $catalog) throw new RuntimeException('Catalog readback differs');
        $receipt = ['categories'=>17, 'links'=>60, 'after_sha256'=>hash('sha256', lc008Json($catalog)), 'backup_sha256'=>hash_file('sha256', $dir.'/before.json')];
        $q = $p->prepare('INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note) VALUES (?,?,?,?,?,NOW(),?,?)');
        $q->execute([LC008_KEY, '008 navigation catalog from Ruihao', 'scripts/import/customer_008_link_catalog.php', hash_file('sha256', __FILE__), $commit, 'Codex', lc008Json($receipt)]);
        $p->commit();
        return $receipt;
    } catch (Throwable $e) { if ($p->inTransaction()) $p->rollBack(); throw $e; }
}

/** Roll back only an unchanged imported directory; later edits or additions
 * abort restoration. Keep the audit as a rolled-back historical receipt.
 */
function lc008Rollback(PDO $p, array $snapshot, array $catalog): void
{
    if (($snapshot['instance'] ?? '') !== '008.cc3798.com' || ($snapshot['database'] ?? '') !== 'mohe_008'
        || ($snapshot['source_sha256'] ?? '') !== LC008_SHA || ($snapshot['after'] ?? null) !== $catalog
        || ($snapshot['before'] ?? null) !== ['eb_page_category'=>[], 'eb_page_link'=>[]]) throw new RuntimeException('Invalid snapshot');
    lc008Begin($p);
    try {
        if (lc008Rows($p, true) !== $catalog) throw new RuntimeException('Later catalog change prevents rollback');
        $q = $p->prepare('SELECT code_version,result_note FROM eb_database_upgrade_log WHERE upgrade_key=? FOR UPDATE');
        $q->execute([LC008_KEY]); $audit = $q->fetch();
        if (!$audit || $audit['code_version'] !== $snapshot['commit'] || (json_decode($audit['result_note'], true)['after_sha256'] ?? '') !== hash('sha256', lc008Json($catalog))) throw new RuntimeException('Rollback audit mismatch');
        // Exact full-table equality above is required before deleting batch IDs.
        foreach (array_reverse(LC008_TABLES) as $t) {
            $q = $p->prepare('DELETE FROM '.$t.' WHERE id=?');
            foreach ($catalog[$t] as $r) $q->execute([$r['id']]);
        }
        if (lc008Rows($p) !== $snapshot['before']) throw new RuntimeException('Rollback readback differs');
        $q = $p->prepare('UPDATE eb_database_upgrade_log SET result_note=? WHERE upgrade_key=?');
        $q->execute([lc008Json(['status'=>'ROLLED_BACK', 'after_sha256'=>hash('sha256', lc008Json($catalog))]), LC008_KEY]);
        $p->commit();
    } catch (Throwable $e) { if ($p->inTransaction()) $p->rollBack(); throw $e; }
}

/** Credentials stay on the target server; enforce database AND port identity. */
function lc008Connect(): PDO
{
    $e = parse_ini_file('/www/wwwroot/008.cc3798.com/.env', true); $d = $e['DATABASE'];
    if ($d['DATABASE'] !== 'mohe_008' || (int)$d['HOSTPORT'] !== 3307) throw new RuntimeException('Wrong target environment');
    $p = new PDO('mysql:host='.$d['HOSTNAME'].';port=3307;dbname=mohe_008;charset=utf8mb4', $d['USERNAME'], $d['PASSWORD'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_STRINGIFY_FETCHES=>true, PDO::ATTR_EMULATE_PREPARES=>false]);
    $id = $p->query('SELECT DATABASE() db,@@port port')->fetch();
    if ($id !== ['db'=>'mohe_008','port'=>'3307']) throw new RuntimeException('Wrong target connection');
    return $p;
}

/** Invalidate exactly the category tree cache, never flush Redis or sessions. */
function lc008Invalidate(): void
{
    $e = parse_ini_file('/www/wwwroot/008.cc3798.com/.env', true); $c = $e['REDIS'];
    if (($e['CACHE']['PREFIX'] ?? '') !== '008:' || ($e['CACHE']['TAG_PREFIX'] ?? '') !== '008:' || (int)$c['PORT'] !== 6388 || !in_array($c['REDIS_HOSTNAME'], ['127.0.0.1','localhost'], true)) throw new RuntimeException('Wrong cache isolation');
    $r = new Redis(); $r->connect($c['REDIS_HOSTNAME'], 6388);
    if (($c['REDIS_PASSWORD'] ?? '') !== '') $r->auth($c['REDIS_PASSWORD']);
    $r->select((int)($c['SELECT'] ?? 0)); $r->del('008:tree_page_categroy'); $r->close();
}

/** Deployment entry is explicit; check/verify are read-only and never open the
 * source server. Cache failure after SQL commit is reported, not called rollback.
 */
function lc008Main(array $argv): void
{
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['check','apply','verify','rollback','invalidate'], true)) throw new RuntimeException('Use check/apply/verify/rollback/invalidate + catalog JSON + accepted commit');
    $catalog = lc008Catalog($argv[2] ?? ''); $p = lc008Connect();
    if ($mode === 'apply') {
        if (!is_dir(LC008_DIR) && !mkdir(LC008_DIR, 0700)) throw new RuntimeException('Cannot create backup directory');
        $receipt = lc008Apply($p, $catalog, LC008_DIR, $argv[3] ?? '');
        echo lc008Json(['status'=>'SQL_COMMITTED', 'receipt'=>$receipt]).PHP_EOL;
        lc008Invalidate(); echo "CACHE_INVALIDATED\n"; return;
    }
    if ($mode === 'rollback') {
        $snapshot = json_decode(file_get_contents(LC008_DIR.'/before.json'), true, 512, JSON_THROW_ON_ERROR);
        lc008Rollback($p, $snapshot, $catalog); echo "ROLLBACK_SQL_COMMITTED\n";
        lc008Invalidate(); return;
    }
    $p->exec('SET SESSION TRANSACTION READ ONLY'); $p->beginTransaction();
    try {
        $rows = lc008Rows($p);
        if ($mode === 'check' && $rows !== ['eb_page_category'=>[], 'eb_page_link'=>[]]) throw new RuntimeException('Target no longer empty');
        if ($mode !== 'check') {
            if ($rows !== $catalog) throw new RuntimeException('Verification differs');
            $q = $p->prepare('SELECT result_note FROM eb_database_upgrade_log WHERE upgrade_key=?'); $q->execute([LC008_KEY]);
            if ((json_decode((string)$q->fetchColumn(), true)['after_sha256'] ?? '') !== hash('sha256', lc008Json($catalog))) throw new RuntimeException('Audit verification differs');
        }
    } finally { $p->rollBack(); }
    if ($mode === 'invalidate') lc008Invalidate();
    echo lc008Json(['status'=>strtoupper($mode), 'categories'=>17, 'links'=>60, 'source_sha256'=>LC008_SHA]).PHP_EOL;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { lc008Main($argv); }
    catch (Throwable $e) { fwrite(STDERR, $e instanceof RuntimeException ? $e->getMessage().PHP_EOL : "Catalog operation failed; inspect private diagnostics.\n"); exit(1); }
}
