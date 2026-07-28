<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\cashier\v3;

use think\facade\Db;

/**
 * 工作台投影上下文与投影游标。
 *
 * 冻结口径：
 * - 根上下文 = 当前登录账号 + 后端强制门店 + 当前浏览器工作台会话；
 * - stateContextId 对前端不透明，由服务端随机签发并持久化，不是可反推的哈希；
 * - 同一 stateContextId 内 stateRevision 从 1 开始严格递增；
 * - 不同 stateContextId 之间禁止比较：账号、强制门店或标签页变化时会得到新的
 *   stateContextId，前端先清空旧根状态再接受新上下文的首份投影；
 * - stateRevision 只防止旧完整页面投影晚到覆盖新页面，不承担业务并发控制；
 * - 异步聚合不主动推动所有工作台版本。
 *
 * 不使用任何跨请求 static 缓存。
 */
class CashierV3StateContextServices
{
    public const TABLE = 'cashier_v3_state_context';

    /** @var CashierV3IdempotencyKeyServices */
    protected $keyServices;

    public function __construct(CashierV3IdempotencyKeyServices $keyServices)
    {
        $this->keyServices = $keyServices;
    }

    /**
     * 解析（必要时创建）当前工作台的投影上下文。
     *
     * @return array{state_context_id:string,current_revision:int,context_changed:bool}
     */
    public function resolve(int $storeId, int $operatorId, string $rawClientSessionId, string $clientStateContextId = ''): array
    {
        if ($storeId <= 0 || $operatorId <= 0) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                '当前登录门店或账号无效，请重新登录后重试。'
            );
        }
        $clientSessionId = $this->keyServices->normalizeClientSessionId($rawClientSessionId);

        $row = $this->findByIdentity($storeId, $operatorId, $clientSessionId);
        if (!$row) {
            $row = $this->createIdentity($storeId, $operatorId, $clientSessionId);
        }

        $stateContextId = (string)$row['state_context_id'];
        $providedContextId = trim($clientStateContextId);

        return [
            'state_context_id' => $stateContextId,
            'current_revision' => (int)$row['current_revision'],
            // 换账号／换强制门店／换标签页时前端会带着旧上下文来；这不是错误，
            // 由前端按 contextChanged 清空旧根状态再接受首份投影。
            'context_changed' => $providedContextId !== '' && $providedContextId !== $stateContextId,
        ];
    }

    /**
     * 推进并返回下一个投影序号。**必须在调用方已开启的事务内执行**。
     *
     * 用 SELECT ... FOR UPDATE + UPDATE，而不是 LAST_INSERT_ID()：
     * Swoole 连接池下无法保证「写」和「读 LAST_INSERT_ID」落在同一条连接上。
     */
    public function nextRevisionInTx(string $stateContextId): int
    {
        // 事务外执行 FOR UPDATE 只会「加锁即释放」，两个并发请求会拿到同一个序号
        CashierV3TransactionGuard::assertInTransaction('nextRevisionInTx');

        $row = Db::name(self::TABLE)
            ->where('state_context_id', $stateContextId)
            ->lock(true)
            ->find();
        if (!$row) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                '当前工作台会话已失效，请刷新页面后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['state_context_id' => $stateContextId]
            );
        }
        $next = (int)$row['current_revision'] + 1;
        $affected = Db::name(self::TABLE)
            ->where('id', (int)$row['id'])
            ->where('current_revision', (int)$row['current_revision'])
            ->update([
                'current_revision' => $next,
                'last_seen_time' => time(),
            ]);
        if ((int)$affected !== 1) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                '当前工作台会话状态更新失败，请刷新页面后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['state_context_id' => $stateContextId]
            );
        }
        return $next;
    }

    /**
     * 只读投影（分页、详情、准备弹窗、看板筛选）需要替换完整根投影时使用。
     * 只推进本工作台的投影序号，不推进任何业务资源版本，也不产生命令与事实。
     *
     * 调用方必须**确实**要返回一份完整根 state；只递增数字而不带 state，
     * 会让前端把下一份真正的投影当成过期投影丢弃。
     */
    public function issueProjectionRevision(string $stateContextId): int
    {
        return Db::transaction(function () use ($stateContextId) {
            return $this->nextRevisionInTx($stateContextId);
        });
    }

    /**
     * 组装返回给前端的根投影信封字段。stateRevision 用字符串返回：
     * 前端 canonicalStateRevision 按十进制字符串比较，可越过 JS 安全整数上限。
     */
    public function stateEnvelope(string $stateContextId, int $stateRevision): array
    {
        return [
            'stateContextId' => $stateContextId,
            'stateRevision' => (string)$stateRevision,
        ];
    }

    /**
     * @return array|null
     */
    protected function findByIdentity(int $storeId, int $operatorId, string $clientSessionId)
    {
        return Db::name(self::TABLE)
            ->where('store_id', $storeId)
            ->where('operator_id', $operatorId)
            ->where('client_session_id', $clientSessionId)
            ->find();
    }

    protected function createIdentity(int $storeId, int $operatorId, string $clientSessionId): array
    {
        $now = time();
        try {
            Db::name(self::TABLE)->insert([
                'state_context_id' => $this->generateOpaqueContextId(),
                'store_id' => $storeId,
                'operator_id' => $operatorId,
                'client_session_id' => $clientSessionId,
                'current_revision' => 0,
                'add_time' => $now,
                'last_seen_time' => $now,
            ]);
        } catch (\Throwable $exception) {
            // 同一标签页并发首个请求：唯一键拦下后按已存在读取
            $row = $this->findByIdentity($storeId, $operatorId, $clientSessionId);
            if (!$row) {
                throw $exception;
            }
            return $row;
        }
        $row = $this->findByIdentity($storeId, $operatorId, $clientSessionId);
        if (!$row) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                '当前工作台会话初始化失败，请刷新页面后重试。'
            );
        }
        return $row;
    }

    /**
     * 随机不透明标识：不携带门店、账号或会话信息，前端无法反推也无法伪造比较。
     *
     * 只允许安全随机源。原先在 random_bytes() 失败时回退到
     * md5(uniqid(mt_rand()))，那是可预测序列：攻击者能猜出别人的 stateContextId，
     * 进而伪造投影上下文。随机源不可用时必须 fail-closed。
     */
    protected function generateOpaqueContextId(): string
    {
        try {
            $random = random_bytes(16);
        } catch (\Throwable $exception) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::SECURE_RANDOM_UNAVAILABLE,
                '系统安全组件暂时不可用，请稍后重试或联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'random_bytes_unavailable']
            );
        }
        if (!is_string($random) || strlen($random) !== 16) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::SECURE_RANDOM_UNAVAILABLE,
                '系统安全组件暂时不可用，请稍后重试或联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'random_bytes_short']
            );
        }
        return 'SC-' . bin2hex($random);
    }
}
