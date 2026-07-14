<?php

namespace app\controller\store\report;
use app\controller\store\AuthController;
use app\dao\salary\SalaryStoreDao;
use app\model\salary\SalaryField;
use app\model\salary\SalaryStore;
use app\model\salary\SalaryTable;
use app\Request;
use app\services\report\ReportServices;
use app\services\salary\SalaryTableServices;
use think\facade\App;

/**
 * Class OtherOrder
 * @package app\controller\admin\v1\order
 */
class ReportTable extends AuthController
{
    /**
     * StoreOrder constructor.
     * @param App $app
     */
    public function __construct(App $app, SalaryTableServices $service)
    {
        parent::__construct($app);
        $this->services = $service;
    }
    public function addSalary(Request $request){
        $data = $request->postMore([
            ['table_ids',""]
        ]);
        $list=SalaryField::whereFindInSet("table_ids",$data['table_ids'])->select();
        $salary_info=[];
        foreach ($list as $k=>$v){
            $salary_info[$v['key']]='';
        }
        $salary_info=json_encode($salary_info);
        $save=[];
        $save['date']=strtotime(date("Y-m-d"));
        $save['salary_info']=$salary_info;
        $save['store_id']=$this->storeId;
        $save['table_ids']=$data['table_ids'];
        $salaryStore=new SalaryStore();
        $salaryStore->save($save);
        return $this->success("成功！");
    }
    //编辑自定义表单
    public function setSalary(Request $request){
        $data = $request->postMore([
            ['id', ''],
            ['key',""],
            ['table_ids',""],
            ['value',""]
        ]);
        if(!empty($data['key'])) {
            $service=app()->make(ReportServices::class);
            $salary = SalaryStore::where('id', $data['id'])->find();
            if($data['key'] == 'date'){
                SalaryStore::where('id', $data['id'])->update(['date'=>strtotime($data['value'])]);
            }else {
                $salary_info = json_decode($salary['salary_info'], true);
                $salary_info[$data['key']] = $data['value'];
                //公式
                $list = SalaryField::where("type", 2)->whereFindInSet("table_ids", $data['table_ids'])->where("info", "like", "%" . $data['key'] . "%")->select();
                foreach ($list as $k => $v) {
                    $salary_info[$v['key']] = $service->calculateByFormula($salary_info, $v['info']);
                }
                $salary_info = json_encode($salary_info);
                SalaryStore::where('id', $data['id'])->update(['salary_info' => $salary_info]);
            }
        }
        return $this->success("成功！");
    }
    public function delTable($id){
        if (!$id) {
            return app('json')->fail('缺少参数ID');
        }
        SalaryStore::where("id",$id)->delete();
        return app('json')->success('删除成功');
    }
    //获得表
    public function getTable(){
        $result=SalaryTable::where("status",1) ->whereFindInSet("show_type",2)->select();
        return $this->success('ok', $result ? $result->toArray() : []);
    }

