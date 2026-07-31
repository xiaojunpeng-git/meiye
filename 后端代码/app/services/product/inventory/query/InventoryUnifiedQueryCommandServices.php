<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

use app\services\query\UnifiedQueryCommandCoordinator;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryJson;
use app\services\query\UnifiedQueryRuntime;
use think\facade\Db;

/**
 * Inventory host gateway for neutral unified-query write commands.
 *
 * The browser never chooses a tenant, a store, a warehouse scope or the
 * query-preference version.  Each successful command is kept as an immutable
 * receipt so a retry returns the original result instead of creating another
 * field version or export task.
 */
final class InventoryUnifiedQueryCommandServices
{
    private const RECEIPT_TABLE = 'inventory_unified_query_command_receipt';

    public function dispatch(int $storeId, int $operatorId, array $rules, array $input): array
    {
        $runtime = UnifiedQueryRuntime::runtime();
        $contextFactory = new InventoryStoreUnifiedQueryContextFactory($runtime['contextFactory']);
        $context = $contextFactory->make($storeId, $operatorId, $rules, date('Y-m-d'));
        return $this->dispatchWithContext($context, $input);
    }

    public function dispatchWithContext(array $context, array $input): array
    {
        $command = $this->normalize($input);
        // Receipt identity includes the server-derived scope.  A retry against a
        // different selected warehouse must not replay an export/settings result
        // created under a broader or narrower DataScope.
        $command['requestHash'] = hash('sha256', UnifiedQueryJson::encode([
            'request_hash' => $command['requestHash'],
            'scope_dimensions' => (array)($context['scope_dimensions'] ?? []),
        ]));
        $runtime = UnifiedQueryRuntime::runtime();
        $settings = $runtime['preferences']->load($context, InventoryBatchStockQueryContract::PAGE_CODE);
        $context['page_code'] = InventoryBatchStockQueryContract::PAGE_CODE;
        $context['query_preference_version'] = (int)$settings['settingsVersion'];

        return Db::transaction(function () use ($runtime, $context, $command): array {
            $existing = Db::name(self::RECEIPT_TABLE)
                ->where('tenant_id', (string)$context['tenant_id'])
                ->where('store_id', (int)$context['store_id'])
                ->where('operator_id', (int)$context['operator_id'])
                ->where('action', $command['action'])
                ->where('idempotency_key', $command['idempotencyKey'])
                ->lock(true)
                ->find();
            if ($existing) {
                if (!hash_equals((string)$existing['request_hash'], $command['requestHash'])) {
                    throw new UnifiedQueryException(
                        'INVENTORY_UNIFIED_QUERY_IDEMPOTENCY_CONFLICT',
                        '本次查询操作与已提交请求不一致，请刷新后重新操作。',
                        []
                    );
                }
                return $this->receiptResult((array)$existing);
            }

            $now = time();
            $receiptId = (int)Db::name(self::RECEIPT_TABLE)->insertGetId([
                'tenant_id' => (string)$context['tenant_id'],
                'store_id' => (int)$context['store_id'],
                'operator_id' => (int)$context['operator_id'],
                'page_code' => InventoryBatchStockQueryContract::PAGE_CODE,
                'action' => $command['action'],
                'idempotency_key' => $command['idempotencyKey'],
                'request_hash' => $command['requestHash'],
                'status' => 'PROCESSING',
                'result_json' => '',
                'business_no' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($receiptId <= 0) {
                throw new \RuntimeException('库存查询操作回执创建失败');
            }

            $dispatched = $runtime['commandCoordinator']->dispatch(
                $command['action'],
                $context,
                $command['payload']
            );
            $result = [
                'result' => [
                    'status' => 'success',
                    'code' => 'INVENTORY_UNIFIED_QUERY_COMMAND_SUCCEEDED',
                    'message' => (string)$dispatched['message'],
                    'data' => (array)$dispatched['data'],
                ],
                'idempotencyKey' => $command['idempotencyKey'],
                'boundIdempotencyKey' => $command['idempotencyKey'],
            ];
            $saved = Db::name(self::RECEIPT_TABLE)
                ->where('id', $receiptId)
                ->where('status', 'PROCESSING')
                ->update([
                    'status' => 'SUCCEEDED',
                    'result_json' => UnifiedQueryJson::encode($result),
                    'business_no' => (string)($dispatched['business_no'] ?? ''),
                    'updated_at' => time(),
                ]);
            if ((int)$saved !== 1) {
                throw new \RuntimeException('库存查询操作回执写入失败');
            }
            return $result;
        });
    }

    private function normalize(array $input): array
    {
        $action = trim((string)($input['action'] ?? ''));
        $key = trim((string)($input['idempotencyKey'] ?? ($input['idempotency_key'] ?? '')));
        if (!in_array($action, (new UnifiedQueryCommandCoordinator(
            UnifiedQueryRuntime::service('registry'),
            UnifiedQueryRuntime::service('preferences'),
            UnifiedQueryRuntime::service('aliases'),
            UnifiedQueryRuntime::service('customFields'),
            UnifiedQueryRuntime::service('exports')
        ))->supportedActions(), true)) {
            throw new UnifiedQueryException('UNIFIED_QUERY_COMMAND_NOT_ALLOWED', '该统一查询操作不受支持，请刷新后重试。', []);
        }
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key) !== 1) {
            throw new UnifiedQueryException('INVENTORY_UNIFIED_QUERY_IDEMPOTENCY_INVALID', '查询操作缺少有效的防重复标识，请刷新后重试。', []);
        }
        $payload = $input;
        unset($payload['action'], $payload['idempotencyKey'], $payload['idempotency_key'], $payload['commandContexts'], $payload['command_contexts']);
        foreach (['tenant_id', 'tenantId', 'store_id', 'storeId', 'organization_id', 'organizationId', 'location_id', 'locationId', 'scope_dimensions', 'scopeDimensions', 'permissions', 'granted_features', 'query_preference_version', 'queryPreferenceVersion'] as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                throw new UnifiedQueryException('INVENTORY_UNIFIED_QUERY_CLIENT_SCOPE_FORBIDDEN', '查询操作不能指定租户、门店或仓库范围。', []);
            }
        }
        $payload['pageCode'] = InventoryBatchStockQueryContract::PAGE_CODE;
        return [
            'action' => $action,
            'idempotencyKey' => $key,
            'payload' => $payload,
            'requestHash' => hash('sha256', UnifiedQueryJson::encode([
                'pageCode' => InventoryBatchStockQueryContract::PAGE_CODE,
                'action' => $action,
                'payload' => $payload,
            ])),
        ];
    }

    private function receiptResult(array $receipt): array
    {
        if ((string)($receipt['status'] ?? '') !== 'SUCCEEDED') {
            throw new UnifiedQueryException('INVENTORY_UNIFIED_QUERY_RESULT_UNKNOWN', '查询操作结果仍在确认中，请稍后刷新页面。', []);
        }
        $result = UnifiedQueryJson::decode((string)($receipt['result_json'] ?? ''));
        if (!isset($result['result']) || !is_array($result['result'])) {
            throw new \RuntimeException('库存查询操作回执损坏');
        }
        return $result;
    }
}
