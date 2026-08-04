<?php

namespace app\services\cashier\v3\projection;

/** Installs explicit empty capability states only for still-missing root domains. */
final class CashierV3StructuredEmptyRootPartitionModule
{
    public static function install(CashierV3RootDomainAssembler $assembler): void
    {
        $registered = array_fill_keys($assembler->registeredPartitionKeys(), true);
        foreach (CashierV3StructuredEmptyRootPartitionProvider::partitionKeys() as $key) {
            if (isset($registered[$key])) {
                continue;
            }
            $assembler->registerPartitionProvider(
                new CashierV3StructuredEmptyRootPartitionProvider($key)
            );
            $registered[$key] = true;
        }
    }
}
