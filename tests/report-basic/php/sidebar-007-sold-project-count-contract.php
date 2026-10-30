<?php
declare(strict_types=1);

require __DIR__.'/../../../后端代码/vendor/autoload.php';

use app\services\report\Sidebar007SoldProjectCountReadServices;

function checkSold(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$sales=static fn():array=>[
    ['fact_id'=>'L1','store_id'=>7,'order_id'=>'IMP007-1-sale',
        'source_line_id'=>'IMP007-1-line-11','source_document_type'=>'legacy_store_order',
        'source_type'=>'project','quantity'=>'2'],
    ['fact_id'=>'L2','store_id'=>7,'order_id'=>'IMP007-2-sale',
        'source_line_id'=>'IMP007-2-line-22','source_document_type'=>'legacy_store_order',
        'source_type'=>'card','quantity'=>'4'],
    ['fact_id'=>'N1','store_id'=>7,'order_id'=>'V3-1',
        'source_line_id'=>'line-1','source_document_type'=>'sales_order',
        'source_type'=>'card','quantity'=>'3'],
    ['fact_id'=>'N2','store_id'=>8,'order_id'=>'V3-2',
        'source_line_id'=>'line-2','source_document_type'=>'sales_order',
        'source_type'=>'product','quantity'=>'5'],
];
$coverage=static fn():array=>['complete'=>true,'gaps'=>[],
    'eligible_legacy_fact_ids'=>['L1']];
$reader=new Sidebar007SoldProjectCountReadServices($sales,$coverage);
$result=$reader->read([7,8],['start'=>'2026-10-01','end'=>'2026-10-30']);
checkSold($result['complete']&&$result['by_store']=== [7=>5,8=>0],
    '旧原购物车资格只计匹配事实；新项目/卡项数量计入，商品不计');
$gap=(new Sidebar007SoldProjectCountReadServices($sales,
    static fn():array=>['complete'=>false,'gaps'=>[['code'=>'SOURCE_MISSING']]]))
    ->read([7,8],['start'=>'2026-10-01','end'=>'2026-10-30']);
checkSold(!$gap['complete']&&$gap['by_store'][7]===null,
    '旧来源人口不完整时不得显示V3子集');
$outside=(new Sidebar007SoldProjectCountReadServices(static fn():array=>[
    ['fact_id'=>'X','store_id'=>9]],$coverage));
try{$outside->read([7,8],['start'=>'2026-10-01','end'=>'2026-10-30']);
    throw new RuntimeException('销售项目越权未拒绝');}
catch(LogicException $expected){}

echo "sidebar-007-sold-project-count-contract PASS\n";
