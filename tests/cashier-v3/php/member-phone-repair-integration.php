<?php
/** Isolated R43 member phone repair through the real V3 command and identity writer. */
require __DIR__ . '/../lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/../lib/_lib.php';
require __DIR__ . '/../lib/TestGraphFactory.php';
require __DIR__ . '/../lib/MemberIntegrationFixture.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\user\CanonicalUserIdentityServices;
use C1A\CashierV3\Test\MemberIntegrationFixture;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');
MemberIntegrationFixture::bindTestAdapters();

function phoneEditRequest($dispatcher, int $memberId, string $phone): array
{
    $request = MemberIntegrationFixture::commandRequest($dispatcher, 'update-member', [
        'memberId' => $memberId,
        'name' => '待修正会员',
        'phone' => $phone,
    ]);
    $projection = $dispatcher->dispatch([
        'action' => 'open-member-editor',
        'memberId' => $memberId,
        'clientSessionId' => $request['session']['client_session_id'],
        'stateContextId' => $request['session']['state_context_id'],
        'correlationId' => 'CORR-' . MemberIntegrationFixture::uuid(),
    ], $request['session']);
    foreach ((array)($projection['versions'] ?? []) as $version) {
        if (($version['kind'] ?? '') === 'member' && (string)($version['id'] ?? '') === (string)$memberId) {
            $request['body']['command']['contexts'][] = [
                'kind' => 'member', 'id' => (string)$memberId,
                'expectedVersion' => (int)$version['version'],
            ];
            break;
        }
    }
    return $request;
}

function phoneEditError($dispatcher, array $request): array
{
    try {
        $dispatcher->dispatch($request['body'], $request['session']);
        return ['code' => '', 'detail' => []];
    } catch (CashierV3CommandException $exception) {
        return ['code' => $exception->getResultCode(), 'detail' => $exception->getDetail()];
    }
}

try {
    MemberIntegrationFixture::ensureLegacySchema();
    MemberIntegrationFixture::resetAndSeed();
    MemberIntegrationFixture::seedMember(101, '待修正会员', '13800001101', 8);
    MemberIntegrationFixture::seedMember(102, '其他会员', '13800001102', 8);
    $identity = app()->make(CanonicalUserIdentityServices::class);
    $identity->bindPhoneIdentity(101, '13800001101', ['operator_type' => 'TEST', 'source' => 'R43_PHONE_REPAIR']);
    $dispatcher = MemberIntegrationFixture::dispatcher();

    $invalid = phoneEditError($dispatcher, phoneEditRequest($dispatcher, 101, '123'));
    $occupied = phoneEditError($dispatcher, phoneEditRequest($dispatcher, 101, '13800001102'));
    ok('非法及已占用手机号均不覆盖原会员身份',
        $invalid['code'] === CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE
            && isset($invalid['detail']['fieldErrors']['phone'])
            && $occupied['code'] === CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE
            && isset($occupied['detail']['fieldErrors']['phone'])
            && (string)Db::name('user')->where('uid', 101)->value('phone') === '13800001101',
        json_encode(['invalid' => $invalid, 'occupied' => $occupied], JSON_UNESCAPED_UNICODE),
        'R43-MEM-01');

    $request = phoneEditRequest($dispatcher, 101, '13800001103');
    $first = $dispatcher->dispatch($request['body'], $request['session']);
    $replay = $dispatcher->dispatch($request['body'], $request['session']);
    $old = Db::name('user_phone_identity')->where('phone_digest', hash('sha256', '13800001101'))->find();
    $new = Db::name('user_phone_identity')->where('phone_digest', hash('sha256', '13800001103'))->find();
    $events = Db::name('cashier_v3_business_event')->where('event_type', 'member.updated')
        ->where('aggregate_id', '101')->select()->toArray();
    $eventPayload = json_decode((string)($events[0]['payload'] ?? ''), true);
    ok('修正手机号保持原 UID，旧身份释放、新身份绑定且重放不重复记事件',
        ($first['result']['status'] ?? '') === CashierV3ResultCode::STATUS_SUCCESS
            && !empty($replay['replay'])
            && (int)($first['data']['member']['memberId'] ?? 0) === 101
            && (string)Db::name('user')->where('uid', 101)->value('phone') === '13800001103'
            && (string)($old['state'] ?? '') === 'RELEASED'
            && (int)($old['bound_uid'] ?? 0) === 0
            && (string)($new['state'] ?? '') === 'BOUND'
            && (int)($new['bound_uid'] ?? 0) === 101
            && count($events) === 1
            && in_array('phone', (array)($eventPayload['changed_fields'] ?? []), true)
            && (string)($eventPayload['phone'] ?? '') === '13800001103',
        json_encode(['first' => $first, 'old' => $old, 'new' => $new, 'events' => $events], JSON_UNESCAPED_UNICODE),
        'R43-MEM-02');
} catch (\Throwable $exception) {
    ok('手机号修正集成测试无未处理异常', false,
        get_class($exception) . ': ' . $exception->getMessage() . ' @ '
            . $exception->getFile() . ':' . $exception->getLine() . "\n" . $exception->getTraceAsString(),
        'R43-MEM-00');
}

finish('R43-member-phone-repair-integration');
