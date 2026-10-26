<?php

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\organization\JobPositionPolicyServices;

$code = 'cashier.v3.cashier.old_card_entry';
$cases = [
    'legacy cashier parent does not grant historical card entry' => [[301001], false],
    'ordinary checkout child does not grant historical card entry' => [[301001, 301012], false],
    'old management rule number does not grant historical card entry' => [[301007, 301042], false],
    'explicit historical card child grants the route' => [[301001, 301050], true],
];
foreach ($cases as $label => [$ids, $expected]) {
    $allowed = in_array($code, JobPositionPolicyServices::storeV3FeaturesFromRuleIds($ids), true);
    if ($allowed !== $expected) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
    }
    echo "PASS {$label}\n";
}
