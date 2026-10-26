<?php
namespace app\services\organization;

use think\facade\Db;

/**
 * 组织/授权写路径共用严格幂等（request_token UUID + payload hash）
 * 同 token 同 operator/action/scope/hash → 回放；任一维不一致 → CONFLICT；不完整结果 fail-closed
 */
class OrganizationStrictIdempotencyServices
{
    public const ERR_IDEMPOTENCY_CONFLICT = 'IDEMPOTENCY_TOKEN_CONFLICT';
    public const ERR_IDEMPOTENCY_INCOMPLETE = '幂等结果不完整，请更换请求令牌后重试';
    public const ERR_TOKEN_REQUIRED = '缺少请求令牌';
    public const ERR_TOKEN_MISMATCH = '请求令牌不一致';
    public const ERR_TOKEN_INVALID = '请求令牌格式无效';

    /** @var OrganizationWorkspaceWriteGate */
    protected $gate;
    /** @var OrganizationManageServices */
    protected $manage;

    public function __construct(
        OrganizationWorkspaceWriteGate $gate,
        OrganizationManageServices $manage
    ) {
        $this->gate = $gate;
        $this->manage = $manage;
    }

    /**
     * @param callable(array):array{msg:string,data:array} $business
     * @return array{msg:string,data:array,replay:bool}
     */
    public function run(
        string $action,
        string $scopeKey,
        array $payload,
        array $adminInfo,
        array $requestCtx,
        callable $business,
        bool $requireSuperAdmin = true
    ): array {
        $this->gate->assertCanWrite();
        if ($requireSuperAdmin) {
            $super = $this->gate->assertSuperAdmin($adminInfo);
            if (!$super['ok']) {
                throw new \Exception($super['reason_text'] !== '' ? $super['reason_text'] : '无权限执行该操作');
            }
        }

        $token = $this->resolveRequestToken($requestCtx);
        $operatorId = (int)$adminInfo['id'];
        $operatorName = (string)($adminInfo['account'] ?? $adminInfo['real_name'] ?? '');
        $requestHash = $this->hashPayload($payload);
        $requestId = sprintf(
            'idem-%s-%s',
            date('YmdHis'),
            substr(hash('sha256', $token . '|' . $operatorId . '|' . microtime(true) . '|' . mt_rand()), 0, 16)
        );
        $operatorIp = (string)($requestCtx['operator_ip'] ?? '');

        return $this->manage->withOrganizationStructureLock(function () use (
            $action,
            $scopeKey,
            $token,
            $operatorId,
            $operatorName,
            $requestHash,
            $requestId,
            $operatorIp,
            $business
        ) {
            Db::startTrans();
            try {
                if (!$this->idempotencyTableReady()) {
                    throw new \Exception('组织写幂等表未就绪，请先执行数据库升级');
                }

                $existing = Db::name('organization_write_idempotency')
                    ->where('request_token', $token)
                    ->lock(true)
                    ->find();
                if ($existing) {
                    $this->assertIdempotencyMatch($existing, $operatorId, $action, $scopeKey, $requestHash);
                    $decoded = $this->decodeCompleteResponse((string)($existing['response_json'] ?? ''));
                    Db::commit();
                    return [
                        'msg' => (string)$decoded['msg'],
                        'data' => is_array($decoded['data']) ? $decoded['data'] : [],
                        'replay' => true,
                    ];
                }

                try {
                    Db::name('organization_write_idempotency')->insert([
                        'request_token' => $token,
                        'operator_id' => $operatorId,
                        'action' => $action,
                        'scope_key' => $scopeKey,
                        'request_hash' => $requestHash,
                        'response_json' => '',
                        'add_time' => time(),
                    ]);
                } catch (\Throwable $e) {
                    $existing = Db::name('organization_write_idempotency')
                        ->where('request_token', $token)
                        ->lock(true)
                        ->find();
                    if (!$existing) {
                        throw $e;
                    }
                    $this->assertIdempotencyMatch($existing, $operatorId, $action, $scopeKey, $requestHash);
                    $decoded = $this->decodeCompleteResponse((string)($existing['response_json'] ?? ''));
                    Db::commit();
                    return [
                        'msg' => (string)$decoded['msg'],
                        'data' => is_array($decoded['data']) ? $decoded['data'] : [],
                        'replay' => true,
                    ];
                }

                $auditMeta = [
                    'operator_id' => $operatorId,
                    'operator_name' => $operatorName,
                    'operator_ip' => $operatorIp,
                    'request_id' => $requestId,
                    'request_token' => $token,
                ];
                $out = $business($auditMeta);
                $msg = (string)($out['msg'] ?? '保存成功');
                $data = is_array($out['data'] ?? null) ? $out['data'] : [];
                $responseJson = json_encode(['msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
                if ($responseJson === false || $responseJson === '') {
                    throw new \Exception('写入响应序列化失败');
                }
                $affected = Db::name('organization_write_idempotency')
                    ->where('request_token', $token)
                    ->where('response_json', '')
                    ->update(['response_json' => $responseJson]);
                if ((int)$affected !== 1) {
                    $check = Db::name('organization_write_idempotency')->where('request_token', $token)->value('response_json');
                    if ((string)$check !== $responseJson) {
                        throw new \Exception('幂等结果写入失败');
                    }
                }

                Db::commit();
                return ['msg' => $msg, 'data' => $data, 'replay' => false];
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }
        });
    }

    public function hashPayload(array $payload): string
    {
        $normalized = $this->normalizeForHash($payload);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \Exception('请求参数规范化失败');
        }
        return hash('sha256', $json);
    }

    public function isValidUuid(string $token): bool
    {
        return (bool)preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
            $token
        );
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public function normalizeForHash($value)
    {
        if (!is_array($value)) {
            if (is_bool($value)) {
                return $value ? 1 : 0;
            }
            if (is_float($value)) {
                return (string)$value;
            }
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            $out = [];
            foreach ($value as $item) {
                $out[] = $this->normalizeForHash($item);
            }
            return $out;
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            if ($k === 'request_token') {
                continue;
            }
            $out[(string)$k] = $this->normalizeForHash($v);
        }
        return $out;
    }

    /**
     * @param array{header_token?:string,body_token?:string,operator_ip?:string} $requestCtx
     */
    protected function resolveRequestToken(array $requestCtx): string
    {
        $header = trim((string)($requestCtx['header_token'] ?? ''));
        $body = trim((string)($requestCtx['body_token'] ?? ''));
        if ($header !== '' && $body !== '' && $header !== $body) {
            throw new \Exception(self::ERR_TOKEN_MISMATCH);
        }
        $token = $header !== '' ? $header : $body;
        if ($token === '') {
            throw new \Exception(self::ERR_TOKEN_REQUIRED);
        }
        if (!$this->isValidUuid($token)) {
            throw new \Exception(self::ERR_TOKEN_INVALID);
        }
        return strtolower($token);
    }

    protected function assertIdempotencyMatch(array $row, int $operatorId, string $action, string $scopeKey, string $requestHash): void
    {
        if ((int)($row['operator_id'] ?? 0) !== $operatorId
            || (string)($row['action'] ?? '') !== $action
            || (string)($row['scope_key'] ?? '') !== $scopeKey
            || (string)($row['request_hash'] ?? '') !== $requestHash
        ) {
            throw new \Exception(self::ERR_IDEMPOTENCY_CONFLICT);
        }
    }

    /**
     * @return array{msg:string,data:mixed}
     */
    protected function decodeCompleteResponse(string $responseJson): array
    {
        $decoded = json_decode($responseJson, true);
        if (!is_array($decoded) || !isset($decoded['msg']) || !array_key_exists('data', $decoded)) {
            throw new \Exception(self::ERR_IDEMPOTENCY_INCOMPLETE);
        }
        return $decoded;
    }

    protected function idempotencyTableReady(): bool
    {
        static $positive = false;
        if ($positive) {
            return true;
        }
        try {
            $rows = Db::query("SHOW TABLES LIKE 'eb_organization_write_idempotency'");
            $positive = !empty($rows);
            return $positive;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
