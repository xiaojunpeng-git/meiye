<?php
namespace app\services\salary;

use app\dao\salary\SalaryAgentDao;
use app\dao\salary\SalaryInfoDao;
use app\model\position\Position;
use app\model\position\PositionLevel;
use app\model\salary\SalaryField;
use app\model\store\SystemStore;
use app\model\store\SystemStoreStaff;
use app\model\user\User;
use app\services\BaseServices;


class SalaryServices extends BaseServices
{
    public function __construct(SalaryInfoDao $dao)
    {
        $this->dao = $dao;
    }


    public function agentList(array $where){
        [$page, $limit] = $this->getPageValue();
        $dao=app()->make(SalaryAgentDao::class);
        $list = $dao->getList($where, $page, $limit);
        $count = $dao->count($where);
        foreach ($list as $nk=>$nv){
            $list[$nk]['staff_name']=SystemStoreStaff::where("id",$nv['staff_id'])->value("staff_name");
        }
        return compact('count', 'list');
    }
    public function getList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, $page, $limit);
        $count = $this->dao->count($where);
        $data=SalaryField::order("sort","asc")->whereFindInSet("table_ids",1)->select();
        foreach ($list as $nk=>$item) {
             $salaryInfo=json_decode($item['salary_info'],true);
             $staffInfo=SystemStoreStaff::where("id",$item['staff_id'])->find();
             foreach ($data as $k=>$v){
                 $list[$nk][$v['key']]=$salaryInfo[$v['key']] ?? '';
                 if(strstr($v['name'],"提点")){
                     $list[$nk][$v['key']]=bcmul($list[$nk][$v['key']],100)."%";
                 }
                 switch ($v['key']){
                     case 'store_name':
                         $list[$nk][$v['key']]=SystemStore::where("id",$item['store_id'])->value("name");
                         break;
                     case 'xingming':
                         $list[$nk][$v['key']]=$staffInfo['staff_name'];
                         break;
                     case 'nicheng':
                         $list[$nk][$v['key']]=User::where("uid",$staffInfo['uid'])->value("nickname");
                         break;
                     case 'zhiwu':
                         $list[$nk][$v['key']]=Position::where("id",$staffInfo['position'])->value("name");
                         break;
                     case 'zhiji':
                         $list[$nk][$v['key']]=PositionLevel::where("id",$staffInfo['position_level'])->value("name");
                         break;
                 }
             }
            $list[$nk]['date']=date("Y-m",$item['date']);
        }
        return compact('count', 'list');
    }

    //合计
    public function moneyCount(array $where){
        $list=$this->dao->moneyCount($where);
        $data=SalaryField::order("sort","asc")
            ->whereFindInSet("table_ids",$where['table_ids'])
            ->where("is_count",1)
            ->select();
        $total=[];
        foreach ($list as $nk=>$item) {
            $salaryInfo = json_decode($item['salary_info'], true);
            foreach ($data as $k=>$v) {
                if(!isset($total[$v['key']])) {
                    $total[$v['key']]['count'] = $salaryInfo[$v['key']] ?? '';
                    $total[$v['key']]['name'] = $v['name'];
                }else{
                    $total[$v['key']]['count']=bcadd($salaryInfo[$v['key']],$total[$v['key']]['count']);
                }
            }
        }
        return array_values($total);
    }
}
