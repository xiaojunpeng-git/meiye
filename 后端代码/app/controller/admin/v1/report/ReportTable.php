<?php

namespace app\controller\admin\v1\report;
use app\controller\admin\AuthController;
use app\model\salary\SalaryField;
use app\model\salary\SalarySearchField;
use app\model\salary\SalaryStore;
use app\model\salary\SalaryTable;
use app\Request;
use think\exception\ValidateException;
use app\services\report\ReportServices;
use app\services\salary\SalaryServices;
use app\services\salary\SalaryTableServices;
use app\services\yeji\YejiPkServices;
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

    //获得表
    public function getTable(){
        $result=SalaryTable::where("status",1) ->whereFindInSet("show_type",1)->select();
        return $this->success("ok",$result);
    }

    /**
     * 新增自定义表格行
     */
    public function addSalary(Request $request)
    {
        $data = $request->postMore([
            ['table_ids', ''],
            ['store_id', ''],
        ]);
        if (empty($data['table_ids'])) {
            return $this->fail('缺少报表ID');
        }
        if ($data['store_id'] === '' || $data['store_id'] === null) {
            $data['store_id'] = 0;
        }
        $list = SalaryField::whereFindInSet('table_ids', $data['table_ids'])->select();
        $salary_info = [];
        foreach ($list as $v) {
            $salary_info[$v['key']] = '';
        }
        $save = [
            'date' => strtotime(date('Y-m-d')),
            'salary_info' => json_encode($salary_info),
            'store_id' => (int)$data['store_id'],
            'table_ids' => $data['table_ids'],
        ];
        $salaryStore = new SalaryStore();
        $salaryStore->save($save);
        return $this->success('成功！');
    }

    /**
     * 编辑自定义表格
     */
    public function setSalary(Request $request)
    {
        $data = $request->postMore([
            ['id', ''],
            ['key', ''],
            ['table_ids', ''],
            ['value', ''],
        ]);
        if (!empty($data['key'])) {
            $service = app()->make(ReportServices::class);
            $salary = SalaryStore::where('id', $data['id'])->find();
            if (!$salary) {
                return $this->fail('数据不存在');
            }
            if ($data['key'] == 'date') {
                SalaryStore::where('id', $data['id'])->update(['date' => strtotime($data['value'])]);
            } else {
                $salary_info = json_decode($salary['salary_info'], true);
                $salary_info[$data['key']] = $data['value'];
                $list = SalaryField::where('type', 2)->whereFindInSet('table_ids', $data['table_ids'])->where('info', 'like', '%' . $data['key'] . '%')->select();
                foreach ($list as $v) {
                    $salary_info[$v['key']] = $service->calculateByFormula($salary_info, $v['info']);
                }
                SalaryStore::where('id', $data['id'])->update(['salary_info' => json_encode($salary_info)]);
            }
        }
        return $this->success('成功！');
    }

    /**
     * 删除自定义表格行
     */
    public function delTable($id)
    {
        if (!$id) {
            return $this->fail('缺少参数ID');
        }
        SalaryStore::where('id', $id)->delete();
        return $this->success('删除成功');
    }

    /**
     * 报表搜索栏配置（前端渲染用）
     */
    public function selfSearch(Request $request)
    {
        $tableIds = (string)$request->get('table_ids', '');
        return $this->success($this->services->getReportSearchFields($tableIds));
    }

    /**
     * 搜索项列表（后台配置）
     */
    public function searchFieldList(Request $request)
    {
        $tableIds = (string)$request->get('table_ids', '');
        if ($tableIds === '') {
            return $this->success([]);
        }
        try {
            $list = SalarySearchField::where('is_show', 1)
                ->where(function ($query) use ($tableIds) {
                    $query->whereFindInSet('table_ids', $tableIds)->whereOr('table_ids', $tableIds);
                })
                ->order('sort', 'asc')
                ->select();
        } catch (\Throwable $e) {
            return $this->fail('请先执行 database/salary_search_field.sql 创建搜索项表');
        }
        return $this->success($list ? $list->toArray() : []);
    }

    /**
     * 保存搜索项
     */
    public function searchFieldSave(Request $request)
    {
        $data = $request->postMore([
            ['id', 0],
            ['key', ''],
            ['name', ''],
            ['input_type', 1],
            ['info', ''],
            ['sort', 0],
            ['is_show', 1],
            ['table_ids', ''],
        ]);
        $key = trim((string)$data['key']);
        if ($key === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
            throw new ValidateException('参数名 key 仅允许字母、数字、下划线，且不能以数字开头');
        }
        if (in_array($key, $this->services->getReservedReportSearchKeys(), true)) {
            throw new ValidateException('门店(store_id)、时间(date) 为固定搜索项，请勿重复添加');
        }
        if (in_array((int)$data['input_type'], [5, 6], true)) {
            throw new ValidateException('门店、日期区间已固定展示，自定义项请使用文本/单日/下拉');
        }
        if (trim((string)$data['name']) === '') {
            throw new ValidateException('请填写搜索项名称');
        }
        if (trim((string)$data['table_ids']) === '') {
            throw new ValidateException('缺少报表 table_ids');
        }
        $save = [
            'key' => $key,
            'name' => trim((string)$data['name']),
            'input_type' => (int)$data['input_type'],
            'info' => trim((string)$data['info']),
            'sort' => (int)$data['sort'],
            'is_show' => (int)$data['is_show'] ? 1 : 0,
            'table_ids' => (string)$data['table_ids'],
        ];
        try {
            if (!empty($data['id'])) {
                SalarySearchField::where('id', (int)$data['id'])->update($save);
            } else {
                SalarySearchField::create($save);
            }
        } catch (\Throwable $e) {
            return $this->fail('保存失败，请确认已创建 eb_salary_search_field 表');
        }
        return $this->success('保存成功');
    }

    /**
     * 删除搜索项
     */
    public function searchFieldDelete($id)
    {
        if (!(int)$id) {
            return $this->fail('缺少ID');
        }
        SalarySearchField::destroy((int)$id);
        return $this->success('删除成功');
    }
    //工资合计
    public function selfCount(Request $request){
        $data = $request->getMore([
            ['date', ''],
            ['store_id',""],
            ['table_ids',""]
        ]);
        $data['salary_status']=1;
        $salaryTable=SalaryTable::where("id",$data['table_ids'])->find();
        if($salaryTable['type'] != 5){
            $data['date']=explode("-",$data['date']);
            $data['date'][1]=$data['date'][1]." 23:59:59";
            $data['date']=implode("-",$data['date']);
        }
        $result=[];
        if($salaryTable['type'] == 1) {
            $result = $this->services->moneyCount($data);
        }
        if($salaryTable['type'] == 2) {
            $result = $this->services->moneyStoreCount($data);
        }
        if($salaryTable['type'] == 3) {
            $allData=$request->all();
            $data['product_type']=explode(",",$salaryTable['product_type']);
            $result=$this->services->productCount($data,$allData);
        }
        if($salaryTable['type'] == 4){
            //自定义表单
            $result=$this->services->selfList($data,1);
        }
        if($salaryTable['type'] == 5){
            $result = $this->services->sqlReportList($data, $request->param(), 1);
        }
        return $this->success($result);
    }
      //工资列
    public function selfColumn(Request $request){
        $data = $request->getMore([
            ['table_ids',""],
            ['date', ''],
            ['store_id', ''],
        ]);
        $tableIds=$data['table_ids'];
        $type=SalaryTable::where('id',$tableIds)->value("type");
        if ((int)$type === 5 && $this->services->hasSqlReportConfig((string)$tableIds)) {
            $params = $this->services->mergeSqlReportParams($data, $request->param());
            $columns = $this->services->sqlReportSelfColumn((string)$tableIds, $params, [], false);
            return $this->success('成功！', $columns);
        }
        $data=SalaryField::order("sort","asc")
            ->where("is_show",1)
            ->where('type', '<>', 5)
            ->whereFindInSet("table_ids",$data['table_ids'])
            ->select();
        $columns=[];
        $keys=['date','store_name','zhiwu','xingming','zhiji'];
        foreach ($data as $nk=>$nv){
            $columnOne=[
                'title'=>$nv['name'],
                'minWidth'=>100,
                'input_type'=>0,
                'input_info'=>[],
            ];
            if (in_array((int)$nv['type'], [1, 3, 4], true)) {
                $columnOne['slot']=$nv['key'];
                $columnOne['key']='';
                $columnOne['input_type']=$nv['type'];
                $columnOne['input_info']= $nv['info'] ? explode(',', (string)$nv['info']) : [];
            } else {
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
        if ((int)$type === 4) {
            $columns[] = [
                'title' => '操作',
                'minWidth' => 100,
                'slot' => 'action',
            ];
        }
        return $this->success("成功！",$columns);
    }

      //工资表
    public function selfList(Request $request){
        $data = $request->getMore([
            ['date', ''],
            ['store_id',""],
            ['is_excel',''],
            ['table_ids',""]
        ]);
        $allData=$request->all();
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
            $result = $this->services->sqlReportList($data, $request->param());
        }
        $result['table_type'] = $salaryTable['type'];
        if($data['is_excel'] == 1){
            $filename = $name.'导出_' . date('YmdHis', time());
            $tableIds = (string)($data['table_ids'] ?? '');
            if ((int)$salaryTable['type'] === 5 && $this->services->hasSqlReportConfig($tableIds)) {
                $exportWhere = $request->getMore([
                    ['date', ''],
                    ['store_id', ''],
                    ['table_ids', ''],
                ]);
                $exportWhere['salary_status'] = 1;
                $result = $this->services->sqlReportExcelExport($exportWhere, $request->param(), $filename);
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
