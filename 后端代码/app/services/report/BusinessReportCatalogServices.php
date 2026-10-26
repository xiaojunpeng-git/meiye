<?php
namespace app\services\report;

use app\services\BaseServices;

/** 平台报表入口目录；数据查询仍由各专题的统一事实查询服务承接。 */
class BusinessReportCatalogServices extends BaseServices
{
    public function list()
    {
        return [
            ['folder'=>'门店业务部门','scope'=>'platform_and_store','reports'=>['运营管理报表','数据统计','弱店数据','拓客数据统计','3万+客户数据','光电业绩','月度目标与数据统计','化妆品入库验收','门店基础数据']],
            ['folder'=>'光电部门','scope'=>'platform_only','reports'=>['业绩市场分布','业绩成交','品项业绩','品项成交分析','城市经理业绩分布','消耗及退款明细','顾客现金消费分析']],
            ['folder'=>'私密部门','scope'=>'platform_only','reports'=>['品项成交分析']],
            ['folder'=>'拓客部门','scope'=>'platform_only','reports'=>['拓客数据统计','客户来源数据分析']],
            ['folder'=>'营销部门','scope'=>'platform_only','reports'=>['业绩汇总','拓客数据统计','运营管理报表','年度业绩']],
            ['folder'=>'财务-王小龙','scope'=>'platform_only','reports'=>['产品库龄分析','品项成交分析','品项毛利排名','现金消费分级','退款明细']],
            ['folder'=>'财务前台数据','scope'=>'platform_only','reports'=>['人头人次项目数顾客业绩','外接数据明细汇总','地推拓客汇总','收客明细汇总','系统工资表','自家员工新客','销售经理每日业绩','门店收款明细']],
            ['folder'=>'人事部门','scope'=>'platform_only','reports'=>['服务人次统计','人事数据','人力资源报表','市场月初数据']],
            ['folder'=>'培训部门','scope'=>'platform_only','reports'=>['员工需求统计表']],
        ];
    }
}
