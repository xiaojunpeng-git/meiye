<?php
declare(strict_types=1);
/** Actual canonical writers, MySQL, JWT/Redis and API auth parser; synthetic local rows only. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\user\CanonicalUserIdentityServices;
use app\services\user\MemberPhoneResetServices;
use app\services\user\LoginServices;
use app\services\user\UserAuthServices;
use app\services\user\UserServices;
use app\services\wechat\WechatUserServices;
use mohe\services\CacheService;
use mohe\utils\JwtAuth;
use mohe\exceptions\AuthException;

if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local Docker only');
// Additive local migration only; every synthetic business row below is rolled back.
$sql = file_get_contents('/var/www/html/database/upgrades/2026-10-28-会员改号撤销登录/02-正式升级.sql');
Db::execute($sql);
$checks = 0; $tokens = []; $uids = [];
function checkReset(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $checks++; echo 'PASS ', $label, PHP_EOL;
}
function deniedReset(callable $fn, string $label): void {
    try { $fn(); } catch (AuthException $e) { checkReset(in_array($e->getCode(), [410000, 410001], true), $label); return; }
    throw new RuntimeException('FAIL expected auth rejection: ' . $label);
}
function requiredSeed(string $table, array $data): array {
    foreach (Db::query('SHOW COLUMNS FROM eb_' . $table) as $f) {
        $k = $f['Field'];
        if (array_key_exists($k, $data) || $f['Default'] !== null || $f['Null'] === 'YES' || strpos($f['Extra'], 'auto_increment') !== false) continue;
        $data[$k] = preg_match('/int|decimal|float|double/', $f['Type']) ? 0 : '';
    }
    return $data;
}
$identity = app()->make(CanonicalUserIdentityServices::class);
$security = app()->make(MemberPhoneResetServices::class);
$login = app()->make(LoginServices::class);
$auth = app()->make(UserAuthServices::class);
$wechat = app()->make(WechatUserServices::class);
// Keep provider/notification/visit side effects inert while running the actual
// mini login response and token issuance methods against real DB/JWT/Redis.
app()->instance(app\services\user\UserVisitServices::class, new class extends app\services\user\UserVisitServices {
    public function __construct() {}
    public function loginSaveVisit($user) { return true; }
});
app('event')->remove('user.login');
$prefix = 'LOCAL_PHONE_RESET_' . bin2hex(random_bytes(6));
// Randomized synthetic numbers, never real user accounts or provider calls.
$old = '199' . (string)random_int(10000000, 39999999);
$new = '199' . (string)random_int(40000000, 69999999);
$other = '199' . (string)random_int(70000000, 99999999);
foreach ([$old, $new, $other] as $phone) if (Db::name('user')->where('phone', $phone)->count()) throw new RuntimeException('Synthetic number collision');
$issue = function (int $uid, string $type = 'routine', ?string $proof = null) use ($login, &$tokens): array {
    $token = $login->createToken($uid, $type, md5('local-test-only'), $proof);
    $tokens[] = $token['token']; return $token;
};
$before = (int)Db::name('member_phone_login_reset')->count();
Db::startTrans();
try {
    $uid = (int)Db::name('user')->insertGetId(requiredSeed('user', [
        'phone' => $old, 'nickname' => $prefix, 'pwd' => md5('local-test-only'), 'status' => 1,
        'is_del' => 0, 'now_money' => '1234.00', 'integral' => 37, 'add_time' => time(), 'user_type' => 'routine',
    ])); $uids[] = $uid;
    $identity->bindPhoneIdentity($uid, $old, ['source' => 'LOCAL_TEST']);
    $wid = (int)Db::name('wechat_user')->insertGetId(requiredSeed('wechat_user', [
        'uid' => $uid, 'openid' => $prefix, 'unionid' => $prefix . '_union', 'user_type' => 'routine',
        'headimgurl' => '', 'is_del' => 0,
    ]));
    $legacy = app()->make(JwtAuth::class)->createToken($uid, 'routine', ['auth' => md5(md5('local-test-only'))]);
    $tokens[] = $legacy['token'];
    checkReset((int)$auth->parseToken($legacy['token'])['user']['uid'] === $uid, 'legacy token accepted before any change');
    $oldToken = $issue($uid); $oldApi = $issue($uid, 'api');
    checkReset((int)$auth->parseToken($oldToken['token'])['user']['uid'] === $uid, 'real JWT Redis bucket and API parser accept original session');
    $staleProof = $security->silentProof($uid);
    $identity->replacePhoneIdentity($uid, $old, ['source' => 'LOCAL_TEST']);
    checkReset(!$security->state($uid), 'saving unchanged phone does not revoke');
    // Failed outer business transaction cannot leave partial phone/session/WeChat changes.
    Db::startTrans();
    $identity->replacePhoneIdentity($uid, $new, ['source' => 'LOCAL_TEST_ROLLBACK']);
    Db::rollback();
    checkReset(!$security->state($uid) && Db::name('user')->where('uid', $uid)->value('phone') === $old, 'rollback preserves old phone and login generation');
    checkReset((int)Db::name('wechat_user')->where('id', $wid)->value('uid') === $uid, 'rollback preserves original WeChat binding');
    $identity->replacePhoneIdentity($uid, $new, ['source' => 'LOCAL_TEST']);
    $state = $security->state($uid);
    checkReset(strlen($state['nonce']) === 32 && !(int)$state['phone_verified'], 'phone change creates durable unverified revocation');
    $binding = Db::name('wechat_user')->where('id', $wid)->find();
    checkReset((int)$binding['uid'] === 0 && (int)$binding['is_del'] === 1, 'old WeChat binding removed with historical row retained');
    checkReset(in_array($wid, json_decode($state['binding_ids'], true), true), 'audit links original member to revoked historical binding');
    checkReset(!$wechat->getAuthUserInfo($prefix, 'routine'), 'old openid no longer resolves original member');
    deniedReset(function () use ($auth, $legacy) { $auth->parseToken($legacy['token']); }, 'pre-change legacy session immediately denied even with warm user cache');
    deniedReset(function () use ($auth, $oldToken) { $auth->parseToken($oldToken['token']); }, 'all original mini sessions denied');
    deniedReset(function () use ($auth, $oldApi) { $auth->parseToken($oldApi['token']); }, 'original H5 member session denied');
    deniedReset(function () use ($issue, $uid) { $issue($uid); }, 'cannot mint replacement token before phone authorization');
    $adminToken = $issue($uid, 'admin');
    checkReset(!array_key_exists('member_login_nonce', $adminToken['params']), 'shared token factory leaves same numeric platform identity outside member revocation');
    deniedReset(function () use ($security, $uid, $old) { $security->authorizeWechatLocked($uid, $old); }, 'old verified phone cannot reclaim changed member');
    deniedReset(function () use ($security, $uid) { $security->authorizeWechatLocked($uid, ''); }, 'stale authorization cache without phone cannot clear gate');
    checkReset($security->silentProof($uid) === null, 'silent login requires fresh phone grant');
    // Registration of the released old number owns a separate UID; the old WeChat
    // identity must not use unionid/openid fallback to jump back to the rich member.
    $oldOwner = (int)Db::name('user')->insertGetId(requiredSeed('user', [
        'phone' => '', 'nickname' => $prefix . '_old_number', 'pwd' => md5('local-test-only'),
        'status' => 1, 'is_del' => 0, 'now_money' => '0.00', 'integral' => 0, 'add_time' => time(), 'user_type' => 'routine',
    ])); $uids[] = $oldOwner;
    $identity->bindPhoneIdentity($oldOwner, $old, ['source' => 'LOCAL_TEST_REGISTRATION']);
    $oldLogin = $wechat->wechatOauthAfter([$prefix, ['openid' => $prefix, 'phone' => $old, 'unionid' => $prefix . '_union'], 0, 'routine', 'routine']);
    checkReset((int)$oldLogin['uid'] === $oldOwner && $oldOwner !== $uid && (float)$oldLogin['now_money'] === 0.0, 'same original WeChat using old phone resolves separate empty account, never original assets');
    $separateToken = $issue($oldOwner, 'routine', $oldLogin['_member_login_proof']);
    checkReset((int)$auth->parseToken($separateToken['token'])['user']['uid'] === $oldOwner, 'old number can enter only its separate member; original revocation does not block unrelated UID');
    // Actual OAuth resolution and persistence with provider-verified result supplied as test input.
    $u = $wechat->wechatOauthAfter([$prefix, ['openid' => $prefix, 'phone' => $new, 'user_type' => 'routine', 'headimgurl' => '', 'unionid' => $prefix . '_union'], 0, 'routine', 'routine']);
    checkReset((int)$u['uid'] === $uid && $u['_member_login_proof'] === $state['nonce'], 'new authorized phone returns exact original UID through real OAuth service');
    checkReset((int)$security->state($uid)['phone_verified'] === 1, 'new phone authorization unlocks current generation');
    $newToken = app()->make(app\services\wechat\RoutineServices::class)->getReturnInfo($u);
    $tokens[] = $newToken['token'];
    checkReset(!isset($newToken['userInfo']['_member_login_proof']), 'actual mini login response strips internal proof from member profile');
    checkReset((int)$auth->parseToken($newToken['token'])['user']['uid'] === $uid, 'new authorized session enters original account');
    deniedReset(function () use ($auth, $oldToken) { $auth->parseToken($oldToken['token']); }, 'new authorization never revives old session');
    deniedReset(function () use ($issue, $uid, $staleProof) { $issue($uid, 'routine', $staleProof); }, 'login started before phone change cannot mint token afterwards');
    checkReset((int)Db::name('wechat_user')->where('uid', $uid)->where('is_del', 0)->count() === 1, 'fresh authorization creates one active binding without reviving historical row');
    $account = Db::name('user')->where('uid', $uid)->find();
    checkReset($account['phone'] === $new && (string)$account['now_money'] === '1234.00' && (int)$account['integral'] === 37, 'original phone updated; UID balance and points unchanged');
    // Change back must generate a different nonce rather than reviving the initial credentials.
    // A conflicting old-number owner must be rejected without revoking the current session.
    try {
        $identity->replacePhoneIdentity($uid, $old, ['source' => 'LOCAL_TEST_CONFLICT']);
        throw new RuntimeException('expected duplicate rejection');
    } catch (mohe\exceptions\AdminException $e) {
        checkReset($security->state($uid)['nonce'] === $state['nonce'], 'duplicate target phone rejects atomically without revoking current valid login');
    }
    $identity->clearPhoneIdentity($oldOwner, ['source' => 'LOCAL_TEST_RELEASE']);
    $identity->replacePhoneIdentity($uid, $old, ['source' => 'LOCAL_TEST']);
    checkReset($security->state($uid)['nonce'] !== $state['nonce'], 'changing back to old digits still creates a distinct revocation');
    deniedReset(function () use ($auth, $newToken) { $auth->parseToken($newToken['token']); }, 'second phone change invalidates the newly issued session too');
    $security->authorizeWechatLocked($uid, $old);
    deniedReset(function () use ($auth, $legacy) { $auth->parseToken($legacy['token']); }, 'initial legacy token stays invalid after digits change back');
    $identity->clearPhoneIdentity($uid, ['source' => 'LOCAL_TEST']);
    checkReset($security->silentProof($uid) === null && Db::name('user')->where('uid', $uid)->value('phone') === '', 'clearing phone also requires reauthorization');
} finally {
    Db::rollback();
    foreach ($tokens as $token) CacheService::clearToken(md5($token));
    foreach ($uids as $id) think\facade\Cache::delete('user_info_' . $id);
}
checkReset((int)Db::name('member_phone_login_reset')->count() === $before && !Db::name('user')->where('nickname', $prefix)->count(), 'all synthetic database facts rolled back');
echo 'MEMBER_PHONE_RESET_DATABASE_PASS ', $checks, ' checks; no provider calls or production data changes', PHP_EOL;
