<?php

namespace app\services\cashier\v3\projection;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;

/**
 * Transitional root projection for domains that have no production reader yet.
 *
 * The payload is an explicit capability state, not an assertion that the
 * corresponding business tables contain no records. Real domain providers
 * replace these entries before this module is installed.
 */
final class CashierV3StructuredEmptyRootPartitionProvider implements CashierV3RootPartitionProvider
{
    public const CONTRACT_VERSION = 'cashier-v3-structured-empty-root-partition-v1';

    private const STATUS_NOT_ACTIVATED = 'not_activated';
    private const STATUS_ON_DEMAND = 'on_demand';

    private const DEFINITIONS = [
        'serviceCompletion' => [self::STATUS_NOT_ACTIVATED, 'SERVICE_COMPLETION_NOT_ACTIVATED'],
        'writeoff' => [self::STATUS_NOT_ACTIVATED, 'WRITEOFF_NOT_ACTIVATED'],
        'room' => [self::STATUS_NOT_ACTIVATED, 'ROOM_NOT_ACTIVATED'],
        'reservation' => [self::STATUS_NOT_ACTIVATED, 'RESERVATION_NOT_ACTIVATED'],
        'memberCenter' => [self::STATUS_ON_DEMAND, 'MEMBER_CENTER_LOAD_ON_DEMAND'],
        'memberSelector' => [self::STATUS_ON_DEMAND, 'MEMBER_SELECTOR_LOAD_ON_DEMAND'],
        'queryEntitySelector' => [self::STATUS_NOT_ACTIVATED, 'ENTITY_SELECTOR_NOT_ACTIVATED'],
        'managementCenter' => [self::STATUS_NOT_ACTIVATED, 'MANAGEMENT_CENTER_NOT_ACTIVATED'],
        'businessDashboard' => [self::STATUS_NOT_ACTIVATED, 'BUSINESS_DASHBOARD_NOT_ACTIVATED'],
        'hangOrders' => [self::STATUS_NOT_ACTIVATED, 'HANG_ORDER_QUERY_NOT_ACTIVATED'],
        'pendingHangCount' => [self::STATUS_NOT_ACTIVATED, 'HANG_ORDER_COUNT_NOT_ACTIVATED'],
    ];

    /** @var string */
    private $key;

    public function __construct(string $key)
    {
        if (!isset(self::DEFINITIONS[$key])) {
            throw new \InvalidArgumentException('unsupported structured empty root partition: ' . $key);
        }
        $this->key = $key;
    }

    /** @return string[] */
    public static function partitionKeys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public function partitionKey(): string
    {
        return $this->key;
    }

    public function readPartition(
        string $stateContextId,
        string $stateRevision,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        array $hints = []
    ): array {
        return [
            'ready' => true,
            'payload' => self::payloadFor($this->key),
            'public_versions' => [],
            'availability' => self::availabilityFor($this->key),
        ];
    }

    /** @return array<string,mixed> */
    public static function availabilityFor(string $key): array
    {
        if (!isset(self::DEFINITIONS[$key])) {
            throw new \InvalidArgumentException('unsupported structured empty root partition: ' . $key);
        }
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'status' => self::DEFINITIONS[$key][0],
            'reasonCode' => self::DEFINITIONS[$key][1],
            'dataLoaded' => false,
            'businessFactsIncluded' => false,
        ];
    }

    /** @return array|int */
    public static function payloadFor(string $key)
    {
        $availability = self::availabilityFor($key);
        $emptyPage = static function (): array {
            return [
                'records' => [],
                'total' => 0,
                'page' => 1,
                'pageSize' => 20,
                'isLoading' => false,
            ];
        };

        switch ($key) {
            case 'serviceCompletion':
                return [
                    'availability' => $availability,
                    'serviceOrder' => null,
                    'lines' => [],
                    'commandContexts' => [],
                ];
            case 'writeoff':
                return [
                    'availability' => $availability,
                    'member' => null,
                    'sources' => [],
                    'summary' => [
                        'selectedSourceCount' => 0,
                        'selectedProjectTypeCount' => 0,
                        'selectedTimes' => 0,
                        'writeoffAmount' => 0,
                    ],
                    'activeServiceSession' => null,
                    'pendingSubmittedRequest' => null,
                    'confirmation' => null,
                    'supplement' => null,
                ];
            case 'room':
                return [
                    'availability' => $availability,
                    'refreshedAt' => null,
                    'staleMessage' => '',
                    'pendingAssignmentCount' => 0,
                    'pendingAssignments' => [],
                    'unassignedList' => [
                        'records' => [],
                        'total' => 0,
                        'page' => 1,
                        'pageSize' => 20,
                        'refreshedAt' => '',
                        'staleMessage' => '',
                    ],
                    'categories' => [],
                    'detail' => null,
                    'assignment' => null,
                ];
            case 'reservation':
                return [
                    'availability' => $availability,
                    'quickCounts' => new \stdClass(),
                    'records' => [],
                    'total' => 0,
                    'page' => 1,
                    'pageSize' => 20,
                    'detail' => null,
                    'editor' => [
                        'draft' => new \stdClass(),
                        'catalogOptions' => [],
                        'craftsmenOptions' => [],
                        'rooms' => [],
                    ],
                    'calendar' => [
                        'date' => null,
                        'resources' => [],
                        'blocks' => [],
                    ],
                ];
            case 'memberCenter':
                return [
                    'availability' => $availability,
                    'canBatchOperate' => false,
                    'statusOptions' => [],
                    'records' => [],
                    'total' => 0,
                    'page' => 1,
                    'pageSize' => 20,
                    'detail' => null,
                    'debtSnapshot' => null,
                ];
            case 'memberSelector':
                return array_merge(['availability' => $availability], $emptyPage());
            case 'queryEntitySelector':
                return [
                    'availability' => $availability,
                    'person' => $emptyPage(),
                    'store' => $emptyPage(),
                    'organization' => $emptyPage(),
                ];
            case 'managementCenter':
                return [
                    'availability' => $availability,
                    'entries' => [],
                ];
            case 'businessDashboard':
                return [
                    'availability' => $availability,
                    'mode' => 'store',
                    'scope' => [
                        'dateRange' => ['start' => '', 'end' => ''],
                        'organization' => null,
                        'store' => null,
                        'forcedRangeLabel' => '',
                    ],
                    'cards' => [],
                    'selectedMetricCode' => 'cash_performance',
                    'trend' => [
                        'metricCode' => 'cash_performance',
                        'points' => [],
                        'isLoading' => false,
                    ],
                    'ranking' => [
                        'dimension' => 'staff',
                        'sortBy' => 'cash_performance',
                        'sortOrder' => 'desc',
                        'sortOptions' => [],
                        'columns' => [],
                        'records' => [],
                        'isLoading' => false,
                    ],
                    'metricVersion' => '',
                    'dataAsOf' => '',
                    'aggregationCaughtUp' => null,
                    'coverageStart' => '',
                ];
            case 'hangOrders':
                return [
                    'availability' => $availability,
                    'pendingCountAuthoritative' => false,
                    'statusOptions' => [],
                    'records' => [],
                    'total' => 0,
                    'page' => 1,
                    'pageSize' => 20,
                ];
            case 'pendingHangCount':
                // Required by the current cross-client root schema. The paired
                // hangOrders availability explicitly marks this as a UI fallback,
                // not an authoritative statement that no pending hangs exist.
                return 0;
        }

        throw new \LogicException('structured empty root partition payload missing: ' . $key);
    }
}