    /**
     * 报表搜索栏（门店端：固定时间 + 额外自定义项）
     */
    public function selfSearch(Request $request)
    {
        $tableIds = (string)$request->get('table_ids', '');
        return $this->success($this->services->getReportSearchFields($tableIds, true));
    }
    //工资合计
    public function selfCount(Request $request){
        $data = $request->getMore([
            ['date', ''],
            ['table_ids',""]
        ]);
        $data['store_id'] = (int)$this->storeId;
        $data['salary_status']=1;
        $salaryTable=SalaryTable::where("id",$data['table_ids'])->find();
        $result=[];
        if($salaryTable['type'] == 1) {
            $result = $this->services->moneyCount($data);
        }
        if($salaryTable['type'] == 2) {
            $result = $this->services->moneyStoreCount($data);
        }
        if($salaryTable['type'] == 3) {
            $allData=$request->all();
            $allData['store_id'] = (int)$this->storeId;
            $data['product_type']=explode(",",$salaryTable['product_type']);
            $result=$this->services->productCount($data,$allData);
        }
        if($salaryTable['type'] == 4){
            //自定义表单
            $result=$this->services->selfList($data,1);
        }
        if($salaryTable['type'] == 5 && $this->services->hasSqlReportConfig((string)$data['table_ids'])){
            $requestParams = $request->param();
            $requestParams['store_id'] = (int)$this->storeId;
            $result = $this->services->sqlReportList($data, $requestParams, 1);
        }
        return $this->success($result);
    }
      //工资列
    public function selfColumn(Request $request){
        $data = $request->getMore([
            ['table_ids',""],
            ['date', ''],
        ]);
        $tableIds=$data['table_ids'];
        $type=SalaryTable::where('id',$tableIds)->value("type");
        if ((int)$type === 5 && $this->services->hasSqlReportConfig((string)$tableIds)) {
            $requestParams = $request->param();
            $requestParams['store_id'] = (int)$this->storeId;
            $params = $this->services->mergeSqlReportParams($data, $requestParams);
            $columns = $this->services->sqlReportSelfColumn((string)$tableIds, $params, [], true);
            return $this->success('成功！', $columns);
        }
        $data=SalaryField::order("sort","asc")
            ->where("is_show",1)
            ->where('type', '<>', 5)
            ->whereFindInSet("table_ids",$data['table_ids'])
            ->select();
        $columns=[];
        $keys=['store_name','zhiwu','xingming','zhiji'];
        foreach ($data as $nk=>$nv){
            $columnOne=[
                'title'=>$nv['name'],
                'minWidth'=>100,
                'input_type'=>0,
                'input_info'=>[],
            ];
            if(in_array($nv['type'],[1,3,4])){
                $columnOne['slot']=$nv['key'];
                $columnOne['key']='';
                $columnOne['input_type']=$nv['type'];
                $columnOne['input_info']=explode(",",$nv['info']);
            }else{
                $columnOne['key']=$nv['key'];
                $columnOne['slot']='';
            }
            if(in_array($nv['key'],$keys)){
                $columnOne['fixed']="left";
            }
            if(($type == 3 && !in_array($nv['key'],['pinxiangleixing','pinxiangfenlei','mingcheng'])) || $type == 5){
                $columnOne['sortable']=true;
            }
            $columns[]=$columnOne;
        }
        if($type == 4){
            //自定义表格
            $columns[]=[
                 'title'=>'操作',
                 'minWidth'=>100,
                 'slot'=>'action'
            ];
        }
        return $this->success("成功！",$columns);
    }

      //工资表
    public function selfList(Request $request,ReportServices $services){
        $data = $request->getMore([
            ['date', ''],
            ['is_excel',''],
            ['table_ids',""]
        ]);
        $data['store_id'] = (int)$this->storeId;
        $allData=$request->all();
        $allData['store_id'] = (int)$this->storeId;
        $data['salary_status']=1;
        $salaryTable=SalaryTable::where("id",$data['table_ids'])->find();
        if($salaryTable['type'] != 5) {
            $data['date'] = explode("-", $data['date']);
            $data['date'][1] = $data['date'][1] . " 23:59:59";
            $data['date'] = implode("-", $data['date']);
        }
        $name=$salaryTable['name'];
        if($salaryTable['type'] == 1){
            //员工
            $result=$this->services->getList($data);
        }
        if($salaryTable['type'] == 2){
            //门店
            $result=$this->services->storeList($data);
        }
        if($salaryTable['type'] == 3){
            //产品
            $data['product_type']=explode(",",$salaryTable['product_type']);
            $result=$this->services->productList($data,$allData);
        }
        if($salaryTable['type'] == 4){
            //自定义表单
            $result=$this->services->selfList($data);
        }
        if($salaryTable['type'] == 5){
            if ($this->services->hasSqlReportConfig((string)$data['table_ids'])) {
                $requestParams = $request->param();
                $requestParams['store_id'] = (int)$this->storeId;
                $result = $this->services->sqlReportList($data, $requestParams);
            } else {
                // 未配置 SQL 时沿用原销售分析报表
                $result = $services->storeReportList($data);
            }
        }
        $result['table_type']=$salaryTable['type'];
        if($data['is_excel'] == 1){
            $filename = $name.'导出_' . date('YmdHis', time());
            $tableIds = (string)($data['table_ids'] ?? '');
            if ((int)$salaryTable['type'] === 5 && $this->services->hasSqlReportConfig($tableIds)) {
                $exportWhere = $request->getMore([
                    ['date', ''],
                    ['table_ids', ''],
                ]);
                $exportWhere['store_id'] = (int)$this->storeId;
                $exportWhere['salary_status'] = 1;
                $requestParams = $request->param();
                $requestParams['store_id'] = (int)$this->storeId;
                $result = $this->services->sqlReportExcelExport($exportWhere, $requestParams, $filename);
            } else {
                $fields = SalaryField::order("sort","asc")->where("is_show",1)->where('type', '<>', 5)->whereFindInSet("table_ids",$tableIds)->select();
                $header=[];
                $filekey = [];
                foreach ($fields as $nk=>$nv){
                    $header[]=$nv['name'];
                    $filekey[]=$nv['key'];
                }
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
        }
        return $this->success($result);
    }
}
