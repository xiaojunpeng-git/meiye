<?php
namespace app\controller\cashier\v3;

use app\controller\cashier\AuthController;
use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3CommandFailureEnvelopeServices;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\query\UnifiedQueryModule;
use app\services\query\UnifiedQueryException;
use think\facade\App;

/**
 * 收银 V3 命令网关入口。
 *
 * 控制器只从唯一生产 Composition Root 取得 dispatcher，
 * 禁止可选类型注入让 ThinkPHP 自动拼出第二套 Dispatcher。
 */
class Command extends AuthController
{
    /** @var CashierV3ActionDispatcher */
    protected $dispatcher;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->dispatcher = CashierV3Bootstrap::dispatcher();
    }

    /**
     * POST /cashierapi/v3/workbenches/actions
     */
    public function dispatchAction()
    {
        $body = $this->request->post();
        $body = is_array($body) ? $body : [];

        try {
            return $this->respond($this->dispatcher->dispatch($body, $this->sessionContext($body)));
        } catch (CashierV3CommandException $exception) {
            return $this->respond((new CashierV3CommandFailureEnvelopeServices())
                ->fromException($body, $exception));
        }
    }

    /**
     * GET /cashierapi/v3/unified-query/exports/:taskNo/download
     */
    public function downloadUnifiedQueryExport(string $taskNo)
    {
        try {
            $runtime = UnifiedQueryModule::runtime();
            $operatorScope = $this->dispatcher->scopeResolver()->operatorScope(
                (int)$this->storeId,
                (int)$this->cashierId
            );
            $dataScope = $this->dispatcher->dataScopeFactory()->build(
                (int)$this->storeId,
                (int)$this->cashierId,
                is_array($this->cashierInfo) ? $this->cashierInfo : [],
                $operatorScope->tenantId(),
                $operatorScope->organizationId()
            );
            $context = $runtime['contextFactory']->make($operatorScope, $dataScope);
            $descriptor = $runtime['exports']->resolveDownloadDescriptor($context, $taskNo);
            $path = $runtime['exportStorage']->absolutePath(
                (string)$descriptor['storageKey']
            );
            if (!is_file($path) || !is_readable($path)) {
                return app('json')->fail('导出文件不存在或已过期。');
            }
            return download($path, (string)$descriptor['fileName'])
                ->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        } catch (UnifiedQueryException $exception) {
            return app('json')->fail($exception->getMessage(), [
                'code' => $exception->getErrorCode(),
            ]);
        } catch (\Throwable $exception) {
            return app('json')->fail('导出文件暂时无法下载，请稍后重试。');
        }
    }

    protected function sessionContext(array $body): array
    {
        return [
            'store_id' => (int)$this->storeId,
            'operator_id' => (int)$this->cashierId,
            'operator_profile' => is_array($this->cashierInfo) ? $this->cashierInfo : [],
            'client_session_id' => (string)($body['clientSessionId'] ?? ''),
            'state_context_id' => (string)($body['stateContextId'] ?? ''),
            'operator_ip' => (string)$this->request->ip(),
        ];
    }

    protected function respond(array $envelope)
    {
        return $this->success('ok', $envelope);
    }
}
