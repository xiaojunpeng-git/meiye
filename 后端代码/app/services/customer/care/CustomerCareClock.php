<?php

namespace app\services\customer\care;

interface CustomerCareClock
{
    public function now(): int;

    public function businessDate(int $timestamp, string $timezone): string;
}
