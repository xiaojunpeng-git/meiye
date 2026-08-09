<?php
/**
 * C1-A 永久回归：容器真实解析与唯一 Composition Root。
 */
require __DIR__ . '/../lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/../lib/_lib.php';

use app\controller\cashier\v3\Command;
use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3CommandGatewayServices;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\event\CashierV3ActionActivationGate;
use app\services\cashier\v3\event\CashierV3EventConsumerRegistry;
use app\services\cashier\v3\event\CashierV3OutboxServices;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\readiness\CashierV3TableReadinessGuard;
use app\services\cashier\v3\registry\CashierV3ContextPolicy;
use think\facade\Db;

$app = c1aBootThinkApp('/var/www/html/');
CashierV3Bootstrap::resetForTests();

echo "== container resolve + composition root ==\n";

try {
    CashierV3Bootstrap::registerModuleInstaller(function ($dispatcher): void {
        $dispatcher->policies()->register(new CashierV3ContextPolicy(
            'upgrade-sales-order',
            ['cashier_workspace'],
            [],
            null,
            ['cashier_workspace']
        ));
        $dispatcher->handlers()->registerCommand('upgrade-sales-order', function (): array {
            return ['data' => ['mustNotRun' => true], 'touched' => ['cashier_workspace']];
        });
    });
    $deferredBootstrapRejected = false;
    $deferredBootstrapMessage = '';
    try {
        CashierV3Bootstrap::dispatcher();
    } catch (\LogicException $exception) {
        $deferredBootstrapMessage = $exception->getMessage();
        $deferredBootstrapRejected = strpos($deferredBootstrapMessage, 'production_event_contract') !== false;
    }
    ok(
        '已注册 handler 的延后合同阻断 Bootstrap freeze',
        $deferredBootstrapRejected,
        $deferredBootstrapMessage,
        'EO-15-02'
    );
    CashierV3Bootstrap::resetForTests();

    $d1 = CashierV3Bootstrap::dispatcher();
    $d2 = CashierV3Bootstrap::dispatcher();
    ok('Bootstrap::dispatcher 同一实例', $d1 === $d2, '', 'PG-13-01');
    ok('Dispatcher 类型正确', $d1 instanceof CashierV3ActionDispatcher, '', 'CR-4-01');
    ok(
        '销售欠款补交准备与提交在生产 Composition Root 完整激活',
        $d1->handlers()->hasCommand('prepare-debt-repayment')
            && $d1->handlers()->hasCommand('submit-debt-repayment')
            && $d1->policies()->has('prepare-debt-repayment')
            && $d1->policies()->has('submit-debt-repayment'),
        json_encode([
            'handlers' => $d1->handlers()->registeredActions(),
            'policies' => $d1->policies()->registeredActions(),
        ], JSON_UNESCAPED_UNICODE),
        'CASHIER-V3-00002'
    );

    // 容器必须返回 Bootstrap 同一 Gateway／Dispatcher，禁止旁路第二套
    $gateway = app()->make(CashierV3CommandGatewayServices::class);
    ok('容器 Gateway 即 Bootstrap 内网关', $gateway === $d1->gateway(), '', 'CR-4-01');
    $directGatewayBlocked = false;
    $directBusinessRan = false;
    try {
        $gateway->execute(
            'upgrade-sales-order',
            [],
            [],
            new CashierV3OperatorScope(8, 1, '3', '0'),
            [],
            function () use (&$directBusinessRan): array {
                $directBusinessRan = true;
                return ['data' => [], 'touched' => []];
            }
        );
    } catch (CashierV3CommandException $exception) {
        $directGatewayBlocked = $exception->getResultCode() === CashierV3ResultCode::ACTION_NOT_IMPLEMENTED
            && in_array('production_event_contract', (array)($exception->getDetail()['missing'] ?? []), true);
    }
    ok(
        'Gateway 直调也在业务 callback 前拒绝延后合同',
        $directGatewayBlocked && !$directBusinessRan,
        '',
        'EO-15-02'
    );

    $forgedDefinition = CashierV3ActionManifest::requireAction('submit-checkout');
    $forgedDefinition['event_contract'] = [
        'required_event_types' => [],
        'allowed_event_types' => [],
        'event_rules' => [],
        'eventless_reason' => '调用方伪造为无需业务事件。',
        'activation_blocked_until_event_contract' => false,
        'consumers' => [],
    ];
    $writeCountsBeforeForgery = [
        'state_context' => (int)Db::name('cashier_v3_state_context')->count(),
        'receipt' => (int)Db::name('cashier_v3_command_receipt')->count(),
        'business_event' => (int)Db::name('cashier_v3_business_event')->count(),
        'outbox' => (int)Db::name('cashier_v3_outbox')->count(),
    ];
    $forgedDefinitionRejected = false;
    $forgedDefinitionReason = '';
    $forgedBusinessRan = false;
    try {
        $gateway->execute(
            'submit-checkout',
            ['idempotencyKey' => 'CMD-00000000-0000-4000-8000-000000000001', 'contexts' => []],
            [],
            new CashierV3OperatorScope(8, 1, '3', '0'),
            [],
            function () use (&$forgedBusinessRan): array {
                $forgedBusinessRan = true;
                return ['data' => ['mustNotRun' => true], 'touched' => []];
            },
            null,
            $forgedDefinition
        );
    } catch (CashierV3CommandException $exception) {
        $forgedDefinitionReason = (string)($exception->getDetail()['reason'] ?? '');
        $forgedDefinitionRejected = $exception->getResultCode() === CashierV3ResultCode::EVENT_CONTRACT_INVALID
            && $forgedDefinitionReason === 'action_definition_not_authoritative';
    }
    $writeCountsAfterForgery = [
        'state_context' => (int)Db::name('cashier_v3_state_context')->count(),
        'receipt' => (int)Db::name('cashier_v3_command_receipt')->count(),
        'business_event' => (int)Db::name('cashier_v3_business_event')->count(),
        'outbox' => (int)Db::name('cashier_v3_outbox')->count(),
    ];
    ok(
        'Gateway 拒绝调用方把 submit-checkout 伪造成 eventless 且零业务写入',
        $forgedDefinitionRejected
            && !$forgedBusinessRan
            && $writeCountsAfterForgery === $writeCountsBeforeForgery,
        json_encode([
            'reason' => $forgedDefinitionReason,
            'before' => $writeCountsBeforeForgery,
            'after' => $writeCountsAfterForgery,
        ], JSON_UNESCAPED_UNICODE),
        'EO-15-02'
    );
    $dispatcherFromContainer = app()->make(CashierV3ActionDispatcher::class);
    ok('容器 Dispatcher 即 Bootstrap', $dispatcherFromContainer === $d1, '', 'CR-4-01');
    ok('生产 Dispatcher 唯一', $d1 === CashierV3Bootstrap::dispatcher(), '', 'CR-4-01');

    $activationGate = app()->make(CashierV3ActionActivationGate::class);
    $consumerRegistry = app()->make(CashierV3EventConsumerRegistry::class);
    $outbox = app()->make(CashierV3OutboxServices::class);
    ok(
        'Bootstrap/Gateway/Dispatcher/Outbox 共用冻结消费者目录',
        $activationGate === $d1->actionActivationGate()
            && $activationGate === $gateway->actionActivationGate()
            && $consumerRegistry === $activationGate->consumerRegistry()
            && $consumerRegistry === $d1->eventConsumerRegistry()
            && $consumerRegistry === $outbox->consumerRegistry()
            && $outbox === CashierV3Bootstrap::outbox()
            && $consumerRegistry->isFrozen(),
        json_encode([
            'registryFrozen' => $consumerRegistry->isFrozen(),
            'codes' => $consumerRegistry->codes(),
        ]),
        'CR-4-01'
    );

    $readiness = app()->make(CashierV3TableReadinessGuard::class);
    ok('ReadinessGuard 容器解析', $readiness instanceof CashierV3TableReadinessGuard, '', 'PG-13-01');

    $cmd = app()->make(Command::class);
    ok('Command 容器解析', $cmd instanceof Command, '', 'CR-4-01');

    $ref = new ReflectionClass($cmd);
    $prop = $ref->getProperty('dispatcher');
    $prop->setAccessible(true);
    $cmdDispatcher = $prop->getValue($cmd);
    ok('Controller dispatcher 与 Bootstrap 同一对象', $cmdDispatcher === $d1, '', 'CR-4-01');

    $check = CashierV3Bootstrap::lastSelfCheck();
    ok('Bootstrap selfCheck 结构', is_array($check) && array_key_exists('ok', $check), json_encode($check), 'CR-4-02');
    ok('selfCheck ok 才 freeze', !empty($check['ok']), json_encode($check['problems'] ?? []), 'CR-4-02');
    ok('frozen', CashierV3Bootstrap::isFrozen() && $d1->isFrozen(), '', 'CR-4-04');

    // 生产 Bootstrap 不得再暴露 buildDispatcherForTests
    ok(
        '生产无 buildDispatcherForTests 旁路',
        !method_exists(CashierV3Bootstrap::class, 'buildDispatcherForTests'),
        '',
        'CR-4-01'
    );

    // 容器绑定失败负例：毒化 instance 后不得当作合法 Composition Root
    $bindFailClosed = false;
    $bindFailDetail = '';
    try {
        $appPoison = app();
        if (method_exists($appPoison, 'instance')) {
            $appPoison->instance(CashierV3ActionDispatcher::class, new stdClass());
        }
        $rogue = $appPoison->make(CashierV3ActionDispatcher::class);
        $bindFailClosed = !($rogue instanceof CashierV3ActionDispatcher && $rogue === $d1);
        $bindFailDetail = $bindFailClosed ? ('rogue_type=' . (is_object($rogue) ? get_class($rogue) : gettype($rogue))) : 'same_as_bootstrap';
    } catch (Throwable $e) {
        $bindFailClosed = true;
        $bindFailDetail = $e->getMessage();
    }
    CashierV3Bootstrap::resetForTests();
    $restored = CashierV3Bootstrap::dispatcher();
    ok('容器绑定失败 fail-closed 负例', $bindFailClosed === true, $bindFailDetail, 'CR-4-01');
    ok('恢复后 Bootstrap dispatcher 可用', $restored instanceof CashierV3ActionDispatcher, '', 'CR-4-01');
    $check = CashierV3Bootstrap::lastSelfCheck();

    // open-cashier-workbench 投影已注册
    $matrix = $check['active_matrix']['open-cashier-workbench'] ?? null;
    ok(
        'workbench bootstrap 投影 provider 从注册导出',
        is_array($matrix) && !empty($matrix['projection_provider']) && !empty($matrix['handler']),
        json_encode($matrix),
        'CR-4-02'
    );
} catch (Throwable $e) {
    ok('Bootstrap 容器解析不得吞异常', false, $e->getMessage(), 'PG-13-01');
}

finish('container-resolve');
