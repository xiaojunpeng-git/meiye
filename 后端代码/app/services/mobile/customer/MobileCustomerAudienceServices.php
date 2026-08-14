<?php

namespace app\services\mobile\customer;

use app\services\query\UnifiedQueryJson;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 员工自有的动态客户客群。只保存经过统一查询验证的规则，不保存成员名单。
 */
class MobileCustomerAudienceServices
{
    public const TABLE = 'mobile_customer_audience';
    public const RECEIPT_TABLE = 'mobile_customer_audience_receipt';
    public const STATE_ACTIVE = 'ACTIVE';
    public const STATE_ARCHIVED = 'ARCHIVED';

    public function list(array $identity): array
    {
        $owner = $this->owner($identity);
        return array_map(function (array $row): array {
            return $this->present($row);
        }, Db::name(self::TABLE)
            ->where('owner_employee_id', $owner['employeeId'])
            ->where('state', self::STATE_ACTIVE)
            ->order('updated_at', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray());
    }

    public function find(array $identity, int $audienceId): array
    {
        $owner = $this->owner($identity);
        if ($audienceId <= 0) {
            throw new ValidateException('客群不存在或已失效。');
        }
        $row = Db::name(self::TABLE)
            ->where('id', $audienceId)
            ->where('owner_employee_id', $owner['employeeId'])
            ->where('state', self::STATE_ACTIVE)
            ->find();
        if (!is_array($row)) {
            throw new ValidateException('客群不存在或已失效。');
        }
        return $this->present($row);
    }

    /**
     * Dynamic audience metadata plus a server-calculated member total. The
     * total is supplied by the unified member query layer so no stale member
     * snapshot is persisted.
     */
    public function overview(array $identity, int $audienceId, int $memberCount): array
    {
        $audience = $this->find($identity, $audienceId);
        return [
            'audience' => $audience,
            'overview' => [
                'memberCount' => max(0, $memberCount),
                'membership' => 'DYNAMIC_QUERY',
                'calculatedAt' => date('c'),
                'dataScopeApplied' => true,
            ],
        ];
    }

    public function create(array $identity, array $payload): array
    {
        $owner = $this->owner($identity);
        $command = $this->command($payload, true);
        return Db::transaction(function () use ($owner, $command): array {
            $replayed = $this->replayed($owner, $command);
            if ($replayed !== null) {
                return $replayed;
            }
            $now = time();
            $audienceId = (int)Db::name(self::TABLE)->insertGetId([
                'owner_employee_id' => $owner['employeeId'],
                'owner_account_id' => $owner['accountId'],
                'name' => $command['name'],
                'rule_payload' => $command['rulePayload'],
                'rule_digest' => $command['ruleDigest'],
                'state' => self::STATE_ACTIVE,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'archived_at' => 0,
            ]);
            $row = Db::name(self::TABLE)->where('id', $audienceId)->find();
            if (!is_array($row)) {
                throw new ValidateException('客群保存失败，请重试。');
            }
            $result = $this->present($row);
            $this->record($owner, $command, $audienceId, $result, $now);
            return $result;
        });
    }

    public function update(array $identity, int $audienceId, array $payload): array
    {
        $owner = $this->owner($identity);
        $command = $this->command($payload, false);
        return Db::transaction(function () use ($owner, $command, $audienceId): array {
            $replayed = $this->replayed($owner, $command);
            if ($replayed !== null) {
                return $replayed;
            }
            $row = $this->activeForUpdate($owner, $audienceId);
            $this->expectedVersion($row, $command['expectedVersion']);
            $now = time();
            Db::name(self::TABLE)->where('id', $audienceId)->update([
                'name' => $command['name'],
                'rule_payload' => $command['rulePayload'],
                'rule_digest' => $command['ruleDigest'],
                'version' => (int)$row['version'] + 1,
                'updated_at' => $now,
            ]);
            $result = $this->present((array)Db::name(self::TABLE)->where('id', $audienceId)->find());
            $this->record($owner, $command, $audienceId, $result, $now);
            return $result;
        });
    }

    public function archive(array $identity, int $audienceId, array $payload): array
    {
        $owner = $this->owner($identity);
        $command = $this->command($payload, false, true);
        return Db::transaction(function () use ($owner, $command, $audienceId): array {
            $replayed = $this->replayed($owner, $command);
            if ($replayed !== null) {
                return $replayed;
            }
            $row = $this->activeForUpdate($owner, $audienceId);
            $this->expectedVersion($row, $command['expectedVersion']);
            $now = time();
            Db::name(self::TABLE)->where('id', $audienceId)->update([
                'state' => self::STATE_ARCHIVED,
                'version' => (int)$row['version'] + 1,
                'updated_at' => $now,
                'archived_at' => $now,
            ]);
            $result = ['audienceId' => (string)$audienceId, 'archived' => true];
            $this->record($owner, $command, $audienceId, $result, $now);
            return $result;
        });
    }

    private function owner(array $identity): array
    {
        $employeeId = trim((string)($identity['employeeId'] ?? ''));
        $accountId = (int)($identity['accountId'] ?? 0);
        if ($employeeId === '' || strlen($employeeId) > 64 || $accountId <= 0) {
            throw new ValidateException('商家身份无效，请重新登录后重试。');
        }
        return ['employeeId' => $employeeId, 'accountId' => $accountId];
    }

    private function command(array $payload, bool $creating, bool $archiving = false): array
    {
        $idempotencyKey = trim((string)($payload['idempotencyKey'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $idempotencyKey)) {
            throw new ValidateException('本次操作标识无效，请重试。');
        }
        $expectedVersion = (int)($payload['expectedVersion'] ?? 0);
        if (!$creating && $expectedVersion <= 0) {
            throw new ValidateException('客群已变化，请刷新后重试。');
        }
        $name = trim((string)($payload['name'] ?? ''));
        $rule = $payload['validatedRule'] ?? null;
        if ($archiving) {
            $name = '';
            $rule = [];
        }
        if (!$archiving && ($name === '' || $this->length($name) > 64 || !is_array($rule))) {
            throw new ValidateException('客群名称或筛选条件不合法。');
        }
        if (!$archiving && ($rule['page_code'] ?? '') !== 'member_list'
            || (!$archiving && empty($rule['permission_must_be_injected_before_calculation']))) {
            throw new ValidateException('客群筛选条件未通过服务端校验。');
        }
        $rulePayload = UnifiedQueryJson::encode($rule);
        $request = [
            'operation' => $archiving ? 'ARCHIVE' : ($creating ? 'CREATE' : 'UPDATE'),
            'expectedVersion' => $expectedVersion,
            'name' => $name,
            'rulePayload' => $rulePayload,
        ];
        return [
            'operation' => $request['operation'],
            'idempotencyKey' => $idempotencyKey,
            'expectedVersion' => $expectedVersion,
            'name' => $name,
            'rulePayload' => $rulePayload,
            'ruleDigest' => hash('sha256', $rulePayload),
            'requestDigest' => hash('sha256', UnifiedQueryJson::encode($request)),
        ];
    }

	private function replayed(array $owner, array $command): ?array
	{
		try {
			Db::name(self::RECEIPT_TABLE)->insert([
				'owner_employee_id' => $owner['employeeId'],
				'idempotency_key' => $command['idempotencyKey'],
				'operation' => $command['operation'],
				'request_digest' => $command['requestDigest'],
				'audience_id' => 0,
				'result_payload' => '',
				'created_at' => time(),
			]);
			return null;
		} catch (\Throwable $exception) {
			$receipt = Db::name(self::RECEIPT_TABLE)
				->where('owner_employee_id', $owner['employeeId'])
				->where('idempotency_key', $command['idempotencyKey'])
				->lock(true)
				->find();
			if (!$receipt) {
				throw $exception;
			}
		}
		if ((string)$receipt['operation'] !== $command['operation']
            || !hash_equals((string)$receipt['request_digest'], $command['requestDigest'])) {
            throw new ValidateException('本次操作标识已用于不同请求，请重新发起。');
        }
        $result = json_decode((string)$receipt['result_payload'], true);
		if (!is_array($result) || !$result) {
			throw new ValidateException('历史操作回执损坏，请联系管理员。');
        }
        return $result;
    }

    private function activeForUpdate(array $owner, int $audienceId): array
    {
        if ($audienceId <= 0) {
            throw new ValidateException('客群不存在或已失效。');
        }
        $row = Db::name(self::TABLE)
            ->where('id', $audienceId)
            ->where('owner_employee_id', $owner['employeeId'])
            ->where('state', self::STATE_ACTIVE)
            ->lock(true)
            ->find();
        if (!is_array($row)) {
            throw new ValidateException('客群不存在或已失效。');
        }
        return $row;
    }

    private function expectedVersion(array $row, int $expectedVersion): void
    {
        if ($expectedVersion <= 0 || (int)$row['version'] !== $expectedVersion) {
            throw new ValidateException('客群已被其他操作更新，请刷新后重试。');
        }
    }

    private function record(array $owner, array $command, int $audienceId, array $result, int $now): void
    {
		$updated = Db::name(self::RECEIPT_TABLE)
			->where('owner_employee_id', $owner['employeeId'])
			->where('idempotency_key', $command['idempotencyKey'])
			->where('operation', $command['operation'])
			->where('request_digest', $command['requestDigest'])
			->where('audience_id', 0)
			->where('result_payload', '')
			->update([
				'audience_id' => $audienceId,
				'result_payload' => UnifiedQueryJson::encode($result),
			]);
		if ($updated !== 1) {
			throw new ValidateException('客群操作回执保存失败，请重试。');
		}
    }

    private function present(array $row): array
    {
        return [
            'audienceId' => (string)($row['id'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'version' => (int)($row['version'] ?? 0),
            'validatedRule' => json_decode((string)($row['rule_payload'] ?? ''), true) ?: [],
            'updatedAt' => (int)($row['updated_at'] ?? 0),
        ];
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
