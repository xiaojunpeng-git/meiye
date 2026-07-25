<?php
namespace app\services\organization;

use think\facade\Db;

/**
 * O4 组织工作台 HTTP 写编排（唯一门禁入口）
 * 顺序：写门禁 → 超管 → request token → 结构锁 → 事务 → 幂等 → 业务 → 审计
 */
class OrganizationWorkspaceWriteServices
{
    public const ERR_IDEMPOTENCY_CONFLICT = 'IDEMPOTENCY_TOKEN_CONFLICT';
    public const ERR_IDEMPOTENCY_INCOMPLETE = '幂等结果不完整，请更换请求令牌后重试';
    public const ERR_TOKEN_REQUIRED = '缺少请求令牌';
    public const ERR_TOKEN_MISMATCH = '请求令牌不一致';
    public const ERR_TOKEN_INVALID = '请求令牌格式无效';
    public const ERR_CLI_AUDIT_FAULT = '组织审计故障注入（CLI smoke）';

    /**
     * CLI-only 一次性审计故障开关（默认关；HTTP/Header/Cookie 均不可控）
     * @var bool
     */
    private static $cliAuditFaultArmed = false;

    /** @var OrganizationWorkspaceWriteGate */
    protected $gate;
    /** @var OrganizationManageServices */
    protected $manage;

