<?php

namespace app\services\cashier\v3\order;

/**
 * 销售订单分页游标签名器。
 *
 * 游标内容只由服务端生成；客户端只能原样回传，不能直接提交 cutoff、最大订单 ID
 * 或键集锚点。签名密钥从部署 APP_KEY 派生，测试使用显式隔离密钥。
 */
final class CashierV3SalesOrderCursorCodec
{
    private const TOKEN_PREFIX = 'c5o1';
    private const MAX_TOKEN_LENGTH = 4096;

    /** @var string 二进制 HMAC 密钥 */
    private $key;

    public function __construct(string $secret)
    {
        $secret = trim($secret);
        if (strlen($secret) < 12) {
            throw new \InvalidArgumentException('sales_order_cursor_secret_invalid');
        }
        $this->key = hash('sha256', "cashier-v3.sales-order.cursor\0" . $secret, true);
    }

    public static function production(): self
    {
        $secret = self::environmentSecret();
        if ($secret === '' && class_exists(\think\facade\Env::class)) {
            // ThinkPHP parses INI sections into dotted paths. The local formal-test
            // configuration keeps this independent secret under [APP].
            foreach ([
                'cashier_v3.order_cursor_secret',
                'app.cashier_v3_order_cursor_secret',
                'app.key',
                'app.app_key',
            ] as $name) {
                $secret = trim((string)\think\facade\Env::get($name, ''));
                if ($secret !== '') {
                    break;
                }
            }
        }
        if ($secret === '') {
            throw new \RuntimeException('sales_order_cursor_secret_unavailable');
        }
        return new self($secret);
    }

    private static function environmentSecret(): string
    {
        foreach (['CASHIER_V3_ORDER_CURSOR_SECRET', 'APP_KEY'] as $name) {
            $secret = trim((string)getenv($name));
            if ($secret !== '') {
                return $secret;
            }
        }
        return '';
    }

    public function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('sales_order_cursor_encode_failed');
        }
        $body = self::base64UrlEncode($json);
        $signature = self::base64UrlEncode(hash_hmac('sha256', $body, $this->key, true));
        return self::TOKEN_PREFIX . '.' . $body . '.' . $signature;
    }

    public function decode(string $token): array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            throw new \InvalidArgumentException('sales_order_query_cursor_invalid');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== self::TOKEN_PREFIX) {
            throw new \InvalidArgumentException('sales_order_query_cursor_invalid');
        }
        $expected = self::base64UrlEncode(hash_hmac('sha256', $parts[1], $this->key, true));
        if (!hash_equals($expected, $parts[2])) {
            throw new \InvalidArgumentException('sales_order_query_cursor_invalid');
        }
        $json = self::base64UrlDecode($parts[1]);
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('sales_order_query_cursor_invalid');
        }
        return $decoded;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new \InvalidArgumentException('sales_order_query_cursor_invalid');
        }
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (!is_string($decoded)) {
            throw new \InvalidArgumentException('sales_order_query_cursor_invalid');
        }
        return $decoded;
    }
}
