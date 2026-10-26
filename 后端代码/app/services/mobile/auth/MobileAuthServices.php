<?php

declare(strict_types=1);

namespace app\services\mobile\auth;

use app\services\mobile\protocol\MobileApiException;
use app\services\mobile\protocol\MobileApiResponse;
use think\facade\Db;

/** Independent mobile SMS/App-session authority. It does not use marketplace JWT. */
final class MobileAuthServices
{
    private MobileCaptchaProvider $captcha;
    private MobileSmsProvider $sms;

    public function __construct(MobileCaptchaProvider $captcha, MobileSmsProvider $sms)
    {
        $this->captcha = $captcha;
        $this->sms = $sms;
    }

    public function createCaptcha(array $input, array $meta): array
    {
        $phone = $this->phone($input['phone'] ?? null);
        $deviceId = $this->opaque($input['deviceId'] ?? null, 'deviceId');
        $purpose = $this->purpose($input['purpose'] ?? null);
        $now = time();
        $challengeId = $this->uuid();
        Db::name('mobile_captcha_challenge')->insert([
            'challenge_id' => $challengeId, 'proof_hash' => null,
            'phone_digest' => $this->digest($phone), 'purpose' => $purpose,
            'client_session_id_hash' => $this->digest($meta['clientSessionId']),
            'installation_digest' => $this->digest($deviceId), 'state' => 'PENDING',
            'expires_at' => $now + (int)config('mobile_auth.captcha_seconds'),
            'consumed_at' => 0, 'invalidated_at' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return ['challengeId' => $challengeId, 'expiresAt' => $now + (int)config('mobile_auth.captcha_seconds')];
    }

    public function verifyCaptcha(array $input, array $meta): array
    {
        $challengeId = $this->opaque($input['challengeId'] ?? null, 'challengeId');
        $deviceId = $this->opaque($input['deviceId'] ?? null, 'deviceId');
        $providerToken = trim((string)($input['captchaProviderToken'] ?? ''));
        if ($providerToken === '') {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '缺少人机校验凭据。', 'captchaProviderToken');
        }
        $proof = $this->token();
        $now = time();
        return Db::transaction(function () use ($challengeId, $deviceId, $providerToken, $proof, $meta, $now): array {
            $row = Db::name('mobile_captcha_challenge')->where('challenge_id', $challengeId)->lock(true)->find();
            if (!$row || !hash_equals((string)$row['client_session_id_hash'], $this->digest($meta['clientSessionId']))
                || !hash_equals((string)$row['installation_digest'], $this->digest($deviceId))) {
                throw MobileApiException::protocol('CAPTCHA_PROOF_INVALID', '人机校验无效，请重新验证。', 'challengeId');
            }
            if ((int)$row['expires_at'] < $now) {
                throw MobileApiException::protocol('CAPTCHA_PROOF_EXPIRED', '人机校验已过期，请重新验证。', 'challengeId');
            }
            if ((string)$row['state'] !== 'PENDING') {
                throw MobileApiException::protocol('CAPTCHA_PROOF_INVALID', '人机校验已使用，请重新验证。', 'challengeId');
            }
            $this->captcha->verify($providerToken, $row);
            Db::name('mobile_captcha_challenge')->where('id', (int)$row['id'])->update([
                'proof_hash' => $this->digest($proof), 'state' => 'VERIFIED', 'updated_at' => $now,
            ]);
            return ['challengeId' => $challengeId, 'captchaProof' => $proof, 'expiresAt' => (int)$row['expires_at']];
        });
    }

    public function createSmsChallenge(array $input, array $meta, string $ip): array
    {
        $phone = $this->phone($input['phone'] ?? null);
        $deviceId = $this->opaque($input['deviceId'] ?? null, 'deviceId');
        $captchaProof = $this->opaque($input['captchaProof'] ?? null, 'captchaProof');
        $idempotencyKey = $this->opaque($input['idempotencyKey'] ?? null, 'idempotencyKey');
        $purpose = $this->purpose($input['purpose'] ?? null);
        $now = time();
        $phoneDigest = $this->digest($phone);
        $clientDigest = $this->digest($meta['clientSessionId']);
        $installDigest = $this->digest($deviceId);
        $captcha = Db::name('mobile_captcha_challenge')->where('proof_hash', $this->digest($captchaProof))->find();
        if (!$captcha || (string)$captcha['state'] !== 'VERIFIED' || (int)$captcha['expires_at'] < $now
            || !hash_equals((string)$captcha['phone_digest'], $phoneDigest)
            || !hash_equals((string)$captcha['client_session_id_hash'], $clientDigest)
            || !hash_equals((string)$captcha['installation_digest'], $installDigest)
            || (string)$captcha['purpose'] !== $purpose) {
            throw MobileApiException::protocol('CAPTCHA_PROOF_INVALID', '人机校验无效，请重新验证。', 'captchaProof');
        }
        $requestHash = $this->digest(implode('|', [$purpose, $phoneDigest, (string)$captcha['challenge_id'], $clientDigest]));
        $idemDigest = $this->digest($idempotencyKey);
        $existing = Db::name('mobile_sms_challenge')->where('purpose', $purpose)->where('phone_digest', $phoneDigest)
            ->where('idempotency_key_hash', $idemDigest)->find();
        if ($existing) {
            if (!hash_equals((string)$existing['request_hash'], $requestHash)) {
                throw MobileApiException::protocol('IDEMPOTENCY_KEY_CONFLICT', '本次短信请求标识已用于其他请求。', 'idempotencyKey');
            }
            return $this->smsResponse($existing, $phone, $now);
        }
        $this->assertSmsRate($phoneDigest, $installDigest, $this->digest($ip), $now);
        $code = $this->testCodeOrRandom();
        $salt = bin2hex(random_bytes(16));
        $challengeId = $this->uuid();
        Db::transaction(function () use ($captcha, $challengeId, $phoneDigest, $purpose, $clientDigest, $installDigest, $ip, $code, $salt, $requestHash, $idemDigest, $now): void {
            $lockedCaptcha = Db::name('mobile_captcha_challenge')->where('id', (int)$captcha['id'])->lock(true)->find();
            if (!$lockedCaptcha || (string)$lockedCaptcha['state'] !== 'VERIFIED') {
                throw MobileApiException::protocol('CAPTCHA_PROOF_INVALID', '人机校验已失效，请重新验证。', 'captchaProof');
            }
            Db::name('mobile_captcha_challenge')->where('id', (int)$lockedCaptcha['id'])->update(['state' => 'CONSUMED', 'consumed_at' => $now, 'updated_at' => $now]);
            Db::name('mobile_sms_challenge')->insert([
                'challenge_id' => $challengeId, 'phone_digest' => $phoneDigest, 'purpose' => $purpose,
                'captcha_challenge_id' => (string)$lockedCaptcha['challenge_id'], 'client_session_id_hash' => $clientDigest,
                'installation_digest' => $installDigest, 'ip_digest' => $this->digest($ip),
                'code_hmac' => $this->codeHmac($code, $salt), 'code_salt' => $salt, 'key_version' => (string)config('mobile_auth.hmac_key_version'),
                'request_hash' => $requestHash, 'idempotency_key_hash' => $idemDigest, 'challenge_state' => 'CREATED', 'delivery_state' => 'PENDING',
                'attempt_count' => 0, 'locked_until' => 0, 'expires_at' => $now + (int)config('mobile_auth.sms_seconds'), 'consumed_at' => 0,
                'provider_request_digest' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        });
        try {
            $delivery = $this->sms->send($phone, $code);
            Db::name('mobile_sms_challenge')->where('challenge_id', $challengeId)->update([
                'delivery_state' => $delivery['state'], 'provider_request_digest' => $delivery['requestDigest'], 'updated_at' => time(),
            ]);
        } catch (MobileApiException $exception) {
            Db::name('mobile_sms_challenge')->where('challenge_id', $challengeId)->update([
                'delivery_state' => $exception->mobileCode() === 'SMS_PROVIDER_UNKNOWN' ? 'UNKNOWN' : 'FAILED', 'updated_at' => time(),
            ]);
            throw $exception;
        }
        $row = Db::name('mobile_sms_challenge')->where('challenge_id', $challengeId)->find();
        return $this->smsResponse($row ?: [], $phone, time());
    }

    public function verifySmsChallenge(array $input, array $meta): array
    {
        $challengeId = $this->opaque($input['challengeId'] ?? null, 'challengeId');
        $code = trim((string)($input['code'] ?? ''));
        $idempotencyKey = $this->opaque($input['idempotencyKey'] ?? null, 'idempotencyKey');
        if (!preg_match('/^[0-9]{6}$/', $code)) {
            throw MobileApiException::business('SMS_CODE_INVALID', '验证码格式无效。');
        }
        $now = time();
        return Db::transaction(function () use ($challengeId, $code, $idempotencyKey, $meta, $now): array {
            $challenge = Db::name('mobile_sms_challenge')->where('challenge_id', $challengeId)->lock(true)->find();
            if (!$challenge || !hash_equals((string)$challenge['client_session_id_hash'], $this->digest($meta['clientSessionId']))
                || (int)$challenge['expires_at'] < $now || (string)$challenge['challenge_state'] !== 'CREATED') {
                throw MobileApiException::business('SMS_CHALLENGE_EXPIRED', '验证码已过期，请重新获取。');
            }
            if ((string)$challenge['delivery_state'] === 'UNKNOWN') {
                throw MobileApiException::business('SMS_PROVIDER_UNKNOWN', '短信发送结果未知，请重新获取。');
            }
            if ((string)$challenge['delivery_state'] !== 'SENT') {
                throw MobileApiException::business('SMS_PROVIDER_FAILED', '短信发送失败，请重新获取。');
            }
            if ((int)$challenge['locked_until'] > $now) {
                throw MobileApiException::business('SMS_CODE_INVALID', '验证码已锁定，请稍后重试。');
            }
            if (!hash_equals((string)$challenge['code_hmac'], $this->codeHmac($code, (string)$challenge['code_salt']))) {
                $attempts = (int)$challenge['attempt_count'] + 1;
                Db::name('mobile_sms_challenge')->where('id', (int)$challenge['id'])->update([
                    'attempt_count' => $attempts, 'locked_until' => $attempts >= 5 ? $now + 1800 : 0, 'updated_at' => $now,
                ]);
                throw MobileApiException::business('SMS_CODE_INVALID', '验证码错误。');
            }
            Db::name('mobile_sms_challenge')->where('id', (int)$challenge['id'])->update(['challenge_state' => 'VERIFIED', 'consumed_at' => $now, 'updated_at' => $now]);
            return $this->issueAppSession((string)$challenge['phone_digest'], (string)$challenge['installation_digest'], $meta, $now);
        });
    }

    private function issueAppSession(string $phoneDigest, string $installationDigest, array $meta, int $now): array
    {
        $identity = Db::name('user_phone_identity')->where('phone_digest', $phoneDigest)->lock(true)->find();
        if (!$identity || (string)$identity['state'] !== 'BOUND' || (int)$identity['bound_uid'] <= 0) {
            throw MobileApiException::business('LEGACY_PHONE_CONFLICT', '该手机号未能关联有效会员，请联系总部处理。');
        }
        $user = Db::name('user')->where('uid', (int)$identity['bound_uid'])->where('is_del', 0)->lock(true)->find();
        if (!$user) {
            throw MobileApiException::business('LEGACY_PHONE_CONFLICT', '该手机号存在历史身份冲突，请联系总部处理。');
        }
        $employeeBinding = Db::name('employee_phone_binding')->where('phone_digest', $phoneDigest)->lock(true)->find();
        $employeeId = $employeeBinding && (string)$employeeBinding['state'] === 'BOUND' ? (int)$employeeBinding['employee_id'] : 0;
        $authState = $employeeId > 0 ? Db::name('employee_mobile_auth_state')->where('employee_id', $employeeId)->lock(true)->find() : null;
        $appSessionId = $this->uuid();
        $verificationId = $this->uuid();
        $token = $this->token();
        $expiresAt = $now + (int)config('mobile_auth.app_session_seconds');
        Db::name('mobile_app_session')->insert([
            'app_session_id' => $appSessionId, 'uid' => (int)$user['uid'], 'verification_id' => $verificationId,
            'token_hash' => $this->digest($token), 'client_session_id_hash' => $this->digest($meta['clientSessionId']),
            'installation_digest' => $installationDigest, 'state' => 'ACTIVE', 'expires_at' => $expiresAt,
            'signed_out_at' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        Db::name('mobile_phone_verification')->insert([
            'verification_id' => $verificationId, 'app_session_id' => $appSessionId, 'verified_phone_digest' => $phoneDigest,
            'identity_version' => (int)$identity['identity_version'], 'employee_id_snapshot' => $employeeId ?: null,
            'employee_phone_binding_version_snapshot' => $authState ? (int)$authState['phone_binding_version'] : null,
            'method' => 'SMS', 'state' => 'ACTIVE', 'verified_at' => $now, 'expires_at' => $now + 300,
            'invalidated_at' => 0, 'merchant_consumed_at' => 0, 'merchant_consume_idempotency_hash' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $modes = ['member'];
        if ($employeeId > 0 && $authState) $modes[] = 'merchant';
        return [
            'appSession' => ['accessToken' => $token, 'expiresAt' => $expiresAt],
            'phoneVerification' => ['status' => 'ACTIVE', 'maskedPhone' => $this->maskedPhoneDigest($phoneDigest, (string)$user['phone']),
                'verifiedAt' => $now, 'method' => 'SMS', 'verificationVersion' => (string)$identity['identity_version']],
            'availableModes' => $modes,
        ];
    }

    private function assertSmsRate(string $phoneDigest, string $installationDigest, string $ipDigest, int $now): void
    {
        $sinceHour = $now - 3600;
        $sinceDay = strtotime(date('Y-m-d 00:00:00'));
        $query = Db::name('mobile_sms_challenge');
        if ((int)(clone $query)->where('phone_digest', $phoneDigest)->where('created_at', '>=', $sinceDay)->count() >= 10
            || (int)Db::name('mobile_sms_challenge')->where('installation_digest', $installationDigest)->where('created_at', '>=', $sinceHour)->count() >= 5
            || (int)Db::name('mobile_sms_challenge')->where('ip_digest', $ipDigest)->where('created_at', '>=', $sinceHour)->count() >= 30) {
            throw MobileApiException::business('SMS_TOO_FREQUENT', '短信请求过于频繁，请稍后重试。');
        }
        $latest = Db::name('mobile_sms_challenge')->where('phone_digest', $phoneDigest)->order('created_at', 'desc')->find();
        if ($latest && (int)$latest['created_at'] + 60 > $now) {
            throw MobileApiException::business('SMS_TOO_FREQUENT', '请稍后再获取验证码。');
        }
    }

    private function smsResponse(array $row, string $phone, int $now): array
    {
        return ['challengeId' => (string)$row['challenge_id'], 'maskedPhone' => $this->maskedPhone($phone),
            'expiresAt' => (int)$row['expires_at'], 'resendAfterSeconds' => max(0, (int)$row['created_at'] + 60 - $now)];
    }

    private function phone($value): string
    {
        $phone = trim((string)$value);
        if (!preg_match('/^1[3-9][0-9]{9}$/', $phone)) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '手机号格式不正确。', 'phone');
        return $phone;
    }
    private function purpose($value): string
    {
        if ($value !== 'APP_LOGIN') throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '不支持的短信用途。', 'purpose');
        return 'APP_LOGIN';
    }
    private function opaque($value, string $field): string
    {
        $value = trim((string)$value);
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $value)) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请求字段格式无效。', $field);
        return $value;
    }
    private function digest(string $value): string { return hash('sha256', $value); }
    private function token(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    private function uuid(): string { $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)); }
    private function sixDigits(): string { return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); }
    private function testCodeOrRandom(): string
    {
        $testCode = trim((string)config('mobile_auth.test_sms_code'));
        if ((string)config('mobile_auth.provider') === 'test' && env('APP_DEBUG', false) && preg_match('/^[0-9]{6}$/', $testCode)) {
            return $testCode;
        }
        return $this->sixDigits();
    }
    private function codeHmac(string $code, string $salt): string
    {
        $key = trim((string)config('mobile_auth.hmac_key'));
        if ($key === '') throw MobileApiException::business('SMS_PROVIDER_FAILED', '手机认证密钥尚未完成配置。');
        return hash_hmac('sha256', $salt . ':' . $code, $key);
    }
    private function maskedPhone(string $phone): string { return substr($phone, 0, 3) . '****' . substr($phone, -4); }
    private function maskedPhoneDigest(string $digest, string $phone): string { return $phone === '' ? '****' : $this->maskedPhone($phone); }
}
