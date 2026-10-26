<?php
declare(strict_types=1);

/** One-time catalog repair: names identify candidates here, never in report reads. */
function option(array $args, string $name): string
{
    foreach ($args as $arg) {
        if (strpos($arg, $name.'=') === 0) return substr($arg, strlen($name) + 1);
    }
    return '';
}

/** A candidate is usable only when every ancestor is a current platform category. */
function effectiveCategory(string $raw, array $categories): int
{
    $best = 0;
    $depth = 0;
    foreach (explode(',', $raw) as $value) {
        $value = trim($value);
        if (!preg_match('/^[1-9][0-9]*$/D', $value)) continue;
        $id = (int)$value;
        $seen = [];
        $length = 0;
        $cursor = $id;
        while ($cursor > 0 && isset($categories[$cursor]) && !isset($seen[$cursor]) && $length < 12) {
            $seen[$cursor] = true;
            if (trim((string)$categories[$cursor]['cate_name']) === '') break;
            $cursor = (int)$categories[$cursor]['pid'];
            $length++;
        }
        if ($cursor === 0 && $length > $depth) {
            $best = $id;
            $depth = $length;
        }
    }
    return $best;
}

/** Recompute under the transaction lock so an approved preview cannot repair drifted data. */
function inventory(PDO $db, array $categories, bool $lock): array
{
    $ids = [54456, 56952, 57056, 57264, 64128, 603822];
    $suffix = $lock ? ' FOR UPDATE' : '';
    $query = $db->query('SELECT id,store_name,cate_id,type,relation_id,is_del FROM eb_store_product
        WHERE id IN ('.implode(',', $ids).') ORDER BY id'.$suffix);
    $targets = $query->fetchAll();
    if (count($targets) !== count($ids)) throw new RuntimeException('audited target set changed');
    $sameName = $db->prepare('SELECT id,store_name,cate_id FROM eb_store_product
        WHERE type=0 AND relation_id=0 AND is_del=0 AND store_name=? ORDER BY id'.$suffix);
    $children = $db->prepare('SELECT COUNT(*) FROM eb_store_product WHERE type=1 AND is_del=0 AND pid=?');
    $repair = [];
    $unresolved = [];
    foreach ($targets as $target) {
        $id = (int)$target['id'];
        if ((int)$target['type'] !== 0 || (int)$target['relation_id'] !== 0 || (int)$target['is_del'] !== 0) {
            throw new RuntimeException('platform identity changed for '.$id);
        }
        $old = (string)$target['cate_id'];
        if (effectiveCategory($old, $categories) > 0) {
            $unresolved[] = ['id' => $id, 'reason' => 'already_has_valid_category'];
            continue;
        }
        $sameName->execute([(string)$target['store_name']]);
        $byCategory = [];
        foreach ($sameName->fetchAll() as $source) {
            if ((string)$source['store_name'] !== (string)$target['store_name'] || (int)$source['id'] === $id) continue;
            $category = effectiveCategory((string)$source['cate_id'], $categories);
            if ($category > 0) $byCategory[$category][] = (int)$source['id'];
        }
        if (count($byCategory) !== 1) {
            $unresolved[] = ['id' => $id, 'name' => $target['store_name'],
                'reason' => $byCategory === [] ? 'no_valid_same_name_category' : 'conflicting_same_name_categories'];
            continue;
        }
        $category = (int)array_key_first($byCategory);
        $sourceIds = $byCategory[$category];
        sort($sourceIds, SORT_NUMERIC);
        $children->execute([$id]);
        $repair[] = ['id' => $id, 'name' => $target['store_name'], 'old' => $old,
            'new' => (string)$category, 'source_ids' => $sourceIds,
            'active_children' => (int)$children->fetchColumn()];
    }
    return ['repair' => $repair, 'unresolved' => $unresolved];
}

$args = array_slice($argv, 1);
$execute = in_array('--execute', $args, true);
if ($execute && (!in_array('--allow-ruihao', $args, true)
    || !preg_match('/^[a-f0-9]{64}$/D', option($args, '--expect'))
    || trim(option($args, '--backup-ref')) === '')) {
    throw new RuntimeException('execution requires instance approval, a backup reference and the preview hash');
}
$config = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env', true, INI_SCANNER_RAW)['DATABASE'] ?? [];
if (($config['DATABASE'] ?? '') !== 'ruihao') throw new RuntimeException('database guard: expected ruihao');
$db = new PDO('mysql:host='.$config['HOSTNAME'].';port='.$config['HOSTPORT'].';dbname=ruihao;charset=utf8mb4',
    $config['USERNAME'], $config['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$readCategories = static function (bool $lock = false) use ($db): array {
    $categories = [];
    $sql = 'SELECT id,pid,cate_name FROM eb_store_product_category WHERE type=0 AND relation_id=0 ORDER BY id';
    // A repair must not race an edit to the authority it is copying.
    foreach ($db->query($sql.($lock ? ' FOR UPDATE' : '')) as $row) {
        $categories[(int)$row['id']] = $row;
    }
    return $categories;
};
$preview = inventory($db, $readCategories(), false);
$hash = hash('sha256', json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo json_encode(['mode' => $execute ? 'preflight' : 'dry-run', 'evidence_hash' => $hash,
    'inventory' => $preview], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
if (!$execute) exit(0);
if (!hash_equals(option($args, '--expect'), $hash)) throw new RuntimeException('approved inventory hash changed');
$lockName = 'rh_platform_category_repair_20261012';
$lock = $db->prepare('SELECT GET_LOCK(?,0)');
$lock->execute([$lockName]);
if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('another repair is running');
try {
    $db->beginTransaction();
    $locked = inventory($db, $readCategories(true), true);
    if (!hash_equals($hash, hash('sha256', json_encode($locked, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))) {
        throw new RuntimeException('catalog changed after preflight');
    }
    $write = $db->prepare('UPDATE eb_store_product SET cate_id=? WHERE id=? AND cate_id=? AND type=0 AND relation_id=0 AND is_del=0');
    $audit = $db->prepare('INSERT INTO mig_rh_20261012_platform_category_repair
        (product_id,old_cate_id,new_cate_id,source_product_ids,evidence_hash,backup_ref,applied_at)
        VALUES (?,?,?,?,?,?,NOW())');
    foreach ($locked['repair'] as $row) {
        $audit->execute([$row['id'], $row['old'], $row['new'], implode(',', $row['source_ids']),
            $hash, trim(option($args, '--backup-ref'))]);
        $write->execute([$row['new'], $row['id'], $row['old']]);
        if ($write->rowCount() !== 1) throw new RuntimeException('guarded product update failed for '.$row['id']);
    }
    $db->commit();
    echo json_encode(['applied' => count($locked['repair']), 'evidence_hash' => $hash], JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
} finally {
    $release = $db->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}
