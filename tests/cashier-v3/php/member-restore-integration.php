<?php
/** Isolated member cancellation, phone ownership and original-UID restoration regression. */
require __DIR__ . '/../lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/../lib/_lib.php';
require __DIR__ . '/../lib/TestGraphFactory.php';
require __DIR__ . '/../lib/MemberIntegrationFixture.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\user\CanonicalUserIdentityServices;
use C1A\CashierV3\Test\MemberIntegrationFixture;
use mohe\exceptions\AdminException;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');
MemberIntegrationFixture::bindTestAdapters();

try {
    MemberIntegrationFixture::ensureLegacySchema();
    MemberIntegrationFixture::resetAndSeed();
    MemberIntegrationFixture::seedMember(107, '注销待恢复', '13100000107', 8, 0, 1, '2026-07-28 00:00:00');
    MemberIntegrationFixture::seedMember(108, '跨店注销会员', '13100000108', 9, 0, 1, '2026-07-28 00:00:00');
    $dispatcher = MemberIntegrationFixture::dispatcher();
    $session = MemberIntegrationFixture::projectionSession(1);
    $selector = $dispatcher->dispatch([
        'action' => 'query-member-selector', 'selectorEntry' => 'cashier',
        'keyword' => '13100000107', 'memberScope' => 'all',
        'clientSessionId' => $session['client_session_id'],
        'correlationId' => 'CORR-' . MemberIntegrationFixture::uuid(),
    ], $session);
    ok('注销号码不出现在收银搜索及总数中',
        (int)($selector['data']['total'] ?? -1) === 0
            && ($selector['data']['records'] ?? null) === [],
        json_encode($selector, JSON_UNESCAPED_UNICODE), 'R31-MEMBER-01');

    $identity = app()->make(CanonicalUserIdentityServices::class);
    $created = false;
    $blocked = false;
    try {
        $identity->ensureUserIdentity('13100000107', function () use (&$created) {
            $created = true;
            return 999;
        });
    } catch (AdminException $exception) {
        $blocked = strpos($exception->getMessage(), '已注销会员') !== false;
    }
    ok('统一身份入口不重建已注销号码', $blocked && !$created, '', 'R31-MEMBER-02');
    $rebindBlocked = false;
    try {
        $identity->bindPhoneIdentity(108, '13100000107');
    } catch (AdminException $exception) {
        $rebindBlocked = strpos($exception->getMessage(), '已属于其他会员') !== false;
    }
    ok('注销号码不能绑定到另一注销会员', $rebindBlocked
        && (string)Db::name('user')->where('uid', 108)->value('phone') === '13100000108',
        '', 'R31-MEMBER-02');

    $crossStoreBlocked = false;
    try {
        $dispatcher->dispatch([
            'action' => 'open-member-restore', 'memberId' => 108,
            'clientSessionId' => $session['client_session_id'],
            'correlationId' => 'CORR-' . MemberIntegrationFixture::uuid(),
        ], $session);
    } catch (CashierV3CommandException $exception) {
        $crossStoreBlocked = true;
    }
    ok('跨店无授权账号不能打开恢复投影', $crossStoreBlocked, '', 'R31-MEMBER-03');

    $request = MemberIntegrationFixture::commandRequest($dispatcher, 'restore-member', ['memberId' => 107]);
    $projection = $dispatcher->dispatch([
        'action' => 'open-member-restore', 'memberId' => 107,
        'clientSessionId' => $request['session']['client_session_id'],
        'stateContextId' => $request['session']['state_context_id'],
        'correlationId' => 'CORR-' . MemberIntegrationFixture::uuid(),
    ], $request['session']);
    $memberVersion = 0;
    foreach ((array)($projection['versions'] ?? []) as $version) {
        if (($version['kind'] ?? '') === 'member' && (string)($version['id'] ?? '') === '107') {
            $memberVersion = (int)($version['version'] ?? 0);
        }
    }
    ok('恢复投影签发注销会员当前版本', $memberVersion > 0, json_encode($projection, JSON_UNESCAPED_UNICODE), 'R31-MEMBER-03');
    $request['body']['command']['contexts'][] = [
        'kind' => 'member', 'id' => '107', 'expectedVersion' => $memberVersion,
    ];

    MemberIntegrationFixture::seedMember(109, '历史重复占号', '13100000107', 8, 0, 1, '2026-07-28 00:00:00');
    $duplicateBlocked = false;
    try {
        $dispatcher->dispatch($request['body'], $request['session']);
    } catch (CashierV3CommandException $exception) {
        $duplicateBlocked = strpos($exception->getMessage(), '已属于其他会员') !== false;
    }
    ok('另一注销会员占号时拒绝恢复且原会员不变', $duplicateBlocked
        && (int)Db::name('user')->where('uid', 107)->value('is_del') === 1, '', 'R31-MEMBER-04');
    Db::name('store_user')->where('uid', 109)->delete();
    Db::name('user')->where('uid', 109)->delete();

    $request['body']['command']['idempotencyKey'] = 'CMD-' . MemberIntegrationFixture::uuid();
    $first = $dispatcher->dispatch($request['body'], $request['session']);
    $replay = $dispatcher->dispatch($request['body'], $request['session']);
    $member = Db::name('user')->where('uid', 107)->find();
    $mapped = Db::name('user_phone_identity')->where('phone_digest', hash('sha256', '13100000107'))->find();
    ok('恢复原 UID、状态和身份映射且事件只写一次',
        (string)($member['phone'] ?? '') === '13100000107'
            && (int)($member['status'] ?? 0) === 1
            && (int)($member['is_del'] ?? 1) === 0
            && ($member['delete_time'] ?? null) === null
            && (string)($mapped['state'] ?? '') === 'BOUND'
            && (int)($mapped['bound_uid'] ?? 0) === 107
            && (int)Db::name('cashier_v3_business_event')->where('event_type', 'member.restored')->where('aggregate_id', '107')->count() === 1
            && ($first['result']['status'] ?? '') === 'success'
            && !empty($replay['replay']),
        json_encode(['member' => $member, 'identity' => $mapped, 'first' => $first, 'replay' => $replay], JSON_UNESCAPED_UNICODE),
        'R31-MEMBER-05');
    $reused = $identity->ensureUserIdentity('13100000107', function () use (&$created) {
        $created = true;
        return 999;
    });
    ok('恢复后统一身份入口复用原 UID', $reused === 107 && !$created, '', 'R31-MEMBER-06');
    $replaceBlocked = false;
    try {
        $identity->replacePhoneIdentity(107, '13100000108');
    } catch (AdminException $exception) {
        $replaceBlocked = strpos($exception->getMessage(), '已属于其他会员') !== false;
    }
    ok('正常会员换号也不能占用注销会员号码', $replaceBlocked
        && (string)Db::name('user')->where('uid', 107)->value('phone') === '13100000107', '', 'R31-MEMBER-07');
    $cancelledClearBlocked = false;
    try {
        $identity->clearPhoneIdentity(108);
    } catch (AdminException $exception) {
        $cancelledClearBlocked = true;
    }
    ok('注销会员不能借清空号码释放占号', $cancelledClearBlocked
        && (string)Db::name('user')->where('uid', 108)->value('phone') === '13100000108', '', 'R31-MEMBER-08');
    $identity->clearPhoneIdentity(107);
    $released = Db::name('user_phone_identity')->where('phone_digest', hash('sha256', '13100000107'))->find();
    ok('仅活跃会员主动清空号码才释放身份映射',
        (string)Db::name('user')->where('uid', 107)->value('phone') === ''
            && (string)($released['state'] ?? '') === 'RELEASED'
            && (int)($released['bound_uid'] ?? 0) === 0,
        '', 'R31-MEMBER-08');
} catch (\Throwable $exception) {
    ok('恢复集成测试无未处理异常', false,
        get_class($exception) . ': ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine()
            . "\n" . $exception->getTraceAsString(), 'R31-MEMBER-00');
}

finish('R31-member-restore-integration');
