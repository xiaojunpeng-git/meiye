<?php

namespace app\services\customer\care\integration;

use app\services\customer\care\CustomerCareCommandService;
use app\services\customer\care\CustomerCareDomainException;
use app\services\customer\care\CustomerCareErrorCode;
use app\services\customer\care\query\CustomerCareProjectionErrorCode;
use app\services\customer\care\query\CustomerCareProjectionException;
use app\services\customer\care\query\CustomerCareQueryScope;
use app\services\customer\care\query\CustomerCareWorkbenchQueryService;
use think\facade\Log;

/**
 * Standalone Phase B adapter. Shared cashier-v3 registration is intentionally deferred.
 * A future Gateway module must pass its server-trusted idempotency key and last workbench
 * query; page payloads are never allowed to supply tenant, stores or permission scope.
 */
final class CustomerCareWorkbenchActionAdapter
{
    /** @var CustomerCareCommandService */
    private $commands;

    /** @var CustomerCareWorkbenchQueryService */
    private $queries;

    /** @var CustomerCareActionInputMapper */
    private $mapper;

    public function __construct(
        CustomerCareCommandService $commands,
        CustomerCareWorkbenchQueryService $queries,
        CustomerCareActionInputMapper $mapper
    ) {
        $this->commands = $commands;
        $this->queries = $queries;
        $this->mapper = $mapper;
    }

    public function handle(
        string $action,
        array $trustedContext,
        array $payload,
        string $gatewayIdempotencyKey = '',
        array $workbenchRequest = []
    ): array {
        try {
            $scope = CustomerCareQueryScope::fromTrustedContext($trustedContext);
            if ($action === CustomerCareActionInputMapper::QUERY) {
                $projection = $this->queries->queryForScope($scope, $payload);
                return $this->success('客情数据已重新读取。', null, $projection);
            }
            $mapped = $this->mapper->map($action, $scope, $payload, $gatewayIdempotencyKey);
            $method = $mapped['method'];
            $commandResult = $this->commands->{$method}($scope->commandActor(), $mapped['command']);
            try {
                $projection = $this->queries->queryForScope(
                    $scope,
                    $workbenchRequest !== [] ? $workbenchRequest : $this->defaultWorkbenchRequest()
                );
            } catch (\Throwable $projectionFailure) {
                $this->logProjectionRefreshFailure(
                    $action,
                    $commandResult,
                    $workbenchRequest,
                    $projectionFailure
                );
                return [
                    'result' => [
                        'status' => 'failed',
                        'code' => CustomerCareProjectionErrorCode::PROJECTION_REFRESH_REQUIRED,
                        'message' => '客情操作已形成确定回执，但完整页面数据刷新失败；可使用原请求标识安全重试。',
                        'detail' => [
                            'operationId' => (int)($commandResult['operationId'] ?? 0),
                            'operationKey' => (string)($commandResult['operationKey'] ?? ''),
                            'replayed' => (bool)($commandResult['replayed'] ?? false),
                            'retrySafe' => true,
                            'commandReceipt' => $commandResult,
                        ],
                    ],
                ];
            }
            return $this->success(
                $this->successMessage($action),
                $commandResult,
                $projection
            );
        } catch (CustomerCareDomainException $exception) {
            $result = [
                'status' => $exception->getErrorCode() === CustomerCareErrorCode::VERSION_CONFLICT
                    ? 'conflict'
                    : 'failed',
                'code' => $exception->getErrorCode(),
                'message' => $exception->getMessage(),
                'detail' => $exception->getDetail(),
            ];
            if ($exception->getErrorCode() === CustomerCareErrorCode::VERSION_CONFLICT) {
                $latest = $this->latestTask($trustedContext, $payload);
                if ($latest !== null) {
                    $result['latestTask'] = $latest;
                }
            }
            return ['result' => $result];
        } catch (CustomerCareProjectionException $exception) {
            return ['result' => [
                'status' => 'failed',
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
                'detail' => $exception->detail(),
            ]];
        } catch (\Throwable $unexpected) {
            Log::error('[customer-care] unexpected workbench failure: ' . json_encode([
                'action' => $action,
                'failureClass' => get_class($unexpected),
                'failureMessage' => $unexpected->getMessage(),
                'failureFile' => basename($unexpected->getFile()),
                'failureLine' => $unexpected->getLine(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ['result' => [
                'status' => 'failed',
                'code' => CustomerCareProjectionErrorCode::DEPENDENCY_NOT_READY,
                'message' => '客情服务暂时不可用，请稍后安全重试。',
                'detail' => ['retrySafe' => true],
            ]];
        }
    }

    private function latestTask(array $trustedContext, array $payload): ?array
    {
        $taskId = $payload['taskId'] ?? null;
        if (!is_int($taskId) && !(is_string($taskId) && preg_match('/^[1-9][0-9]*$/D', $taskId))) {
            return null;
        }
        try {
            return $this->queries->taskDetail($trustedContext, (int)$taskId);
        } catch (\Throwable $throwable) {
            return null;
        }
    }

    private function logProjectionRefreshFailure(
        string $action,
        array $commandResult,
        array $workbenchRequest,
        \Throwable $failure
    ): void {
        Log::warning('[customer-care] command applied but projection refresh failed: ' . json_encode([
            'action' => $action,
            'operationId' => (int)($commandResult['operationId'] ?? 0),
            'operationKey' => (string)($commandResult['operationKey'] ?? ''),
            'view' => (string)($workbenchRequest['view'] ?? 'tasks'),
            'failureClass' => get_class($failure),
            'failureMessage' => $failure->getMessage(),
            'failureFile' => basename($failure->getFile()),
            'failureLine' => $failure->getLine(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function success(string $message, ?array $commandResult, array $projection): array
    {
        $result = [
            'status' => 'success',
            'code' => '',
            'message' => $message,
        ];
        if ($commandResult !== null) {
            $result['commandReceipt'] = $commandResult;
        }
        return [
            'result' => $result,
            'projection' => ['customerCare' => $projection],
        ];
    }

    private function successMessage(string $action): string
    {
        $messages = [
            CustomerCareActionInputMapper::CREATE_TASK => '跟进任务已创建。',
            CustomerCareActionInputMapper::START_TASK => '任务已开始。',
            CustomerCareActionInputMapper::COMPLETE_TASK => '跟进结果已提交。',
            CustomerCareActionInputMapper::REASSIGN_TASK => '任务已转派。',
            CustomerCareActionInputMapper::VOID_TASK => '任务已作废。',
            CustomerCareActionInputMapper::DELETE_TASK => '未开始任务已删除，审计记录继续保留。',
            CustomerCareActionInputMapper::CREATE_RECORD => '客情记录已提交。',
            CustomerCareActionInputMapper::VOID_RECORD => '客情记录已作废。',
        ];
        return $messages[$action] ?? '客情操作已完成。';
    }

    private function defaultWorkbenchRequest(): array
    {
        return [
            'view' => 'tasks',
            'query' => ['scope' => 'my', 'bucket' => 'today'],
        ];
    }
}
