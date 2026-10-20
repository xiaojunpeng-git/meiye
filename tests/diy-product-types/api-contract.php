<?php
/** Public route contract: inspect the actual controller's service boundary without writes. */
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
class DiyTypeMemberRequest extends app\Request { public function uid() { return 0; } }
class DiyTypeProductProbe extends app\services\product\product\StoreProductServices
{
    public $captured;
    public function __construct() {}
    public function getGoodsList(array $where, int $uid, int $promotions_type = 0, bool $isEntryRules = true)
    {
        $this->captured = $where;
        return [];
    }
}
$service = new DiyTypeProductProbe();
$controller = new app\controller\api\v1\product\StoreProduct($service);
foreach ([null, [], [0], [5,6], [0,5,6]] as $selected) {
    $get=['ids'=>'11,12','brand_id'=>'7','store_label_id'=>'8'];
    if ($selected!==null) $get['catalog_types']=json_encode($selected);
    $request=new DiyTypeMemberRequest();$request->withGet($get);
    $controller->lst($request,app()->make(app\services\product\category\StoreProductCategoryServices::class));
    $where=$service->captured;
    if ($selected===null ? isset($where['product_type']) : $where['product_type']!==($selected ?: [-999])) throw new RuntimeException('Public type transport mismatch');
    if ($where['ids']!==[11,12] || $where['brand_id']!==[7] || $where['store_label_id']!==[8] || $where['show_type']!==[0,1]) throw new RuntimeException('Existing query scope changed');
}
echo json_encode(['status'=>'PASS','cases'=>['public-route-missing-empty-single-multiple','ids-brand-label-show-scope-preserved']]).PHP_EOL;
