<?php
namespace app\services\report;

use app\services\report\query\BasicRegistrationQueryProvider;
use think\facade\Db;

/**
 * 仅新增、修改或真删除人工刷单登记，不写收银命令、资金、核销、业绩或订单事实。
 * record_version 仅用于人工记录编辑并发，绝不是结账版本或另一份收银快照。
 */
final class BasicRegistrationWriteServices
{
    /** 真删除源行；身份、门店、版本和原内容均在事务内复核，删除前快照留在独立审计表。 */
    public function delete(array $context, array $payload): array
    {
        $stores = BasicRegistrationQueryProvider::stores($context);
        if (empty($context['basic_registration_write'])) throw new \InvalidArgumentException('当前账号只能查看刷单登记');
        if (array_diff(array_keys($payload), ['id', 'store_id', 'expected_version', 'expected_source_hash', 'idempotency_key'])) {
            throw new \InvalidArgumentException('删除请求包含不允许的字段');
        }
        $id = self::integer($payload['id'] ?? 0);
        $store = self::integer($payload['store_id'] ?? 0);
        $expected = self::integer($payload['expected_version'] ?? 0);
        $sourceHash = (string)($payload['expected_source_hash'] ?? '');
        $key = (string)($payload['idempotency_key'] ?? '');
        if ($id <= 0 || $expected <= 0 || !in_array($store, $stores, true)) throw new \InvalidArgumentException('登记不存在或无权删除');
        if (!preg_match('/^[a-f0-9]{64}$/D', $sourceHash)) throw new \InvalidArgumentException('缺少原登记内容证明，请重新查询');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/D', $key)) throw new \InvalidArgumentException('删除请求标识不正确');
        $actor = (string)($context['basic_actor_key'] ?? '');
        if (!preg_match('/^(platform|store):[1-9][0-9]*$/D', $actor)) throw new \InvalidArgumentException('登记操作员身份无效');
        $fingerprint = hash('sha256', json_encode(['delete', $id, $store, $expected, $sourceHash], JSON_THROW_ON_ERROR));
        $receiptQuery = static function () use ($actor, $key) {
            return Db::name('basic_report_registration_audit')->where('actor_key', $actor)->where('idempotency_key', $key);
        };
        $replay = static function (array $receipt) use ($fingerprint, $stores): array {
            if (!hash_equals((string)$receipt['request_fingerprint'], $fingerprint)
                || !in_array((int)$receipt['store_id'], $stores, true)) throw new \InvalidArgumentException('重复请求与原删除内容不一致');
            return ['id' => (int)$receipt['registration_id'], 'record_version' => (int)$receipt['record_version'], 'replayed' => true];
        };
        $prior = $receiptQuery()->find();
        if ($prior) return $replay($prior);
        try {
            return Db::transaction(function () use ($id, $store, $expected, $sourceHash, $stores, $actor, $key, $fingerprint, $receiptQuery, $replay): array {
                $table = BasicRegistrationQueryProvider::legacyTableId();
                $before = Db::name('salary_store')->where('id', $id)->whereFindInSet('table_ids', $table)->lock(true)->find();
                $prior = $receiptQuery()->find();
                if ($prior) return $replay($prior);
                if (!$before || (int)$before['store_id'] !== $store || !in_array((int)$before['store_id'], $stores, true)) {
                    throw new \InvalidArgumentException('登记不存在或无权删除');
                }
                // 旧表允许一行挂多张人工表；有其他表引用时不能物理删掉它们的共用源行。
                if (array_values(array_filter(array_map('trim', explode(',', (string)$before['table_ids'])))) !== [(string)$table]) {
                    throw new \InvalidArgumentException('该登记同时关联其他人工表，需先解除关联后再删除');
                }
                $version = max(1, (int)Db::name('basic_report_registration_audit')->where('registration_id', $id)->max('record_version'));
                if ($expected !== $version || !hash_equals(BasicRegistrationQueryProvider::sourceHash($before), $sourceHash)) {
                    throw new \InvalidArgumentException('此登记已变化，请重新查询后再删除');
                }
                // 源行在事务中物理移除；审计保留完整原行，不能用软删后的行继续参与查询与合计。
                Db::name('salary_store')->where('id', $id)->delete();
                Db::name('basic_report_registration_audit')->insert([
                    'registration_id' => $id, 'store_id' => $store, 'record_version' => $version + 1,
                    'actor_key' => $actor, 'idempotency_key' => $key, 'request_fingerprint' => $fingerprint,
                    'before_json' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'after_json' => 'null', 'recorded_at' => time(),
                ]);
                return ['id' => $id, 'record_version' => $version + 1, 'replayed' => false];
            });
        } catch (\Throwable $exception) {
            $prior = $receiptQuery()->find();
            if ($prior) return $replay($prior);
            throw $exception;
        }
    }

