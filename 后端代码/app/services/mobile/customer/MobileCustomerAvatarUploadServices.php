<?php

declare(strict_types=1);

namespace app\services\mobile\customer;

use app\services\mobile\protocol\MobileApiException;
use app\services\system\attachment\SystemAttachmentServices;
use think\Request;
use think\facade\Db;

/** Stores a merchant-session-owned avatar and returns an opaque upload receipt key. */
final class MobileCustomerAvatarUploadServices
{
    public const COMMAND_CODE = 'UPLOAD_CUSTOMER_PROFILE_AVATAR';

    public function upload(array $merchant, Request $request): array
    {
        $key = trim((string)$request->post('idempotencyKey', ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key)) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '头像上传标识无效。', 'idempotencyKey');
        }
        if (!$request->file('file')) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请选择头像图片。', 'file');
        }
        $keyHash = hash('sha256', $key);
        $requestHash = hash('sha256', 'avatar-upload:' . $keyHash);

        return Db::transaction(function () use ($merchant, $key, $keyHash, $requestHash): array {
            $receipt = Db::name('mobile_merchant_idempotency')->where('employee_id', (int)$merchant['employeeId'])
                ->where('command_code', self::COMMAND_CODE)->where('idempotency_key_hash', $keyHash)->lock(true)->find();
            if ($receipt) {
                if (!hash_equals((string)$receipt['request_hash'], $requestHash)) {
                    throw MobileApiException::protocol('IDEMPOTENCY_KEY_CONFLICT', '头像上传标识已用于其他文件。', 'idempotencyKey');
                }
                return $this->decrypt((string)$receipt['response_ciphertext']);
            }
            /** @var SystemAttachmentServices $attachments */
            $attachments = app()->make(SystemAttachmentServices::class);
            $path = $attachments->upload(0, 'file', (int)sys_config('upload_type', 1), 2);
            $avatar = path_to_url((string)$path);
            if ($avatar === '') throw MobileApiException::business('AUTH_REQUIRED', '头像上传失败，请稍后重试。');
            $result = ['avatarUploadKey' => $key, 'avatar' => $avatar];
            $now = time();
            Db::name('mobile_merchant_idempotency')->insert([
                'employee_id' => (int)$merchant['employeeId'], 'actor_employee_id' => (int)$merchant['employeeId'],
                'command_code' => self::COMMAND_CODE, 'idempotency_key_hash' => $keyHash, 'request_hash' => $requestHash,
                'state' => 'SUCCEEDED', 'response_key_version' => (string)config('mobile_auth.hmac_key_version'),
                'response_ciphertext' => $this->encrypt($result), 'expires_at' => (int)($merchant['session']['expires_at'] ?? ($now + 3600)),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            return $result;
        });
    }

    private function encrypt(array $data): string
    {
        $key = hash('sha256', (string)config('mobile_auth.hmac_key'), true); $iv = random_bytes(12); $tag = '';
        $ciphertext = openssl_encrypt(json_encode($data, JSON_UNESCAPED_UNICODE), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) throw MobileApiException::business('AUTH_REQUIRED', '头像上传回执创建失败。');
        return base64_encode($iv . $tag . $ciphertext);
    }

    private function decrypt(string $ciphertext): array
    {
        $bytes = base64_decode($ciphertext, true);
        if ($bytes === false || strlen($bytes) < 29) throw MobileApiException::business('AUTH_REQUIRED', '头像上传回执不可用，请重新选择。');
        $key = hash('sha256', (string)config('mobile_auth.hmac_key'), true);
        $json = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16));
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)) throw MobileApiException::business('AUTH_REQUIRED', '头像上传回执不可用，请重新选择。');
        return $data;
    }
}
