<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$appRoot = $root . '/后端代码/app';
$helper = file_get_contents($appRoot . '/services/cashier/v3/CashierV3CheckoutWorkspaceIdentity.php');
$policy = file_get_contents($appRoot . '/services/cashier/v3/registry/CashierV3ContextPolicyRegistry.php');

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $ok) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
};

$phpFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $phpFiles[] = (string)$file;
    }
}
$allSource = '';
foreach ($phpFiles as $file) {
    $allSource .= "\n" . (string)file_get_contents($file);
}

$check(
    'workspace identity is store/state scoped and never accepts an operator argument',
    is_string($helper)
    && strpos($helper, "sprintf('ws:%d:%s'") !== false
    && strpos($helper, 'operatorId') === false
);
$check(
    'backend has no operator-bearing workspace formatter',
    !preg_match('/ws:%d:%d(?::%s)?/', $allSource)
);
$check(
    'context policy derives the canonical identity from store and state',
    is_string($policy)
    && strpos($policy, 'CashierV3CheckoutWorkspaceIdentity::id($storeId, $stateContextId)') !== false
);

echo "WORKSPACE_IDENTITY_ASSERTIONS={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
