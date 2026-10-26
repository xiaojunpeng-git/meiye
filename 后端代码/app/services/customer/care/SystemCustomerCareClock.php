<?php

namespace app\services\customer\care;

final class SystemCustomerCareClock implements CustomerCareClock
{
    public function now(): int
    {
        return time();
    }

    public function businessDate(int $timestamp, string $timezone): string
    {
        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Throwable $throwable) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::INVALID_ARGUMENT,
                '客情任务业务时区无效。',
                ['timezone' => $timezone]
            );
        }
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format('Y-m-d');
    }
}
