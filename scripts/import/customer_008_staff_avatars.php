<?php
/**
 * Restore old Meiyue technician portraits to 008 only. A reviewed private plan
 * binds identity, organization, original avatars and image bytes. No account,
 * assignment, authorization, member identity or business fact is rewritten.
 */
declare(strict_types=1);

const AVATAR_ACTION = '008_staff_avatar_20261019';

/** Stable digests keep phone numbers out of plans and audit output. */
function avatarDigest(array $value): string
{
    return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** Only recognized built-in placeholders are replaceable, never arbitrary URLs. */
function avatarIsDefault(string $value): bool
{
    if (trim($value) === '') return true;
    $parts = parse_url($value);
    if ($parts === false || isset($parts['user']) || isset($parts['pass'])) return false;
    if (isset($parts['host']) && !in_array(strtolower($parts['host']), ['008.cc3798.com'], true)) return false;
    return (bool)preg_match('#^/static/images/staff/avatar_(male|female)\.(png|svg)$#i', $parts['path'] ?? '');
}

/** Only the existing Meiyue CDN and WeChat avatar CDN may supply portraits. */
function avatarSourceUrl(string $value): ?string
{
    $p = parse_url($value);
    if (!$p || !in_array($p['scheme'] ?? '', ['https', 'http'], true)
        || !in_array(strtolower($p['host'] ?? ''), ['myqiniu.cc3798.com', 'thirdwx.qlogo.cn'], true)
        || isset($p['port']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) return null;
    return preg_replace('#^http://#', 'https://', $value);
}

/** Follow the live organization tree; cycles and missing parents fail closed. */
function avatarUnderMeiyue(int $id, array $orgs): bool
{
    $seen = [];
    while ($id > 0 && isset($orgs[$id]) && !isset($seen[$id])) {
        if ($id === 3) return true;
        $seen[$id] = true;
        $id = (int)$orgs[$id]['pid'];
    }
    return false;
}

/** Build a deterministic, data-only candidate. Ambiguous identities are never guessed. */
function avatarPlan(array $state): array
{
    $orgs = array_column($state['orgs'], null, 'id');
    if (($orgs[3]['name'] ?? '') !== '渼约') throw new RuntimeException('Meiyue root changed');
    $stores = [];
    foreach ($state['store_orgs'] as $r) $stores[(int)$r['store_id']][] = (int)$r['org_id'];
    $phones = $sources = $staff = $jobs = [];
    foreach ($state['employees'] as $r) $phones[$r['phone']][] = $r;
    foreach ($state['source'] as $r) $sources[$r['phone']][] = $r;
    foreach ($state['staff'] as $r) $staff[(int)$r['employee_id']][] = $r;
    foreach ($state['jobs'] as $r) $jobs[(int)$r['employee_id']][] = (int)$r['org_id'];
    $plan = ['schema' => 1, 'instance' => '008.cc3798.com', 'database' => 'mohe_008:3307',
        'source_count' => count($state['source']), 'skipped' => [], 'entries' => []];
    foreach ($state['source'] as $source) {
        $phone = (string)$source['phone'];
        $reason = null;
        $owners = $phones[$phone] ?? [];
        if (!preg_match('/^1[3-9][0-9]{9}$/', $phone)) $reason = 'invalid_phone';
        elseif (count($sources[$phone]) !== 1 || count($owners) > 1) $reason = 'ambiguous_phone';
        elseif (!$owners) $reason = 'unmatched';
        elseif ((int)$source['status'] !== 1 || (int)$source['is_del'] !== 0) $reason = 'source_inactive';
        $url = avatarSourceUrl((string)$source['avatar']);
        if (!$reason && !$url) $reason = 'source_avatar_unapproved';
        if (!$reason) {
            $employee = $owners[0];
            $id = (int)$employee['id'];
            $assignments = $staff[$id] ?? [];
            $active = array_values(array_filter($assignments, static function ($r) { return (int)$r['status'] === 1; }));
            $orgIds = $jobs[$id] ?? [];
            if (!$orgIds || !$active) $reason = 'no_active_assignment';
            foreach ($orgIds as $orgId) if (!avatarUnderMeiyue($orgId, $orgs)) $reason = 'outside_meiyue';
            foreach ($assignments as $r) {
                $bound = $stores[(int)$r['store_id']] ?? [];
                if (count($bound) !== 1 || !avatarUnderMeiyue($bound[0], $orgs)) $reason = 'outside_meiyue';
                if ($r['phone'] !== $phone) $reason = 'assignment_identity_mismatch';
                if (!avatarIsDefault((string)$r['avatar'])) $reason = 'custom_avatar_protected';
            }
            if (!avatarIsDefault((string)$employee['avatar'])) $reason = 'custom_avatar_protected';
            if (!$reason) {
                $plan['entries'][] = ['employee_id' => $id, 'source_uid' => (int)$source['uid'],
                    'source_guard' => avatarDigest($source), 'employee_guard' => avatarDigest($employee),
                    'staff_guard' => avatarDigest($assignments), 'avatar' => $url,
                    'employee_before' => ['id' => $id, 'avatar' => $employee['avatar'], 'avatar_type' => $employee['avatar_type']],
                    'staff_before' => array_map(static function ($r) { return ['id' => (int)$r['id'], 'avatar' => $r['avatar']]; }, $active)];
            }
        }
        if ($reason) $plan['skipped'][$reason] = ($plan['skipped'][$reason] ?? 0) + 1;
    }
    usort($plan['entries'], static function ($a, $b) { return $a['employee_id'] <=> $b['employee_id']; });
    ksort($plan['skipped']);
    // Bind scope independently of employee identity so a transfer invalidates approval.
    $plan['scope_guard'] = avatarDigest([$state['orgs'], $state['store_orgs'], $state['jobs']]);
    return $plan;
}

/** Read only identity/profile columns; account secrets never enter the candidate. */
function avatarState(PDO $source, PDO $target): array
{
    return [
        'source' => $source->query('SELECT uid,phone,avatar,status,is_del FROM eb_user WHERE group_id=7 ORDER BY uid')->fetchAll(),
        'employees' => $target->query('SELECT id,name,phone,avatar,avatar_type,status,is_del FROM eb_employee WHERE is_del=0 ORDER BY id')->fetchAll(),
        'staff' => $target->query('SELECT id,employee_id,store_id,phone,avatar,status FROM eb_system_store_staff WHERE is_del=0 ORDER BY id')->fetchAll(),
        'orgs' => $target->query('SELECT id,pid,name FROM eb_organization WHERE is_del=0 ORDER BY id')->fetchAll(),
        'store_orgs' => $target->query('SELECT store_id,org_id FROM eb_organization_store ORDER BY store_id,org_id')->fetchAll(),
        'jobs' => $target->query('SELECT employee_id,org_id FROM eb_organization_employee WHERE is_del=0 AND status=1 ORDER BY employee_id,org_id')->fetchAll(),
    ];
}

/** Lock the scope tables before profile rows so transfers cannot race this batch. */
function avatarScopeGuard(PDO $target): string
{
    $orgs = $target->query('SELECT id,pid,name FROM eb_organization WHERE is_del=0 ORDER BY id FOR UPDATE')->fetchAll();
    $stores = $target->query('SELECT store_id,org_id FROM eb_organization_store ORDER BY store_id,org_id FOR UPDATE')->fetchAll();
    $jobs = $target->query('SELECT employee_id,org_id FROM eb_organization_employee WHERE is_del=0 AND status=1 ORDER BY employee_id,org_id FOR UPDATE')->fetchAll();
    return avatarDigest([$orgs, $stores, $jobs]);
}

/** Load only pinned local credentials, verify the actual DB identity, never print them. */
function avatarConnect(string $path, string $database, int $port): PDO
{
    $e = parse_ini_file($path);
    if (!$e || ($e['DATABASE'] ?? '') !== $database || (int)($e['HOSTPORT'] ?? 0) !== $port) throw new RuntimeException('Database configuration identity mismatch');
    $pdo = new PDO('mysql:host=' . $e['HOSTNAME'] . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
        $e['USERNAME'], $e['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => true, PDO::ATTR_EMULATE_PREPARES => false]);
    $r = $pdo->query('SELECT DATABASE() db,@@port port')->fetch();
    if ($r['db'] !== $database || (int)$r['port'] !== $port) throw new RuntimeException('Connected database identity mismatch');
    return $pdo;
}

/** Bounded TLS-verified GET, no redirects; validate decoded image, not just HTTP 200. */
function avatarImage(string $url): array
{
    if (avatarSourceUrl($url) !== $url) throw new RuntimeException('Portrait URL not approved');
    $bytes = '';
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$bytes) {
            if (strlen($bytes) + strlen($chunk) > 4 * 1024 * 1024) return 0;
            $bytes .= $chunk;
            return strlen($chunk);
        }]);
    $ok = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $info = @getimagesizefromstring($bytes);
    if (!$ok || $status !== 200 || !$info || $info[0] < 1 || $info[1] < 1
        || $info[0] > 8192 || $info[1] > 8192
        || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) throw new RuntimeException('Portrait unavailable or not a supported image');
    return ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'width' => $info[0], 'height' => $info[1], 'mime' => $info['mime']];
}

