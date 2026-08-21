<?php
/** Product detail reads must not acquire the catalog write lock. */

$source = file_get_contents(__DIR__ . '/../../../后端代码/app/services/product/product/StoreProductServices.php');
if (!is_string($source)) {
    fwrite(STDERR, "cannot read StoreProductServices.php\n");
    exit(1);
}

$getInfoStart = strpos($source, 'public function getInfo(int $id)');
$saveDataStart = strpos($source, 'public function saveData(int $id');
if ($getInfoStart === false || $saveDataStart === false || $saveDataStart <= $getInfoStart) {
    fwrite(STDERR, "service methods not found\n");
    exit(1);
}

$getInfo = substr($source, $getInfoStart, $saveDataStart - $getInfoStart);
$checks = [
    'detail read does not call the write-repair path' => strpos($getInfo, 'checkProductReseultAndSaveAttr(') === false,
    'detail read still loads the stored specification result' => strpos($getInfo, 'getResult([\'product_id\' => $id, \'type\' => 0])') !== false,
    'detail read has an in-memory single-spec fallback' => strpos($getInfo, '$hasStructuredResult') !== false
        && strpos($getInfo, "'spec_type'] = 0") !== false,
];

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $failed += $ok ? 0 : 1;
}
exit($failed === 0 ? 0 : 1);