    /**
     * 仅 PHP CLI 可武装；武装后下一次 writeLog(含 request_token) 消费并抛错。
     */
    public static function armCliAuditFaultOnce(): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('armCliAuditFaultOnce 仅允许 CLI');
        }
        self::$cliAuditFaultArmed = true;
    }

    /**
     * 一次性消费；非 CLI 恒为 false（硬隔离）。
     */
    public static function consumeCliAuditFaultOnce(): bool
    {
        if (PHP_SAPI !== 'cli') {
            return false;
        }
        if (!self::$cliAuditFaultArmed) {
            return false;
        }
        self::$cliAuditFaultArmed = false;
        return true;
    }

    public static function isCliAuditFaultArmed(): bool
    {
        return PHP_SAPI === 'cli' && self::$cliAuditFaultArmed;
    }

    public function __construct(
        OrganizationWorkspaceWriteGate $gate,
        OrganizationManageServices $manage
    ) {
        $this->gate = $gate;
        $this->manage = $manage;
    }

    public function getWriteStatus(array $adminInfo): array
    {
        return $this->gate->getWriteStatus($adminInfo);
    }

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function saveOrganization(int $id, array $data, array $adminInfo, array $requestCtx): array
    {
        $payload = [
            'pid' => (int)($data['pid'] ?? 0),
            'name' => trim((string)($data['name'] ?? '')),
            'sort' => (int)($data['sort'] ?? 0),
        ];
        $scopeKey = $id > 0 ? ('org:' . $id) : ('org:create:' . $payload['pid']);
        return $this->runWrite(
            'org_save',
            $scopeKey,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($id, $payload) {
                $newId = $this->manage->applySaveOrganization(
                    $id,
                    $payload,
                    (int)$auditMeta['operator_id'],
                    (string)$auditMeta['operator_name'],
                    $auditMeta
                );
                return ['msg' => '保存成功', 'data' => ['id' => $newId]];
            }
        );
    }

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function deleteOrganization(int $id, array $adminInfo, array $requestCtx): array
    {
        return $this->runWrite(
            'org_delete',
            'org:' . $id,
            ['id' => $id],
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($id) {
                $this->manage->applyDeleteOrganization(
                    $id,
                    (int)$auditMeta['operator_id'],
                    (string)$auditMeta['operator_name'],
                    $auditMeta
                );
                return ['msg' => '删除成功', 'data' => ['id' => $id]];
            }
        );
    }

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function bindStore(int $storeId, int $orgId, array $adminInfo, array $requestCtx): array
    {
        $payload = ['store_id' => $storeId, 'org_id' => $orgId];
        return $this->runWrite(
            'org_bind_store',
            'store:' . $storeId,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($storeId, $orgId) {
                $ret = $this->manage->applyBindStoreToOrg(
                    $storeId,
                    $orgId,
                    (int)$auditMeta['operator_id'],
                    (string)$auditMeta['operator_name'],
                    $auditMeta
                );
                return [
                    'msg' => '绑定成功',
                    'data' => [
                        'store_id' => $storeId,
                        'org_id' => $orgId,
                        'changed' => (bool)$ret['changed'],
                        'old_org_id' => (int)$ret['old_org_id'],
                    ],
                ];
            }
        );
    }

    /**
     * 旧排除语义（store_ids=排除门店）
     * @return array{msg:string,data:array,replay:bool}
     */
    public function saveAdminExcludes(int $orgAdminId, array $storeIds, array $adminInfo, array $requestCtx): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        sort($storeIds);
        $payload = ['org_admin_id' => $orgAdminId, 'store_ids' => $storeIds];
        return $this->runWrite(
            'org_save_exclude',
            'org_admin:' . $orgAdminId,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($orgAdminId, $storeIds) {
                $ret = $this->manage->applySaveAdminStoreExcludes(
                    $orgAdminId,
                    $storeIds,
                    (int)$auditMeta['operator_id'],
                    (string)$auditMeta['operator_name'],
                    $auditMeta
                );
                return [
                    'msg' => '保存成功',
                    'data' => [
                        'org_admin_id' => $orgAdminId,
                        'org_id' => (int)$ret['org_id'],
                        'store_ids' => $storeIds,
                        'changed' => (bool)$ret['changed'],
                    ],
                ];
            }
        );
    }

    /**
     * by_agent：门禁/超管/token 之前禁止查映射；锁内再解析 orgAdminId
     * @return array{msg:string,data:array,replay:bool}
     */
    public function saveAdminExcludesByAgent(int $legacyAgentId, array $storeIds, array $adminInfo, array $requestCtx): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        sort($storeIds);
        $legacyAgentId = (int)$legacyAgentId;
        $payload = ['legacy_agent_id' => $legacyAgentId, 'store_ids' => $storeIds];
        return $this->runWrite(
            'org_save_exclude_by_agent',
            'legacy_agent:' . $legacyAgentId,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($legacyAgentId, $storeIds) {
                $orgAdminId = $this->manage->resolveOrgAdminIdByLegacyAgentPublic($legacyAgentId);
                $ret = $this->manage->applySaveAdminStoreExcludes(
                    $orgAdminId,
                    $storeIds,
                    (int)$auditMeta['operator_id'],
                    (string)$auditMeta['operator_name'],
                    $auditMeta
                );
                return [
                    'msg' => '保存成功',
                    'data' => [
                        'legacy_agent_id' => $legacyAgentId,
                        'org_admin_id' => $orgAdminId,
                        'org_id' => (int)$ret['org_id'],
                        'store_ids' => $storeIds,
                        'changed' => (bool)$ret['changed'],
                    ],
                ];
            }
        );
    }

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function saveLeaders(int $orgId, array $leaders, array $adminInfo, array $requestCtx): array
    {
        $norm = [];
        foreach ($leaders as $item) {
            if (is_array($item)) {
                $eid = (int)($item['employee_id'] ?? 0);
                $sort = (int)($item['sort'] ?? 0);
            } else {
                $eid = (int)$item;
                $sort = 0;
            }
            if ($eid > 0) {
                $norm[$eid] = ['employee_id' => $eid, 'sort' => $sort];
            }
        }
        $list = array_values($norm);
        usort($list, static function ($a, $b) {
            if ($a['sort'] === $b['sort']) {
                return $a['employee_id'] <=> $b['employee_id'];
            }
            return $a['sort'] <=> $b['sort'];
        });
        $payload = ['org_id' => $orgId, 'leaders' => $list];
        return $this->runWrite(
            'org_save_leaders',
            'org:' . $orgId,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($orgId, $list) {
                $ret = $this->manage->applySaveLeaders(
                    $orgId,
                    $list,
                    (int)$auditMeta['operator_id'],
                    (string)$auditMeta['operator_name'],
                    $auditMeta
                );
                return [
                    'msg' => '保存成功',
                    'data' => [
                        'org_id' => (int)$ret['org_id'],
                        'leaders' => $ret['leaders'],
                        'leader_count' => (int)$ret['leader_count'],
                    ],
                ];
            }
        );
    }

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function saveAdminPermission(int $orgAdminId, string $scopeMode, array $allowedStoreIds, array $adminInfo, array $requestCtx): array
    {
        $allowedStoreIds = array_values(array_unique(array_filter(array_map('intval', $allowedStoreIds))));
        sort($allowedStoreIds);
        $payload = [
            'org_admin_id' => $orgAdminId,
            'scope_mode' => strtolower(trim($scopeMode)),
            'allowed_store_ids' => $allowedStoreIds,
        ];
        return $this->runWrite(
            'org_save_admin_permission',
            'org_admin:' . $orgAdminId,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($orgAdminId, $scopeMode, $allowedStoreIds) {
                $ret = $this->manage->applySaveAdminPermissionScope(
                    $orgAdminId,
                    $scopeMode,
                    $allowedStoreIds,
                    (int)$auditMeta['operator_id'],
                    (string)$auditMeta['operator_name'],
                    $auditMeta
                );
                return [
                    'msg' => '保存成功',
                    'data' => [
                        'org_admin_id' => $orgAdminId,
                        'org_id' => (int)$ret['org_id'],
                        'scope_mode' => (string)$ret['scope_mode'],
                        'allowed_store_ids' => $ret['allowed_store_ids'],
                        'excluded_store_ids' => $ret['excluded_store_ids'],
                        'changed' => (bool)$ret['changed'],
                    ],
                ];
            }
        );
    }

    /**
     * 新增组织权限授权关系（不改账号密码角色）
     * @param int[] $allowedStoreIds
     * @return array{msg:string,data:array,replay:bool}
     */
    public function grantAdmin(
        int $orgId,
        int $employeeId,
        int $adminId,
        string $scopeMode,
        array $allowedStoreIds,
        array $adminInfo,
        array $requestCtx
    ): array {
        $allowedStoreIds = array_values(array_unique(array_filter(array_map('intval', $allowedStoreIds))));
        sort($allowedStoreIds);
        $payload = [
            'org_id' => $orgId,
            'employee_id' => $employeeId,
            'admin_id' => $adminId,
            'scope_mode' => strtolower(trim($scopeMode)),
            'allowed_store_ids' => $allowedStoreIds,
        ];
        return $this->runWrite(
            'org_grant_admin',
            'org:' . $orgId,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($orgId, $employeeId, $adminId, $scopeMode, $allowedStoreIds) {
                $ret = $this->manage->applyGrantAdmin(
                    $orgId,
                    $employeeId,
                    $adminId,
                    $scopeMode,
                    $allowedStoreIds,
                    (int)$auditMeta['operator_id'],
                    (string)$auditMeta['operator_name'],
                    $auditMeta
                );
                return [
                    'msg' => !empty($ret['idempotent']) ? '已授权' : '授权成功',
                    'data' => $ret,
                ];
            }
        );
    }

    /**
     * 撤销组织权限授权关系
     * @return array{msg:string,data:array,replay:bool}
     */
    public function revokeAdminGrant(int $orgId, int $orgAdminId, array $adminInfo, array $requestCtx): array
    {
        $payload = [
            'org_id' => $orgId,
            'org_admin_id' => $orgAdminId,
        ];
        return $this->runWrite(
            'org_revoke_admin_grant',
            'org:' . $orgId,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($orgId, $orgAdminId) {
                $ret = $this->manage->applyRevokeAdminGrant(
                    $orgId,
                    $orgAdminId,
                    (int)$auditMeta['operator_id'],
                    (string)$auditMeta['operator_name'],
                    $auditMeta
                );
                return [
                    'msg' => !empty($ret['changed']) ? '已撤销' : '已撤销',
                    'data' => $ret,
                ];
            }
        );
    }

    /**
     * @param callable(array):array{msg:string,data:array} $business
     * @return array{msg:string,data:array,replay:bool}
     */
    protected function runWrite(
        string $action,
        string $scopeKey,
        array $payload,
        array $adminInfo,
        array $requestCtx,
        callable $business
    ): array {
        $this->gate->assertCanWrite();
        $super = $this->gate->assertSuperAdmin($adminInfo);
        if (!$super['ok']) {
            throw new \Exception($super['reason_text'] !== '' ? $super['reason_text'] : '无权限执行该操作');
        }

        $token = $this->resolveRequestToken($requestCtx);
        $operatorId = (int)$adminInfo['id'];
        $operatorName = (string)($adminInfo['account'] ?? '');
        $requestHash = $this->hashPayload($payload);
        // request_id 仅服务端生成，不信任客户端 X-Request-Id
        $requestId = sprintf(
            'o4-%s-%s',
            date('YmdHis'),
            substr(hash('sha256', $token . '|' . $operatorId . '|' . microtime(true) . '|' . mt_rand()), 0, 16)
        );
        $operatorIp = (string)($requestCtx['operator_ip'] ?? '');

        $result = $this->manage->withOrganizationStructureLock(function () use (
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
                    $responseJson = (string)($existing['response_json'] ?? '');
                    $decoded = json_decode($responseJson, true);
                    if (!is_array($decoded) || !isset($decoded['msg']) || !array_key_exists('data', $decoded)) {
                        throw new \Exception(self::ERR_IDEMPOTENCY_INCOMPLETE);
                    }
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
                    $responseJson = (string)($existing['response_json'] ?? '');
                    $decoded = json_decode($responseJson, true);
                    if (!is_array($decoded) || !isset($decoded['msg']) || !array_key_exists('data', $decoded)) {
                        throw new \Exception(self::ERR_IDEMPOTENCY_INCOMPLETE);
                    }
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
                    // 再读确认
                    $check = Db::name('organization_write_idempotency')->where('request_token', $token)->value('response_json');
                    if ((string)$check !== $responseJson) {
                        throw new \Exception('幂等结果写入失败');
                    }
                }

                Db::commit();
                OrganizationScopeService::clearCache();
                OrganizationScopeService::clearIntegrityCache();
                return ['msg' => $msg, 'data' => $data, 'replay' => false];
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }
        });

        return $result;
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

    public function isValidUuid(string $token): bool
    {
        return (bool)preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
            $token
        );
    }

    protected function hashPayload(array $payload): string
    {
        $normalized = $this->normalizeForHash($payload);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \Exception('请求参数规范化失败');
        }
        return hash('sha256', $json);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    protected function normalizeForHash($value)
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
     * 仅正向缓存 true；false 每次重查，避免 011 执行后同 Worker 永久认为无表
     */
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