/** Private atomic artifacts are rollback evidence, never a second business source. */
function avatarSave(string $path, array $value): string
{
    if (file_exists($path)) throw new RuntimeException('Refusing to overwrite an existing private artifact');
    $old = umask(0077);
    try {
        $bytes = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        $f = fopen($path, 'x');
        if (!$f) throw new RuntimeException('Cannot create private artifact');
        try { if (fwrite($f, $bytes) !== strlen($bytes) || !fflush($f)) throw new RuntimeException('Private artifact write failed'); }
        finally { fclose($f); }
        if (!hash_equals(hash('sha256', $bytes), hash_file('sha256', $path))) throw new RuntimeException('Private artifact readback mismatch');
        return hash('sha256', $bytes);
    } finally { umask($old); }
}

/** The transaction writes only avatar fields and technical update time, plus its audit. */
function avatarWrite(PDO $target, array $plan, string $commit, string $backupDir, bool $rehearse): array
{
    if (!preg_match('/^[a-f0-9]{40}$/', $commit) || !$plan['entries']) throw new RuntimeException('A fixed nonempty candidate and commit are required');
    $got = $target->query("SELECT GET_LOCK('008_staff_avatar_repair',10)")->fetchColumn();
    if ((int)$got !== 1) throw new RuntimeException('Avatar repair lock unavailable');
    $digest = avatarDigest($plan);
    $request = '008-avatar-' . substr($digest, 0, 32);
    try {
        $target->beginTransaction();
        try {
            if (!hash_equals($plan['scope_guard'], avatarScopeGuard($target))) throw new RuntimeException('Live organization scope changed');
            $marker = $target->prepare('SELECT COUNT(*) FROM eb_employee_change_log WHERE action=? AND request_id=?');
            $marker->execute([AVATAR_ACTION, $request]);
            if ((int)$marker->fetchColumn() !== 0) throw new RuntimeException('Candidate already applied; use verify instead');
            $snapshot = ['instance' => '008.cc3798.com', 'plan_sha256' => $digest, 'commit' => $commit, 'rows' => []];
            $staffCount = 0;
            foreach ($plan['entries'] as $entry) {
                $q = $target->prepare('SELECT * FROM eb_employee WHERE id=? FOR UPDATE'); $q->execute([$entry['employee_id']]); $employee = $q->fetch();
                if (!$employee || !avatarIsDefault((string)$employee['avatar'])) throw new RuntimeException('Employee portrait changed');
                $profile = array_intersect_key($employee, array_flip(['id','name','phone','avatar','avatar_type','status','is_del']));
                // Match SELECT column order used by the planner, independent of physical schema order.
                $ordered = []; foreach (['id','name','phone','avatar','avatar_type','status','is_del'] as $key) $ordered[$key] = $profile[$key];
                if (!hash_equals($entry['employee_guard'], avatarDigest($ordered))) throw new RuntimeException('Employee identity/profile drift');
                $q = $target->prepare('SELECT id FROM eb_employee WHERE phone=? AND is_del=0 ORDER BY id FOR UPDATE');
                $q->execute([$employee['phone']]);
                if (count($q->fetchAll()) !== 1) throw new RuntimeException('Employee phone ownership changed');
                $q = $target->prepare('SELECT * FROM eb_system_store_staff WHERE employee_id=? AND is_del=0 ORDER BY id FOR UPDATE');
                $q->execute([$entry['employee_id']]); $staff = $q->fetchAll();
                $ordered = [];
                foreach ($staff as $r) { $p = []; foreach (['id','employee_id','store_id','phone','avatar','status'] as $key) $p[$key] = $r[$key]; $ordered[] = $p; }
                if (!hash_equals($entry['staff_guard'], avatarDigest($ordered))) throw new RuntimeException('Staff assignment/profile drift');
                $before = ['id' => $entry['employee_id'], 'avatar' => $employee['avatar'], 'avatar_type' => $employee['avatar_type'], 'update_time' => $employee['update_time'], 'staff' => $entry['staff_before']];
                $snapshot['rows'][] = $before;
                $staffCount += count($entry['staff_before']);
            }
            $backupPath = $backupDir . '/before-' . $digest . '.json';
            $backupSha = avatarSave($backupPath, $snapshot);
            $now = time();
            foreach ($plan['entries'] as $index => $entry) {
                $q = $target->prepare('UPDATE eb_employee SET avatar=?,avatar_type=3,update_time=? WHERE id=?');
                $q->execute([$entry['avatar'], $now, $entry['employee_id']]);
                foreach ($entry['staff_before'] as $r) {
                    $q = $target->prepare('UPDATE eb_system_store_staff SET avatar=? WHERE id=? AND employee_id=? AND status=1 AND is_del=0');
                    $q->execute([$entry['avatar'], $r['id'], $entry['employee_id']]);
                    if ($q->rowCount() !== 1) throw new RuntimeException('Staff update failed');
                }
                $q = $target->prepare('INSERT INTO eb_employee_change_log (employee_id,action,target_type,target_id,source,before_data,after_data,reason,operator_type,operator_id,operator_name,operator_ip,request_id,add_time) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $q->execute([$entry['employee_id'], AVATAR_ACTION, 'employee', $entry['employee_id'], 'admin',
                    json_encode($snapshot['rows'][$index], JSON_UNESCAPED_SLASHES),
                    json_encode(['avatar' => $entry['avatar'], 'avatar_type' => 3, 'source_uid' => $entry['source_uid'], 'plan_sha256' => $digest, 'commit' => $commit, 'backup' => $backupPath, 'backup_sha256' => $backupSha], JSON_UNESCAPED_SLASHES),
                    '008 restore old Meiyue portraits; preserve identity and permissions', 'admin', 0, 'Codex avatar repair', '127.0.0.1', $request, $now]);
            }
            avatarVerify($target, $plan);
            if ($rehearse) $target->rollBack(); else $target->commit();
            return ['employees' => count($plan['entries']), 'staff' => $staffCount, 'backup' => $backupPath, 'backup_sha256' => $backupSha, 'rehearsal' => $rehearse];
        } catch (Throwable $error) { if ($target->inTransaction()) $target->rollBack(); throw $error; }
    } finally { $target->query("SELECT RELEASE_LOCK('008_staff_avatar_repair')"); }
}

