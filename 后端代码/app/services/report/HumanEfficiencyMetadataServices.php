<?php

declare(strict_types=1);
namespace app\services\report;

use think\facade\Db;

/** 人效榜单布局由后端目录声明；同一分类契约用于查询、明细与导出。 */
final class HumanEfficiencyMetadataServices
{
    public function metadata(): array
    {
        $rows = Db::name('store_product_category')->where('type', 0)->where('relation_id', 0)
            ->field('id,pid,cate_name,statistics_enabled,sort')->select()->toArray();
        $categories=HumanEfficiencyRules::categories($rows);
        return [
            'ranking_layouts'=>$this->layouts($categories),
            'categories' => $categories,
            'page_size' => 20,
            'source_explanations' => [
                'categories' => '读取平台商品分类中已启用统计的一级分类，按配置顺序展示；未配置或关闭统计的分类不展示。各榜单完整查询结果中，成交顾客与现金业绩均全为0的分类隐藏，翻页不改变表头。',
                'cash' => '销售现金业绩读取有效正向销售及补款分配，不扣退款（退款净额属于实际业绩）；顾问包含副店长及既有顾问岗位，优先于店长标记归类；店长与顾问按个人分配，保留期间已离职人员贡献。',
                'target' => '店长和顾问均取所属门店的现金业绩目标；完成率为个人现金业绩除以门店目标，不是个人目标完成率；跨月累加各月目标，自定义不足月仍取整月门店目标。',
                'manager' => '城市经理仅统计已配置当前经理的管辖门店，汇总、榜单、明细与导出范围一致；按当前管辖门店归集所选期间业绩；月目标按查询覆盖的自然日分摊并归当前经理。组织调整会改变历史期间的归集结果，原单金额保持不变。',
                'service' => '人员活客按可计人数的已完成服务顾客去重；售后人次、项目数和消耗业绩使用统一人员服务份额。城市经理读取所辖门店的完成服务与消耗事实，不累计员工分配额；体验服务不计门店售后人次。',
                'heads' => '成交顾客人数按正向现金事实的会员身份去重；销售单数按门店与原单身份去重，各分类成交顾客不可直接相加。',
                'tenure' => '工龄以查询截止日计算，满3年、满1年不满3年、满6个月不满1年、不满6个月互斥；缺失入职日期单列。历史业绩保留，期末离职人员不进入人数分母。活客和成交顾客占比为本组去重人数除以全部去重人数，跨组重复时占比之和可能超过100%。',
                'sorting' => '顾问按同门店顾问现金业绩合计降序，同店店长跟随；店长及城市经理按完成率降序，未知或零目标放末尾，现金业绩及稳定身份作同值排序。',
                'average' => '人均分母为查询期末在职人数；分母为0显示“—”。',
            ],
        ];
    }
    /** 固定列、顺序、宽度、分组和精确下钻由后端声明，前端只呈现字段，不推断业务列。 */
    public function layouts(array $categories, ?array $rowsByKind = null): array
    {
        $layouts=[];
        $column=static function(string $key,string $label,string $group='identity',string $format='number',?string $metric=null,int $width=110,array $path=[]):array{
            return ['key'=>$key,'label'=>$label,'group'=>$group,'format'=>$format,'metric'=>$metric,'width'=>$width,
                'path'=>$path?:[$key],'category_id'=>0,'fixed'=>$group==='identity','additive'=>in_array($key,['cash','consume','items','visits'],true)];
        };
        foreach(['advisor','leader','manager'] as $kind){
            $cols=[$column('rank',$kind==='advisor'?'院店排名':'排名','identity','number',null,80)];
            if($kind!=='manager'){
                $cols[]=$column('store','门店','identity','text',null,150);
                $cols[]=$column('role','岗位','identity','text',null,90);
            }
            $cols[]=$column('name','姓名','identity','text',null,115);
            if($kind==='manager')$cols[]=$column('stores','管辖门店','identity','number','stores');
            else $cols[]=$column('staff','在岗美容师人数','service');
            $cols[]=$column('active','活客数','service','number','active');
            if($kind!=='manager')$cols[]=$column('average_active','美容人均活客数','service');
            $cols[]=$column('visits','售后人次','service','number','visits');
            if($kind!=='manager')$cols[]=$column('average_visits','美容人均售后人次','service');
            $cols[]=$column('items','项目数','service','number','items');
            $cols[]=$column('consume','生美消耗业绩','service','money','consume',130);
            $groups=[['id'=>'service','label'=>'服务数据','tone'=>'service']];
            foreach($rowsByKind === null ? $categories : HumanEfficiencyRules::visibleCategories($categories, $rowsByKind[$kind] ?? []) as $c){
                $group='category:'.$c['id'];$groups[]=['id'=>$group,'label'=>$c['name'],'tone'=>'sales'];
                foreach(['buyers'=>'成交顾客','cash'=>'现金业绩'] as $metric=>$label){
                    $col=$column($group.':'.$metric,$label,$group,$metric==='cash'?'money':'number',$metric,120,['category_values',$c['id'],$metric]);
                    $col['category_id']=$c['id'];$cols[]=$col;
                }
            }
            $groups[]=['id'=>'total','label'=>'销售合计','tone'=>'total'];
            $cols[]=$column('buyers','成交顾客','total','number','buyers');
            $cols[]=$column('cash','现金业绩','total','money','cash',130);
            $cols[]=$column('target',$kind==='manager'?'目标':'业绩目标','total','money',null,130);
            $cols[]=$column('completion','目标完成率'.($kind==='advisor'?'':' ↓'),'total','rate',null,210);
            $layouts[$kind]=['columns'=>$cols,'groups'=>$groups,'page_size'=>10];
        }
        return $layouts;
    }

}
