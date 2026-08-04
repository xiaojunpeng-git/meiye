<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\registry\CashierV3ContextPolicy;

/** V3 Gateway installation for card operations. */
final class CashierV3CardOperationModule
{
    public static function install(CashierV3ActionDispatcher $dispatcher): void
    {
        self::registerPolicy($dispatcher);
        $handlers = $dispatcher->handlers();
        if ($handlers->hasCommand(CashierV3CardOperationKernel::ACTION)) {
            throw new \LogicException('card operation handler duplicate');
        }
        $authority = new CashierV3CardOperationAuthorityServices();
        $handlers->registerCommand(CashierV3CardOperationKernel::ACTION, static function (array $scope) use ($authority): array {
            $result = $authority->submitInTx($scope);
            $operation = is_array($result['operation'] ?? null) ? $result['operation'] : [];
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $type = (string)($payload['operationType'] ?? '');
            $awaitingCheckout = in_array($type, [
                CashierV3CardOperationKernel::TYPE_CARD_UPGRADE,
                CashierV3CardOperationKernel::TYPE_PROJECT_UPGRADE,
            ], true);
            $cashierDraft = is_array($result['cashierDraft'] ?? null) ? $result['cashierDraft'] : null;
            if ($awaitingCheckout && $cashierDraft === null) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '升级补价草稿未完整写入，本次操作已取消，请刷新后重试。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['reason' => 'card_operation_upgrade_workspace_write_missing']
                );
            }
            // Upgrade only creates a protected cashier draft. It must not
            // advance the source-card version until the final checkout does.
            // The writer above returns only after the same transaction has
            // appended the real workbench line, so this is a real mutation.
            $touched = $awaitingCheckout ? ['cashier_workspace'] : ['source_card'];
            if (!$awaitingCheckout && $cashierDraft !== null) {
                $touched[] = 'cashier_workspace';
            }
            if ($type === CashierV3CardOperationKernel::TYPE_PROJECT_REPLACEMENT) {
                foreach ((array)($payload['projectLines'] ?? []) as $line) {
                    $detailId = trim((string)(is_array($line) ? ($line['sourceDetailId'] ?? '') : ''));
                    if ($detailId !== '') {
                        $touched[] = 'source_project:' . $detailId;
                    }
                }
            }
            return [
                'data' => [
                    'cardOperation' => $operation,
                    'cardState' => is_array($result['state'] ?? null) ? $result['state'] : [],
                    'cashierDraft' => $cashierDraft,
                ],
                'business_no' => (string)($operation['operationNo'] ?? ''),
                'touched' => array_values(array_unique($touched)),
                'message' => self::messageFor((string)($operation['operationStatus'] ?? '')),
            ];
        });
    }

    private static function registerPolicy(CashierV3ActionDispatcher $dispatcher): void
    {
        $action = CashierV3CardOperationKernel::ACTION;
        if ($dispatcher->policies()->has($action)) {
            throw new \LogicException('card operation context policy duplicate');
        }
        $policy = new CashierV3ContextPolicy(
            $action,
            ['card_holder'],
            ['member', 'cashier_workspace'],
            static function (array $payload, array $base = []): array {
                $type = trim((string)($payload['operationType'] ?? ''));
                $holderId = self::positiveId($payload['sourceCardHolderId'] ?? null, 'source_card_missing');
                $identities = [[
                    'role' => 'source_card',
                    'kind' => 'card_holder',
                    'id' => (string)$holderId,
                    'required' => true,
                ]];
                $required = ['card_holder'];
                $read = ['source_card'];
                $awaitingCheckout = in_array($type, [
                    CashierV3CardOperationKernel::TYPE_CARD_UPGRADE,
                    CashierV3CardOperationKernel::TYPE_PROJECT_UPGRADE,
                ], true);
                $touched = $awaitingCheckout ? [] : ['source_card'];
                if ($awaitingCheckout) {
                    $workspaceId = trim((string)($base['session']['workspace_id'] ?? ''));
                    if ($workspaceId === '') {
                        throw new CashierV3CommandException(
                            CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                            '当前收银工作台会话无效，请刷新页面后重试。',
                            CashierV3ResultCode::STATUS_FAILED,
                            ['reason' => 'card_operation_upgrade_workspace_missing']
                        );
                    }
                    $identities[] = [
                        'role' => 'cashier_workspace',
                        'kind' => 'cashier_workspace',
                        'id' => $workspaceId,
                        'required' => true,
                    ];
                    $required[] = 'cashier_workspace';
                    $read[] = 'cashier_workspace';
                    $touched[] = 'cashier_workspace';
                }
                if ($type === CashierV3CardOperationKernel::TYPE_CARD_TRANSFER) {
                    // The receiving member is discovered and locked by the
                    // authority writer in the same transaction. The generic
                    // member selector is deliberately read-only and has no
                    // public member-version issue contract, so requiring a
                    // client-supplied member context here would either force
                    // a stale version guess or mutate the active cashier
                    // member just to obtain one. Source-card version +
                    // server-side target lock gives the command its actual
                    // concurrency boundary; an inactive/deleted target is
                    // still rejected before any card state is changed.
                    self::positiveId($payload['targetMemberId'] ?? null, 'target_member_invalid');
                }
                return [
                    'required' => $required,
                    'allowed' => [],
                    'identities' => $identities,
                    'required_read_roles' => $read,
                    'required_touched_roles' => array_values(array_unique($touched)),
                ];
            },
            [],
            ['source_card', 'cashier_workspace'],
            ['card_holder', 'cashier_workspace']
        );
        $policy->configureServerResourceDiscovery(
            [new CashierV3CardOperationResourceDiscovery(), 'discover'],
            ['source_project', 'target_project_catalog'],
            ['member_benefit_pool', 'catalog_product', 'catalog_sku', 'catalog_card_definition']
        );
        $dispatcher->policies()->register($policy);
    }

    private static function positiveId($value, string $reason): int
    {
        if (is_bool($value) || is_array($value) || $value === null) {
            throw self::invalid($reason);
        }
        $raw = trim((string)$value);
        if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1 || (string)(int)$raw !== $raw) {
            throw self::invalid($reason);
        }
        return (int)$raw;
    }

    private static function invalid(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
            '卡操作对象或版本无效，请刷新后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }

    private static function messageFor(string $status): string
    {
        return $status === 'awaiting_checkout'
            ? '升级补价已计算，请先完成正式结账后再生效。'
            : '卡操作已完成。';
    }
}
