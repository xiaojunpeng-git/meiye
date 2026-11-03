<?php
declare(strict_types=1);

/**
 * Local MySQL acceptance for the two native member promotion flags.
 * Runs the production permission guard, registered update handler, event writer
 * and editor projection. Every fixture and resulting write stays in one rolled
 * back transaction; no existing member, schema, payment or online instance is changed.
 */
require (getenv('BACKEND_ROOT') ?: '/var/www/html') . '/vendor/autoload.php';

use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use think\facade\Db;

$app = new think\App((getenv('BACKEND_ROOT') ?: '/var/www/html') . '/');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Only local Docker mysql/ruihao is allowed.');
}

function r44MemberCheck(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . "\n";
}

/** Required schema values are fabricated, never cloned from a real member. */
function r44MemberSeed(string $table, array $data): int
{
    foreach (Db::query('SHOW COLUMNS FROM eb_' . $table) as $field) {
        $key = $field['Field'];
        if (array_key_exists($key, $data) || $field['Default'] !== null
            || $field['Null'] === 'YES' || strpos($field['Extra'], 'auto_increment') !== false) continue;
        $data[$key] = preg_match('/int|decimal|float|double/', $field['Type']) ? 0 : '';
    }
    return (int)Db::name($table)->insertGetId($data);
}

/** Simulate trusted controller contexts; platform identity is never a fake store employee. */
function r44MemberScope(int $store, int $org, bool $platform, array $stores, bool $feature = true): array
{
    $operatorId = $platform ? 944001 : 944002;
    $profile = ['id' => $operatorId, 'name' => $platform ? 'R44虚构平台管理员' : 'R44虚构门店操作员'];
    if ($platform) $profile['_platform_member'] = true;
    return [
        new CashierV3OperatorScope($store, $operatorId, (string)$org, '0'),
        new CashierV3DataScopeContext($operatorId, $platform ? 0 : 944002, $store, '0', (string)$org,
            $stores, CashierV3DataScopeContext::MODE_STORES, [], false, '', 'r44-local-permission',
            $feature ? ['cashier.v3.member', 'cashier.v3.member.edit'] : ['cashier.v3.member'], $profile),
    ];
}

$tables = ['system_store', 'organization', 'organization_store', 'user', 'store_user', 'user_level',
    'user_label_relation', 'cashier_v3_business_event', 'cashier_v3_outbox', 'cashier_v3_entitlement_resource_version'];
foreach ($tables as $table) {
    $engine = Db::query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['eb_' . $table]);
    if (strtoupper((string)($engine[0]['ENGINE'] ?? '')) !== 'INNODB') {
        throw new RuntimeException('Rollback acceptance requires InnoDB: ' . $table);
    }
}
$dispatcher = CashierV3Bootstrap::dispatcher();
$saveDefinition = CashierV3ActionManifest::requireAction('update-member');
$readDefinition = CashierV3ActionManifest::requireAction('open-member-editor');
$saveHandler = $dispatcher->handlers()->requireCommand('update-member');
$readHandler = $dispatcher->handlers()->requireProjection('open-member-editor');
$recorder = new CashierV3BusinessEventRecorder();
$tag = 'R44-MEMBER-' . bin2hex(random_bytes(5));
$fixtureIds = [];

