<?php
declare(strict_types=1);

namespace app\services\cashier\v3 {
    final class CashierV3TransactionGuard
    {
        /** @var array<int,string> */
        public static $calls = [];

        public static function assertInTransaction(string $operation): void
        {
            self::$calls[] = $operation;
        }
    }
}

namespace {
    $root = getenv('C3_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
    require_once $root . '/app/services/cashier/v3/checkout/persistence/CashierV3EntitlementCompletionOccupationWriter.php';
    require_once $root . '/app/services/cashier/v3/service/CashierV3ServiceOrderAuthorityException.php';
    require_once $root . '/app/services/cashier/v3/service/CashierV3ServiceOrderState.php';
    require_once $root . '/app/services/cashier/v3/service/CashierV3ServiceOrderRepository.php';
    require_once $root . '/app/services/cashier/v3/service/ThinkPhpCashierV3EntitlementCompletionOccupationWriter.php';

    use app\services\cashier\v3\CashierV3TransactionGuard;
    use app\services\cashier\v3\service\CashierV3ServiceOrderAuthorityException;
    use app\services\cashier\v3\service\CashierV3ServiceOrderRepository;
    use app\services\cashier\v3\service\ThinkPhpCashierV3EntitlementCompletionOccupationWriter;

    final class CompletionOccupationMemoryRepository implements CashierV3ServiceOrderRepository
    {
        /** @var array<int,array> */
        public $orders;

        /** @var array<int,array> */
        public $lines;

        /** @var array<int,array> */
        public $guards = [];

        /** @var array<string,array> */
        public $operations = [];

        /** @var array<int,string> */
        public $calls = [];

        /** @var int */
        public $transactionCalls = 0;

        /** @var string */
        public $failCas = '';

        public function __construct(array $order, array $lines)
        {
            $this->orders = [(int)$order['id'] => $order];
            $this->lines = [];
            foreach ($lines as $line) {
                $this->lines[(int)$line['id']] = $line;
            }
            ksort($this->lines, SORT_NUMERIC);
        }

        public function transaction(callable $callback)
        {
            $this->transactionCalls++;
            return $callback();
        }

        public function lockOrCreateEntitlementGuard(string $tenantId, int $detailId): array
        {
            $this->calls[] = 'guard:' . $detailId;
            if (!isset($this->guards[$detailId])) {
                $this->guards[$detailId] = [
                    'tenant_id' => $tenantId,
                    'entitlement_source_detail_id' => $detailId,
                    'current_version' => 1,
                ];
            }
            return $this->guards[$detailId];
        }

        public function lockOccupationSet(string $tenantId, int $detailId, int $includeOrderId = 0): array
        {
            $this->calls[] = 'set:' . $detailId;
            return [
                'orders' => array_values($this->orders),
                'lines' => array_values($this->lines),
                'targetLines' => array_values(array_filter(
                    $this->lines,
                    static function (array $line) use ($detailId): bool {
                        return (int)$line['entitlement_source_detail_id'] === $detailId;
                    }
                )),
            ];
        }

        public function findOperation(string $tenantId, string $idempotencyKey): ?array
        {
            $this->calls[] = 'find-operation';
            return $this->operations[$idempotencyKey] ?? null;
        }

        public function insertServiceOrder(array $row): array
        {
            throw new \RuntimeException('not used');
        }

        public function insertLine(array $row): array
        {
            throw new \RuntimeException('not used');
        }

        public function updateServiceOrderCas(
            string $tenantId,
            int $serviceOrderId,
            int $expectedVersion,
            array $fields
        ): bool {
            $this->calls[] = 'order-cas:' . $serviceOrderId;
            if ($this->failCas === 'order'
                || !isset($this->orders[$serviceOrderId])
                || (int)$this->orders[$serviceOrderId]['version'] !== $expectedVersion) {
                return false;
            }
            $this->orders[$serviceOrderId] = array_merge($this->orders[$serviceOrderId], $fields);
            return true;
        }

        public function updateLineCas(
            string $tenantId,
            int $lineId,
            int $expectedVersion,
            array $fields
        ): bool {
            $this->calls[] = 'line-cas:' . $lineId;
            if ($this->failCas === 'line'
                || !isset($this->lines[$lineId])
                || (int)$this->lines[$lineId]['version'] !== $expectedVersion) {
                return false;
            }
            $this->lines[$lineId] = array_merge($this->lines[$lineId], $fields);
            return true;
        }

        public function bumpEntitlementGuardCas(
            string $tenantId,
            int $detailId,
            int $expectedVersion,
            string $action,
            int $now
        ): int {
            $this->calls[] = 'guard-cas:' . $detailId;
            if ($this->failCas === 'guard'
                || !isset($this->guards[$detailId])
                || (int)$this->guards[$detailId]['current_version'] !== $expectedVersion) {
                throw new CashierV3ServiceOrderAuthorityException('service_order_entitlement_guard_version_conflict');
            }
            $this->guards[$detailId]['current_version'] = $expectedVersion + 1;
            return $expectedVersion + 1;
        }

        public function insertOperation(array $row): array
        {
            $this->calls[] = 'insert-operation';
            $key = (string)$row['command_idempotency_key'];
            if (isset($this->operations[$key])) {
                throw new CashierV3ServiceOrderAuthorityException('service_order_operation_unique_conflict');
            }
            $row['id'] = count($this->operations) + 1;
            $this->operations[$key] = $row;
            return $row;
        }
    }

    $passed = 0;
    $failed = 0;

    function cowOk(string $name, bool $condition, string $detail = ''): void
    {
        global $passed, $failed;
        if ($condition) {
            $passed++;
            echo "PASS {$name}\n";
            return;
        }
        $failed++;
        echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n";
    }

    function cowReason(callable $callback): string
    {
        try {
            $callback();
        } catch (CashierV3ServiceOrderAuthorityException $exception) {
            return $exception->reason();
        } catch (\Throwable $exception) {
            return 'UNEXPECTED:' . get_class($exception) . ':' . $exception->getMessage();
        }
        return '';
    }

    function cowContext(string $sourceType, int $sourceId, string $idempotencyKey): array
    {
        return [
            'tenant_id' => 'tenant-1',
            'store_id' => 7,
            'member_id' => 1001,
            'operator_id' => 21,
            'operator_name_snapshot' => '操作员甲',
            'occurred_at' => 1785283200,
            'settled_at' => 1785283260,
            'recorded_at' => 1785283261,
            'checkout_request_id' => 'checkout-001',
            'command_idempotency_key' => $idempotencyKey,
            'source_type' => $sourceType,
            'service_order_id' => $sourceType === 'service_order' ? $sourceId : 0,
            'reservation_id' => $sourceType === 'reservation' ? $sourceId : 0,
        ];
    }

    function cowDeduction(int $detailId, int $deduct, int $convert, string $lineId): array
    {
        return [
            'source_key' => 'card_holder:2001:' . $detailId,
            'entitlement_instance_type' => 'card_holder',
            'entitlement_instance_id' => 2001,
            'source_kind' => 'count_card',
            'is_gift' => 0,
            'gift_source_type' => 'none',
            'gift_id' => 0,
            'gift_version' => 0,
            'holder_id' => 2001,
            'origin_order_id' => 5001,
            'source_detail_id' => $detailId,
            'project_id' => 9000 + $detailId,
            'source_version' => 81,
            'detail_version' => 131,
            'expected_physical_remaining_times' => 9,
            'deduct_physical_times' => $deduct,
            'convert_current_source_occupied_times' => $convert,
            'line_ids' => [$lineId],
        ];
    }

    function cowOrder(int $id, int $version = 10): array
    {
        return [
            'id' => $id,
            'tenant_id' => 'tenant-1',
            'business_store_id' => 7,
            'member_id' => 1001,
            'status' => 'PENDING_CHECKOUT',
            'version' => $version,
            'completed_at' => 0,
            'updated_at' => 1785283100,
        ];
    }

    function cowLine(int $id, int $orderId, int $detailId, int $times, int $version = 1, string $status = 'ACTIVE'): array
    {
        return [
            'id' => $id,
            'tenant_id' => 'tenant-1',
            'service_order_id' => $orderId,
            'entitlement_source_detail_id' => $detailId,
            'occupied_times' => $times,
            'status' => $status,
            'version' => $version,
            'updated_at' => 1785283100,
        ];
    }

    $emptyRepo = new CompletionOccupationMemoryRepository(cowOrder(77), [cowLine(1, 77, 501, 1)]);
    $emptyWriter = new ThinkPhpCashierV3EntitlementCompletionOccupationWriter($emptyRepo);
    $direct = $emptyWriter->convertInTx(cowContext('direct', 0, 'idem-direct'), []);
    cowOk('C3-COW-01 direct returns the exact zero-conversion result', $direct === [
        'contractVersion' => 'cashier-v3-entitlement-completion-occupation-writer-v1',
        'sourceType' => 'direct',
        'sourceId' => 0,
        'convertedTimes' => 0,
    ] && $emptyRepo->calls === []);
    cowOk('C3-COW-02 direct rejects any requested occupation conversion',
        cowReason(static function () use ($emptyWriter): void {
            $emptyWriter->convertInTx(
                cowContext('direct', 0, 'idem-direct-bad'),
                [cowDeduction(501, 1, 1, 'line-direct')]
            );
        }) === 'direct_occupation_conversion_forbidden');

    $reservationCalls = count($emptyRepo->calls);
    cowOk('C3-COW-03 legacy reservation conversion is explicitly fail-closed',
        cowReason(static function () use ($emptyWriter): void {
            $emptyWriter->convertInTx(
                cowContext('reservation', 901, 'idem-reservation'),
                [cowDeduction(501, 1, 1, 'line-reservation')]
            );
        }) === 'reservation_occupation_conversion_not_supported'
        && count($emptyRepo->calls) === $reservationCalls);

    $fullRepo = new CompletionOccupationMemoryRepository(cowOrder(88), [
        cowLine(10, 88, 501, 1, 2),
        cowLine(11, 88, 502, 2, 3),
        cowLine(12, 88, 700, 0, 4, 'RELEASED'),
    ]);
    $fullWriter = new ThinkPhpCashierV3EntitlementCompletionOccupationWriter($fullRepo);
    $fullContext = cowContext('service_order', 88, 'idem-service-full');
    $fullDeductions = [
        cowDeduction(502, 2, 2, 'cart-line-502'),
        cowDeduction(501, 1, 1, 'cart-line-501'),
    ];
    $fullResult = $fullWriter->convertInTx($fullContext, $fullDeductions);
    cowOk('C3-COW-04 guards and complete sets lock in stable detail order',
        array_slice($fullRepo->calls, 0, 5) === [
            'guard:501', 'guard:502', 'find-operation', 'set:501', 'set:502',
        ], json_encode($fullRepo->calls));
    cowOk('C3-COW-05 exact requested quantities release each active line',
        $fullResult['convertedTimes'] === 3
        && $fullRepo->lines[10]['occupied_times'] === 0
        && $fullRepo->lines[10]['status'] === 'RELEASED'
        && $fullRepo->lines[11]['occupied_times'] === 0
        && $fullRepo->lines[11]['status'] === 'RELEASED'
        && $fullRepo->lines[12]['version'] === 4);
    cowOk('C3-COW-06 all active lines released is the only completion transition',
        $fullRepo->orders[88]['status'] === 'COMPLETED'
        && $fullRepo->orders[88]['version'] === 11
        && $fullRepo->orders[88]['completed_at'] === 1785283260);
    cowOk('C3-COW-07 line order and every changed guard use CAS',
        in_array('line-cas:10', $fullRepo->calls, true)
        && in_array('line-cas:11', $fullRepo->calls, true)
        && $fullRepo->guards[501]['current_version'] === 2
        && $fullRepo->guards[502]['current_version'] === 2);
    cowOk('C3-COW-08 one append-only operation freezes the exact result',
        count($fullRepo->operations) === 1
        && array_values($fullRepo->operations)[0]['operation_type'] === 'entitlement_completion'
        && json_decode(array_values($fullRepo->operations)[0]['result_json'], true)['publicResult'] === $fullResult);

    $lineVersions = array_column($fullRepo->lines, 'version', 'id');
    $orderVersion = $fullRepo->orders[88]['version'];
    $guardVersions = array_column($fullRepo->guards, 'current_version', 'entitlement_source_detail_id');
    $operationCount = count($fullRepo->operations);
    $replayed = $fullWriter->convertInTx($fullContext, $fullDeductions);
    cowOk('C3-COW-09 same idempotency operation replays without a second release',
        $replayed === $fullResult
        && array_column($fullRepo->lines, 'version', 'id') === $lineVersions
        && $fullRepo->orders[88]['version'] === $orderVersion
        && array_column($fullRepo->guards, 'current_version', 'entitlement_source_detail_id') === $guardVersions
        && count($fullRepo->operations) === $operationCount);
    $changed = $fullDeductions;
    $changed[0]['line_ids'] = ['changed-cart-line'];
    cowOk('C3-COW-10 reused idempotency key with changed payload is rejected before order mutation',
        cowReason(static function () use ($fullWriter, $fullContext, $changed): void {
            $fullWriter->convertInTx($fullContext, $changed);
        }) === 'completion_occupation_idempotency_conflict'
        && $fullRepo->orders[88]['version'] === $orderVersion);

    $partialRepo = new CompletionOccupationMemoryRepository(cowOrder(99, 4), [
        cowLine(20, 99, 501, 3, 6),
        cowLine(21, 99, 900, 1, 2),
    ]);
    $partialWriter = new ThinkPhpCashierV3EntitlementCompletionOccupationWriter($partialRepo);
    $partial = $partialWriter->convertInTx(
        cowContext('service_order', 99, 'idem-service-partial'),
        [cowDeduction(501, 2, 2, 'cart-line-partial')]
    );
    cowOk('C3-COW-11 partial conversion preserves remaining occupation and active order status',
        $partial['convertedTimes'] === 2
        && $partialRepo->lines[20]['occupied_times'] === 1
        && $partialRepo->lines[20]['status'] === 'ACTIVE'
        && $partialRepo->orders[99]['status'] === 'PENDING_CHECKOUT'
        && $partialRepo->orders[99]['version'] === 5
        && $partialRepo->orders[99]['completed_at'] === 0);

    $casRepo = new CompletionOccupationMemoryRepository(cowOrder(111), [cowLine(31, 111, 501, 1)]);
    $casRepo->failCas = 'line';
    $casWriter = new ThinkPhpCashierV3EntitlementCompletionOccupationWriter($casRepo);
    cowOk('C3-COW-12 line version conflict fails the caller transaction',
        cowReason(static function () use ($casWriter): void {
            $casWriter->convertInTx(
                cowContext('service_order', 111, 'idem-cas-line'),
                [cowDeduction(501, 1, 1, 'cart-line-cas')]
            );
        }) === 'service_order_line_version_conflict');

    $source = (string)file_get_contents(
        $root . '/app/services/cashier/v3/service/ThinkPhpCashierV3EntitlementCompletionOccupationWriter.php'
    );
    $repositorySource = (string)file_get_contents(
        $root . '/app/services/cashier/v3/service/ThinkPhpCashierV3ServiceOrderRepository.php'
    );
    cowOk('C3-COW-13 adapter requires an outer transaction and never owns transaction lifecycle',
        count(CashierV3TransactionGuard::$calls) >= 1
        && strpos($source, "assertInTransaction('serviceOrderEntitlementCompletion.convertInTx')") !== false
        && strpos($source, 'Db::transaction(') === false
        && strpos($source, 'startTrans(') === false
        && strpos($source, '->commit(') === false
        && strpos($source, '->rollback(') === false
        && $fullRepo->transactionCalls === 0
        && $partialRepo->transactionCalls === 0);
    cowOk('C3-COW-14 adapter never calls the legacy reservation service',
        strpos($source, 'StoreReservationOrderServices') === false
        && strpos($source, 'MerchantReservationServices') === false
        && strpos($source, 'Db::name(\'store_reservation_order\')') === false);
    cowOk('C3-COW-15 production repository permits the audited completion timestamp CAS',
        strpos($repositorySource, "'service_started_at', 'pending_checkout_at', 'completed_at'") !== false);
    cowOk('C3-COW-16 occupation writer never performs the entitlement deduction itself',
        strpos($source, 'user_card_holder') === false
        && strpos($source, 'store_order_cart_info') === false
        && strpos($source, 'cashier_v3_entitlement_writeoff_fact') === false);

    echo "C3_COMPLETION_OCCUPATION_WRITER_CONTRACT passed={$passed} failed={$failed}\n";
    exit($failed === 0 ? 0 : 1);
}
