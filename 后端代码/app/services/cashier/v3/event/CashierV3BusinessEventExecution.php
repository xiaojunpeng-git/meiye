<?php

namespace app\services\cashier\v3\event;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;

/**
 * Per-command event collector. It is deliberately created inside the
 * Gateway transaction and never stored on a singleton service.
 */
final class CashierV3BusinessEventExecution
{
    private $action;
    private $idempotencyKey;
    private $operatorScope;
    private $dataScope;
    private $stateContextId;
    /** @var array<int,array> Complete immutable descriptors emitted in this transaction. */
    private $emittedEvents = [];
    private $closed = false;

    public function __construct(
        string $action,
        string $idempotencyKey,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        string $stateContextId
    ) {
        $this->action = $action;
        $this->idempotencyKey = $idempotencyKey;
        $this->operatorScope = $operatorScope;
        $this->dataScope = $dataScope;
        $this->stateContextId = $stateContextId;
    }

    public function action(): string { return $this->action; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
    public function operatorScope(): CashierV3OperatorScope { return $this->operatorScope; }
    public function dataScope(): CashierV3DataScopeContext { return $this->dataScope; }
    public function stateContextId(): string { return $this->stateContextId; }

    public function addEvent(array $descriptor): void
    {
        if ($this->closed) {
            throw new \LogicException('event execution 已关闭');
        }
        if ((int)($descriptor['event_id'] ?? 0) <= 0
            || trim((string)($descriptor['event_type'] ?? '')) === ''
            || trim((string)($descriptor['event_key'] ?? '')) === '') {
            throw new \InvalidArgumentException('invalid event identity');
        }
        $this->emittedEvents[] = $descriptor;
    }

    /** @return int[] */
    public function eventIds(): array
    {
        return array_values(array_map(function (array $event): int {
            return (int)$event['event_id'];
        }, $this->emittedEvents));
    }
    /** @return string[] */
    public function eventTypes(): array
    {
        return array_values(array_map(function (array $event): string {
            return (string)$event['event_type'];
        }, $this->emittedEvents));
    }
    /** @return array<int,array> */
    public function emittedEvents(): array { return array_values($this->emittedEvents); }

    public function close(): void
    {
        $this->closed = true;
        $this->emittedEvents = [];
    }
}