Db::startTrans();
try {
    $org = r44MemberSeed('organization', ['name' => $tag . '-组织', 'pid' => 0, 'is_del' => 0]);
    $store = r44MemberSeed('system_store', ['name' => $tag . '-门店', 'is_show' => 1, 'is_del' => 0]);
    $otherStore = r44MemberSeed('system_store', ['name' => $tag . '-无授权门店', 'is_show' => 1, 'is_del' => 0]);
    r44MemberSeed('organization_store', ['org_id' => $org, 'store_id' => $store]);
    do { $phone = '199' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT); }
    while (Db::name('user')->where('phone', $phone)->count());
    $uid = r44MemberSeed('user', ['nickname' => $tag, 'real_name' => $tag, 'phone' => $phone,
        'status' => 1, 'is_del' => 0, 'belong_store_id' => $store, 'spread_open' => 1, 'is_promoter' => 0,
        'extend_info' => '[]', 'bar_code' => (string)random_int(800000000, 899999999)]);
    r44MemberSeed('store_user', ['uid' => $uid, 'store_id' => $store, 'status' => 1]);
    $fixtureIds = compact('uid', 'store', 'otherStore', 'org');

    $save = static function (array $pair, array $values) use ($dispatcher, $saveDefinition, $saveHandler, $recorder, $uid, $tag): array {
        [$operator, $scope] = $pair;
        $payload = array_merge(['memberId' => $uid, 'name' => $tag], $values);
        $dispatcher->permissionGuard()->assertAllowed($saveDefinition, $scope, $payload);
        $key = 'R44-MEMBER-' . bin2hex(random_bytes(12));
        $execution = $recorder->newExecution('update-member', $key, $operator, $scope, 'R44-LOCAL');
        $result = $saveHandler(['payload' => $payload, 'operator_scope' => $operator, 'data_scope' => $scope,
            'idempotency_key' => $key, 'event_recorder' => $recorder, 'event_execution' => $execution,
            'event_contract' => $saveDefinition['event_contract']]);
        $recorder->assertRequiredPersistedInTx($execution, $saveDefinition['event_contract']);
        return $result;
    };
    $read = static function (array $pair) use ($dispatcher, $readDefinition, $readHandler, $uid): array {
        [$operator, $scope] = $pair;
        $payload = ['memberId' => $uid];
        $dispatcher->permissionGuard()->assertAllowed($readDefinition, $scope, $payload);
        return $readHandler(['payload' => $payload, 'operator_scope' => $operator, 'data_scope' => $scope])['data']['member'];
    };
    $rejected = static function (callable $attempt, string $label, string $expectedCode) use ($uid): void {
        $before = Db::name('user')->where('uid', $uid)->field('spread_open,is_promoter')->find();
        $events = Db::name('cashier_v3_business_event')->where('member_id', $uid)->count();
        try { $attempt(); throw new RuntimeException('Unexpected permission/value success: ' . $label); }
        catch (CashierV3CommandException $e) {
            r44MemberCheck($e->getResultCode() === $expectedCode, $label . ' rejected by production code');
        }
        r44MemberCheck(Db::name('user')->where('uid', $uid)->field('spread_open,is_promoter')->find() === $before
            && Db::name('cashier_v3_business_event')->where('member_id', $uid)->count() === $events,
            $label . ' leaves values and events unchanged');
    };

    foreach ([false, true] as $platform) {
        $name = $platform ? 'platform' : 'store';
        $pair = r44MemberScope($store, $org, $platform, [$store]);
        foreach ([[0, 1], [1, 0], ['0', '1'], ['1', '0']] as $values) {
            $save($pair, ['spread_open' => $values[0], 'is_promoter' => $values[1]]);
            $raw = Db::name('user')->where('uid', $uid)->field('spread_open,is_promoter')->find();
            $fresh = $read($pair);
            r44MemberCheck((int)$raw['spread_open'] === (int)$values[0] && (int)$raw['is_promoter'] === (int)$values[1]
                && $fresh['spread_open'] === (int)$values[0] && $fresh['is_promoter'] === (int)$values[1],
                $name . ' legal flags persist and editor reload matches ' . json_encode($values));
        }
        $save($pair, ['spread_open' => 0, 'is_promoter' => 1]);
        $save($pair, []);
        $fresh = $read($pair);
        r44MemberCheck($fresh['spread_open'] === 0 && $fresh['is_promoter'] === 1, $name . ' omitted flags retain stored values');
        $save($pair, ['spread_open' => 1]);
        $fresh = $read($pair);
        r44MemberCheck($fresh['spread_open'] === 1 && $fresh['is_promoter'] === 1, $name . ' one supplied flag preserves the other');
        foreach (['spread_open', 'is_promoter'] as $field) {
            foreach ([2, -1, true, false, 1.0, '', null, [], '01', 'true'] as $bad) {
                $rejected(static function () use ($save, $pair, $field, $bad): void { $save($pair, [$field => $bad]); },
                    $name . ' invalid ' . $field . '=' . json_encode($bad), CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE);
            }
        }
        $withoutFeature = r44MemberScope($store, $org, $platform, [$store], false);
        $outsideScope = r44MemberScope($store, $org, $platform, [$otherStore]);
        foreach (['missing edit permission' => $withoutFeature, 'outside member store' => $outsideScope] as $case => $badPair) {
            $rejected(static function () use ($save, $badPair): void { $save($badPair, ['spread_open' => 0, 'is_promoter' => 0]); },
                $name . ' ' . $case, CashierV3ResultCode::PERMISSION_DENIED);
            $rejected(static function () use ($read, $badPair): void { $read($badPair); },
                $name . ' editor ' . $case, CashierV3ResultCode::PERMISSION_DENIED);
        }
        $event = Db::name('cashier_v3_business_event')->where('member_id', $uid)->order('id desc')->find();
        $payload = json_decode((string)$event['payload'], true);
        r44MemberCheck($payload['actor_type'] === ($platform ? 'platform_admin' : 'store')
            && isset($payload['promotion_permissions_before']['spread_open'])
            && $payload['promotion_permissions_after']['spread_open'] === 1,
            $name . ' real mutation event records actor and before/after native flag');
    }
} finally {
    Db::rollback();
}
if ($fixtureIds) {
    r44MemberCheck(Db::name('user')->where('uid', $fixtureIds['uid'])->count() === 0
        && Db::name('store_user')->where('uid', $fixtureIds['uid'])->count() === 0
        && Db::name('cashier_v3_business_event')->where('member_id', $fixtureIds['uid'])->count() === 0
        && Db::name('cashier_v3_entitlement_resource_version')->where('member_id', $fixtureIds['uid'])->count() === 0
        && Db::name('system_store')->whereIn('id', [$fixtureIds['store'], $fixtureIds['otherStore']])->count() === 0
        && Db::name('organization')->where('id', $fixtureIds['org'])->count() === 0,
        'all fabricated members, stores, organization, events and projections rolled back');
}
echo "PASS R44 member native promotion flags local MySQL acceptance\n";
