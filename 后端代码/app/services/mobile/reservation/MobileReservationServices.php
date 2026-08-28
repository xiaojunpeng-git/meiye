<?php

declare(strict_types=1);

namespace app\services\mobile\reservation;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use think\facade\Db;

/**
 * Mobile boundary for the V3 reservation lifecycle.
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

    public function detail(array $merchant, array $payload): array
    {
        return $this->dispatch($merchant, 'open-reservation-detail', [
            'reservationId' => (int)($payload['reservationId'] ?? $payload['id'] ?? 0),
        ]);
    }

    public function projectCatalog(array $merchant, array $payload): array
    {
        return $this->dispatch($merchant, 'query-reservation-project-catalog', [
            'memberId' => (int)($payload['memberId'] ?? 0),
        ]);
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

    public function update(array $merchant, int $reservationId, array $payload): array
    {
        $command = is_array($payload['command'] ?? null) ? $payload['command'] : [];
        $command['action'] = 'update-reservation';
        return $this->dispatch($merchant, 'update-reservation', [
            'reservationId' => $reservationId,
            'reservation' => is_array($payload['reservation'] ?? null) ? $payload['reservation'] : [],
            'command' => $command,
        ]);
    }

    public function delete(array $merchant, int $reservationId, array $payload): array
    {
        return $this->dispatch($merchant, 'cancel-reservation', $this->actionPayload('cancel-reservation', $reservationId, $payload));
    }

    public function startService(array $merchant, int $reservationId, array $payload): array
    {
        return $this->dispatch($merchant, 'start-reservation-service', $this->actionPayload('start-reservation-service', $reservationId, $payload));
    }

    public function endService(array $merchant, int $reservationId, array $payload): array
    {
        return $this->dispatch($merchant, 'end-reservation-service', $this->actionPayload('end-reservation-service', $reservationId, $payload));
    }

    private function actionPayload(string $action, int $reservationId, array $payload): array
    {
        $command = is_array($payload['command'] ?? null) ? $payload['command'] : [];
        $command['action'] = $action;
        return [
            'reservationId' => $reservationId,
            'command' => $command,
        ];
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
        $operatorId = (int)($merchant['staffId'] ?? 0);
        if ($operatorId <= 0) {
            $operatorId = (int)($merchant['employeeId'] ?? 0);
        }
        $scope = $dispatcher->scopeResolver()->operatorScope((int)$merchant['storeId'], $operatorId);
        $versions = $dispatcher->versionServices();
        if ($versions === null) {
            throw new \LogicException('mobile_reservation_workspace_versions_missing');
        }
        $workspaceId = CashierV3CheckoutWorkspaceIdentity::id($scope->storeId(), $stateContextId);
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
        $staff = $staffId > 0
            ? Db::name('system_store_staff')->where('id', $staffId)->where('employee_id', $employeeId)
                ->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->find()
            : null;
        // 预约入口的授权主体是员工账号，而不是是否存在门店任职。
        // 组织直属员工（staff_id=0）在已选门店上下文中仍可使用预约；
        // 以员工 ID 作为 V3 操作人标识，避免伪造或借用其它门店员工。
        if (!is_array($staff) && $staffId <= 0 && $employeeId > 0) {
            $employee = Db::name('employee')->where('id', $employeeId)->where('status', 1)->where('is_del', 0)
                ->field('id,name')->find();
            if (is_array($employee)) {
                $staff = [
                    'id' => 0,
                    'employee_id' => $employeeId,
                    'store_id' => $storeId,
                    'roles' => '',
                    'level' => 1,
                    'staff_name' => (string)($employee['name'] ?? ''),
                ];
            }
        }
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
            // CashierV3OperatorScope requires a positive operator id. For an
            // organization-direct employee there is no store-staff row, so
            // the authenticated employee id is the stable operator identity.
            'operator_id' => $staffId > 0 ? $staffId : $employeeId,
            'operator_profile' => [
                'employee_id' => $employeeId,
                'roles' => array_values($roles),
                'level' => (int)($staff['level'] ?? 1),
                'admin_type' => 3,
                'account' => (string)($staff['account'] ?? ''),
                'staff_name' => (string)($staff['staff_name'] ?? ''),
                'role_name' => (string)($staff['staff_name'] ?? ''),
                // Organization-direct employees have no system_store_staff
                // row. Mark this trusted projection as delegated so the V3
                // permission snapshot validates the employee profile rather
                // than rejecting it as a store-staff mismatch.
                '_cashier_v3_delegated' => $staffId <= 0,
                '_mobile_merchant_write' => true,
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
