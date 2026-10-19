<?php
/** Synthetic fixtures only; the optional MySQL rehearsal has a pinned disposable DB. */
declare(strict_types=1);
require dirname(__DIR__, 3) . '/scripts/import/customer_008_staff_avatars.php';

function avatarAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

/** A minimal employee fixture includes protected fields to detect accidental broad writes. */
function avatarFixture(): array
{
    return [
        'source' => [['uid'=>'1','phone'=>'13800000001','avatar'=>'https://myqiniu.cc3798.com/store/comment/a.jpg','status'=>'1','is_del'=>'0']],
        'employees' => [['id'=>'1','name'=>'Fixture','phone'=>'13800000001','avatar'=>'/static/images/staff/avatar_male.png','avatar_type'=>'1','status'=>'1','is_del'=>'0']],
        'staff' => [['id'=>'11','employee_id'=>'1','store_id'=>'101','phone'=>'13800000001','avatar'=>'/static/images/staff/avatar_male.png','status'=>'1']],
        'orgs' => [['id'=>'3','pid'=>'1','name'=>'渼约'],['id'=>'4','pid'=>'1','name'=>'Other'],['id'=>'5','pid'=>'3','name'=>'Fixture shop']],
        'store_orgs' => [['store_id'=>'101','org_id'=>'5']],
        'jobs' => [['employee_id'=>'1','org_id'=>'5']],
    ];
}

$state = avatarFixture();
$plan = avatarPlan($state);
avatarAssert(count($plan['entries']) === 1, 'Expected one eligible portrait');
avatarAssert(strpos(json_encode($plan), '13800000001') === false, 'Plan leaked a phone');
foreach (['', '/static/images/staff/avatar_female.svg', 'https://008.cc3798.com/static/images/staff/avatar_male.png?v=1'] as $url) avatarAssert(avatarIsDefault($url), 'Known placeholder rejected');
foreach (['https://evil.test/static/images/staff/avatar_male.png', '/upload/avatar_male.png', 'https://myqiniu.cc3798.com/custom.jpg'] as $url) avatarAssert(!avatarIsDefault($url), 'Custom image was classified as a placeholder');
foreach (['https://user:pass@myqiniu.cc3798.com/a.jpg', 'https://myqiniu.cc3798.com.evil.test/a.jpg', 'http://127.0.0.1/a.jpg', 'file:///etc/passwd'] as $url) avatarAssert(avatarSourceUrl($url) === null, 'Unapproved URL accepted');
$cases = [
    'master custom' => static function (&$s) { $s['employees'][0]['avatar'] = '/upload/manual.jpg'; },
    'staff custom' => static function (&$s) { $s['staff'][0]['avatar'] = '/upload/manual.jpg'; },
    'source duplicate' => static function (&$s) { $s['source'][] = $s['source'][0]; },
    'target duplicate' => static function (&$s) { $s['employees'][] = $s['employees'][0]; },
    'wrong org' => static function (&$s) { $s['jobs'][0]['org_id'] = '4'; },
    'wrong store' => static function (&$s) { $s['store_orgs'][0]['org_id'] = '4'; },
    'unmatched' => static function (&$s) { $s['source'][0]['phone'] = '13800000002'; },
    'deleted source' => static function (&$s) { $s['source'][0]['is_del'] = '1'; },
    'staff mismatch' => static function (&$s) { $s['staff'][0]['phone'] = '13800000002'; },
    'inactive staff' => static function (&$s) { $s['staff'][0]['status'] = '0'; },
    'missing assignment' => static function (&$s) { $s['jobs'] = []; },
    'missing image' => static function (&$s) { $s['source'][0]['avatar'] = ''; },
];
foreach ($cases as $name => $mutate) { $s = $state; $mutate($s); avatarAssert(!avatarPlan($s)['entries'], $name . ' was not protected'); }
echo "Planner/default/custom/identity/scope/URL tests: PASS\n";

