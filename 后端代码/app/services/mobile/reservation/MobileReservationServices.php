<?php

declare(strict_types=1);

namespace app\services\mobile\reservation;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use think\facade\Db;

/**
 * Mobile boundary for the released C3 reservation slice.
 *
 * This adapter intentionally owns no reservation SQL. Reads and writes go
 * through the frozen Cashier V3 dispatcher; `cashier_v3_reservation` remains
 * the only reservation authority. Legacy reservation endpoints/tables never
 * appear here.
 */
final class MobileReservationServices
{
    public function list(array $merchant): array
    {
        return $this->dispatch($merchant, 'query-reservations', []);
    }

    public function openEditor(array $merchant): array
    {
        // Resolve the V3 state context first, then register its session-local
        // workspace resource. The workspace is a V3 technical version guard,
        // not a new mobile reservation source.
        $list = $this->dispatch($merchant, 'query-reservations', []);
        $stateContextId = trim((string)($list['stateContextId'] ?? ''));
        if ($stateContextId === '') {
            return $this->failed('COMMAND_RESULT_INCOMPLETE', '预约工作台初始化失败，请刷新后重试。');
        }
        $this->ensureWorkspace($merchant, $stateContextId);
        return $this->dispatch($merchant, 'open-reservation-editor', [
            'mode' => 'create',
            'preparationRequestId' => 'mobile-reservation-editor',
        ]);
    }

    public function recalculate(array $merchant, array $payload): array
    {
        return $this->dispatch($merchant, 'recalculate-reservation-plan', [
            'reservation' => is_array($payload['reservation'] ?? null) ? $payload['reservation'] : [],
            'recalculationRequestId' => trim((string)($payload['recalculationRequestId'] ?? '')),
        ]);
    }

    public function create(array $merchant, array $payload): array
    {
        $command = is_array($payload['command'] ?? null) ? $payload['command'] : [];
        $command['action'] = 'create-reservation';
        return $this->dispatch($merchant, 'create-reservation', [
            'reservation' => is_array($payload['reservation'] ?? null) ? $payload['reservation'] : [],
            'command' => $command,
        ]);
    }

    private function dispatch(array $merchant, string $action, array $payload): array
    {
        $dispatcher = CashierV3Bootstrap::dispatcher();
        $session = $this->v3Session($merchant);
        $body = array_merge($payload, [
            'action' => $action,
            'clientSessionId' => (string)$session['client_session_id'],
            'stateContextId' => '',
            'correlationId' => 'mobile-reservation-' . substr(hash('sha256', (string)($merchant['requestMetadata']['requestId'] ?? '') . ':' . $action), 0, 24),
            'returnCurrentState' => false,
        ]);
        try {
            return $this->mobileEnvelope($dispatcher->dispatch($body, $session));
        } catch (CashierV3CommandException $exception) {
            return $this->failed($exception->getResultCode(), $exception->getMessage(), $exception->getResultStatus());
        }
    }

    private function ensureWorkspace(array $merchant, string $stateContextId): void
    {
        $dispatcher = CashierV3Bootstrap::dispatcher();
        $scope = $dispatcher->scopeResolver()->operatorScope((int)$merchant['storeId'], (int)$merchant['staffId']);
        $versions = $dispatcher->versionServices();
        if ($versions === null) {
            throw new \LogicException('mobile_reservation_workspace_versions_missing');
        }
        $workspaceId = sprintf('ws:%d:%d:%s', $scope->storeId(), $scope->operatorId(), $stateContextId);
        Db::transaction(function () use ($versions, $scope, $workspaceId): void {
            $versions->ensureRegistered(
                CashierV3ResourceScope::of(CashierV3ResourceScope::TYPE_STORE, (string)$scope->storeId()),
                'cashier_workspace',
                $workspaceId
            );
        });
    }

    private function v3Session(array $merchant): array
    {
        $staffId = (int)($merchant['staffId'] ?? 0);
        $employeeId = (int)($merchant['employeeId'] ?? 0);
        $storeId = (int)($merchant['storeId'] ?? 0);
        $staff = Db::name('system_store_staff')->where('id', $staffId)->where('employee_id', $employeeId)
            ->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->find();
        if (!is_array($staff)) {
            throw new \LogicException('mobile_reservation_staff_context_missing');
        }
        $roles = [];
        foreach (explode(',', (string)($staff['roles'] ?? '')) as $role) {
            $id = (int)$role;
            if ($id > 0) $roles[$id] = $id;
        }
        $sessionId = trim((string)($merchant['session']['session_id'] ?? ''));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sessionId)) {
            throw new \LogicException('mobile_reservation_session_id_invalid');
        }
        return [
            'store_id' => $storeId,
            'operator_id' => $staffId,
            'operator_profile' => [
                'employee_id' => $employeeId,
                'roles' => array_values($roles),
                'level' => (int)($staff['level'] ?? 1),
                'admin_type' => 3,
                'account' => (string)($staff['account'] ?? ''),
                'role_name' => (string)($staff['staff_name'] ?? ''),
                '_trusted_mobile_merchant_session' => true,
            ],
            'client_session_id' => 'SESSION-' . strtolower($sessionId),
            'state_context_id' => '',
            'operator_ip' => 'mobile-merchant-v1',
        ];
    }

    private function failed(string $code, string $message, string $status = 'failed'): array
    {
        return [
            'result' => ['status' => $status, 'code' => $code, 'message' => $message],
            'data' => [], 'replay' => false, 'requiresRefresh' => false,
        ];
    }

    /**
     * The V3 dispatcher may rebuild the full desktop cashier root for its own
     * browser contract. Mobile reservation never needs that root and must not
     * receive unrelated cashier/member/order partitions as a side effect.
     */
    private function mobileEnvelope(array $envelope): array
    {
        $out = [];
        foreach ([
            'result', 'data', 'replay', 'stateContextId', 'contextChanged',
            'boundAction', 'boundCanonical', 'boundIdempotencyKey',
            'correlationId', 'boundCorrelationId', 'businessNo',
            'idempotencyKey', 'requiresRefresh'
        ] as $field) {
            if (array_key_exists($field, $envelope)) $out[$field] = $envelope[$field];
        }
        return $out;
    }
}
