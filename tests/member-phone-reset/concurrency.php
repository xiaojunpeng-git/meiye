<?php
declare(strict_types=1);
/** Separate real DB connections race token issuance against a locked phone change. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\user\CanonicalUserIdentityServices;
use app\services\user\LoginServices;
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local only');
$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $phone = '198' . random_int(10000000, 39999999);
    $new = '198' . random_int(40000000, 69999999);
    if (Db::name('user')->whereIn('phone', [$phone, $new])->count()) throw new RuntimeException('Collision');
    $data = ['phone' => '', 'nickname' => 'LOCAL_PHONE_RESET_RACE_' . bin2hex(random_bytes(6)),
        'status' => 1, 'is_del' => 0, 'pwd' => md5('local-only')];
    foreach (Db::query('SHOW COLUMNS FROM eb_user') as $f) {
        $key = $f['Field'];
        if (!array_key_exists($key, $data) && $f['Default'] === null && $f['Null'] !== 'YES' && strpos($f['Extra'], 'auto_increment') === false) {
            $data[$key] = preg_match('/int|decimal|float|double/', $f['Type']) ? 0 : '';
        }
    }
    Db::transaction(function () use ($data, $phone, $new): void {
        $uid = (int)Db::name('user')->insertGetId($data);
        app()->make(CanonicalUserIdentityServices::class)->bindPhoneIdentity($uid, $phone, ['source' => 'LOCAL_RACE_TEST']);
        echo json_encode(['uid' => $uid, 'old' => $phone, 'new' => $new]), PHP_EOL;
    });
    exit;
}
$uid = (int)($argv[2] ?? 0); $old = $argv[3] ?? ''; $new = $argv[4] ?? '';
$user = Db::name('user')->where('uid', $uid)->find();
if (!$user || strpos($user['nickname'], 'LOCAL_PHONE_RESET_RACE_') !== 0) throw new RuntimeException('Fixture ownership guard');
if ($mode === 'commit' || $mode === 'rollback') {
    Db::startTrans();
    try {
        app()->make(CanonicalUserIdentityServices::class)->replacePhoneIdentity($uid, $new, ['source' => 'LOCAL_RACE_TEST']);
        echo "LOCKED\n"; flush(); usleep(1200000);
        if ($mode === 'commit') Db::commit(); else Db::rollback();
        echo "WRITER_", strtoupper($mode), "_PASS\n";
    } catch (Throwable $e) { Db::rollback(); throw $e; }
} elseif ($mode === 'issue') {
    $start = microtime(true);
    try {
        $token = app()->make(LoginServices::class)->createToken($uid, 'routine', md5('local-only'), '');
        mohe\services\CacheService::clearToken(md5($token['token']));
        echo 'ISSUE_ALLOWED';
    } catch (mohe\exceptions\AuthException $e) {
        if ($e->getCode() !== 410001) throw $e;
        echo 'ISSUE_DENIED';
    }
    if (microtime(true) - $start < 0.5) throw new RuntimeException('Issuer did not wait for member row lock');
    echo "_AFTER_LOCK_PASS\n";
} elseif ($mode === 'cleanup') {
    Db::transaction(function () use ($uid, $old, $new): void {
        $digests = [hash('sha256', $old), hash('sha256', $new)];
        Db::name('user_phone_identity_audit')->whereIn('phone_digest', $digests)->delete();
        Db::name('user_phone_identity')->whereIn('phone_digest', $digests)->delete();
        Db::name('member_phone_login_reset')->where('uid', $uid)->delete();
        Db::name('user')->where('uid', $uid)->delete();
    });
    if (Db::name('user')->where('uid', $uid)->count() || Db::name('member_phone_login_reset')->where('uid', $uid)->count()) throw new RuntimeException('Cleanup failed');
    echo "OWNED_FIXTURE_CLEANUP_PASS\n";
} else throw new RuntimeException('Unknown test mode');
