<?php

declare(strict_types=1);

namespace app\services\mobile\customer;

use app\services\mobile\protocol\MobileApiException;
use app\services\user\CanonicalUserIdentityServices;
use app\services\user\UserServices;
use app\services\user\label\UserLabelRelationServices;
use app\services\user\level\UserLevelServices;
use mohe\services\SystemConfigService;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * Full mobile customer profile facade.
 *
 * Profile data stays in the established member rows, label relations and
 * user_extend_info definition/value model.  This facade deliberately does not
 * accept balance, card, debt, store or order fields.
 */
final class MobileCustomerProfileServices
{
    private const CREATE_COMMAND = 'CREATE_CUSTOMER_PROFILE';
    private const UPDATE_COMMAND = 'UPDATE_CUSTOMER_PROFILE';
    private const FIXED_PARAMS = ['real_name', 'sex', 'birthday', 'card_id', 'address', 'mark'];

    /** @var MobileCustomerQueryServices */
    private $customers;

    public function __construct(MobileCustomerQueryServices $customers)
    {
        $this->customers = $customers;
    }

    public function draft(array $merchant): array
    {
        return $this->projection($merchant, null);
    }

    public function detail(array $merchant, int $memberId): array
    {
        $this->customers->assertMemberVisible($merchant, $memberId);
        $user = Db::name('user')->where('uid', $memberId)->where('is_del', 0)->find();
        if (!is_array($user)) throw new ValidateException('客户不存在或已注销。');
        return $this->projection($merchant, $user);
    }

    public function create(array $merchant, array $payload): array
    {
        $normalized = $this->normalizedPayload($merchant, $payload, null);
        $idempotencyKey = (string)$payload['idempotencyKey'];
        return $this->withReceipt($merchant, self::CREATE_COMMAND, $payload, function () use ($merchant, $normalized, $idempotencyKey): array {
            $phone = $normalized['phone'];
            if (Db::name('user')->where('phone', $phone)->lock(true)->count() > 0) {
                throw MobileApiException::business('LEGACY_PHONE_CONFLICT', '该手机号已存在客户，请直接搜索并使用已有客户。');
            }
            /** @var UserServices $users */
            $users = app()->make(UserServices::class);
            $saved = $users->setUserInfo(['nickname' => $normalized['name'], 'phone' => $phone], 0, 'mobile_merchant');
            $memberId = (int)($saved->uid ?? 0);
            if ($memberId <= 0) throw MobileApiException::business('LEGACY_PHONE_CONFLICT', '客户身份创建失败，请稍后重试。');
            $this->ensureStoreRelation($memberId, (int)$merchant['storeId']);
            $this->writeProfile($merchant, $memberId, $normalized, true, $idempotencyKey);
            return $this->detail($merchant, $memberId);
        });
    }

    public function update(array $merchant, int $memberId, array $payload): array
    {
        $this->customers->assertMemberVisible($merchant, $memberId);
        $normalized = $this->normalizedPayload($merchant, $payload, $memberId);
        $idempotencyKey = (string)$payload['idempotencyKey'];
        return $this->withReceipt($merchant, self::UPDATE_COMMAND, $payload, function () use ($merchant, $memberId, $normalized, $idempotencyKey): array {
            $user = Db::name('user')->where('uid', $memberId)->where('is_del', 0)->lock(true)->find();
            if (!is_array($user)) throw new ValidateException('客户不存在或已注销。');
            $current = $this->projection($merchant, $user);
            if (!hash_equals((string)$current['profileVersion'], (string)$normalized['expectedVersion'])) {
                throw MobileApiException::business('PROFILE_VERSION_CONFLICT', '客户资料已被其他操作更新，请刷新后再保存。');
            }
            if ((string)$user['phone'] !== $normalized['phone']) {
                /** @var CanonicalUserIdentityServices $identity */
                $identity = app()->make(CanonicalUserIdentityServices::class);
                $identity->replacePhoneIdentity($memberId, $normalized['phone'], [
                    'operator_type' => 'EMPLOYEE', 'operator_id' => (int)$merchant['employeeId'], 'source' => 'MOBILE_CUSTOMER_PROFILE',
                ]);
            }
            $this->writeProfile($merchant, $memberId, $normalized, false, $idempotencyKey);
            return $this->detail($merchant, $memberId);
        });
    }

