<?php
namespace app\services\yeji;

use app\dao\yeji\YejiCommissionDao;
use app\model\product\product\StoreProduct;
use app\model\yeji\YejiCommission;
use app\model\yeji\YejiRange;
use app\services\BaseServices;
use mohe\services\FormBuilder as Form;
use think\exception\ValidateException;
use think\facade\Db;


class YejiCommissionServices extends BaseServices
{
    public function __construct(YejiCommissionDao $dao)
    {
        $this->dao = $dao;
    }

    public function getList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, $page, $limit);
        $count = $this->dao->count($where);
        foreach ($list as $nk=>$nv) {
             $commission_json=json_decode($nv['commission_json'],true);
              foreach ($commission_json as $k=>$v){
                  $list[$nk][$k]=$v;
              }
        }
        return compact('count', 'list');
    }

    public function edit($id=0,$type = 0)
    {
        if(!empty($id)) {
            $serviceInfo = $this->dao->get($id)->toArray();
        }else{
            $serviceInfo=[];
        }
        return create_form('编辑提成', $this->createServiceForm($serviceInfo, $type), $this->url('/order/saveCommission/' . $id), 'POST');
    }

    public function saveCommission($allData,$id){
         $filed=['yeji','product_id','id'];
         $commissionJson=[];
         $data=[];
         foreach ($allData as $k=>$v){
              if(!in_array($k,$filed)){
                  $commissionJson[$k]=$v;
              }else{
                  $data[$k]=$v;
              }
         }
         $data['commission_json']=json_encode($commissionJson);
        if ($id) {//修改
            $delivery = $this->dao->get($id);
            if (!$delivery) {
                throw new ValidateException('数据不存在');
            }
            $res = $this->dao->update($id, $data);
        } else {//添加
            $res = $this->dao->save($data);
        }
        return $res->id;
    }
    /**
     * 创建配送员表单
     * @param array $formData
     * @param int $type
     * @return array
     */
    public function createServiceForm(array $formData = [], int $type = 0)
    {
        $field=[];
        $has=YejiCommission::column("product_id");
        $goods=StoreProduct::where("pid",0)->whereNotIn("id",$has)->where("product_type",6)->where("is_del",0)->select();
        $setOptionUserGroup = function () use ($goods) {
            $menus = [];
            foreach ($goods as $menu) {
                $menus[] = ['value' => $menu['id'], 'label' => $menu['store_name']];
            }
            return $menus;
        };
        $range=YejiRange::select();
        $field[] = Form::select('product_id', '选择商品',$formData['product_id'] ?? '')
            ->setOptions(Form::setOptions($setOptionUserGroup))
            ->filterable(true);
        $field[] = Form::number('yeji', '耗卡业绩', $formData['yeji'] ?? '')->style(['width' => '100%'])->required('耗卡业绩')->col(24);
        $json=$formData['commission_json'] ?? [];
        if(!empty($json)){
            $json=json_decode($json,true);
        }
        foreach ($range as $nk=>$nv){
            $rangeTxt=$nv['yeji_min']."-".$nv['yeji_max'];
            $value=$json[$rangeTxt] ?? '';
            $field[] = Form::number($rangeTxt, $rangeTxt, $value)->placeholder("请输入区间提成百分比")->style(['width' => '100%'])->info("提成百分比%")->required('区间提成');
        }
        return $field;
    }

    public function getColumn(){
        $range=YejiRange::select();
        $data=[];
        foreach ($range as $nk=>$nv){
            $rangeTxt=$nv['yeji_min']."-".$nv['yeji_max'];
            $data[]=$rangeTxt;
        }
        return $data;
    }

}
