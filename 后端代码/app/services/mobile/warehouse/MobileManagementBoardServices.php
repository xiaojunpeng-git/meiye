<?php
declare(strict_types=1);
namespace app\services\mobile\warehouse;

use app\services\report\HumanEfficiencyDashboardServices;
use app\services\report\HumanEfficiencyDetailCollector;
use app\services\report\HumanEfficiencyFactReader;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use think\facade\Db;

/** 商家管理看板适配层。门店范围只能由数仓已解析的当前节点传入，不再次解释客户端组织或身份。 */
final class MobileManagementBoardServices
{
    public function read(array $stores, array $range, array $input): array
    {
        return Db::transaction(function () use ($stores, $range, $input): array {
            $ids = $input['boardEmployeeIds'] ?? [];
            if (!is_array($ids) || count($ids) > 500) throw new \InvalidArgumentException('员工筛选不正确');
            foreach ($ids as $id) if (!is_string($id) || !preg_match('/^\d+:\d+$/', $id)) throw new \InvalidArgumentException('员工筛选不正确');
            $board = (new HumanEfficiencyDashboardServices())->read($stores, $range, ['action'=>'management_board','boardEmployeeIds'=>$ids]);
            $board['scope'] = ['store_ids'=>$stores,'range'=>$range];
            if (($input['boardAction'] ?? '') !== 'detail') return $board;
            // 下钻行列均从本次相同范围的后端看板反查；传入姓名、金额或任意人员ID不能扩大范围。
            $table = $board[(string)($input['boardTable'] ?? '')] ?? null;
            if (!is_array($table) || !isset($table['rows'])) throw new \InvalidArgumentException('看板明细位置不正确');
            $row = null; $cell = null;
            foreach ($table['rows'] as $candidate) if ($candidate['key'] === ($input['boardRow'] ?? '')) $row = $candidate;
            foreach ($row['cells'] ?? [] as $candidate) if ($candidate['key'] === ($input['boardColumn'] ?? '')) $cell = $candidate;
            if (!$row || !$cell || !$cell['drillable']) throw new \InvalidArgumentException('当前指标没有可查看的明细');
            $page = max(1, (int)($input['boardDetailPage'] ?? 1));
            if (in_array($row['metric'], ['booked','arrived'], true)) {
                $sets = (new HumanEfficiencyFactReader())->managementAppointments($stores, $range); $rows = [];
                $kind = $row['metric'] === 'booked' ? 'booked' : 'arrived';
                foreach ($cell['entity_ids'] as $id) foreach ($sets[$id][$kind] ?? [] as $member=>$record) $rows[$member] = $record;
                ksort($rows, SORT_NUMERIC); $total = count($rows); $pages = max(1, (int)ceil($total / 20)); $page = min($page, $pages);
                $board['detail'] = ['title'=>$row['label'],'rows'=>array_slice(array_values($rows), ($page-1)*20, 20),'total'=>$total,'page'=>$page,'pages'=>$pages,'page_size'=>20];
            } else {
                $categories = [];
                foreach ($table['rows'] as $definition) if ($definition['category_id'] > 0) $categories[] = ['id'=>$definition['category_id']];
                // 分类路径投影继续交给人效明细服务；人员集合只取上面已授权单元格，顾客跨人重新去重。
                $parents = Db::name('store_product_category')->where('type',0)->where('relation_id',0)->column('pid','id');
                $enabled = array_fill_keys(array_column($categories, 'id'), true); $roots = [];
                foreach ($parents as $id=>$_) { $at=(int)$id; $seen=[]; while(isset($parents[$at])&&!isset($seen[$at])) { if(isset($enabled[$at])) { $roots[$id]=$at; break; } $seen[$at]=true; $at=(int)$parents[$at]; } }
                $collector = new HumanEfficiencyDetailCollector(['entity_id'=>'summary:board','entity_ids'=>$cell['entity_ids'],'metric'=>$row['metric'],'category_id'=>$row['category_id'],'page'=>$page,'page_size'=>20], $roots, $categories);
                $facts = (new StoreUnifiedReportPhaseSixServices())->humanEfficiencyFacts($stores, $range);
                // 页码必须基于稳定业务日与事实身份排序，不能依赖数据库未声明的返回顺序。
                usort($facts, static function (array $a, array $b): int {
                    $key = static fn(array $f): array => [(string)$f['business_date'], (int)$f['store_id'],
                        (int)$f['employee_id'], (string)($f['row_key'] ?? $f['fact_id'] ?? $f['id'] ?? ''),
                        (string)($f['checkout_request_id'] ?? ''), (string)($f['source_line_id'] ?? '')];
                    return $key($a) <=> $key($b);
                });
                foreach ($facts as $fact) {
                    if (in_array($fact['source_type'] ?? '', ['manual_refund','imported_card_refund'], true)) continue;
                    $id = $fact['store_id'].':'.$fact['employee_id'];
                    if (!in_array($id, $cell['entity_ids'], true)) continue;
                    $collector->add(['id'=>$id,'name'=>$fact['employee_name']], $fact);
                }
                $board['detail'] = $collector->result($row['label']) + ['page_size'=>20];
            }
            $board['detail']['display'] = $cell['display'];
            $board['detail']['format'] = $row['format'];
            return $board;
        });
    }
}
