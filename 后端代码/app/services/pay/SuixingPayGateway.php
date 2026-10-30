<?php
declare(strict_types=1);

namespace app\services\pay;

use think\exception\ValidateException;

/**
 * Tianque RSA transport for member mini-program payments. Merchant keys live
 * outside the source tree and are supplied per customer instance by its env.
 */
final class SuixingPayGateway
{
    /** A disabled or incomplete instance must never offer a payable method. */
    public static function ready(): bool
    {
        if ((int)sys_config('suixingpay_pay_status', 0) !== 1) return false;
        return self::credentialsReady();
    }

    /** Existing attempts still need signed query after the new-order switch is off. */
    public static function credentialsReady(): bool
    {
        foreach (['suixingpay_org_id', 'suixingpay_mno', 'suixingpay_gateway_url',
                  'suixingpay_platform_public_key', 'suixingpay_sub_appid'] as $name) {
            if (trim((string)sys_config($name, '')) === '') return false;
        }
        $path = trim((string)env('SUIXINGPAY_PRIVATE_KEY_FILE', ''));
        // Group access may be needed by the PHP worker; public readability is
        // never acceptable for a merchant signing key.
        return $path !== '' && is_file($path) && is_readable($path)
            && ((fileperms($path) & 0007) === 0);
    }

    /** Canonical form follows the official PHP demo: sorted outer keys and
     * unescaped JSON for nested request/response objects. Never sign `sign`. */
    public static function canonical(array $fields): string
    {
        unset($fields['sign']);
        ksort($fields, SORT_STRING);
        $pairs = [];
        foreach ($fields as $key => $value) {
            $pairs[] = $key . '=' . (is_array($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : (string)$value);
        }
        return implode('&', $pairs);
    }

    /** The live gateway accepts signType=RSA with SHA1 when version=1.2.
     * SHA256 probes still returned 0005 while SHA1 reached order validation. */
    public static function sign(array $fields, string $privatePem): string
    {
        if (!openssl_sign(self::canonical($fields), $signature, $privatePem, OPENSSL_ALGO_SHA1)) {
            throw new ValidateException('随行付签名失败。');
        }
        return base64_encode($signature);
    }

    /** The live production error envelopes currently verify with SHA1; keep
     * inbound verification separate from the outbound merchant algorithm. */
    public static function verify(array $fields, string $publicKey): bool
    {
        $signature = base64_decode((string)($fields['sign'] ?? ''), true);
        if ($signature === false || $signature === '') return false;
        $pem = strpos($publicKey, 'BEGIN PUBLIC KEY') !== false ? $publicKey
            : "-----BEGIN PUBLIC KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $publicKey), 64)
              . "-----END PUBLIC KEY-----\n";
        return openssl_verify(self::canonical($fields), $signature, $pem, OPENSSL_ALGO_SHA1) === 1;
    }

    /** Production error envelopes can contain only code/msg/sign. They may
     * report an error, but can never authorize settlement; success must echo
     * both identities, and any echoed identity must match the request. */
    public static function validReply(array $reply, string $publicKey, string $orgId, string $reqId): bool
    {
        if (!self::verify($reply, $publicKey)) return false;
        if (isset($reply['reqId']) && (string)$reply['reqId'] !== $reqId) return false;
        if (isset($reply['orgId']) && (string)$reply['orgId'] !== $orgId) return false;
        return (string)($reply['code'] ?? '') !== '0000'
            || ((string)($reply['reqId'] ?? '') === $reqId
                && (string)($reply['orgId'] ?? '') === $orgId);
    }

    /** Send a signed request and verify the complete provider envelope. */
    public function request(string $uri, array $data): array
    {
        if (!self::credentialsReady()) throw new ValidateException('随行付配置尚未完成。');
        $base = rtrim((string)sys_config('suixingpay_gateway_url'), '/');
        $host = (string)parse_url($base, PHP_URL_HOST);
        if (parse_url($base, PHP_URL_SCHEME) !== 'https'
            || !preg_match('/(^|\.)(tianquetech\.com|suixingpay\.com)$/D', $host)) {
            throw new ValidateException('随行付接口地址无效。');
        }
        // The common-envelope contract defines a 14-digit China local time.
        // Set the zone explicitly so a CLI/worker UTC default cannot drift.
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('YmdHis');
        $fields = ['orgId' => (string)sys_config('suixingpay_org_id'), 'reqData' => $data,
            'reqId' => bin2hex(random_bytes(16)), 'signType' => 'RSA',
            'timestamp' => $timestamp, 'version' => '1.2'];
        $private = file_get_contents((string)env('SUIXINGPAY_PRIVATE_KEY_FILE'));
        if ($private === false) throw new ValidateException('随行付私钥不可读取。');
        $fields['sign'] = self::sign($fields, $private);
        // JSON_THROW_ON_ERROR prevents invalid UTF-8 from silently signing a
        // different reqData string than the bytes sent to the provider.
        $body = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $curl = curl_init($base . $uri);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'], CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $raw = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($raw === false || $status !== 200) throw new ValidateException('随行付响应暂不可确认，请稍后查询订单。');
        $reply = json_decode($raw, true);
        if (!is_array($reply) || !self::validReply($reply, (string)sys_config('suixingpay_platform_public_key'),
            $fields['orgId'], $fields['reqId'])) {
            throw new ValidateException('随行付响应验签失败。');
        }
        return $reply;
    }

    /** The provider amount is yuan; the cashier authority remains integer cents. */
    public static function cents(string $amount): int
    {
        if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $amount)) throw new ValidateException('支付金额格式无效。');
        $cents = (int)bcmul($amount, '100', 0);
        if ($cents % 100 !== 0) throw new ValidateException('支付金额必须为整数元。');
        return $cents;
    }
}
