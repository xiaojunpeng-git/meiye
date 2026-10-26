<?php

namespace app\services\customer\care\query;

final class CustomerCareCursorCodec
{
    /** @var string */
    private $secret;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('Customer-care cursor secret must be at least 32 bytes.');
        }
        $this->secret = $secret;
    }

    public function encode(string $view, string $scopeHash, array $position): string
    {
        $payload = json_encode([
            'v' => 1,
            'view' => $view,
            'scope' => $scopeHash,
            'position' => $position,
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            throw $this->invalid();
        }
        $body = self::base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $body, $this->secret);
        return $body . '.' . $signature;
    }

    public function decode(string $cursor, string $view, string $scopeHash): array
    {
        if ($cursor === '' || strlen($cursor) > 2048 || substr_count($cursor, '.') !== 1) {
            throw $this->invalid();
        }
        [$body, $signature] = explode('.', $cursor, 2);
        $expected = hash_hmac('sha256', $body, $this->secret);
        if (!preg_match('/^[a-f0-9]{64}$/D', $signature) || !hash_equals($expected, $signature)) {
            throw $this->invalid();
        }
        try {
            $json = self::base64UrlDecode($body);
        } catch (\Throwable $throwable) {
            throw $this->invalid();
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)
            || (int)($decoded['v'] ?? 0) !== 1
            || (string)($decoded['view'] ?? '') !== $view
            || !hash_equals((string)($decoded['scope'] ?? ''), $scopeHash)
            || !is_array($decoded['position'] ?? null)) {
            throw $this->invalid();
        }
        return $decoded['position'];
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            throw new \InvalidArgumentException('invalid base64url');
        }
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (!is_string($decoded)) {
            throw new \InvalidArgumentException('invalid base64url');
        }
        return $decoded;
    }

    private function invalid(): CustomerCareProjectionException
    {
        return new CustomerCareProjectionException(
            CustomerCareProjectionErrorCode::INVALID_QUERY,
            '客情分页位置无效或已过期。',
            ['field' => 'cursor']
        );
    }
}
