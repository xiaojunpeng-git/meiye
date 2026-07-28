<?php
namespace app\services\cashier\v3\projection;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3StateContextServices;
use think\facade\Db;

/**
 * 当前根投影重建器。
 *
 * 业务事务提交成功后调用；构建失败不得回滚已成功业务。
 * 流程：开事务 → 锁 state_context → 同一快照组装权威根与 versions →
 * 完整校验成功后才推进并嵌入 stateRevision → 提交。
 * 校验失败抛错回滚，不消耗 revision。
 */
class CashierV3RootProjector
{
    /** @var CashierV3StateContextServices */
    protected $stateContexts;

    /** @var CashierV3RootDomainAssembler|null */
    protected $assembler;

    /** @var bool */
    protected $frozen = false;

    public function __construct(CashierV3StateContextServices $stateContexts)
    {
        $this->stateContexts = $stateContexts;
    }

    public function setAssembler(CashierV3RootDomainAssembler $assembler): void
    {
        if ($this->frozen) {
            throw new \LogicException('CashierV3RootProjector 已 freeze，禁止更换 assembler');
        }
        $this->assembler = $assembler;
    }

    public function hasAssembler(): bool
    {
        return $this->assembler !== null;
    }

    /**
     * 完整根仅在全部必需分区 provider 已注册且可读时才就绪。
     * C2～C5 未安装前空壳 assembler 不得标记就绪。
     */
    public function isReadyForFullRoot(): bool
    {
        return $this->assembler !== null && $this->assembler->isReadyForFullRoot();
    }

    /** @return string[] 已注册分区键（active matrix 从真实注册导出） */
    public function assemblerPartitionKeys(): array
    {
        return $this->assembler ? $this->assembler->registeredPartitionKeys() : [];
    }

    public function freeze(): void
    {
        $this->frozen = true;
        if ($this->assembler !== null) {
            $this->assembler->freeze();
        }
    }

    /**
     * @return array{state:array,stateContextId:string,stateRevision:string,versions:array}|null
     *   null 表示重建失败／未就绪（调用方保留业务成功，requiresRefresh）
     */
    public function rebuild(
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        array $hints = []
    ) {
        if (!$this->isReadyForFullRoot()) {
            // 分区未齐：不得用空壳业务分区伪造完整根
            return null;
        }

        try {
            return Db::transaction(function () use ($stateContextId, $operatorScope, $dataScope, $hints) {
                // 先锁当前 state_context（不推进），读一致性快照所需数据
                $locked = $this->lockStateContext($stateContextId);
                $pendingRevision = (int)$locked['current_revision'] + 1;
                $revisionStr = (string)$pendingRevision;

                $assembled = $this->assembler->assemble(
                    $stateContextId,
                    $revisionStr,
                    $operatorScope,
                    $dataScope,
                    $hints
                );
                $publicVersions = [];
                if (isset($assembled['_public_versions']) && is_array($assembled['_public_versions'])) {
                    $publicVersions = $assembled['_public_versions'];
                    unset($assembled['_public_versions']);
                }

                $state = CashierV3RootStateContract::emptyRoot($stateContextId, $revisionStr, $assembled);
                $state = CashierV3RootStateContract::encodeReady($state);
                $problems = CashierV3RootStateContract::validate($state);
                if ($problems) {
                    // 校验失败：抛错回滚，不推进 revision
                    throw new \RuntimeException('root_state_invalid:' . implode(',', $problems));
                }

                // 校验成功后才推进 revision
                $issued = $this->stateContexts->nextRevisionInTx($stateContextId);
                if ((int)$issued !== $pendingRevision) {
                    throw new \RuntimeException('root_revision_race');
                }

                return [
                    'state' => $state,
                    'stateContextId' => $stateContextId,
                    'stateRevision' => (string)$issued,
                    'versions' => $publicVersions,
                ];
            });
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array
     */
    protected function lockStateContext(string $stateContextId): array
    {
        $row = Db::name(CashierV3StateContextServices::TABLE)
            ->where('state_context_id', $stateContextId)
            ->lock(true)
            ->find();
        if (!$row) {
            throw new \RuntimeException('state_context_missing');
        }
        return $row;
    }
}
