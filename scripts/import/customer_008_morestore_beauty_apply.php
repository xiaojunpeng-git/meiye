<?php
/**
 * Import the old member "美丽日志" into the 008 community without awarding
 * new-post points. Old post IDs and publication state remain traceable; the
 * old table contains no product ID, so product links must not be guessed.
 *
 * Usage on the named server: php customer_008_morestore_beauty_apply.php check|apply|verify
 */
declare(strict_types=1);

const BEAUTY_PLAN = __DIR__ . '/customer_008_morestore_beauty_plan.json';
const BEAUTY_BACKUP_DIR = '/www/backups/008.cc3798.com';
const BEAUTY_LOCK = 'mohe_008_morestore_beauty_20261013';

/** Refuse connection if either site's configured database identity drifts. */
function beautyDb(string $envPath, string $database, int $port): PDO
{
    $env = parse_ini_file($envPath);
    if (!is_array($env) || ($env['DATABASE'] ?? '') !== $database
        || (int)($env['HOSTPORT'] ?? 0) !== $port) {
        throw new RuntimeException('Database environment identity changed');
    }
    $db = new PDO('mysql:host=' . $env['HOSTNAME'] . ';port=' . $port
        . ';dbname=' . $database . ';charset=utf8mb4', $env['USERNAME'], $env['PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    $identity = $db->query('SELECT DATABASE() AS db, @@port AS port')->fetch();
    if (($identity['db'] ?? '') !== $database || (int)($identity['port'] ?? 0) !== $port) {
        throw new RuntimeException('Connected database identity changed');
    }
    return $db;
}

/** Only the reviewed scope and source fingerprint may be written to 008. */
function beautyPlan(): array
{
    $plan = json_decode((string)file_get_contents(BEAUTY_PLAN), true, 512, JSON_THROW_ON_ERROR);
    if (($plan['plan_version'] ?? '') !== '008-morestore-beauty-community-r1'
        || ($plan['source_database'] ?? '') !== 'morestore'
        || ($plan['target_database'] ?? '') !== 'mohe_008'
        || ($plan['target_instance'] ?? '') !== '008.cc3798.com'
        || !preg_match('/^[0-9a-f]{64}$/', (string)($plan['source_sha256'] ?? ''))
        || (int)($plan['source_rows'] ?? 0) !== 2540
        || (int)($plan['source_public_rows'] ?? 0) !== 1289
        || (int)($plan['source_hidden_rows'] ?? 0) !== 1251
        || (int)($plan['source_authors'] ?? 0) !== 155
        || (int)($plan['source_images'] ?? 0) !== 3079) {
        throw new RuntimeException('Reviewed beauty plan changed');
    }
    return $plan;
}

/** Snapshot all source columns in ID order, preserving the exact old text. */
function beautySource(PDO $source, array $plan): array
{
    $source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    $hash = hash_init('sha256');
    $rows = [];
    $authors = [];
    $public = 0;
    $hidden = 0;
    $images = 0;
    foreach ($source->query('SELECT id,title,content,pics,create_time,update_time,uid,status FROM eb_beauty ORDER BY id') as $row) {
        hash_update($hash, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        if ((int)$row['id'] <= 0 || (int)$row['uid'] <= 0 || !in_array((int)$row['status'], [0, 1], true)
            || trim((string)$row['title']) === '' || trim((string)$row['content']) === '') {
            throw new RuntimeException('Old beauty row has invalid core data: ' . $row['id']);
        }
        $pics = json_decode((string)$row['pics'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($pics) || count($pics) === 0) {
            throw new RuntimeException('Old beauty row has no usable pictures: ' . $row['id']);
        }
        $urls = array_values($pics);
        foreach ($urls as $url) {
            if (!is_string($url) || strpos($url, 'https://myqiniu.cc3798.com/') !== 0
                || strlen($url) > 255) {
                throw new RuntimeException('Old beauty row has an unreviewed picture URL: ' . $row['id']);
            }
        }
        $row['_pictures'] = $urls;
        $rows[] = $row;
        $authors[(int)$row['uid']] = true;
        $images += count($urls);
        (int)$row['status'] === 1 ? $public++ : $hidden++;
    }
    $digest = hash_final($hash);
    if ($digest !== $plan['source_sha256'] || count($rows) !== $plan['source_rows']
        || $public !== $plan['source_public_rows'] || $hidden !== $plan['source_hidden_rows']
        || count($authors) !== $plan['source_authors'] || $images !== $plan['source_images']) {
        throw new RuntimeException('Old beauty content differs from the reviewed snapshot');
    }
    $source->exec('COMMIT');
    return [$rows, array_keys($authors), ['sha256' => $digest, 'rows' => count($rows),
        'public' => $public, 'hidden' => $hidden, 'authors' => count($authors), 'images' => $images]];
}

/** Map merged phone accounts through the member migration's authoritative map. */
function beautyAuthors(PDO $target, array $oldAuthors): array
{
    $map = [];
    $query = $target->query('SELECT source_uid,target_uid FROM mig_morestore_20261013_member_map');
    foreach ($query as $row) {
        $map[(int)$row['source_uid']] = (int)$row['target_uid'];
    }
    $uids = [];
    foreach ($oldAuthors as $oldUid) {
        if (!isset($map[$oldUid]) || $map[$oldUid] <= 0) {
            throw new RuntimeException('Missing migrated author: ' . $oldUid);
        }
        $uids[$map[$oldUid]] = true;
    }
    $memberInfo = [];
    $list = implode(',', array_map('intval', array_keys($uids)));
    foreach ($target->query('SELECT uid,nickname,avatar FROM eb_user WHERE uid IN (' . $list . ') AND is_del=0') as $row) {
        $memberInfo[(int)$row['uid']] = $row;
    }
    if (count($memberInfo) !== count($uids)) {
        throw new RuntimeException('A mapped 008 author is absent or deleted');
    }
    return [$map, $memberInfo];
}

/** Preserve existing 008 community content; this first import requires empty posts. */
function beautyPreflight(PDO $target, array $memberInfo): void
{
    if ((int)$target->query('SELECT COUNT(*) FROM eb_community')->fetchColumn() !== 0
        || (int)$target->query('SELECT COUNT(*) FROM eb_community_relevance')->fetchColumn() !== 0) {
        throw new RuntimeException('008 community posts or relations are no longer empty');
    }
    $uids = implode(',', array_map('intval', array_keys($memberInfo)));
    if ((int)$target->query('SELECT COUNT(*) FROM eb_community_user WHERE type=2 AND relation_id IN ('
        . $uids . ')')->fetchColumn() !== 0) {
        throw new RuntimeException('An author already has an 008 community profile');
    }
    $config = $target->query("SELECT id,value FROM eb_system_config WHERE menu_name='community_status'")->fetchAll();
    if (count($config) !== 1 || (string)$config[0]['value'] !== '') {
        throw new RuntimeException('008 community feature flag differs from reviewed empty state');
    }
}

/** A private, exclusive snapshot provides a concrete 008-only rollback point. */
function beautyBackup(PDO $target, string $sourceHash): string
{
    if (!is_dir(BEAUTY_BACKUP_DIR) || !is_writable(BEAUTY_BACKUP_DIR)) {
        throw new RuntimeException('008 backup directory is unavailable');
    }
    $tables = [];
    foreach (['eb_community', 'eb_community_user', 'eb_community_relevance'] as $table) {
        $tables[$table] = $target->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll();
    }
    $tables['eb_system_config.community_status'] = $target->query(
        "SELECT * FROM eb_system_config WHERE menu_name='community_status'")->fetchAll();
    $path = BEAUTY_BACKUP_DIR . '/beauty-community-before-' . date('Ymd_His') . '-'
        . substr($sourceHash, 0, 12) . '.json';
    $previousUmask = umask(0077);
    try {
        $file = fopen($path, 'x');
        if ($file === false) {
            throw new RuntimeException('Cannot create exclusive 008 rollback snapshot');
        }
        $payload = json_encode(['target_database' => 'mohe_008', 'source_sha256' => $sourceHash,
            'created_at' => date(DATE_ATOM), 'tables' => $tables],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (fwrite($file, $payload) !== strlen($payload)) {
            fclose($file);
            throw new RuntimeException('Incomplete 008 rollback snapshot');
        }
        fclose($file);
    } finally {
        umask($previousUmask);
    }
    return $path;
}

/** Insert historical posts directly so the new-post event cannot grant points. */
function beautyApply(PDO $target, array $rows, array $memberMap, array $memberInfo): array
{
    $publicByUid = [];
    foreach ($rows as $row) {
        if ((int)$row['status'] === 1) {
            $uid = $memberMap[(int)$row['uid']];
            $publicByUid[$uid] = ($publicByUid[$uid] ?? 0) + 1;
        }
    }
    $target->beginTransaction();
    try {
        beautyPreflight($target, $memberInfo);
        $profile = $target->prepare('INSERT INTO eb_community_user
            (type,relation_id,nickname,avatar,community_num,add_time)
            VALUES (2,?,?,?,?,?)');
        $communityUser = [];
        foreach ($memberInfo as $uid => $member) {
            $profile->execute([$uid, $member['nickname'], $member['avatar'],
                $publicByUid[$uid] ?? 0, time()]);
            $communityUser[$uid] = (int)$target->lastInsertId();
        }
        $post = $target->prepare('INSERT INTO eb_community
            (id,type,relation_id,community_user_id,content_type,title,image,video_url,
             slider_image,content,topic_id,product_id,like_num,collect_num,play_num,
             comment_num,share_num,star,status,is_recommend,is_verify,refusal,sort,
             is_del,verify_time,add_time)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($rows as $row) {
            $uid = $memberMap[(int)$row['uid']];
            $published = (int)$row['status'] === 1;
            $created = strtotime((string)$row['create_time']);
            $verified = strtotime((string)$row['update_time']);
            if ($created === false || ($published && $verified === false)) {
                throw new RuntimeException('Invalid historical post time: ' . $row['id']);
            }
            $post->execute([(int)$row['id'], 2, $uid, $communityUser[$uid], 1,
                $row['title'], $row['_pictures'][0], '',
                json_encode($row['_pictures'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $row['content'], '', '', 0, 0, 0, 0, 0, 1, $published ? 1 : 0,
                0, $published ? 1 : 0, '', 0, 0, $published ? $verified : 0, $created]);
        }
        // The community endpoint is currently disabled in 008. Enable only
        // reading these posts; old logs do not earn new-post points or enable comments.
        $enable = $target->prepare("UPDATE eb_system_config SET value='1'
            WHERE menu_name='community_status' AND value=''");
        $enable->execute();
        if ($enable->rowCount() !== 1) {
            throw new RuntimeException('008 community flag changed during import');
        }
        $count = (int)$target->query('SELECT COUNT(*) FROM eb_community')->fetchColumn();
        if ($count !== count($rows)) {
            throw new RuntimeException('Inserted post count differs from source');
        }
        $target->commit();
        return ['posts' => $count, 'new_profiles' => count($communityUser)];
    } catch (Throwable $error) {
        if ($target->inTransaction()) $target->rollBack();
        throw $error;
    }
}

/** Compare original ID, author, visibility, text and picture list after import. */
function beautyVerify(PDO $target, array $rows, array $memberMap): array
{
    if ((string)$target->query("SELECT value FROM eb_system_config WHERE menu_name='community_status'")
        ->fetchColumn() !== '1') {
        throw new RuntimeException('008 community flag is not enabled');
    }
    $posts = $target->query('SELECT id,relation_id,title,content,image,slider_image,status,is_verify,add_time
        FROM eb_community ORDER BY id')->fetchAll();
    if (count($posts) !== count($rows)) {
        throw new RuntimeException('008 community row count differs');
    }
    foreach ($rows as $index => $old) {
        $new = $posts[$index];
        $pics = json_decode((string)$new['slider_image'], true, 512, JSON_THROW_ON_ERROR);
        if ((int)$new['id'] !== (int)$old['id']
            || (int)$new['relation_id'] !== $memberMap[(int)$old['uid']]
            || (string)$new['title'] !== (string)$old['title']
            || (string)$new['content'] !== (string)$old['content']
            || (string)$new['image'] !== (string)$old['_pictures'][0]
            || $pics !== $old['_pictures']
            || (int)$new['status'] !== (int)$old['status']
            || (int)$new['is_verify'] !== (int)$old['status']
            || (int)$new['add_time'] !== strtotime((string)$old['create_time'])) {
            throw new RuntimeException('008 post differs from old log: ' . $old['id']);
        }
    }
    return ['posts' => count($posts), 'public' => count(array_filter($posts,
        static function ($row) { return (int)$row['status'] === 1; })),
        'images' => array_sum(array_map(static function ($row) {
            return count(json_decode((string)$row['slider_image'], true));
        }, $posts))];
}

/** Clear only 008's cached community switch after the database commit. */
function beautyInvalidateConfigCache(): void
{
    $env = parse_ini_file('/www/wwwroot/008.cc3798.com/.env', true);
    if (($env['CACHE']['PREFIX'] ?? '') !== '008:'
        || ($env['CACHE']['TAG_PREFIX'] ?? '') !== '008:') {
        throw new RuntimeException('008 Redis namespace changed; community cache was not cleared');
    }
    $config = $env['REDIS'] ?? [];
    $redis = new Redis();
    $redis->connect($config['REDIS_HOSTNAME'], (int)$config['PORT']);
    if (($config['REDIS_PASSWORD'] ?? '') !== '') $redis->auth($config['REDIS_PASSWORD']);
    $redis->select((int)$config['SELECT']);
    $redis->del('008:system_config:community_status');
}

/** Keep check read-only; apply writes only after the source and 008 guards pass. */
function beautyMain(array $argv): void
{
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['check', 'apply', 'verify'], true)) {
        throw new RuntimeException('Use check, apply or verify');
    }
    $plan = beautyPlan();
    $source = beautyDb('/www/wwwroot/morestore.cc3798.com/.env', 'morestore', 3306);
    $target = beautyDb('/www/wwwroot/008.cc3798.com/.env', 'mohe_008', 3307);
    [$rows, $oldAuthors, $summary] = beautySource($source, $plan);
    [$memberMap, $memberInfo] = beautyAuthors($target, $oldAuthors);
    if ($mode === 'check') {
        beautyPreflight($target, $memberInfo);
        echo json_encode(['mode' => 'check', 'target' => 'mohe_008', 'source' => $summary,
            'target_authors' => count($memberInfo)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
        return;
    }
    if ($mode === 'verify') {
        echo json_encode(['mode' => 'verify', 'target' => 'mohe_008',
            'result' => beautyVerify($target, $rows, $memberMap)],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
        return;
    }
    $lock = $target->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([BEAUTY_LOCK]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('008 beauty import lock is busy');
    }
    try {
        beautyPreflight($target, $memberInfo);
        $backup = beautyBackup($target, $summary['sha256']);
        $result = beautyApply($target, $rows, $memberMap, $memberInfo);
        beautyInvalidateConfigCache();
        $verified = beautyVerify($target, $rows, $memberMap);
        echo json_encode(['mode' => 'apply', 'target' => 'mohe_008', 'backup' => $backup,
            'backup_sha256' => hash_file('sha256', $backup), 'result' => $result,
            'verified' => $verified], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    } finally {
        $release = $target->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([BEAUTY_LOCK]);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        beautyMain($argv);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
