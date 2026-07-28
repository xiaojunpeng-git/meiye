<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\cashier\v3\registry;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;

/**
 * 领域处理器注册表（第三层）。
 *
 * 取代原先控制器里「只能靠继承覆盖」的 runDomainHandler()／handleProjection()：
 * 那种写法逼着 C2～C5 争抢同一个控制器文件，谁后改谁覆盖前一个人的实现。
 * 这里改成按 action 注册，四个子任务各自在自己的模块里调用 registerCommand／
 * registerProjection，互不冲突。
 *
 * 写命令处理器签名：
 *   function(array $scope): array{data:array,message?:string,business_no?:string,touched:string[]}
 *   $scope 含 action、contexts（已带 canonical scope）、locked_versions、payload、operator。
 *   payload 是网关规范化后的**同一份**，处理器不得重新从 Request 读业务参数。
 *
 * 只读投影处理器签名：
 *   function(array $scope): array{data:array,message?:string,state?:array,versions?:array}
 */
class CashierV3HandlerRegistry
{
    /** @var array<string,callable> 规范 action => 写命令处理器 */
    protected $commandHandlers = [];

    /** @var array<string,callable> 规范 action => 只读投影处理器 */
    protected $projectionHandlers = [];

    /** @var bool */
    protected $frozen = false;

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function registerCommand(string $canonicalAction, callable $handler): void
    {
        if ($this->frozen) {
            throw new \LogicException('handler registry 已 freeze');
        }
        $definition = CashierV3ActionManifest::requireAction($canonicalAction);
        if ($definition['type'] !== CashierV3ActionManifest::TYPE_COMMAND) {
            throw new \LogicException(sprintf('%s 在清单里是只读投影，不能注册写命令处理器', $canonicalAction));
        }
        if ($definition['canonical'] !== $canonicalAction) {
            throw new \LogicException(sprintf('处理器必须注册在规范 action 上，%s 是别名', $canonicalAction));
        }
        if (isset($this->commandHandlers[$canonicalAction])) {
            throw new \LogicException(sprintf('写命令处理器 %s 重复注册', $canonicalAction));
        }
        $this->commandHandlers[$canonicalAction] = $handler;
    }

    public function registerProjection(string $canonicalAction, callable $handler): void
    {
        if ($this->frozen) {
            throw new \LogicException('handler registry 已 freeze');
        }
        $definition = CashierV3ActionManifest::requireAction($canonicalAction);
        if ($definition['type'] !== CashierV3ActionManifest::TYPE_PROJECTION) {
            throw new \LogicException(sprintf('%s 在清单里是写命令，不能注册只读投影处理器', $canonicalAction));
        }
        if ($definition['canonical'] !== $canonicalAction) {
            throw new \LogicException(sprintf('处理器必须注册在规范 action 上，%s 是别名', $canonicalAction));
        }
        if (isset($this->projectionHandlers[$canonicalAction])) {
            throw new \LogicException(sprintf('投影处理器 %s 重复注册', $canonicalAction));
        }
        $this->projectionHandlers[$canonicalAction] = $handler;
    }

    public function hasCommand(string $canonicalAction): bool
    {
        return isset($this->commandHandlers[$canonicalAction]);
    }

    public function hasProjection(string $canonicalAction): bool
    {
        return isset($this->projectionHandlers[$canonicalAction]);
    }

    public function requireCommand(string $canonicalAction): callable
    {
        if (!isset($this->commandHandlers[$canonicalAction])) {
            throw self::notImplemented($canonicalAction, 'command_handler');
        }
        return $this->commandHandlers[$canonicalAction];
    }

    public function requireProjection(string $canonicalAction): callable
    {
        if (!isset($this->projectionHandlers[$canonicalAction])) {
            throw self::notImplemented($canonicalAction, 'projection_handler');
        }
        return $this->projectionHandlers[$canonicalAction];
    }

    /**
     * @return array{command:string[],projection:string[]}
     */
    public function registeredActions(): array
    {
        $command = array_keys($this->commandHandlers);
        $projection = array_keys($this->projectionHandlers);
        sort($command);
        sort($projection);
        return ['command' => $command, 'projection' => $projection];
    }

    /** @return string[] */
    public function duplicateCheck(): array
    {
        // 写入前已拒绝重复；此处恒为空，供 selfCheck 消费
        return [];
    }

    public static function notImplemented(string $action, string $missing): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::ACTION_NOT_IMPLEMENTED,
            '该操作尚未开放，请联系管理员。',
            CashierV3ResultCode::STATUS_FAILED,
            ['action' => $action, 'missing' => $missing]
        );
    }
}
