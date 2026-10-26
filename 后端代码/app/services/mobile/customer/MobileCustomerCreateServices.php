<?php

declare(strict_types=1);

namespace app\services\mobile\customer;

use app\services\mobile\protocol\MobileApiException;
use app\services\report\StoreUnifiedReportPhaseThreeFoundationServices;
use app\services\user\UserServices;
use think\facade\Db;

/** Creates one member in the active mobile merchant store without legacy tokens. */
final class MobileCustomerCreateServices
{
    private const COMMAND_CODE = 'CREATE_CUSTOMER';

    public function create(array $merchant, array $payload): array
    {
        $name = trim((string)($payload['name'] ?? ''));
        $phone = trim((string)($payload['phone'] ?? ''));
        $key = trim((string)($payload['idempotencyKey'] ?? ''));
        if ($name === '' || mb_strlen($name) > 30) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请填写不超过 30 个字的客户姓名。', 'name');
        }
        if (!preg_match('/^1[3-9][0-9]{9}$/D', $phone)) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请填写正确的手机号。', 'phone');
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key)) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '新建客户请求标识无效。', 'idempotencyKey');
        }

        $employeeId = (int)$merchant['employeeId'];
        $storeId = (int)$merchant['storeId'];
        $requestHash = hash('sha256', json_encode([
            'storeId' => $storeId, 'name' => $name, 'phone' => $phone,
        ], JSON_UNESCAPED_UNICODE));
        $keyHash = hash('sha256', $key);

        return Db::transaction(function () use ($merchant, $employeeId, $storeId, $name, $phone, $requestHash, $keyHash): array {
            $receipt = Db::name('mobile_merchant_idempotency')->where('employee_id', $employeeId)
                ->where('command_code', self::COMMAND_CODE)->where('idempotency_key_hash', $keyHash)->lock(true)->find();
            if ($receipt) {
                if (!hash_equals((string)$receipt['request_hash'], $requestHash)) {
                    throw MobileApiException::protocol('IDEMPOTENCY_KEY_CONFLICT', '本次新建客户标识已用于其他内容。', 'idempotencyKey');
                }
                return $this->decryptReceipt((string)$receipt['response_ciphertext']);
            }

            if (Db::name('user')->where('phone', $phone)->lock(true)->find()) {
                throw MobileApiException::business('LEGACY_PHONE_CONFLICT', '该手机号已存在客户，请直接搜索并使用已有客户。');
            }
            /** @var UserServices $users */
            $users = app()->make(UserServices::class);
            // setUserInfo owns CanonicalUserIdentityServices; never write user.phone directly here.
            $user = $users->setUserInfo(['nickname' => $name, 'phone' => $phone], 0, 'mobile_merchant');
            $uid = (int)($user->uid ?? 0);
            if ($uid <= 0) {
                throw MobileApiException::business('LEGACY_PHONE_CONFLICT', '客户身份创建失败，请稍后重试。');
            }
            $now = time();
            Db::name('user')->where('uid', $uid)->update([
                'real_name' => $name, 'belong_store_id' => $storeId, 'last_time' => $now,
            ]);
            $storeUser = Db::name('store_user')->where('uid', $uid)->where('store_id', $storeId)->lock(true)->find();
            if (!$storeUser && (int)Db::name('store_user')->insert([
                'uid' => $uid, 'store_id' => $storeId, 'label_id' => '', 'status' => 1, 'add_time' => $now,
            ]) !== 1) {
                throw MobileApiException::business('LEGACY_PHONE_CONFLICT', '客户门店关系创建失败，请稍后重试。');
            }
            (new StoreUnifiedReportPhaseThreeFoundationServices())->recordMemberStoreAssignmentInTx(
                ['tenant_id' => '0'],
                [
                    'member_id' => $uid,
                    'assigned' => true,
                    'store_id' => $storeId,
                    'effective_at' => $now,
                    'source_type' => 'MOBILE_CUSTOMER_CREATE',
                    'source_event_id' => (string)$uid,
                    'idempotency_key' => 'phase3-member-store:mobile:' . $uid,
                ]
            );
            $storeName = (string)Db::name('system_store')->where('id', $storeId)->value('name');
            $result = ['customer' => [
                'id' => (string)$uid, 'name' => $name, 'phone' => $phone,
                'level' => '普通会员', 'storeName' => $storeName,
            ]];
            Db::name('mobile_merchant_idempotency')->insert([
                'employee_id' => $employeeId, 'actor_employee_id' => $employeeId, 'command_code' => self::COMMAND_CODE,
                'idempotency_key_hash' => $keyHash, 'request_hash' => $requestHash, 'state' => 'SUCCEEDED',
                'response_key_version' => (string)config('mobile_auth.hmac_key_version'),
                'response_ciphertext' => $this->encryptReceipt($result),
                'expires_at' => (int)($merchant['session']['expires_at'] ?? ($now + 3600)), 'created_at' => $now, 'updated_at' => $now,
            ]);
            return $result;
        });
    }

    private function encryptReceipt(array $data): string
    {
        $key = hash('sha256', (string)config('mobile_auth.hmac_key'), true);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(json_encode($data, JSON_UNESCAPED_UNICODE), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) throw MobileApiException::business('AUTH_REQUIRED', '新建客户回执创建失败。');
        return base64_encode($iv . $tag . $ciphertext);
    }

    private function decryptReceipt(string $ciphertext): array
    {
        $bytes = base64_decode($ciphertext, true);
        if ($bytes === false || strlen($bytes) < 29) throw MobileApiException::business('AUTH_REQUIRED', '新建客户回执不可用，请重新提交。');
        $key = hash('sha256', (string)config('mobile_auth.hmac_key'), true);
        $json = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16));
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)) throw MobileApiException::business('AUTH_REQUIRED', '新建客户回执不可用，请重新提交。');
        return $data;
    }
}
