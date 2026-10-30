<?php
declare(strict_types=1);

require __DIR__ . '/../../../后端代码/vendor/autoload.php';

use app\services\report\Sidebar007SourceMappingReadServices;

function checkSource(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$rows = [];
for ($old = 1; $old <= 12; $old++) $rows[] = [
    'source007Id'=>$old, 'target010Id'=>$old + 17, 'name'=>'来源'.$old, 'status'=>1,
];
$audit = ['mapping_json'=>json_encode($rows, JSON_UNESCAPED_UNICODE), 'applied_at'=>1720000000];
$dictionary = [];
foreach ($rows as $row) $dictionary[] = ['id'=>$row['target010Id'], 'name'=>$row['name']];
$read = static fn():array=>$audit;
$lookup = static function(array $ids) use ($dictionary):array {
    checkSource($ids===range(18,29), 'target IDs must come from the frozen audit');
    return $dictionary;
};
$result = (new Sidebar007SourceMappingReadServices($read,$lookup))->read();
checkSource($result['complete']===true && $result['by_007_id'][1]['v3_id']===18
    && $result['by_v3_id'][29]===12, 'frozen old to new source IDs');

$missing = (new Sidebar007SourceMappingReadServices(static fn():?array=>null,
    static function():array { throw new RuntimeException('dictionary must not be read'); }))->read();
checkSource($missing['complete']===false && $missing['by_v3_id']===[], 'missing audit is a gap');

$changed = $dictionary;
$changed[0]['name']='同号猜测';
try {
    (new Sidebar007SourceMappingReadServices($read,static fn():array=>$changed))->read();
    throw new RuntimeException('drift should fail');
} catch (LogicException $expected) {
    checkSource(strpos($expected->getMessage(),'漂移')!==false, 'dictionary drift evidence');
}

echo "sidebar-007-source-mapping-read-contract PASS\n";
