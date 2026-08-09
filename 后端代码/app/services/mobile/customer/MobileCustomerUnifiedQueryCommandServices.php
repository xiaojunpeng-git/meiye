<?php

namespace app\services\mobile\customer;

use app\services\query\UnifiedQueryCommandCoordinator;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryJson;
use app\services\query\UnifiedQueryRuntime;
use app\services\query\provider\MemberUnifiedQueryProvider;
use think\facade\Db;

/**
 * Mobile merchant boundary for neutral customer-list query metadata commands.
 *
 * Query preferences are technical user configuration, not customer facts.  A
 * receipt is still required: retries must not create duplicate field versions
 * or export tasks.  The trusted merchant context is reconstructed on every
 * request and no client-provided data scope is accepted.
 */
final class MobileCustomerUnifiedQueryCommandServices
{
    private const RECEIPT_TABLE = 'mobile_customer_unified_query_command_receipt';

    /** @var MobileCustomerQueryServices */
    private $customers;

    public function __construct(MobileCustomerQueryServices $customers)
    {
        $this->customers = $customers;
    }

    public function dispatch(array $merchant, array $input): array
    {
        $command = $this->normalize($input);
        $runtime = UnifiedQueryRuntime::runtime();
        $context = $this->customers->unifiedContextForMerchant($merchant);
        $settings = $runtime['preferences']->load($context, MemberUnifiedQueryProvider::PAGE_CODE);
        $context['page_code'] = MemberUnifiedQueryProvider::PAGE_CODE;
        $context['query_preference_version'] = (int)$settings['settingsVersion'];

        return Db::transaction(function () use ($runtime, $context, $merchant, $command): array {
            $existing = Db::name(self::RECEIPT_TABLE)
                ->where('tenant_id', (string)$context['tenant_id'])
                ->where('employee_id', (string)$merchant['employeeId'])
                ->where('account_id', (int)$merchant['accountId'])
                ->where('action', $command['action'])
                ->where('idempotency_key', $command['idempotencyKey'])
                ->lock(true)
                ->find();
            if ($existing) {
                if (!hash_equals((string)$existing['request_hash'], $command['requestHash'])) {
                    throw new UnifiedQueryException(
                        'MOBILE_CUSTOMER_UNIFIED_QUERY_IDEMPOTENCY_CONFLICT',
                        '本次查询设置操作与已提交请求不一致，请刷新后重新操作。',
                        []
                    );
                }
                return $this->receiptResult((array)$existing);
            }

            $now = time();
            $receiptId = (int)Db::name(self::RECEIPT_TABLE)->insertGetId([
                'tenant_id' => (string)$context['tenant_id'],
                'employee_id' => (string)$merchant['employeeId'],
                'account_id' => (int)$merchant['accountId'],
                'store_id' => (int)$merchant['storeId'],
                'page_code' => MemberUnifiedQueryProvider::PAGE_CODE,
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
                throw new \RuntimeException('客户统一查询命令回执创建失败。');
            }

            $dispatched = $runtime['commandCoordinator']->dispatch(
                $command['action'],
                $context,
                $command['payload']
            );
            $result = [
                'result' => [
                    'status' => 'success',
                    'code' => 'MOBILE_CUSTOMER_UNIFIED_QUERY_COMMAND_SUCCEEDED',
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
                throw new \RuntimeException('客户统一查询命令回执保存失败。');
            }
            return $result;
        });
    }

    private function normalize(array $input): array
    {
        $action = trim((string)($input['action'] ?? ''));
        $idempotencyKey = trim((string)($input['idempotencyKey'] ?? ''));
        $supported = UnifiedQueryRuntime::runtime()['commandCoordinator']->supportedActions();
        if (!in_array($action, $supported, true)) {
            throw new UnifiedQueryException('UNIFIED_QUERY_COMMAND_NOT_ALLOWED', '该统一查询操作不受支持，请刷新后重试。', []);
        }
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $idempotencyKey) !== 1) {
            throw new UnifiedQueryException('MOBILE_CUSTOMER_UNIFIED_QUERY_IDEMPOTENCY_INVALID', '查询操作缺少有效的防重复标识，请刷新后重试。', []);
        }
        $payload = $input;
        unset($payload['action'], $payload['idempotencyKey'], $payload['idempotency_key'], $payload['commandContexts'], $payload['command_contexts']);
        foreach (['tenant_id', 'tenantId', 'store_id', 'storeId', 'organization_id', 'organizationId', 'employee_id', 'employeeId', 'account_id', 'accountId', 'scope_dimensions', 'scopeDimensions', 'permissions', 'granted_features', 'query_preference_version', 'queryPreferenceVersion'] as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                throw new UnifiedQueryException('MOBILE_CUSTOMER_UNIFIED_QUERY_CLIENT_SCOPE_FORBIDDEN', '查询操作不能指定租户、人员、门店或组织范围。', []);
            }
        }
        $payload['pageCode'] = MemberUnifiedQueryProvider::PAGE_CODE;
        return [
            'action' => $action,
            'idempotencyKey' => $idempotencyKey,
            'payload' => $payload,
            'requestHash' => hash('sha256', UnifiedQueryJson::encode([
                'pageCode' => MemberUnifiedQueryProvider::PAGE_CODE,
                'action' => $action,
                'payload' => $payload,
            ])),
        ];
    }

    private function receiptResult(array $receipt): array
    {
        if ((string)($receipt['status'] ?? '') !== 'SUCCEEDED') {
            throw new UnifiedQueryException('MOBILE_CUSTOMER_UNIFIED_QUERY_RESULT_UNKNOWN', '查询设置操作结果仍在确认中，请稍后刷新页面。', []);
        }
        $result = UnifiedQueryJson::decode((string)($receipt['result_json'] ?? ''));
        if (!isset($result['result']) || !is_array($result['result'])) {
            throw new \RuntimeException('客户统一查询命令回执损坏。');
        }
        return $result;
    }
}
