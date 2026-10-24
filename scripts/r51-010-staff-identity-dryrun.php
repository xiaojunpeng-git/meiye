<?php
declare(strict_types=1);

/** 010-only read adapter. The accepted source batch and a READ ONLY transaction
 * fence all reads. Apply the proposed plan ONLY to PHP arrays to prove replay;
 * this script has no INSERT/UPDATE/DDL path and never exports private profiles. */
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (): void { throw new RuntimeException('DRYRUN_IO_FAILED'); });
try {
    $request = json_decode(base64_decode('__R51_IDENTITY_DRYRUN__', true), true, 512, JSON_THROW_ON_ERROR);
    $source = base64_decode($request['source'], true);
    if (!is_string($source) || hash('sha256', $source) !== $request['sha256'] || substr($source, 0, 5) !== '<?php')
        throw new RuntimeException('PLANNER_HASH_REQUIRED');
    eval(substr($source, 5));
    $env = array_change_key_case(parse_ini_file('/www/wwwroot/010.cc3798.com/.env', true, INI_SCANNER_RAW), CASE_LOWER);
    $app = array_change_key_case($env['database'] ?? [], CASE_LOWER);
    if (($app['database'] ?? '') !== 'mohe_010_app' || (int)($app['hostport'] ?? 0) !== 3307)
        throw new RuntimeException('010_REQUIRED');
    $ini = parse_ini_file('/root/.mysql57-008.cnf', true, INI_SCANNER_RAW);
    $cfg = array_change_key_case($ini['client'] ?? [], CASE_LOWER);
    if (!in_array($cfg['host'] ?? '127.0.0.1', ['127.0.0.1', 'localhost'], true) || (int)($cfg['port'] ?? 3307) !== 3307)
        throw new RuntimeException('FIXED_ENDPOINT_REQUIRED');
    $dsn = !empty($cfg['socket']) ? 'mysql:unix_socket='.$cfg['socket'] : 'mysql:host='.($cfg['host'] ?? '127.0.0.1').';port=3307';
    $db = new PDO($dsn.';dbname=mohe_010_app;charset=utf8mb4', $cfg['user'], $cfg['password'] ?? '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('SET SESSION TRANSACTION READ ONLY');
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    try {
        $identity = $db->query('SELECT DATABASE() db,@@port port,@@session.tx_read_only ro')->fetch();
        if ($identity['db'] !== 'mohe_010_app' || (int)$identity['port'] !== 3307 || (int)$identity['ro'] !== 1)
            throw new RuntimeException('READ_ONLY_010_REQUIRED');
        $batch = $db->query('SELECT batch_key,source_schema,source_archive_sha256 FROM mohe_010_app.codex_010_migration_batch ORDER BY batch_key')->fetchAll();
        if (count($batch) !== 1 || $batch[0] !== [
            'batch_key'=>'010-linlin-20261023-refresh-1', 'source_schema'=>'mohe_010_refresh',
            'source_archive_sha256'=>'970dfd1c383862e30dd668bd6dbfec0805a996dc23d1a5673f70283058f8eb36'])
            throw new RuntimeException('EXACT_ARCHIVE_REQUIRED');
        $sources = $db->query('SELECT * FROM mohe_010_refresh.eb_system_store_staff ORDER BY id')->fetchAll();
        $targets = $db->query('SELECT * FROM mohe_010_app.eb_system_store_staff ORDER BY id')->fetchAll();
        $employees = $db->query('SELECT * FROM mohe_010_app.eb_employee ORDER BY id')->fetchAll();
        $jobs = $db->query('SELECT * FROM mohe_010_app.eb_staff_job_position ORDER BY id')->fetchAll();
        $memberMap = $db->query('SELECT source_uid,target_uid FROM mohe_010_app.codex_010_member_map ORDER BY source_uid')->fetchAll();
        $mapped = [];
        foreach ($memberMap as $row) $mapped[(int)$row['source_uid']] = $row['target_uid'];
        // Member IDs were explicitly remapped by the original import. Preserve
        // the raw UID in the digest, compare only that already-recorded mapping;
        // it must never become the new employee's UID.
        foreach ($sources as &$row) {
            $row['_archive_uid'] = $row['uid'];
            $row['uid'] = $mapped[(int)$row['uid']] ?? $row['uid'];
        }
        unset($row);
        $before = hash('sha256', serialize([$sources, $targets, $employees, $jobs, $memberMap]));
        $plan = R51StaffIdentityPlanner::plan($sources, $targets, $employees, $jobs);
        if ($plan['report']['blocked'] || $plan['report']['isolated']) throw new RuntimeException('COMPLETE_PLAN_REQUIRED');
        $proposedEmployees = $employees;
        foreach ($plan['private']['employee_inserts'] as $person) $proposedEmployees[] = $person;
        $links = [];
        foreach ($plan['private']['staff_links'] as $link) $links[(int)$link['staff_id']] = $link['employee_id'];
        $proposedStaff = $targets;
        foreach ($proposedStaff as &$row) if (isset($links[(int)$row['id']])) $row['employee_id'] = $links[(int)$row['id']];
        unset($row);
        $replay = R51StaffIdentityPlanner::plan($sources, $proposedStaff, $proposedEmployees, $jobs);
        if ($replay['report']['blocked'] || $replay['report']['isolated'] || $replay['private']['employee_inserts'] || $replay['private']['staff_links'])
            throw new RuntimeException('IDEMPOTENT_REPLAY_REQUIRED');
        $validIds = array_fill_keys(array_map('intval', array_column($proposedEmployees, 'id')), true);
        foreach ($proposedStaff as $row) if (!isset($validIds[(int)($row['employee_id'] ?? 0)]))
            throw new RuntimeException('ALL_IMPORTED_STAFF_MUST_RESOLVE');
        if ($before !== hash('sha256', serialize([$sources, $targets, $employees, $jobs, $memberMap])))
            throw new RuntimeException('INPUT_MUTATION_FORBIDDEN');
        echo json_encode([
            'result'=>'PASS', 'site'=>'010', 'database'=>$identity, 'source_batch'=>$batch[0],
            'planner_sha256'=>$request['sha256'], 'source_staff_count'=>count($sources),
            'original_employee_count'=>count($employees), 'plan'=>$plan['report'],
            'replay_counts'=>$replay['report']['counts'], 'all_imported_staff_resolve_in_memory'=>true,
            'business_writes'=>false, 'mapping_applied'=>false, 'deployed'=>false,
            'jobs_accounts_permissions_unchanged'=>true, 'private_profiles_exported'=>false,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    } finally { if ($db->inTransaction()) $db->rollBack(); }
} catch (Throwable $e) {
    echo '{"error_code":"010_IDENTITY_DRYRUN_FAILED","business_writes":false}', "\n";
    exit(1);
}
