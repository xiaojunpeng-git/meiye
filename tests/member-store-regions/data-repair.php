<?php
/** Real local MySQL DML/rollback regression in connection-local shadow tables.
 * Existing local stores and dictionary must remain byte-value identical. */
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');$app->env->load('/var/www/html/.env');$app->initialize();
$c = config('database.connections.mysql');
if (getenv('REGION_DATA_LOCAL_TEST') !== '1' || $c['hostname'] !== 'mysql' || $c['database'] !== 'ruihao') throw new RuntimeException('Local Docker only');
$dir = '/var/www/html/database/upgrades/2026-10-24-008门店地区数据修复';
require $dir . '/repair.php';
$plan = json_decode(file_get_contents($dir . '/plan.json'), true);
$raw = gzdecode(file_get_contents($dir . '/standard-city.json.gz'));
if (hash('sha256', $raw) !== $plan['seedSha256']) throw new RuntimeException('Seed fingerprint mismatch');
$seed = json_decode($raw, true);unset($raw);
$pdo = new PDO('mysql:host=mysql;dbname=ruihao;charset=utf8mb4', $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function regionDataCheck(bool $ok, string $label): void {if (!$ok) throw new RuntimeException($label);echo 'PASS ', $label, PHP_EOL;}
$original = [];
foreach (['eb_city_area','eb_system_store'] as $table) {
    $original[$table] = Member008RegionDataRepair::digest($pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    $ddl = $pdo->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_ASSOC)['Create Table'];
    $pdo->exec(preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $ddl, 1));
}
foreach ($plan['changes'] as $change) {
    $row = $change['before'];
    $sql = 'INSERT INTO eb_system_store (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0,count($row),'?')) . ')';
    $pdo->prepare($sql)->execute(array_values($row));
}
foreach ($plan['pendingStores'] as $pending) $pdo->prepare('INSERT INTO eb_system_store (id,name,is_show) VALUES (?,?,1)')->execute([$pending['id'],$pending['name']]);
$before = $pdo->query('SELECT * FROM eb_system_store ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$snapshot = null;
$backup = static function ($data) use (&$snapshot) {$snapshot = $data;};
$dry = Member008RegionDataRepair::apply($pdo,$plan,$seed,$backup,true);
regionDataCheck($dry['result'] === 'DRY_RUN_PASS' && $snapshot !== null && (int)$pdo->query('SELECT COUNT(*) FROM eb_city_area')->fetchColumn() === 0, 'Dry run executes all inserts and rolls back');
regionDataCheck(Member008RegionDataRepair::digest($before) === Member008RegionDataRepair::digest($pdo->query('SELECT * FROM eb_system_store ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)), 'Dry run preserves every store field');
$pdo->exec("UPDATE eb_system_store SET address='Concurrent edit' WHERE id=127");
$failed = false;
try {Member008RegionDataRepair::apply($pdo,$plan,$seed,$backup);} catch (RuntimeException $e) {$failed = strpos($e->getMessage(),'baseline changed') !== false;}
regionDataCheck($failed && (int)$pdo->query('SELECT COUNT(*) FROM eb_city_area')->fetchColumn() === 0, 'Concurrent address change blocks repair without partial writes');
$pdo->prepare('UPDATE eb_system_store SET address=? WHERE id=127')->execute([$plan['changes'][0]['before']['address']]);
$bad = $seed;
$bad['rows'][count($bad['rows']) - 1]['name'] = null;
$partialFailure = false;
try {Member008RegionDataRepair::apply($pdo,$plan,$bad,$backup);} catch (Throwable $e) {$partialFailure = true;}
regionDataCheck($partialFailure && (int)$pdo->query('SELECT COUNT(*) FROM eb_city_area')->fetchColumn() === 0, 'Late dictionary insert failure rolls back every earlier batch');
unset($bad);
$result = Member008RegionDataRepair::apply($pdo,$plan,$seed,$backup);
regionDataCheck($result['result'] === 'APPLIED' && $result['stores'] === 3 && $result['dictionaryWrites'] === 44707, 'Actual standard dictionary and exact three stores applied');
$retry = Member008RegionDataRepair::apply($pdo,$plan,$seed,$backup);
regionDataCheck($retry['result'] === 'ALREADY_APPLIED', 'Retry is idempotent');
$untouched = $pdo->query('SELECT * FROM eb_system_store WHERE id=108')->fetch(PDO::FETCH_ASSOC);
regionDataCheck(Member008RegionDataRepair::digest([$untouched]) === Member008RegionDataRepair::digest(array_values(array_filter($before,static fn($r) => (int)$r['id']===108))), 'Unrelated empty-address store untouched');
class RegionRepairDictionary extends app\services\other\CityAreaServices {
    private $pdo;
    public function __construct(PDO $pdo) {$this->pdo=$pdo;}
    /** Test transport only: the real production projector reads actual inserted
     * SQL rows from this connection's temporary dictionary. */
    public function getCityList(array $where, string $field = '*', array $with = []) {
        $ids = array_values($where[0][2]);
        $query = $this->pdo->prepare('SELECT id,name,parent_id,level FROM eb_city_area WHERE id IN (' . implode(',',array_fill(0,count($ids),'?')) . ') ORDER BY id');
        $query->execute($ids);return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
$projector = new app\services\store\StoreSelectableRegionServices(new RegionRepairDictionary($pdo));
$tree = $projector->project($pdo->query('SELECT id,province,city,area FROM eb_system_store ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
regionDataCheck(count($tree['regions']) === 3 && $tree['incomplete_count'] === 21, 'Real projector exposes three provinces and exactly 21 unresolved stores');
foreach ($plan['changes'] as $change) {
    $q = $pdo->prepare('SELECT id FROM eb_system_store WHERE province=? AND city=? AND area=?');
    $q->execute(array_values($change['after']));
    regionDataCheck(array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)) === [(int)$change['before']['id']], 'District filter matches exact store ' . $change['before']['id']);
}
$pdo->exec("UPDATE eb_system_store SET name='Later edit' WHERE id=127");
$blocked = false;
try {Member008RegionDataRepair::rollback($pdo,$snapshot);} catch (RuntimeException $e) {$blocked = true;}
regionDataCheck($blocked, 'Rollback refuses later store edits');
$pdo->prepare('UPDATE eb_system_store SET name=? WHERE id=127')->execute([$plan['changes'][0]['before']['name']]);
Member008RegionDataRepair::rollback($pdo,$snapshot);
regionDataCheck(Member008RegionDataRepair::digest($before) === Member008RegionDataRepair::digest($pdo->query('SELECT * FROM eb_system_store ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)), 'Recovery restores codes and all original store fields');
regionDataCheck((int)$pdo->query('SELECT COUNT(*) FROM eb_city_area')->fetchColumn() === 44707, 'Recovery retains shared dictionary to protect later address references');
foreach (['eb_city_area','eb_system_store'] as $table) {
    $pdo->exec('DROP TEMPORARY TABLE ' . $table);
    regionDataCheck($original[$table] === Member008RegionDataRepair::digest($pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)), 'Persistent local ' . $table . ' unchanged');
}