/** Re-read the authoritative employee and every updated projection, not just row counts. */
function avatarVerify(PDO $target, array $plan): void
{
    foreach ($plan['entries'] as $entry) {
        $q = $target->prepare('SELECT avatar,avatar_type FROM eb_employee WHERE id=?'); $q->execute([$entry['employee_id']]); $r = $q->fetch();
        if (!$r || $r['avatar'] !== $entry['avatar'] || (int)$r['avatar_type'] !== 3) throw new RuntimeException('Employee avatar readback failed');
        foreach ($entry['staff_before'] as $staff) {
            $q = $target->prepare('SELECT avatar FROM eb_system_store_staff WHERE id=? AND employee_id=?'); $q->execute([$staff['id'], $entry['employee_id']]);
            if ($q->fetchColumn() !== $entry['avatar']) throw new RuntimeException('Staff avatar readback failed');
        }
    }
}

/** CLI only exposes aggregate receipts. Source is always a read-only transaction. */
function avatarMain(array $argv): void
{
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['plan','check','apply','verify'], true)) throw new RuntimeException('Use plan/check/apply/verify');
    $source = avatarConnect('/www/wwwroot/morestore.cc3798.com/.env', 'morestore', 3306);
    $target = avatarConnect('/www/wwwroot/008.cc3798.com/.env', 'mohe_008', 3307);
    $source->exec('SET SESSION TRANSACTION READ ONLY'); $source->beginTransaction();
    try {
        if ($mode === 'verify') {
            $bytes = file_get_contents($argv[2]);
            if (!hash_equals($argv[3], hash('sha256', $bytes))) throw new RuntimeException('Fixed plan file mismatch');
            $plan = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            avatarVerify($target, $plan); echo "{\"verified\":true}\n"; return;
        }
        $target->exec('SET SESSION TRANSACTION READ ONLY'); $target->beginTransaction();
        $plan = avatarPlan(avatarState($source, $target));
        $target->rollBack();
        $failed = 0;
        foreach ($plan['entries'] as $i => $entry) {
            try { $plan['entries'][$i]['image'] = avatarImage($entry['avatar']); }
            catch (Throwable $error) { unset($plan['entries'][$i]); $failed++; }
        }
        $plan['entries'] = array_values($plan['entries']);
        if ($failed) $plan['skipped']['unavailable_image'] = $failed;
        if ($mode === 'plan') $fileSha = avatarSave($argv[2], $plan);
        else {
            $bytes = file_get_contents($argv[2]); $fileSha = hash('sha256', $bytes);
            if (!hash_equals($argv[3], $fileSha) || !hash_equals(avatarDigest(json_decode($bytes, true, 512, JSON_THROW_ON_ERROR)), avatarDigest($plan))) throw new RuntimeException('Live candidate differs from fixed plan');
        }
        $out = ['source' => $plan['source_count'], 'employees' => count($plan['entries']),
            'staff' => array_sum(array_map(static function ($r) { return count($r['staff_before']); }, $plan['entries'])),
            'skipped' => $plan['skipped'], 'plan_file_sha256' => $fileSha, 'plan_sha256' => avatarDigest($plan)];
        if ($mode === 'apply') {
            // The writer rechecks and locks scope, phone ownership and all affected profiles.
            $target->exec('SET SESSION TRANSACTION READ WRITE');
            $out['write'] = avatarWrite($target, $plan, $argv[4] ?? '', $argv[5] ?? '', false);
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } finally { if ($source->inTransaction()) $source->rollBack(); if ($target->inTransaction()) $target->rollBack(); }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { avatarMain($argv); }
    catch (Throwable $error) { fwrite(STDERR, get_class($error) === RuntimeException::class ? $error->getMessage() . "\n" : "Avatar operation failed; inspect private server diagnostics.\n"); exit(1); }
}
