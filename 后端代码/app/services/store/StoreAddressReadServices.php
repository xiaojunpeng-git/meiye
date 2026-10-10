<?php

namespace app\services\store;

use app\services\other\CityAreaServices;

/**
 * 门店编辑地址投影：已存地区 ID 优先，旧整串地址仅按本地字典的唯一父子前缀拆分。
 * 本服务只读，不地理编码、不修改门店资料；投影仅在用户正常保存门店时落库。
 */
class StoreAddressReadServices
{
    private $cities;

    public function __construct(CityAreaServices $cities)
    {
        $this->cities = $cities;
    }

    /**
     * 输入门店主表资料，返回编辑投影；缺省/歧义地址原文保留，不用局部匹配冒充完整省市区。
     * 每次最多读取四级的直接子节点，不扫描全量街道，不调用外部地图服务。
     */
    public function project(array $info): array
    {
        $keys = ['province', 'city', 'area', 'street'];
        $ids = array_map(static function ($key) use ($info) {
            return (int)($info[$key] ?? 0);
        }, $keys);
        // 任何既有地区标识都禁止用文本重新推断；只裁掉末尾无街道等零占位。
        if (count(array_filter($ids))) {
            while ($ids && end($ids) === 0) {
                array_pop($ids);
            }
            $info['addressSelect'] = $ids;
            return $info;
        }
        $info['addressSelect'] = [];
        $remaining = (string)($info['address'] ?? '');
        if ($remaining === '') {
            return $info;
        }
        $path = [];
        $names = [];
        $parent = 0;
        for ($level = 1; $level <= 4; $level++) {
            $matches = array_values(array_filter($this->children($parent), static function ($node) use ($remaining, $level) {
                $name = (string)($node['name'] ?? '');
                return (int)$node['level'] === $level && $name !== '' && strpos($remaining, $name) === 0;
            }));
            if (count($matches) !== 1) {
                // 街道可留在详细地址，省市区缺一级或有歧义则整串不动。
                if ($level <= 3) {
                    return $info;
                }
                break;
            }
            $node = $matches[0];
            $parent = (int)$node['id'];
            $path[] = $parent;
            $names[] = $node['name'];
            $remaining = substr($remaining, strlen($node['name']));
        }
        // 两个字段都带门牌等内容时无法确定拼接顺序，保留原始资料交给用户处理。
        if ($remaining !== '' && (string)($info['detailed_address'] ?? '') !== '') {
            return $info;
        }
        $info['addressSelect'] = $path;
        $info['address'] = implode('', $names);
        $info['detailed_address'] = $remaining !== '' ? $remaining : (string)($info['detailed_address'] ?? '');
        foreach ($keys as $index => $key) {
            $info[$key] = $path[$index] ?? 0;
        }
        return $info;
    }

    /** 按父级 ID 查询权威地区字典，避免全国同名区县/街道串联。 */
    protected function children(int $parent): array
    {
        return $this->cities->getCityList(['parent_id' => $parent], 'id,name,level');
    }
}
