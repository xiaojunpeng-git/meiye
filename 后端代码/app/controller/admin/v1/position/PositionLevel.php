<?php

namespace app\controller\admin\v1\position;
use app\controller\admin\AuthController;
use app\model\product\category\StoreProductCategory;
use app\Request;
use app\services\position\PositionYejiServices;
use app\services\product\category\StoreProductCategoryServices;
use think\facade\App;
use think\facade\Db;
use mohe\services\FormBuilder as Form;
use mohe\traits\OptionTrait;
use think\facade\Route as Url;
/**
 * Class OtherOrder
 * @package app\controller\admin\v1\order
 */
class PositionLevel extends AuthController
{
    /**
     * StoreOrder constructor.
     * @param App $app
     */
    public function __construct(App $app, PositionYejiServices $service)
    {
        parent::__construct($app);
        $this->services = $service;
    }

    /**
     * 职位列表
     * @param Request $request
     * @return mixed
     */
    public function positionList(Request $request)
    {
        $where = $request->getMore([
            ['name', ''],
            ['position_id','']
        ]);
        return app('json')->success($this->services->getList($where));
    }

    //编辑职位
    public function editPosition(Request $request)
    {
        $position = [];
        $data = $request->getMore([
            ['id', 0],
            ['position_id', 0],
        ]);
        $id=$data['id'] ?? 0;
        $positionId=$data['position_id'] ?? '';
        $cates=[];
        if ($id) {
            $position=\app\model\position\PositionYeji::where("id",$id)->find();
            $cates=explode(",",$position['cate_ids']);
             foreach ($cates as &$v){
                   $v=(int)$v;
             }
        }else{
            $position['position_id']=$positionId;
            $position['type']=0;
            $position['position_level_id']=0;
            $position['range_type']=0;
            $position['time_type']=0;
            $position['has_recharge']=1;
        }
        $options = function () {
            /** @var StoreProductCategoryServices $storeCategoryService */
            $storeCategoryService = app()->make(StoreProductCategoryServices::class);
            $list = $storeCategoryService->getTierList(1);
            $menus = [];
            foreach (sort_list_tier($list) as $menu) {
                $menus[] = ['value' => $menu['id'], 'label' => $menu['cate_name'], 'disabled' => false];
                $sub=StoreProductCategory::where("is_show",1)->where("pid",$menu['id'])->select();
                foreach ($sub as $k=>$v){
                    $menus[] = ['value' => $v['id'], 'label' => $menu['cate_name']."/".$v['cate_name'], 'disabled' => false];
                }
            }
            return $menus;
        };
        $field[] = Form::select('cate_ids', '品项分类',$cates)->multiple(true)->setOptions(Form::setOptions($options))->filterable(1)->col(12);
        $optionsId = function () {
            /** @var StoreProductCategoryServices $storeCategoryService */
            $list = \app\model\position\Position::where('status',1)->select();
            $menus = [];
            foreach ($list as $menu) {
                $menus[] = ['value' => $menu['id'], 'label' => $menu['name'], 'disabled' => false];
            }
            return $menus;
        };
        $optionsType=[
            ['value' => 1, 'label' => '现金', 'disabled' => false],
            ['value' => 2, 'label' => '手工/耗卡', 'disabled' => false],
            ['value' => 3, 'label' => '扣储值-包含当天充值', 'disabled' => false],
            ['value' => 4, 'label' => '扣储值-不包含当天充值', 'disabled' => false],
        ];
        $rangeType=[
            ['value' => 1, 'label' => '所属门店', 'disabled' => false],
            ['value' => 2, 'label' => '个人', 'disabled' => false],
        ];
        $timeType=[
            ['value' => 1, 'label' => '全部', 'disabled' => false],
            ['value' => 2, 'label' => '指定日期', 'disabled' => false],
        ];
        $has_recharge=[
            ['value' => 1, 'label' => '包含充值', 'disabled' => false],
            ['value' => 2, 'label' => '不包含充值', 'disabled' => false],
        ];
        $optionsZj = function () {
            /** @var StoreProductCategoryServices $storeCategoryService */
            $list = \app\model\position\PositionLevel::where('status',1)->select();
            $menus = [];
            foreach ($list as $menu) {
                $menus[] = ['value' => $menu['id'], 'label' => $menu['name'], 'disabled' => false];
            }
            return $menus;
        };
        $field[] = Form::select('position_id', '职位',(int)$position['position_id'] ?? '')->setOptions(Form::setOptions($optionsId))->disabled(true)->filterable(1)->col(12);
        $field[] = Form::select('type', '类型',(int)$position['type'] ?? '')->setOptions(Form::setOptions($optionsType))->filterable(1)->col(12);
        $field[] = Form::select('range_type', '业绩取值范围',(int)$position['range_type'] ?? '')->setOptions(Form::setOptions($rangeType))->filterable(1)->col(12);
        $field[] = Form::select('position_level_id', '职级',(int)$position['position_level_id'] ?? '')->setOptions(Form::setOptions($optionsZj))->clearable(true)->filterable(1)->col(12);
        $field[] = Form::select('time_type', '计算周期',(int)$position['time_type'] ?? '')->setOptions(Form::setOptions($timeType))->filterable(1)->col(12);
        $field[] = Form::input('range', '业绩区间', $position['range'] ?? '');
        $field[] = Form::input('commission', '提成比例(%)', $position['commission'] ?? '')->maxlength(10);
        $field[] = Form::select('has_recharge', '是否包含充值',(int)$position['has_recharge'] ?? '')->setOptions(Form::setOptions($has_recharge))->filterable(1)->col(12);
        $field[] = Form::switches('status', '状态：', (string)($position['status'] ?? '1'))->falseValue('0')->trueValue('1')->openStr('可用')->closeStr('禁用')->size('large');
        return $this->success(create_form('保存业绩设置', $field, Url::buildUrl('/position/savePositionLevel/' . $id), 'POST'));
    }

    public function savePosition(int $id,Request $request){
        $data = $request->postMore([
            ['position_level_id', 0],
            ['status', 0],
            ['commission', 0],
            ['position_id', 0],
            ['cate_ids',''],
            ['type',0],
            ['range_type',0],
            ['time_type',1],
            ['has_recharge',1],
            ['range',''],
        ]);
        if(!empty($id)){
            $position=\app\model\position\PositionYeji::where("id",$id)->find();
        }else{
            $position=new \app\model\position\PositionYeji();
        }
        $position->position_level_id=$data['position_level_id'];
        $position->status=$data['status'];
        $position->commission=$data['commission'];
        $position->position_id=$data['position_id'];
        $position->range=$data['range'];
        $position->type=$data['type'];
        $position->has_recharge=$data['has_recharge'];
        $position->time_type=$data['time_type'];
        $position->range_type=$data['range_type'];
        $position->cate_ids=implode(",",$data['cate_ids']);
        $position->save();
        return $this->success('保存成功');
    }

    public function delPosition($id){
         \app\model\position\PositionYeji::where("id",$id)->delete();
        return $this->success('删除成功');
    }

    /**
     * 修改状态
     * @param $id
     * @param $status
     * @return mixed
     */
    public function set_status($id, $status)
    {
        if ($status == '' || $id == 0) {
            return $this->fail('参数错误');
        }
        $this->services->update($id, ['status' => $status]);
        return $this->success($status == 0 ? '禁用成功' : '开启成功');
    }
}
