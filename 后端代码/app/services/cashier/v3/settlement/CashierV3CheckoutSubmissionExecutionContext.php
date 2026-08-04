<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;

/**
 * Per-command server authority carried through the submission orchestrator.
 *
 * The execution object is created after Gateway has acquired every lock. It is
 * never stored on a shared service, serialized, or returned to a client.
 */
final class CashierV3CheckoutSubmissionExecutionContext
{
    public const CONTRACT_VERSION = 'cashier-v3-checkout-submission-execution-context-v1';

    /** @var array */
    private $aggregate;

    /** @var CashierV3OperatorScope */
    private $operatorScope;

    /** @var CashierV3DataScopeContext */
    private $dataScope;

    /** @var CashierV3BusinessEventRecorder */
    private $eventRecorder;

    /** @var CashierV3BusinessEventExecution */
    private $eventExecution;

    /** @var array */
    private $eventContract;

    /** @var array|null */
    private $entitlementBundle;

    public function __construct(
        array $aggregate,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        CashierV3BusinessEventRecorder $eventRecorder,
        CashierV3BusinessEventExecution $eventExecution,
        array $eventContract,
        ?array $entitlementBundle
    ) {
        foreach (['request', 'lines', 'payments', 'sources', 'currentRequest', 'verifiedSources'] as $key) {
            if (!array_key_exists($key, $aggregate)) {
                throw new \InvalidArgumentException('checkout_execution_aggregate_incomplete:' . $key);
            }
        }
        if ($eventExecution->action() !== 'submit-checkout'
            || $eventExecution->operatorScope() !== $operatorScope
            || $eventExecution->dataScope() !== $dataScope
            || !$eventContract) {
            throw new \InvalidArgumentException('checkout_execution_event_context_invalid');
        }
        $this->aggregate = $aggregate;
        $this->operatorScope = $operatorScope;
        $this->dataScope = $dataScope;
        $this->eventRecorder = $eventRecorder;
        $this->eventExecution = $eventExecution;
        $this->eventContract = $eventContract;
        $this->entitlementBundle = $entitlementBundle;
    }

    public function aggregate(): array
    {
        return $this->aggregate;
    }

    public function operatorScope(): CashierV3OperatorScope
    {
        return $this->operatorScope;
    }

    public function dataScope(): CashierV3DataScopeContext
    {
        return $this->dataScope;
    }

    public function eventRecorder(): CashierV3BusinessEventRecorder
    {
        return $this->eventRecorder;
    }

    public function eventExecution(): CashierV3BusinessEventExecution
    {
        return $this->eventExecution;
    }

    public function eventContract(): array
    {
        return $this->eventContract;
    }

    public function entitlementBundle(): array
    {
        if ($this->entitlementBundle === null) {
            throw new \LogicException('checkout_execution_entitlement_bundle_missing');
        }
        return $this->entitlementBundle;
    }

    public function hasEntitlementBundle(): bool
    {
        return $this->entitlementBundle !== null;
    }

    public function assertMatchesAuthority(array $authority): void
    {
        $request = is_array($this->aggregate['request'] ?? null)
            ? $this->aggregate['request']
            : [];
        $matches = (string)($request['request_id'] ?? '')
                === (string)($authority['checkoutRequestId'] ?? '')
            && (int)($request['request_version'] ?? 0)
                === (int)($authority['checkoutRequestVersion'] ?? 0)
            && (string)($request['workspace_id'] ?? '')
                === (string)($authority['workspaceId'] ?? '')
            && (string)($request['state_context_id'] ?? '')
                === (string)($authority['stateContextId'] ?? '')
            && (string)($request['tenant_id'] ?? '')
                === (string)($authority['tenantId'] ?? '')
            && (int)($request['store_id'] ?? 0)
                === (int)($authority['storeId'] ?? 0)
            && (int)($request['member_id'] ?? 0)
                === (int)($authority['memberId'] ?? -1)
            && (string)($request['composition'] ?? '')
                === (string)($authority['composition'] ?? '')
            && $this->operatorScope->tenantId() === (string)($authority['tenantId'] ?? '')
            && $this->operatorScope->storeId() === (int)($authority['storeId'] ?? 0)
            && $this->dataScope->tenantId() === (string)($authority['tenantId'] ?? '')
            && $this->dataScope->forcedStoreId() === (int)($authority['storeId'] ?? 0)
            && $this->eventExecution->idempotencyKey()
                === (string)($authority['commandIdempotencyKey'] ?? '')
            && $this->eventExecution->stateContextId()
                === (string)($authority['stateContextId'] ?? '');
        if (!$matches) {
            throw new \LogicException('checkout_execution_authority_mismatch');
        }
        $requiresEntitlement = in_array(
            (string)($authority['composition'] ?? ''),
            ['entitlement_only', 'mixed'],
            true
        );
        if ($requiresEntitlement !== $this->hasEntitlementBundle()) {
            throw new \LogicException('checkout_execution_composition_mismatch');
        }
    }
}
