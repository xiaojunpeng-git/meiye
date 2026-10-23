<?php

namespace app\services\store;

use app\services\other\CityAreaServices;

/** 可选门店的只读地区投影：只认主表地区 ID 和字典父子关系，不猜地址、不回填历史。 */
class StoreSelectableRegionServices
{
    private $cities;

    public function __construct(CityAreaServices $cities)
    {
        $this->cities = $cities;
    }

    /**
     * 输入已经过进店/商品/常用范围限制的完整门店集，输出最多三级地区树。
     * 缺失或父子不一致时只保留有效前缀，并计入待完善数；全部地区仍保留这些门店。
     */
    public function project(array $stores): array
    {
        $ids = [];
        foreach ($stores as $store) {
            foreach (['province', 'city', 'area'] as $key) {
                if ((int)($store[$key] ?? 0) > 0) $ids[] = (int)$store[$key];
            }
        }
        $nodes = [];
        if ($ids) {
            foreach ($this->cities->getCityList([['id', 'in', array_values(array_unique($ids))]], 'id,name,parent_id,level') as $node) {
                $nodes[(int)$node['id']] = $node;
            }
        }
        $tree = [];
        $incomplete = 0;
        foreach ($stores as $store) {
            $branch =& $tree;
            $parent = 0;
            foreach (['province', 'city', 'area'] as $index => $key) {
                $id = (int)($store[$key] ?? 0);
                $node = $nodes[$id] ?? null;
                if (!$node || trim((string)$node['name']) === '' || (int)$node['level'] !== $index + 1 || (int)$node['parent_id'] !== $parent) {
                    $incomplete++;
                    break;
                }
                if (!isset($branch[$id])) {
                    $branch[$id] = ['id' => $id, 'label' => (string)$node['name'], 'children' => []];
                }
                $parent = $id;
                $branch =& $branch[$id]['children'];
            }
            unset($branch);
        }
        // 稳定按权威地区 ID 排序，同名区县始终属于各自父节点，不按名称合并。
        $normalize = function (array $items) use (&$normalize): array {
            ksort($items, SORT_NUMERIC);
            foreach ($items as &$item) $item['children'] = $normalize($item['children']);
            unset($item);
            return array_values($items);
        };
        return ['regions' => $normalize($tree), 'incomplete_count' => $incomplete];
    }
}