// A captured WeChat image is byte-pinned locally; no network or real webroot is used.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jxXcAAAAASUVORK5CYII=');
$info = avatarImageInfo($png);
$freezeDir = '/tmp/008-staff-avatar-frozen-20261019';
$madeFreezeDir = !is_dir($freezeDir);
if ($madeFreezeDir) mkdir($freezeDir,0700);
$freezePath = $freezeDir . '/' . $info['sha256'] . '.png';
avatarAssert(!file_exists($freezePath), 'Refusing an existing test snapshot');
$testRoot = sys_get_temp_dir() . '/008-avatar-publish-test-' . bin2hex(random_bytes(6));
mkdir($testRoot,0700);
try {
    file_put_contents($freezePath,$png); chmod($freezePath,0600);
    $fixed = ['source_url'=>'https://thirdwx.qlogo.cn/fixture/0','path'=>$freezePath,'image'=>$info,
        'avatar'=>'https://008.cc3798.com/upload/staff_avatars/' . $info['sha256'] . '.png'];
    avatarAssert(avatarFrozen($fixed['source_url'],$fixed) === $fixed, 'Frozen image changed');
    $published = avatarPublishFrozen($fixed,$testRoot);
    avatarAssert($published['created'] && hash_file('sha256',$published['path']) === $info['sha256'], 'Publish did not preserve exact bytes');
    avatarAssert(!avatarPublishFrozen($fixed,$testRoot)['created'], 'Identical object was overwritten');
    file_put_contents($published['path'],'different');
    $caught=false; try { avatarPublishFrozen($fixed,$testRoot); } catch (RuntimeException $e) { $caught=true; }
    avatarAssert($caught && file_get_contents($published['path']) === 'different', 'Existing different object was overwritten');
    file_put_contents($freezePath,'tampered');
    $caught=false; try { avatarFrozen($fixed['source_url'],$fixed); } catch (RuntimeException $e) { $caught=true; }
    avatarAssert($caught, 'Tampered private snapshot was accepted');
    unlink($published['path']);
    rmdir(dirname($published['path'])); rmdir($testRoot . '/upload');
} finally {
    unlink($freezePath); rmdir($testRoot);
    if ($madeFreezeDir) rmdir($freezeDir);
}
echo "Frozen image byte pinning/tamper rejection/content-addressed publication/no overwrite: PASS\n";
if (($argv[1] ?? '') !== '--mysql') exit(0);

