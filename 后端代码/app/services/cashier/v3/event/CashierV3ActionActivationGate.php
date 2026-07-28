<?php

namespace app\services\cashier\v3\event;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;

/** Runtime and bootstrap gate for production command event contracts. */
final class CashierV3ActionActivationGate
{
    /** @var CashierV3EventConsumerRegistry */
    private $consumerRegistry;

    public function __construct(CashierV3EventConsumerRegistry $consumerRegistry)
    {
        $this->consumerRegistry = $consumerRegistry;
    }

    public function consumerRegistry(): CashierV3EventConsumerRegistry
    {
        return $this->consumerRegistry;
    }

    /**
     * @return array{contract:array,problems:string[]}
     */
    public function inspect(array $definition, string $action): array
    {
        $contract = CashierV3BusinessEventContractRegistry::normalize($definition, $action);
        $problems = [];
        if (!$this->consumerRegistry->isFrozen()) {
            $problems[] = 'event_consumer_registry_not_frozen';
        }
        if (!empty($contract['activation_blocked_until_event_contract'])) {
            $problems[] = 'production_event_contract';
        }
        foreach ($this->consumerRegistry->missingForContract($contract) as $consumerCode) {
            $problems[] = 'event_consumer:' . $consumerCode;
        }
        return [
            'contract' => $contract,
            'problems' => array_values(array_unique($problems)),
        ];
    }

    /**
     * @return array normalized event contract
     */
    public function assertExecutable(array $definition, string $action): array
    {
        $inspection = $this->inspect($definition, $action);
        if ($inspection['problems']) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_NOT_IMPLEMENTED,
                '该操作尚未开放，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $action, 'missing' => $inspection['problems']]
            );
        }
        return $inspection['contract'];
    }
}
