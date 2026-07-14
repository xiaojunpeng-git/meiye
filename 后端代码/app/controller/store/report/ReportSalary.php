<?php

namespace app\controller\store\report;
use app\controller\store\AuthController;
use app\model\salary\SalaryAgent;
use app\model\salary\SalaryField;
use app\model\salary\SalaryFreeze;
use app\model\salary\SalaryInfo;
use app\model\store\SystemStoreStaff;
use app\Request;
use app\services\other\export\ExportServices;
use app\services\report\ReportServices;
use app\services\salary\SalaryServices;
use app\services\yeji\YejiPkServices;
use mohe\services\FormBuilder as Form;
use think\facade\App;
use think\facade\Route as Url;

/**
 * Class OtherOrder
 * @package app\controller\admin\v1\order
 */
class ReportSalary extends AuthController
{
    /**
     * StoreOrder constructor.
     * @param App $app
     */
    public function __construct(App $app, SalaryServices $service)
    {
        parent::__construct($app);
        $this->services = $service;
    }
      //工资列
      public function salaryColumn(){
           $data=SalaryField::order("sort","asc")->whereFindInSet("table_ids",1)->where("is_show",1)->select();
           $columns=[];
           $keys=['date','store_name','zhiwu','xingming','zhiji'];
           foreach ($data as $nk=>$nv){
               $columnOne=[
                  'title'=>$nv['name'],
                  'minWidth'=>100
               ];
               if($nv['type'] == 1){
                   $columnOne['slot']=$nv['key'];
                   $columnOne['key']='';
               }else{
                   $columnOne['key']=$nv['key'];
                   $columnOne['slot']='';
               }
               if(in_array($nv['key'],$keys)){
                   $columnOne['fixed']="left";
               }
               $columns[]=$columnOne;
           }
           return $this->success("成功！",$columns);
      }

      //工资表
    public function salaryList(Request $request){
        $data = $request->getMore([
            ['date', ''],
            ['is_excel','']
        ]);
        $data['store_id']= (int)$this->storeId;
        if(empty($data['date'])){
            $data['date']=date("Y-m");
        }
        $result=$this->services->getList($data);
        if($data['is_excel'] == 1){
            //导出
            $data=SalaryField::order("sort","asc")->whereFindInSet("table_ids",1)->where("is_show",1)->select();
            $header=[];
            $filekey = [];
            foreach ($data as $nk=>$nv){
                $header[]=$nv['name'];
                $filekey[]=$nv['key'];
            }
            $filename = '工资表导出_' . date('YmdHis', time());
            $export=[];
            foreach ($result['list'] as $kk=>$one_data){
                  $exportOne=[];
                  foreach ($filekey as $k=>$v){
                      $exportOne[$v]=$one_data[$v];
                  }
                $export[]=$exportOne;
            }
            $result=compact('header', 'filekey', 'export', 'filename');
        }
        return $this->success($result);
    }

    //编辑工资
    public function setSalary(Request $request){
        $data = $request->postMore([
            ['id', ''],
            ['key',""],
            ['value',""]
        ]);
        if(!empty($data['key'])) {
            $service=app()->make(ReportServices::class);
            $salary = SalaryInfo::where('id', $data['id'])->find();
            $salaryFreeze=SalaryFreeze::where("date",$salary->date)->value("is_freeze");
            if($salaryFreeze == 1){
                return $this->fail("当月已冻结，门店不可编辑！");
            }
            $salary_info = json_decode($salary['salary_info'], true);
            $salary_info[$data['key']] = $data['value'];
            //公式
            $list=SalaryField::where("type",2)->whereFindInSet("table_ids",1)->where("info","like","%".$data['key']."%")->select();
            foreach ($list as $k=>$v){
                $salary_info[$v['key']]=$service->calculateByFormula($salary_info,$v['info']);
            }
            $salary_info=json_encode($salary_info);
            SalaryInfo::where('id', $data['id'])->update(['salary_info'=>$salary_info]);
        }
        return $this->success("成功！");
    }

    //代理列表
    public function agentList(Request $request){
        $data = $request->getMore([
            ['date', '']
        ]);
        $data['store_id']= (int)$this->storeId;
        if(empty($data['date'])){
            $data['date']=date("Y-m");
        }
        $result=$this->services->agentList($data);
        return $this->success($result);
    }

    //删除代理
    public function delAgent($id){
        SalaryAgent::where("id",$id)->delete();
        return $this->success('删除成功');
    }
    //编辑代理
    public function editAgent(Request $request)
    {
        $agent = [];
        $data = $request->getMore([
            ['id', 0],
        ]);
        $id=$data['id'] ?? 0;
        $dateAttr='';
        if ($id) {
            $agent=SalaryAgent::where("id",$id)->find();
            $dateAttr=$agent['date'];
        }
        $optionsId = function () {
            $list = SystemStoreStaff::where('store_id',$this->storeId)->where("status",1)->where("is_del",0)->select();
            $menus = [];
            foreach ($list as $menu) {
                $menus[] = ['value' => $menu['id'], 'label' => $menu['staff_name'], 'disabled' => false];
            }
            return $menus;
        };
        $field[] = Form::select('staff_id', '选择员工',$agent['staff_id'] ?? '')
            ->setOptions(Form::setOptions($optionsId))
            ->filterable(true)
            ->clearable(true);
        $field[] = Form::datePicker('date', '代班日期',$dateAttr)
            ->type('date')
            ->multiple(true)
            ->style("width:100%");
        return $this->success(create_form('保存代班记录', $field, Url::buildUrl('/report/saveAgent/' . $id), 'POST'));
    }

    //保存代理
    public function saveAgent(int $id,Request $request){
        $data = $request->postMore([
            ['staff_id', 0],
            ['date', ''],
        ]);
        $data['date']=implode(",",$data['date']);
        if(!empty($id)){
            $salaryAgent=SalaryAgent::where("id",$id)->find();
        }else{
            $salaryAgent=new SalaryAgent();
            $salaryAgent->add_time=time();
            $salaryAgent->store_id=SystemStoreStaff::where("id",$data['staff_id'])->value("store_id");
        }
        $salaryAgent->staff_id=$data['staff_id'] ?? 0;
        $salaryAgent->date=$data['date'] ?? '';
        $salaryAgent->save();
        return $this->success('保存成功');
    }

    public function getFreeze(Request $request){
        $data = $request->getMore([
            ['date', '']
        ]);
        $data['date']=strtotime($data['date']);
        $is_freeze=SalaryFreeze::where("date",$data['date'])->value("is_freeze");
        return $this->success(['is_freeze'=>$is_freeze]);
    }
}