// This database is created only on the existing disposable MySQL 5.7 container.
$db = 'm008_avatar_repair_test';
$pdo = new PDO('mysql:host=127.0.0.1;port=13318;charset=utf8mb4', 'root', 'rights-test-local',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES=>true, PDO::ATTR_EMULATE_PREPARES=>false]);
$identity = $pdo->query('SELECT @@port port,VERSION() version')->fetch();
avatarAssert((int)$identity['port'] === 3306 && strpos($identity['version'], '5.7.') === 0, 'Not the pinned disposable MySQL5.7');
$pdo->exec('CREATE DATABASE ' . $db . ' CHARACTER SET utf8mb4');
$dir = sys_get_temp_dir() . '/008-avatar-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
try {
    $pdo->exec('USE ' . $db);
    $pdo->exec('CREATE TABLE eb_employee (id INT PRIMARY KEY,name VARCHAR(100),phone VARCHAR(30),avatar VARCHAR(1000),avatar_type INT,status INT,is_del INT,update_time INT,uid INT,account_secret VARCHAR(100)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE eb_system_store_staff (id INT PRIMARY KEY,employee_id INT,store_id INT,phone VARCHAR(30),avatar VARCHAR(1000),status INT,is_del INT,pwd VARCHAR(100),roles VARCHAR(100),is_reservable INT) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE eb_organization (id INT PRIMARY KEY,pid INT,name VARCHAR(100),is_del INT DEFAULT 0) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE eb_organization_store (store_id INT,org_id INT) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE eb_organization_employee (employee_id INT,org_id INT,is_del INT DEFAULT 0,status INT DEFAULT 1) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE eb_employee_change_log (id INT AUTO_INCREMENT PRIMARY KEY,employee_id INT,action VARCHAR(100),target_type VARCHAR(100),target_id INT,source VARCHAR(100),before_data TEXT,after_data TEXT,reason VARCHAR(500),operator_type VARCHAR(100),operator_id INT,operator_name VARCHAR(100),operator_ip VARCHAR(100),request_id VARCHAR(100),add_time INT) ENGINE=InnoDB');
    foreach ($state['orgs'] as $r) { $q=$pdo->prepare('INSERT INTO eb_organization(id,pid,name) VALUES (?,?,?)'); $q->execute(array_values($r)); }
    $pdo->exec('INSERT INTO eb_organization_store VALUES (101,5)');
    $pdo->exec('INSERT INTO eb_organization_employee(employee_id,org_id) VALUES (1,5)');
    $pdo->exec("INSERT INTO eb_employee VALUES (1,'Fixture','13800000001','/static/images/staff/avatar_male.png',1,1,0,100,998,'protected')");
    $pdo->exec("INSERT INTO eb_system_store_staff VALUES (11,1,101,'13800000001','/static/images/staff/avatar_male.png',1,0,'secret','1,2',1)");
    $before = [$pdo->query('SELECT * FROM eb_employee')->fetchAll(),$pdo->query('SELECT * FROM eb_system_store_staff')->fetchAll()];
    $receipt = avatarWrite($pdo, $plan, str_repeat('a',40), $dir, true);
    avatarAssert($receipt['rehearsal'] && $receipt['staff'] === 1, 'Rehearsal count mismatch');
    avatarAssert($before === [$pdo->query('SELECT * FROM eb_employee')->fetchAll(),$pdo->query('SELECT * FROM eb_system_store_staff')->fetchAll()], 'Rehearsal did not fully roll back');
    avatarAssert((int)$pdo->query('SELECT COUNT(*) FROM eb_employee_change_log')->fetchColumn() === 0, 'Rehearsal audit persisted');
    $snapshot = json_decode(file_get_contents($receipt['backup']), true);
    avatarAssert($snapshot['rows'][0]['avatar_type'] === '1', 'Original avatar type not backed up');
    avatarAssert((fileperms($receipt['backup']) & 0077) === 0, 'Snapshot is not private');
    unlink($receipt['backup']);
    // A concurrent manual portrait or scope transfer must abort before any write.
    foreach (['UPDATE eb_employee SET avatar=\'/manual.jpg\' WHERE id=1', 'UPDATE eb_organization_employee SET org_id=4 WHERE employee_id=1'] as $sql) {
        $pdo->exec($sql); $caught=false;
        try { avatarWrite($pdo,$plan,str_repeat('a',40),$dir,true); } catch (RuntimeException $e) { $caught=true; }
        avatarAssert($caught, 'Concurrent change was overwritten');
        $pdo->exec("UPDATE eb_employee SET avatar='/static/images/staff/avatar_male.png' WHERE id=1");
        $pdo->exec('UPDATE eb_organization_employee SET org_id=5 WHERE employee_id=1');
    }
    // Inject an audit failure after profile updates to prove atomic error rollback.
    $pdo->exec("CREATE TRIGGER avatar_fail_audit BEFORE INSERT ON eb_employee_change_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'");
    $caught=false;
    try { avatarWrite($pdo,$plan,str_repeat('a',40),$dir,false); } catch (PDOException $e) { $caught=true; }
    $pdo->exec('DROP TRIGGER avatar_fail_audit');
    avatarAssert($caught && $before === [$pdo->query('SELECT * FROM eb_employee')->fetchAll(),$pdo->query('SELECT * FROM eb_system_store_staff')->fetchAll()], 'Audit failure left a partial update');
    foreach (glob($dir . '/*') as $file) unlink($file);
    $receipt = avatarWrite($pdo,$plan,str_repeat('a',40),$dir,false);
    avatarVerify($pdo,$plan);
    $after = [$pdo->query('SELECT * FROM eb_employee')->fetchAll(),$pdo->query('SELECT * FROM eb_system_store_staff')->fetchAll()];
    foreach (['avatar','avatar_type','update_time'] as $key) { unset($before[0][0][$key],$after[0][0][$key]); }
    unset($before[1][0]['avatar'],$after[1][0]['avatar']);
    avatarAssert($before === $after, 'A non-avatar business field changed');
    avatarAssert((int)$pdo->query('SELECT COUNT(*) FROM eb_employee_change_log')->fetchColumn() === 1, 'Missing per-employee audit');
    $caught=false; try { avatarWrite($pdo,$plan,str_repeat('a',40),$dir,false); } catch (RuntimeException $e) { $caught=true; }
    avatarAssert($caught && (int)$pdo->query('SELECT COUNT(*) FROM eb_employee_change_log')->fetchColumn() === 1, 'Duplicate apply was not blocked');
    echo "MySQL5.7 write/readback/full rollback/manual-photo protection/scope drift/replay/non-avatar invariants: PASS\n";
} finally {
    $pdo->exec('DROP DATABASE ' . $db);
    foreach (glob($dir . '/*') as $file) unlink($file);
    rmdir($dir);
}
