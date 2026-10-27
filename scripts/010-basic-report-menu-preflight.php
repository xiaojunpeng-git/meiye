<?php
declare(strict_types=1);

/** 010 基础报表平台菜单只读核验；只返回菜单元数据、角色数量与规则摘要，不输出账号或密钥。 */
ini_set('display_errors', '0');
set_error_handler(static function (): void { throw new RuntimeException('READ_ONLY_PROBE_FAILED'); });
try {
    $root = '/www/wwwroot/010.cc3798.com';
    if (realpath($root) !== $root) throw new RuntimeException('INSTANCE_MISMATCH');
    $ini = array_change_key_case(parse_ini_file($root . '/.env', true, INI_SCANNER_RAW), CASE_LOWER);
    $cfg = array_change_key_case($ini['database'] ?? [], CASE_LOWER);
    if (($cfg['database'] ?? '') !== 'mohe_010_app' || (int)($cfg['hostport'] ?? 0) !== 3307
        || ($cfg['prefix'] ?? '') !== 'eb_' || !in_array($cfg['hostname'] ?? '', ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('DATABASE_MISMATCH');
    }
    $db = new PDO('mysql:host=' . $cfg['hostname'] . ';port=3307;dbname=mohe_010_app;charset=utf8mb4',
        $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $db->exec('SET SESSION TRANSACTION READ ONLY');
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    try {
        $identity = $db->query('SELECT DATABASE() db,@@port port,@@session.tx_read_only ro')->fetch();
        if ($identity['db'] !== 'mohe_010_app' || (int)$identity['port'] !== 3307 || (int)$identity['ro'] !== 1) {
            throw new RuntimeException('CONNECTED_INSTANCE_MISMATCH');
        }
        $menus = $db->query("SELECT id,pid,type,menu_name,menu_path,unique_auth,is_show,sort,path FROM eb_system_menus
            WHERE is_del=0 AND (unique_auth IN ('admin-report','admin-report-basic','admin-report-other-reports')
            OR unique_auth LIKE 'admin-report-basic-group-%' OR unique_auth LIKE 'admin-report-basic_%') ORDER BY id")->fetchAll();
        $basic = array_values(array_filter($menus, static function ($row) { return $row['unique_auth'] === 'admin-report-basic'; }));
        if (count($basic) !== 1) throw new RuntimeException('BASIC_MENU_IDENTITY_MISMATCH');
        $roles = $db->query('SELECT id,rules FROM eb_system_role ORDER BY id')->fetchAll();
        $holderCount = 0; $ruleDigests = [];
        foreach ($roles as $role) {
            $rules = (string)$role['rules'];
            if (in_array((int)$basic[0]['id'], array_map('intval', explode(',', $rules)), true)) $holderCount++;
            $ruleDigests[] = [(int)$role['id'], hash('sha256', $rules)];
        }
        $result = ['site' => '010', 'database' => 'mohe_010_app', 'port' => 3307, 'read_only' => true,
            'menus' => $menus, 'role_count' => count($roles), 'basic_granted_role_count' => $holderCount,
            'role_rules_sha256' => hash('sha256', json_encode($ruleDigests, JSON_THROW_ON_ERROR)),
            'business_writes' => false];
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    } finally { if ($db->inTransaction()) $db->rollBack(); }
} catch (Throwable $error) {
    echo json_encode(['error_code' => $error instanceof RuntimeException ? $error->getMessage() : 'READ_ONLY_PROBE_FAILED',
        'business_writes' => false], JSON_THROW_ON_ERROR), "\n";
    exit(1);
}
