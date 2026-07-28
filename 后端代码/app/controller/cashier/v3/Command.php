<?php
namespace app\controller\cashier\v3;

use app\controller\cashier\AuthController;
use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
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
            return $this->respond([
                'result' => [
                    'status' => $exception->getResultStatus(),
                    'code' => $exception->getResultCode(),
                    'message' => $exception->getMessage(),
                ],
                'conflict' => $exception->getResultStatus() === CashierV3ResultCode::STATUS_CONFLICT
                    ? $exception->getDetail()
                    : null,
            ]);
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