    private function projection(array $merchant, ?array $user): array
    {
        $definitions = $this->customDefinitions();
        $customValues = $this->customValues($user, $definitions);
        $memberId = (int)($user['uid'] ?? 0);
        $tagIds = $memberId > 0 ? array_map('intval', Db::name('user_label_relation')->where('uid', $memberId)->where('type', 0)->where('relation_id', 0)->column('label_id')) : [];
        $exclusive = $memberId > 0 ? Db::name('member_exclusive_service')->where('member_id', $memberId)->where('status', 1)->find() : null;
        $profile = [
            'memberId' => (string)$memberId,
            'name' => trim((string)($user['real_name'] ?? $user['nickname'] ?? '')),
            'phone' => (string)($user['phone'] ?? ''),
            'sex' => (int)($user['sex'] ?? 0),
            'birthday' => !empty($user['birthday']) ? date('Y-m-d', (int)$user['birthday']) : '',
            'idCard' => (string)($user['card_id'] ?? ''),
            'address' => (string)($user['addres'] ?? ''),
            'memberLevelId' => (int)($user['level'] ?? 0),
            'memberTagIds' => array_values(array_unique($tagIds)),
            'exclusiveServicePersonId' => (int)($exclusive['staff_id'] ?? 0),
            'note' => (string)($user['mark'] ?? ''),
            'avatar' => (string)($user['avatar'] ?? ''), 'profileFields' => $customValues,
        ];
        return [
            'schemaVersion' => $this->schemaVersion($definitions),
            'profileVersion' => $memberId > 0 ? hash('sha256', json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : '',
            'fixedFields' => [
                ['key' => 'name', 'label' => '会员姓名', 'required' => true, 'format' => 'text'],
                ['key' => 'phone', 'label' => '手机号', 'required' => true, 'format' => 'phone'],
                ['key' => 'sex', 'label' => '性别', 'required' => false, 'format' => 'radio', 'options' => [['value' => 0, 'label' => '保密'], ['value' => 1, 'label' => '男'], ['value' => 2, 'label' => '女']]],
                ['key' => 'birthday', 'label' => '生日', 'required' => false, 'format' => 'date'],
                ['key' => 'idCard', 'label' => '身份证号', 'required' => false, 'format' => 'text'],
                ['key' => 'address', 'label' => '详细地址', 'required' => false, 'format' => 'text'],
                ['key' => 'memberLevelId', 'label' => '会员等级', 'required' => false, 'format' => 'select'],
                ['key' => 'memberTagIds', 'label' => '会员标签', 'required' => false, 'format' => 'multiple'],
                ['key' => 'exclusiveServicePersonId', 'label' => '专属服务人', 'required' => false, 'format' => 'select'],
                ['key' => 'note', 'label' => '备注', 'required' => false, 'format' => 'textarea'],
            ],
            'customFields' => $definitions,
            'memberLevels' => $this->levels(), 'memberTags' => $this->tags(), 'servicePeople' => $this->servicePeople((int)$merchant['storeId']),
            'profile' => $profile + ['storeName' => (string)Db::name('system_store')->where('id', (int)$merchant['storeId'])->value('name')],
        ];
    }

    private function normalizedPayload(array $merchant, array $payload, ?int $memberId): array
    {
        $name = trim((string)($payload['name'] ?? ''));
        $phone = trim((string)($payload['phone'] ?? ''));
        if ($name === '' || mb_strlen($name) > 50) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请填写不超过 50 个字的会员姓名。', 'name');
        if (!preg_match('/^1[3-9][0-9]{9}$/D', $phone)) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请填写正确的手机号。', 'phone');
        $birthday = $this->birthday($payload['birthday'] ?? '');
        $sex = (int)($payload['sex'] ?? 0);
        if (!in_array($sex, [0, 1, 2], true)) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '性别参数无效。', 'sex');
        $custom = $this->normalizedCustomValues($payload['profileFields'] ?? [], $definitions = $this->customDefinitions());
        return [
            'name' => $name, 'phone' => $phone, 'sex' => $sex, 'birthday' => $birthday,
            'idCard' => $this->text($payload['idCard'] ?? '', 30, 'idCard'), 'address' => $this->text($payload['address'] ?? '', 200, 'address'),
            'note' => $this->text($payload['note'] ?? '', 500, 'note'), 'memberLevelId' => $this->levelId($payload['memberLevelId'] ?? 0),
            'memberTagIds' => $this->tagIds($payload['memberTagIds'] ?? []), 'exclusive' => $this->servicePerson($merchant, $payload['exclusiveServicePersonId'] ?? 0),
            'avatar' => $this->avatar($merchant, $payload['avatarUploadKey'] ?? ''), 'customValues' => $custom, 'definitions' => $definitions,
            'expectedVersion' => trim((string)($payload['expectedVersion'] ?? '')),
        ];
    }