    /** 行锁、编辑版本及审计在一个事务内；同一请求键只返回第一次已提交结果。 */
    public function save(array $context, array $payload): array
    {
        $stores = BasicRegistrationQueryProvider::stores($context);
        if (empty($context['basic_registration_write'])) throw new \InvalidArgumentException('当前账号只能查看刷单登记');
        $allowed = ['id', 'store_id', 'expected_version', 'expected_source_hash', 'idempotency_key', 'platform', 'registered_date', 'coupon_code', 'amount', 'registration_type'];
        if (array_diff(array_keys($payload), $allowed)) throw new \InvalidArgumentException('登记包含不允许修改的字段');
        $id = self::integer($payload['id'] ?? 0);
        $expected = self::integer($payload['expected_version'] ?? 0);
        $store = self::integer($payload['store_id'] ?? 0);
        $key = (string)($payload['idempotency_key'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/D', $key)) throw new \InvalidArgumentException('保存请求标识不正确');
        if (!in_array($store, $stores, true)) throw new \InvalidArgumentException('无权保存该门店的登记');
        $actor = (string)($context['basic_actor_key'] ?? '');
        if (!preg_match('/^(platform|store):[1-9][0-9]*$/D', $actor)) throw new \InvalidArgumentException('登记操作员身份无效');
        $values = self::values($payload);
        $sourceHash = (string)($payload['expected_source_hash'] ?? '');
        if ($id > 0 && !preg_match('/^[a-f0-9]{64}$/D', $sourceHash)) throw new \InvalidArgumentException('缺少原登记内容证明，请重新查询');
        $fingerprint = hash('sha256', json_encode([$id, $store, $expected, $sourceHash, $values], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $receiptQuery = static function () use ($actor, $key) {
            return Db::name('basic_report_registration_audit')->where('actor_key', $actor)->where('idempotency_key', $key);
        };
        $replay = static function (array $receipt) use ($fingerprint, $stores): array {
            if (!hash_equals((string)$receipt['request_fingerprint'], $fingerprint)
                || !in_array((int)$receipt['store_id'], $stores, true)) throw new \InvalidArgumentException('重复请求与原保存内容不一致');
            return ['id' => (int)$receipt['registration_id'], 'record_version' => (int)$receipt['record_version'], 'replayed' => true];
        };
        $prior = $receiptQuery()->find();
        if ($prior) return $replay($prior);
        try {
            return Db::transaction(function () use ($payload, $values, $id, $expected, $store, $stores, $actor, $key, $fingerprint, $receiptQuery, $replay): array {
                $before = null; $table = BasicRegistrationQueryProvider::legacyTableId();
                if ($id > 0) {
                    $before = Db::name('salary_store')->where('id', $id)->whereFindInSet('table_ids', $table)->lock(true)->find();
                    if (!$before || !in_array((int)$before['store_id'], $stores, true)) throw new \InvalidArgumentException('登记不存在或无权修改');
                    if ((int)$before['store_id'] !== $store) throw new \InvalidArgumentException('不能通过编辑改变登记归属门店');
                    $prior = $receiptQuery()->find();
                    if ($prior) return $replay($prior);
                    $version = max(1, (int)Db::name('basic_report_registration_audit')->where('registration_id', $id)->max('record_version'));
                    if ($expected !== $version) throw new \InvalidArgumentException('此登记已被其他操作员修改，请重新查询后再编辑');
                    // 旧入口没有审计版本：还须比较查询时的原业务内容指纹，避免被旧入口修改后静默覆盖。
                    if (!hash_equals(BasicRegistrationQueryProvider::sourceHash($before), (string)$payload['expected_source_hash'])) {
                        throw new \InvalidArgumentException('此登记原内容已变化，请重新查询后再编辑');
                    }
                    $info = json_decode((string)$before['salary_info'], true);
                    if (!is_array($info)) throw new \InvalidArgumentException('原登记内容异常，不能覆盖');
                    $version++;
                } else {
                    if ($expected !== 0) throw new \InvalidArgumentException('新增登记不能携带旧记录版本');
                    $version = 1; $info = [];
                }
                // 只替换本表已批准字段，旧 JSON 中的其他追溯信息原样保留。
                $info['shuadanpingtai'] = $values['platform'];
                $info['shuadanquanma'] = $values['coupon_code'];
                $info['shuadanjine'] = $values['amount'];
                $info['quanmabeizhu'] = $values['registration_type'];
                $date = $values['registered_date'] === '' ? 0
                    : (new \DateTimeImmutable($values['registered_date'], new \DateTimeZone('Asia/Shanghai')))->getTimestamp();
                $after = ['date' => $date, 'salary_info' => json_encode($info, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
                if ($id > 0) Db::name('salary_store')->where('id', $id)->update($after);
                else $id = (int)Db::name('salary_store')->insertGetId($after + ['store_id' => $store, 'table_ids' => (string)$table, 'staff_id' => (int)($context['basic_staff_id'] ?? 0)]);
                Db::name('basic_report_registration_audit')->insert([
                    'registration_id' => $id, 'store_id' => $store, 'record_version' => $version,
                    'actor_key' => $actor, 'idempotency_key' => $key, 'request_fingerprint' => $fingerprint,
                    'before_json' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'after_json' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'recorded_at' => time(),
                ]);
                return ['id' => $id, 'record_version' => $version, 'replayed' => false];
            });
        } catch (\Throwable $exception) {
            // 唯一键处理并发首次创建：失败事务完整回滚后，才读取已成功的那一份回执。
            $prior = $receiptQuery()->find();
            if ($prior) return $replay($prior);
            throw $exception;
        }
    }

    private static function integer($value): int
    {
        if (!is_scalar($value) || !preg_match('/^[0-9]{1,10}$/D', (string)$value)) throw new \InvalidArgumentException('登记标识或编辑版本无效');
        return (int)$value;
    }

    /** 人工登记不是收银落账；金额允许两位小数并按原十进制文本保存，日期仍严格校验。 */
    public static function values(array $payload): array
    {
        $values = [];
        foreach (['platform' => 40, 'coupon_code' => 128, 'registration_type' => 40, 'registered_date' => 10, 'amount' => 12] as $key => $limit) {
            if (!is_string($payload[$key] ?? null) && !is_int($payload[$key] ?? null)) throw new \InvalidArgumentException('登记字段格式不正确');
            $values[$key] = trim((string)$payload[$key]);
            if (mb_strlen($values[$key]) > $limit) throw new \InvalidArgumentException('登记字段过长');
        }
        if (!in_array($values['platform'], ['抖音', '大众'], true)) throw new \InvalidArgumentException('请选择有效平台');
        if (!in_array($values['registration_type'], ['门店', '探店'], true)) throw new \InvalidArgumentException('请选择有效登记类型');
        if (!preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D', $values['amount'])) throw new \InvalidArgumentException('登记金额须为非负数，最多保留两位小数');
        $date = $values['registered_date'];
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Asia/Shanghai'));
        if ($date !== '' && (!$parsed || $parsed->format('Y-m-d') !== $date)) throw new \InvalidArgumentException('核销日期不正确');
        return $values;
    }
}
