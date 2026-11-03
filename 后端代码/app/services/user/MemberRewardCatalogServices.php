<?php
declare(strict_types=1);
namespace app\services\user;
use think\facade\Db;
use think\exception\ValidateException;

/** 共享奖励目录、配置校验和总部规格映射；不含答卷、拼团状态或发奖时点逻辑。 */
final class MemberRewardCatalogServices
{
    /** 后端目录用于配置，不接受商品名匹配或把平台ID当作门店SKU。 */
    public function options(int $storeId,string $keyword=''):array
    {
        $stores=Db::name('system_store')->where('is_del',0)->order('id')->field('id,name')->select()->toArray();
        if(!$storeId)return['stores'=>$stores,'products'=>[],'coupons'=>[]];
        if(!in_array($storeId,array_map('intval',array_column($stores,'id')),true))throw new ValidateException('发放门店不可用');
        $keyword=trim($keyword);if(mb_strlen($keyword)>50)throw new ValidateException('搜索内容过长');
        $products=Db::name('store_product_attr_value')->alias('s')->join('store_product p','p.id=s.product_id')
            ->where('p.relation_id',$storeId)->where('p.is_del',0)->whereIn('p.product_type',[0,6])->where('s.type',0)->where(function($q){$q->where('p.product_type',6)->whereOr(function($p){\app\services\product\product\PhysicalProductSelectionPolicy::apply($p->where('p.type',1),false,'p.');});})
            ->when($keyword!=='',static function($q)use($keyword){$q->whereLike('p.store_name','%'.$keyword.'%');})
            ->field('s.id,p.store_name,p.product_type,s.suk')->order('s.id','desc')->limit(100)->select()->toArray();
        $coupons=Db::name('store_coupon_issue')->whereIn('relation_id',[0,$storeId])->where('is_del',0)->where('status',1)
            ->when($keyword!=='',static function($q)use($keyword){$q->whereLike('title','%'.$keyword.'%');})->field('id,title')->order('id','desc')->limit(100)->select()->toArray();
        return['stores'=>$stores,'products'=>$products,'coupons'=>$coupons];
    }
    /** 奖励配置选择总部品项目录；实际门店留到会员提交时确定。 */
    public function catalog(int $storeId,array $filters):array
    {
        $page=max(1,min(10000,(int)($filters['page']??1)));$limit=max(1,min(50,(int)($filters['limit']??20)));
        $keyword=trim((string)($filters['store_name']??''));if(mb_strlen($keyword)>50)throw new ValidateException('搜索内容过长');
        $type=(string)($filters['product_type']??'');if($type!==''&&!in_array($type,['0','6'],true))throw new ValidateException('奖励只能选择项目或实物产品');
        $status=(int)($filters['type']??1);if(!in_array($status,[1,2],true))throw new ValidateException('商品状态无效');
        $query=Db::name('store_product')->alias('p')->where('p.type',0)->where('p.relation_id',0)->where('p.is_del',0)
            ->where('p.is_verify',1)->where('p.is_show',$status===1?1:0)->whereIn('p.product_type',$type===''?[0,6]:[(int)$type]);
        // 实物只接收实体产品；目录资格与保存时的赠送校验一致，避免选中后才报不可赠。
        $query->where(function($q){$q->where('p.product_type',6)->whereOr(function($p){$p->where('p.product_type',0);});});
        if($keyword!=='')$query->where(function($q)use($keyword){$q->whereLike('p.store_name','%'.$keyword.'%')->whereOr('p.id',$keyword);});
        $category=(int)($filters['cate_id']??0);if($category>0)$query->whereRaw('FIND_IN_SET(?, p.cate_id)',[$category]);
        $count=(int)(clone $query)->count();
        $list=$query->field('p.id,p.store_name,p.product_type,p.image,p.cate_id,p.store_label_id,p.is_show')->order('p.id','desc')->page($page,$limit)->select()->toArray();
        $ids=array_map('intval',array_column($list,'id'));
        $skus=$ids?Db::name('store_product_attr_value')->whereIn('product_id',$ids)->where('type',0)->field('id,product_id,suk')->order('id','asc')->select()->toArray():[];
        $first=[];foreach($skus as$sku)if(!isset($first[(int)$sku['product_id']]))$first[(int)$sku['product_id']]=$sku;
        $categoryIds=implode(',',array_filter(array_column($list,'cate_id')));
        $categoryService=app()->make(\app\services\product\category\StoreProductCategoryServices::class);
        $categories=$categoryIds!==''?$categoryService->getCateParentAndChildName($categoryIds):[];
        $labelService=app()->make(\app\services\product\label\StoreProductLabelServices::class);
        foreach($list as&$row){
            $row['attrValue']=isset($first[(int)$row['id']])?[$first[(int)$row['id']]]:[];
            $names=$row['cate_id']?$categoryService->getCateName(explode(',',$row['cate_id']),$categories):[];
            $row['cate_name']=$names?implode(',',$names):'';
            $labels=$row['store_label_id']?$labelService->getColumn([['relation_id','=',0],['type','=',0],['id','IN',$row['store_label_id']]],'id,label_name'):[];
            $row['store_label']=$labels?implode(',',array_column($labels,'label_name')):'';
        }
        unset($row);
        return['list'=>$list,'count'=>$count];
    }
    /** 只接收奖励身份、整数数量及有效期；名称从目录重取，会员策略保存总部SKU供发放时映射。 */
    public function normalize(array $input):array
    {
        $config=['store_id'=>0,'reward_each_round'=>true,'product'=>[],'coupon'=>[]];$memberPolicy=($input['store_policy']??'')==='member';
        $storeRaw=$memberPolicy?0:($input['store_id']??0);if(!preg_match('/^(0|[1-9][0-9]{0,9})$/D',(string)$storeRaw))throw new ValidateException('奖励发放门店格式无效');$store=(int)$storeRaw;$config['store_id']=$store;
        if($memberPolicy)$config['store_policy']='member';
        if(isset($input['reward_each_round'])&&!is_bool($input['reward_each_round']))throw new ValidateException('每轮奖励选项无效');
        $config['reward_each_round']=$input['reward_each_round']??true;$seen=[];
        foreach(['product','coupon']as$kind){
            $rows=$input[$kind]??[];if(!is_array($rows)||count($rows)>20)throw new ValidateException('每类奖励最多设置20项');
            foreach($rows as$row){
                if(!is_array($row)||!preg_match('/^[1-9][0-9]?$/D',(string)($row['num']??''))||!preg_match('/^[1-9][0-9]{0,3}$/D',(string)($row['write_days']??'')))throw new ValidateException('奖励数量须为1至99的整数，有效天数须为1至3650');
                if(!preg_match('/^[1-9][0-9]{0,9}$/D',(string)($row['id']??'')))throw new ValidateException('奖励标识格式无效');$id=(int)$row['id'];$days=(int)$row['write_days'];if($days>3650||$id<=0)throw new ValidateException('奖励内容或有效期无效');
                // 旧草稿可能保存了单店SKU；转为会员归属门店规则时只按商品父ID及规格名称映射总部SKU。
                if($memberPolicy&&$kind==='product'){
                    $oldSku=Db::name('store_product_attr_value')->where('id',$id)->where('type',0)->find();
                    $oldProduct=$oldSku?Db::name('store_product')->where('id',$oldSku['product_id'])->find():null;
                    if($oldProduct&&(int)$oldProduct['type']===1){
                        $master=Db::name('store_product')->where('id',$oldProduct['pid'])->where('type',0)->where('relation_id',0)->find();
                        $masterSkus=$master?Db::name('store_product_attr_value')->where('product_id',$master['id'])->where('type',0)->where('suk',$oldSku['suk'])->limit(2)->select()->toArray():[];
                        if(count($masterSkus)!==1)throw new ValidateException('旧奖励品项没有唯一的总部对应规格，请重新选择');
                        $id=(int)$masterSkus[0]['id'];
                    }
                }
                if(isset($seen[$kind.':'.$id]))throw new ValidateException('奖励内容重复');
                if($kind==='coupon'){$record=Db::name('store_coupon_issue')->where('id',$id)->where('status',1)->where('is_del',0)->whereIn('relation_id',$memberPolicy?[0]:[0,$store])->find();$name=$record['title']??'';$productType=0;}
                else{$sku=Db::name('store_product_attr_value')->where('id',$id)->where('type',0)->find();$record=$sku?Db::name('store_product')->where('id',$sku['product_id'])->where('relation_id',$store)->where('type',$memberPolicy?0:1)->where('is_del',0)->whereIn('product_type',[0,6])->find():null;$name=$record['store_name']??'';$productType=(int)($record['product_type']??-1);if($record&&$productType===0&&!\app\services\product\product\PhysicalProductSelectionPolicy::apply(Db::name('store_product')->where('id',$record['id']),false,'')->count())$record=null;}
                if(!$record)throw new ValidateException('奖励已失效或不属于所选发放门店');
                $config[$kind][]=['id'=>$id,'product_type'=>$productType,'store_name'=>$name,'num'=>(int)$row['num'],'write_days'=>$days];$seen[$kind.':'.$id]=true;
            }
        }
        if($seen&&!$memberPolicy&&!Db::name('system_store')->where('id',$store)->where('is_del',0)->count())throw new ValidateException('请先选择奖励发放门店');
        return$config;
    }
    /** 总部SKU只作配置身份；按同源商品父ID及规格名称解析门店SKU，不能靠商品展示名猜配。 */
    public function storeSkuForMaster(int $masterSkuId,int $storeId):int
    {
        $masterSku=Db::name('store_product_attr_value')->where('id',$masterSkuId)->where('type',0)->find();
        $master=$masterSku?Db::name('store_product')->where('id',$masterSku['product_id'])->where('type',0)->where('relation_id',0)->find():null;
        $product=$master?Db::name('store_product')->where('pid',$master['id'])->where('type',1)->where('relation_id',$storeId)->where('is_del',0)->find():null;
        if(!$product)throw new ValidateException('会员归属门店没有该奖励品项，请由总部核对');
        $skuName=(string)($masterSku['suk']??'');
        $matches=Db::name('store_product_attr_value')->where('product_id',$product['id'])->where('type',0)->where('suk',$skuName)->limit(2)->select()->toArray();
        if(count($matches)!==1)throw new ValidateException('会员归属门店没有唯一对应的奖励规格，请由总部核对');
        return(int)$matches[0]['id'];
    }
}
