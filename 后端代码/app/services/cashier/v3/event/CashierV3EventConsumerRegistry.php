<?php

namespace app\services\cashier\v3\event;

/**
 * Immutable catalog of Outbox consumers known to the production composition.
 */
final class CashierV3EventConsumerRegistry
{
    /** @var array<string,callable> */
    private $consumers = [];

    /** @var bool */
    private $frozen = false;

    /** @param array<string,callable> $consumers */
    public function __construct(array $consumers = [])
    {
        foreach ($consumers as $consumerCode => $consumer) {
            $this->register((string)$consumerCode, $consumer);
        }
    }

    public function register(string $consumerCode, callable $consumer): void
    {
        if ($this->frozen) {
            throw new \LogicException('event consumer registry 已 freeze');
        }
        $consumerCode = self::normalizeCode($consumerCode);
        if (isset($this->consumers[$consumerCode])) {
            throw new \LogicException(sprintf('event consumer %s 重复注册', $consumerCode));
        }
        $this->consumers[$consumerCode] = $consumer;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function has(string $consumerCode): bool
    {
        $consumerCode = trim($consumerCode);
        return $consumerCode !== ''
            && isset($this->consumers[$consumerCode])
            && is_callable($this->consumers[$consumerCode]);
    }

    public function requireConsumer(string $consumerCode): callable
    {
        if (!$this->frozen) {
            throw new \LogicException('event consumer registry 尚未 freeze');
        }
        $consumerCode = trim($consumerCode);
        if (!$this->has($consumerCode)) {
            throw new \LogicException(sprintf('event consumer %s 未注册真实实现', $consumerCode));
        }
        return $this->consumers[$consumerCode];
    }

    /** @return string[] */
    public function codes(): array
    {
        $codes = array_keys($this->consumers);
        sort($codes, SORT_STRING);
        return $codes;
    }

    /** @return string[] */
    public function missingForContract(array $contract): array
    {
        $missing = [];
        foreach ((array)($contract['consumers'] ?? []) as $consumerCodes) {
            foreach ((array)$consumerCodes as $consumerCode) {
                $consumerCode = trim((string)$consumerCode);
                if ($consumerCode !== '' && !$this->has($consumerCode)) {
                    $missing[$consumerCode] = $consumerCode;
                }
            }
        }
        $missing = array_values($missing);
        sort($missing, SORT_STRING);
        return $missing;
    }

    private static function normalizeCode(string $consumerCode): string
    {
        $consumerCode = trim($consumerCode);
        if (!preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $consumerCode)) {
            throw new \InvalidArgumentException('consumerCode is invalid');
        }
        return $consumerCode;
    }
}
