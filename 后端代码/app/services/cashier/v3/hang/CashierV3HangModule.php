<?php

namespace app\services\cashier\v3\hang;

use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\projection\CashierV3RootDomainAssembler;
use app\services\cashier\v3\registry\CashierV3ContextPolicy;
use app\services\cashier\v3\room\CashierV3RoomPartitionProvider;
use app\services\room\guard\RoomOpenServiceGuardAuthority;
use app\services\room\guard\RoomOpenServiceGuardVersionProvider;

final class CashierV3HangModule
{
    public static function install(
        CashierV3ActionDispatcher $dispatcher,
        CashierV3CashierWorkspaceServices $workspace,
        CashierV3RootDomainAssembler $assembler,
        ?CashierV3HangPreparationProvider $provider = null
    ): void {
        $hangVersions = new CashierV3HangOrderVersionProvider();
        $registeredProviders = $dispatcher->versionServices()->registeredProviders();
        if (isset($registeredProviders[CashierV3HangOrderVersionProvider::KIND])) {
            if (!$registeredProviders[CashierV3HangOrderVersionProvider::KIND]
                instanceof CashierV3HangOrderVersionProvider) {
                throw new \LogicException('C3 hang version provider conflict');
            }
            $hangVersions = $registeredProviders[CashierV3HangOrderVersionProvider::KIND];
        } else {
            $dispatcher->versionServices()->registerProvider(
                CashierV3HangOrderVersionProvider::KIND,
                $hangVersions
            );
        }
        $roomVersions = new RoomOpenServiceGuardVersionProvider();
        $registeredProviders = $dispatcher->versionServices()->registeredProviders();
        foreach (RoomOpenServiceGuardVersionProvider::KINDS as $kind) {
            if (isset($registeredProviders[$kind])) {
                if (!$registeredProviders[$kind] instanceof RoomOpenServiceGuardVersionProvider) {
                    throw new \LogicException('C3 room version provider conflict: ' . $kind);
                }
                $roomVersions = $registeredProviders[$kind];
                continue;
            }
            $dispatcher->versionServices()->registerProvider($kind, $roomVersions);
        }
        $provider = $provider ?: new CashierV3LegacyRoomReadProvider(
            new RoomOpenServiceGuardAuthority(),
            $roomVersions
        );
        $services = new CashierV3HangPreparationServices($provider);
        $submission = new CashierV3HangSubmissionServices($workspace, $services);
        $resume = new CashierV3HangResumeServices(
            $workspace,
            new CashierV3SaleCatalogServices()
        );
        $void = new CashierV3HangVoidServices($roomVersions);
        $resultQueries = new CashierV3HangOrderResultQueryServices();
        $list = new CashierV3HangOrderListServices();
        $roomPartition = new CashierV3RoomPartitionProvider($provider);
        $roomDiscovery = new CashierV3HangRoomResourceDiscovery($roomVersions);
        $handlers = $dispatcher->handlers();

        if ($handlers->hasProjection('prepare-empty-room-cashier')
            || $handlers->hasProjection('open-hang-order')) {
            throw new \LogicException('C3 hang preparation projection handler duplicate');
        }

        $handlers->registerProjection('prepare-empty-room-cashier', function (array $scope) use ($services): array {
            return $services->prepareEmptyRoomCashier(
                is_array($scope['payload'] ?? null) ? $scope['payload'] : [],
                (string)($scope['state_context_id'] ?? ''),
                $scope['operator_scope'],
                $scope['data_scope']
            );
        });

        $handlers->registerProjection('open-hang-order', function (array $scope) use ($services, $workspace): array {
            $stateContextId = (string)($scope['state_context_id'] ?? '');
            $operatorScope = $scope['operator_scope'];
            $workspaceId = sprintf(
                'ws:%d:%d:%s',
                $operatorScope->storeId(),
                $operatorScope->operatorId(),
                $stateContextId
            );
            $draft = $workspace->readDraftOrSyntheticGuest(
                $workspaceId,
                $stateContextId,
                $operatorScope
            );
            return $services->prepareHangOrder(
                is_array($scope['payload'] ?? null) ? $scope['payload'] : [],
                $draft,
                $stateContextId,
                $operatorScope,
                $scope['data_scope']
            );
        });

        if ($handlers->hasProjection('query-hang-orders')) {
            throw new \LogicException('C3 hang list projection handler duplicate');
        }
        $handlers->registerProjection('query-hang-orders', function (array $scope) use ($list): array {
            $payload = $list->query(
                is_array($scope['payload'] ?? null) ? $scope['payload'] : [],
                $scope['operator_scope'],
                $scope['data_scope']
            );
            return [
                'data' => ['hangOrders' => $payload],
                'versions' => (array)($payload['publicVersions'] ?? []),
                'message' => '挂单列表已读取。',
            ];
        });

        if ($handlers->hasProjection('open-hang-order-void-confirmation')) {
            throw new \LogicException('C3 hang void confirmation projection handler duplicate');
        }
        $handlers->registerProjection('open-hang-order-void-confirmation', function (array $scope) use ($void): array {
            return [
                'data' => ['hangOrderVoidConfirmation' => $void->prepare(
                    is_array($scope['payload'] ?? null) ? $scope['payload'] : [],
                    $scope['operator_scope'],
                    $scope['data_scope']
                )],
                'message' => '请确认是否作废该挂单。',
            ];
        });

        foreach (['refresh-room-status', 'open-room-detail', 'open-unassigned-room-list'] as $action) {
            if ($handlers->hasProjection($action)) {
                throw new \LogicException('C3 room projection handler duplicate: ' . $action);
            }
        }
        $handlers->registerProjection('refresh-room-status', function (array $scope) use ($roomPartition): array {
            $part = $roomPartition->readPartition(
                (string)($scope['state_context_id'] ?? ''),
                '',
                $scope['operator_scope'],
                $scope['data_scope']
            );
            return [
                'data' => ['room' => $part['payload']],
                'versions' => $part['public_versions'],
                'message' => '房态已刷新。',
            ];
        });
        $handlers->registerProjection('open-room-detail', function (array $scope) use ($roomPartition): array {
            $part = $roomPartition->readPartition(
                (string)($scope['state_context_id'] ?? ''),
                '',
                $scope['operator_scope'],
                $scope['data_scope']
            );
            $detail = $roomPartition->detail(
                is_array($scope['payload'] ?? null) ? $scope['payload'] : [],
                $scope['operator_scope'],
                $scope['data_scope']
            );
            $payload = $part['payload'];
            $payload['detail'] = $detail['detail'];
            return [
                'data' => ['room' => $payload],
                'versions' => $part['public_versions'],
                'message' => '房间详情已读取。',
            ];
        });
        $handlers->registerProjection('open-unassigned-room-list', function (array $scope) use ($roomPartition): array {
            $part = $roomPartition->readPartition(
                (string)($scope['state_context_id'] ?? ''),
                '',
                $scope['operator_scope'],
                $scope['data_scope']
            );
            return [
                'data' => ['room' => $part['payload']],
                'versions' => $part['public_versions'],
                'message' => '待分配房间列表已刷新。',
            ];
        });

        if ($handlers->hasProjection('query-hang-order-result')) {
            throw new \LogicException('C3 hang result query projection handler duplicate');
        }
        $handlers->registerProjection('query-hang-order-result', function (array $scope) use ($resultQueries): array {
            $result = $resultQueries->query(
                is_array($scope['payload'] ?? null) ? $scope['payload'] : [],
                $scope['operator_scope'],
                $scope['data_scope']
            );
            return [
                'data' => ['hangOrderResult' => $result],
                'message' => (string)($result['message'] ?? '挂单结果已查询。'),
                'return_root_state' => (string)($result['status'] ?? '')
                    === \app\services\cashier\v3\CashierV3ResultCode::STATUS_SUCCESS,
            ];
        });

        if ($handlers->hasCommand('submit-hang-order')) {
            throw new \LogicException('C3 hang submission command handler duplicate');
        }
        $handlers->registerCommand('submit-hang-order', function (array $scope) use ($submission): array {
            $submitted = $submission->submitInTx($scope);
            $hangOrder = is_array($submitted['hangOrder'] ?? null)
                ? $submitted['hangOrder']
                : [];
            $businessNo = trim((string)($hangOrder['hangOrderNo'] ?? ''));
            if ($businessNo === '') {
                throw new CashierV3CommandException(
                    \app\services\cashier\v3\CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '挂单结果不完整，本次操作已回滚，请重试。'
                );
            }
            return [
                'data' => [
                    'hangOrderSubmission' => $submitted,
                    'cashierDraft' => (array)($submitted['cashierDraft'] ?? []),
                ],
                'business_no' => $businessNo,
                'touched' => $submitted['roomOccupation'] === null
                    ? ['cashier_workspace']
                    : ['cashier_workspace', 'target_room_time_slot'],
                'message' => $submitted['roomOccupation'] === null
                    ? '挂单成功。'
                    : '挂单成功，房间已自动占用。',
            ];
        });

        if ($handlers->hasCommand('resume-hang-order')) {
            throw new \LogicException('C3 hang resume command handler duplicate');
        }
        $handlers->registerCommand('resume-hang-order', function (array $scope) use ($resume): array {
            $restored = $resume->resumeInTx($scope);
            return [
                'data' => [
                    'hangOrderResume' => $restored,
                    '_navigation' => ['routeName' => 'cashier-v3-cashier', 'query' => []],
                ],
                'business_no' => (string)$restored['hangOrderNo'],
                'touched' => ['hang_order', 'cashier_workspace'],
                'message' => '挂单已提取，请继续结账。',
            ];
        });

        if ($handlers->hasCommand('void-hang-order')) {
            throw new \LogicException('C3 hang void command handler duplicate');
        }
        $handlers->registerCommand('void-hang-order', function (array $scope) use ($void): array {
            $result = $void->voidInTx($scope);
            return [
                'data' => ['hangOrderVoid' => $result],
                'business_no' => (string)$result['hangOrderNo'],
                'touched' => (array)$result['touched'],
                'message' => (string)$result['message'],
            ];
        });

        if ($dispatcher->policies()->has('submit-hang-order')) {
            throw new \LogicException('C3 hang submission context policy duplicate');
        }
        $policy = new CashierV3ContextPolicy(
            'submit-hang-order',
            ['cashier_workspace'],
            [],
            function (array $payload, array $base): array {
                $workspaceId = trim((string)($base['session']['workspace_id'] ?? ''));
                if ($workspaceId === '') {
                    throw CashierV3CommandException::invalidContext(
                        '当前收银工作台会话无效，请刷新页面后重试。',
                        ['reason' => 'hang_workspace_identity_missing']
                    );
                }
                return [
                    'required' => ['cashier_workspace'],
                    'allowed' => [],
                    'identities' => [[
                        'role' => 'cashier_workspace',
                        'kind' => 'cashier_workspace',
                        'id' => $workspaceId,
                        'required' => true,
                    ]],
                    'required_read_roles' => ['cashier_workspace'],
                    'required_touched_roles' => ['cashier_workspace'],
                ];
            },
            ['cashier_workspace'],
            [],
            []
        );
        $policy->configureServerResourceDiscovery(
            [$roomDiscovery, 'discover'],
            ['target_room', 'target_room_time_slot'],
            ['room', 'room_time_slot']
        );
        $dispatcher->policies()->register($policy);

        if ($dispatcher->policies()->has('resume-hang-order')) {
            throw new \LogicException('C3 hang resume context policy duplicate');
        }
        $resumePolicy = new CashierV3ContextPolicy(
            'resume-hang-order',
            ['cashier_workspace'],
            [],
            function (array $payload, array $base): array {
                $workspaceId = trim((string)($base['session']['workspace_id'] ?? ''));
                if ($workspaceId === '') {
                    throw CashierV3CommandException::invalidContext(
                        '当前收银工作台会话无效，请刷新页面后重试。',
                        ['reason' => 'hang_resume_workspace_identity_missing']
                    );
                }
                return [
                    'required' => ['cashier_workspace'],
                    'allowed' => [],
                    'identities' => [[
                        'role' => 'cashier_workspace',
                        'kind' => 'cashier_workspace',
                        'id' => $workspaceId,
                        'required' => true,
                    ]],
                    'required_read_roles' => ['cashier_workspace'],
                    'required_touched_roles' => ['cashier_workspace'],
                ];
            },
            ['cashier_workspace'],
            ['hang_order'],
            ['hang_order', 'catalog_card_definition', 'catalog_product', 'catalog_sku']
        );
        $resumePolicy->configureServerResourceDiscovery(
            [$resume, 'discover'],
            ['hang_order', 'cashier_workspace'],
            ['hang_order', 'catalog_card_definition', 'catalog_product', 'catalog_sku']
        );
        $dispatcher->policies()->register($resumePolicy);

        if ($dispatcher->policies()->has('void-hang-order')) {
            throw new \LogicException('C3 hang void context policy duplicate');
        }
        $voidPolicy = new CashierV3ContextPolicy(
            'void-hang-order',
            ['cashier_workspace'],
            [],
            static function (array $payload, array $base): array {
                $hangOrderId = trim((string)($payload['hangOrderId'] ?? $payload['hang_order_id'] ?? ''));
                $workspaceId = trim((string)($base['session']['workspace_id'] ?? ''));
                if (preg_match('/^HGO[0-9a-f]{40}$/D', $hangOrderId) !== 1) {
                    throw CashierV3CommandException::invalidContext(
                        '挂单标识无效，请刷新列表后重试。',
                        ['reason' => 'hang_void_identity_invalid']
                    );
                }
                if ($workspaceId === '') {
                    throw CashierV3CommandException::invalidContext(
                        '当前收银工作台会话无效，请刷新页面后重试。',
                        ['reason' => 'hang_void_workspace_identity_missing']
                    );
                }
                return [
                    'identities' => [[
                        'role' => 'cashier_workspace',
                        'kind' => 'cashier_workspace',
                        'id' => $workspaceId,
                        'required' => true,
                    ]],
                    'required_read_roles' => ['cashier_workspace'],
                ];
            },
            [],
            ['cashier_workspace', 'hang_order', 'room_time_slot'],
            ['cashier_workspace', 'hang_order', 'room_time_slot']
        );
        $voidPolicy->configureServerResourceDiscovery(
            [$void, 'discover'],
            ['hang_order', 'room_time_slot'],
            ['hang_order', 'room_time_slot']
        );
        $dispatcher->policies()->register($voidPolicy);

        $assembler->registerPartitionProvider($roomPartition);
    }
}