    private function writeProfile(array $merchant, int $memberId, array $data, bool $creating, string $idempotencyKey): void
    {
        /** @var UserServices $users */
        $users = app()->make(UserServices::class);
        $extendValues = $data['customValues'];
        $extendValues['real_name'] = $data['name'];
        $extendValues['sex'] = [0 => '保密', 1 => '男', 2 => '女'][$data['sex']];
        $extendValues['birthday'] = $data['birthday'] === 0 ? '' : date('Y-m-d', $data['birthday']);
        $extendValues['card_id'] = $data['idCard']; $extendValues['address'] = $data['address']; $extendValues['mark'] = $data['note'];
        $extendInfo = $users->handelExtendInfo($extendValues);
        $update = [
            'real_name' => $data['name'], 'nickname' => $data['name'], 'sex' => $data['sex'], 'birthday' => $data['birthday'],
            'card_id' => $data['idCard'], 'addres' => $data['address'], 'mark' => $data['note'],
            'extend_info' => json_encode($extendInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'belong_store_id' => (int)$merchant['storeId'], 'last_time' => time(),
        ];
        if ($data['avatar'] !== '') $update['avatar'] = $data['avatar'];
        Db::name('user')->where('uid', $memberId)->update($update);
        app()->make(UserLevelServices::class)->setUserLevel($memberId, $data['memberLevelId']);
        app()->make(UserLabelRelationServices::class)->setUserLable([$memberId], $data['memberTagIds'], 0, 0, true);
        $this->assignExclusive($merchant, $memberId, $data['exclusive'], $creating ? 'member_create' : 'member_profile_update', $idempotencyKey);
    }

    private function customDefinitions(): array
    {
        $rows = SystemConfigService::get('user_extend_info', []);
        $result = [];
        foreach ((array)$rows as $row) {
            if (!is_array($row) || empty($row['use'])) continue;
            $key = trim((string)($row['param'] ?? $row['info'] ?? ''));
            if ($key === '' || in_array($key, self::FIXED_PARAMS, true)) continue;
            $options = [];
            foreach ((array)($row['singlearr'] ?? []) as $value => $label) $options[] = ['value' => (string)$value, 'label' => (string)$label];
            $result[] = ['key' => $key, 'label' => (string)($row['info'] ?? $key), 'required' => !empty($row['required']), 'format' => (string)($row['format'] ?? 'text'), 'placeholder' => (string)($row['tip'] ?? ''), 'options' => $options, 'sort' => (int)($row['sort'] ?? 0), 'info' => (string)($row['info'] ?? $key)];
        }
        usort($result, static function (array $a, array $b): int { return $a['sort'] <=> $b['sort']; });
        return $result;
    }

    private function avatar(array $merchant, $uploadKey): string
    {
        $key = trim((string)$uploadKey);
        if ($key === '') return '';
        $receipt = Db::name('mobile_merchant_idempotency')->where('employee_id', (int)$merchant['employeeId'])
            ->where('command_code', MobileCustomerAvatarUploadServices::COMMAND_CODE)->where('idempotency_key_hash', hash('sha256', $key))->lock(true)->find();
        if (!$receipt || (int)$receipt['expires_at'] < time()) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '头像上传回执不可用，请重新选择。', 'avatarUploadKey');
        $result = $this->decryptReceipt((string)$receipt['response_ciphertext']);
        if (!hash_equals((string)($result['avatarUploadKey'] ?? ''), $key) || trim((string)($result['avatar'] ?? '')) === '') throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '头像上传回执不可用，请重新选择。', 'avatarUploadKey');
        return (string)$result['avatar'];
    }

    private function customValues(?array $user, array $definitions): array
    {
        $raw = $user['extend_info'] ?? [];
        $rows = is_array($raw) ? $raw : json_decode((string)$raw, true);
        $stored = [];
        foreach ((array)$rows as $row) if (is_array($row)) $stored[(string)($row['info'] ?? '')] = $row['value'] ?? '';
        $values = [];
        foreach ($definitions as $field) $values[$field['key']] = $stored[$field['info']] ?? '';
        return $values;
    }

    private function normalizedCustomValues($raw, array $definitions): array
    {
        if (!is_array($raw)) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '自定义资料格式无效。', 'profileFields');
        $values = [];
        foreach ($definitions as $field) {
            $key = $field['key']; $value = $raw[$key] ?? '';
            if (is_array($value)) $value = implode(',', array_values(array_unique(array_filter(array_map('strval', $value))))); else $value = trim((string)$value);
            if ($field['required'] && ($value === '' || $value === [])) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请填写' . $field['label'] . '。', 'profileFields.' . $key);
            $values[$key] = $value;
        }
        return $values;
    }

    private function levels(): array { return array_map(static function (array $row): array { return ['value' => (int)$row['id'], 'label' => (string)$row['name']]; }, Db::name('system_user_level')->where('is_del', 0)->where('is_show', 1)->field('id,name')->order('grade', 'asc')->select()->toArray()); }
    private function tags(): array { return array_map(static function (array $row): array { return ['value' => (int)$row['id'], 'label' => (string)$row['label_name']]; }, Db::name('user_label')->where('type', 0)->where('relation_id', 0)->field('id,label_name')->order('id', 'asc')->select()->toArray()); }
    private function servicePeople(int $storeId): array { return array_map(static function (array $row): array { return ['value' => (int)$row['id'], 'label' => trim((string)($row['employee_name'] ?? '')) ?: (string)$row['staff_name']]; }, Db::name('system_store_staff')->alias('ss')->leftJoin('employee e', 'e.id=ss.employee_id')->where('ss.store_id', $storeId)->where('ss.status', 1)->where('ss.is_del', 0)->where('e.status', 1)->where('e.is_del', 0)->field('ss.id,ss.staff_name,e.name as employee_name')->order('ss.id', 'asc')->select()->toArray()); }

    private function levelId($value): int { if ($value === '' || $value === null || (int)$value === 0) return 0; $id = (int)$value; if ($id <= 0 || !Db::name('system_user_level')->where('id', $id)->where('is_del', 0)->where('is_show', 1)->lock(true)->count()) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '所选会员等级不存在或已停用。', 'memberLevelId'); return $id; }
    private function tagIds($value): array { $ids = array_values(array_unique(array_filter(array_map('intval', is_array($value) ? $value : [$value])))); if ($ids !== [] && Db::name('user_label')->whereIn('id', $ids)->where('type', 0)->where('relation_id', 0)->count() !== count($ids)) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '所选会员标签不存在或已停用。', 'memberTagIds'); return $ids; }
    private function servicePerson(array $merchant, $value): ?array { $id = (int)$value; if ($id <= 0) return null; $row = Db::name('system_store_staff')->alias('ss')->leftJoin('employee e', 'e.id=ss.employee_id')->where('ss.id', $id)->where('ss.store_id', (int)$merchant['storeId'])->where('ss.status', 1)->where('ss.is_del', 0)->where('e.status', 1)->where('e.is_del', 0)->field('ss.id,ss.employee_id,ss.store_id,ss.staff_name,e.name as employee_name')->lock(true)->find(); if (!is_array($row)) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '所选专属服务人不可用。', 'exclusiveServicePersonId'); return ['staffId' => (int)$row['id'], 'employeeId' => (int)$row['employee_id'], 'storeId' => (int)$row['store_id'], 'name' => trim((string)($row['employee_name'] ?? '')) ?: (string)$row['staff_name']]; }

    private function assignExclusive(array $merchant, int $memberId, ?array $next, string $source, string $idempotencyKey): void
    {
        $current = Db::name('member_exclusive_service')->where('member_id', $memberId)->lock(true)->find();
        $currentStaffId = (int)($current['staff_id'] ?? 0);
        $nextStaffId = (int)($next['staffId'] ?? 0);
        $currentActive = $current !== null && (int)($current['status'] ?? 0) === 1;
        if ($currentActive === ($next !== null) && $currentStaffId === $nextStaffId) return;
        $now = time(); $storeName = (string)Db::name('system_store')->where('id', (int)$merchant['storeId'])->value('name');
        if ($current) Db::name('member_exclusive_service')->where('id', (int)$current['id'])->update(['status' => 0, 'updated_at' => $now, 'version' => (int)$current['version'] + 1]);
        $changeKey = 'mobile-profile:' . $memberId . ':' . substr(hash('sha256', $idempotencyKey), 0, 40);
        if ($next !== null) Db::name('member_exclusive_service')->insert(['member_id' => $memberId, 'staff_id' => $next['staffId'], 'employee_id' => $next['employeeId'], 'store_id' => $next['storeId'], 'staff_name' => $next['name'], 'store_name' => $storeName, 'source_type' => $source, 'source_business_type' => 'member', 'source_business_id' => (string)$memberId, 'reason' => '手机端客户档案保存', 'status' => 1, 'version' => 1, 'bound_at' => $now, 'operator_id' => (int)$merchant['staffId'], 'operator_name' => (string)$merchant['staffName'], 'idempotency_key' => $changeKey, 'created_at' => $now, 'updated_at' => $now]);
        Db::name('member_exclusive_service_change')->insert([
            'change_key' => $changeKey, 'member_id' => $memberId,
            'previous_staff_id' => $currentStaffId, 'previous_employee_id' => (int)($current['employee_id'] ?? 0), 'previous_store_id' => (int)($current['store_id'] ?? 0),
            'previous_staff_name' => (string)($current['staff_name'] ?? ''), 'previous_store_name' => (string)($current['store_name'] ?? ''),
            'current_staff_id' => $nextStaffId, 'current_employee_id' => (int)($next['employeeId'] ?? 0), 'current_store_id' => (int)($next['storeId'] ?? 0),
            'current_staff_name' => (string)($next['name'] ?? ''), 'current_store_name' => $next === null ? '' : $storeName,
            'source_type' => $source, 'source_business_type' => 'member', 'source_business_id' => (string)$memberId, 'reason' => '手机端客户档案保存',
            'operator_id' => (int)$merchant['staffId'], 'operator_name' => (string)$merchant['staffName'], 'idempotency_key' => $changeKey,
            'occurred_at' => $now, 'recorded_at' => $now, 'created_at' => $now,
        ]);
    }

    private function ensureStoreRelation(int $memberId, int $storeId): void { if (!Db::name('store_user')->where('uid', $memberId)->where('store_id', $storeId)->lock(true)->count()) Db::name('store_user')->insert(['uid' => $memberId, 'store_id' => $storeId, 'label_id' => '', 'status' => 1, 'add_time' => time()]); }
    private function birthday($value): int { $text = trim((string)$value); if ($text === '') return 0; if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请填写正确的生日日期。', 'birthday'); $time = strtotime($text . ' 00:00:00'); if ($time === false || $time > time()) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '生日不能晚于今天。', 'birthday'); return (int)$time; }
    private function text($value, int $limit, string $field): string { $text = trim((string)$value); if (mb_strlen($text) > $limit) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '字段内容过长。', $field); return $text; }
    private function schemaVersion(array $definitions): string { return hash('sha256', json_encode($definitions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }

    private function withReceipt(array $merchant, string $command, array $payload, callable $work): array
    {
        $key = trim((string)($payload['idempotencyKey'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key)) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '保存标识无效。', 'idempotencyKey');
        $request = $payload; unset($request['idempotencyKey']); $requestHash = hash('sha256', json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); $keyHash = hash('sha256', $key);
        return Db::transaction(function () use ($merchant, $command, $requestHash, $keyHash, $work): array {
            $receipt = Db::name('mobile_merchant_idempotency')->where('employee_id', (int)$merchant['employeeId'])->where('command_code', $command)->where('idempotency_key_hash', $keyHash)->lock(true)->find();
            if ($receipt) { if (!hash_equals((string)$receipt['request_hash'], $requestHash)) throw MobileApiException::protocol('IDEMPOTENCY_KEY_CONFLICT', '保存标识已用于其他资料。', 'idempotencyKey'); return $this->decryptReceipt((string)$receipt['response_ciphertext']); }
            $result = $work(); $now = time();
            Db::name('mobile_merchant_idempotency')->insert(['employee_id' => (int)$merchant['employeeId'], 'actor_employee_id' => (int)$merchant['employeeId'], 'command_code' => $command, 'idempotency_key_hash' => $keyHash, 'request_hash' => $requestHash, 'state' => 'SUCCEEDED', 'response_key_version' => (string)config('mobile_auth.hmac_key_version'), 'response_ciphertext' => $this->encryptReceipt($result), 'expires_at' => (int)($merchant['session']['expires_at'] ?? ($now + 3600)), 'created_at' => $now, 'updated_at' => $now]);
            return $result;
        });
    }

    private function encryptReceipt(array $data): string { $key = hash('sha256', (string)config('mobile_auth.hmac_key'), true); $iv = random_bytes(12); $tag = ''; $ciphertext = openssl_encrypt(json_encode($data, JSON_UNESCAPED_UNICODE), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag); if ($ciphertext === false) throw MobileApiException::business('AUTH_REQUIRED', '客户资料回执创建失败。'); return base64_encode($iv . $tag . $ciphertext); }
    private function decryptReceipt(string $ciphertext): array { $bytes = base64_decode($ciphertext, true); if ($bytes === false || strlen($bytes) < 29) throw MobileApiException::business('AUTH_REQUIRED', '客户资料回执不可用，请重新提交。'); $key = hash('sha256', (string)config('mobile_auth.hmac_key'), true); $json = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16)); $data = is_string($json) ? json_decode($json, true) : null; if (!is_array($data)) throw MobileApiException::business('AUTH_REQUIRED', '客户资料回执不可用，请重新提交。'); return $data; }
}
