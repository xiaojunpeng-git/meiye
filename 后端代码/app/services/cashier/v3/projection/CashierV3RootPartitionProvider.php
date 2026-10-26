<?php
namespace app\services\cashier\v3\projection;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;

/**
 * 根投影业务分区权威提供者。
 *
 * C2～C5 在 freeze 前通过 Composition Root installer 注册；
 * 未注册的必需分区使完整根保持未就绪，不得用空壳冒充业务现场。
 */
interface CashierV3RootPartitionProvider
{
    /** 根 state 分区键，如 cashier／room／hangOrders */
    public function partitionKey(): string;

    /**
     * 在已锁定 state_context 的同一数据库快照内读取权威分区。
     *
     * @return array{ready:bool,payload:?array,public_versions?:array}
     *   ready=false 时不得把空对象当作权威分区写入完整根。
     */
    public function readPartition(
        string $stateContextId,
        string $stateRevision,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        array $hints = []
    ): array;
}
