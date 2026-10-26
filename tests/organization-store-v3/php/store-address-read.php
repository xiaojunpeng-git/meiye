<?php

/** 地址投影纯逻辑回归：实际业务类、受控字典，不连接数据库、不写门店。 */
require '/var/www/html/vendor/autoload.php';

class StoreAddressFixture extends \app\services\store\StoreAddressReadServices
{
    public $nodes;
    public $reads = [];

    public function __construct(array $nodes)
    {
        $this->nodes = $nodes;
    }

    /** 用小型父子字典覆盖存储边界，业务匹配逻辑保持真实。 */
    protected function children(int $parent): array
    {
        $this->reads[] = $parent;
        return $this->nodes[$parent] ?? [];
    }
}

function addressAssert(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}

$nodes = [
    0 => [['id' => 1, 'name' => '江苏省', 'level' => 1]],
    1 => [['id' => 2, 'name' => '扬州市', 'level' => 2]],
    2 => [['id' => 3, 'name' => '邗江区', 'level' => 3]],
    3 => [['id' => 4, 'name' => '竹西街道', 'level' => 4]],
];
$reader = new StoreAddressFixture($nodes);
$original = ['id' => 179, 'address' => '江苏省扬州市邗江区竹西街道大润发(维扬店)二楼诺曼姿',
    'detailed_address' => '', 'province' => 0, 'city' => 0, 'area' => 0, 'street' => 0,
    'longitude' => '119.4', 'latitude' => '32.4'];
$result = $reader->project($original);
addressAssert($result['addressSelect'] === [1, 2, 3, 4]
    && $result['detailed_address'] === '大润发(维扬店)二楼诺曼姿'
    && $result['address'] . $result['detailed_address'] === $original['address'], '完整旧地址唯一匹配四级且全文无损');
addressAssert($reader->reads === [0, 1, 2, 3] && $original['province'] === 0
    && $result['longitude'] === $original['longitude'] && $result['latitude'] === $original['latitude'],
    '最多四次父级查询，输入与定位坐标不变');

$known = array_merge($original, ['province' => '1', 'city' => '2', 'area' => '3']);
$reader->reads = [];
$result = $reader->project($known);
addressAssert($result['addressSelect'] === [1, 2, 3] && $result['address'] === $known['address']
    && !$reader->reads, '既有 ID 优先，转为数字并去掉末尾零，不按地址重新猜测');

$reader->nodes[3] = [];
$result = $reader->project($original);
addressAssert($result['addressSelect'] === [1, 2, 3]
    && $result['detailed_address'] === '竹西街道大润发(维扬店)二楼诺曼姿', '字典没有街道时保留街道在详细地址');

foreach (['扬州市邗江区某路', '江苏省扬州市某路', '江苏省扬州市不存在区某路', ''] as $text) {
    $input = array_merge($original, ['address' => $text]);
    $result = $reader->project($input);
    addressAssert($result['addressSelect'] === [] && $result['address'] === $text
        && $result['detailed_address'] === '', '未匹配完整省市区时不猜测：' . $text);
}
$reader->nodes[2][] = ['id' => 33, 'name' => '邗江区', 'level' => 3];
$result = $reader->project($original);
addressAssert($result['addressSelect'] === [] && $result['address'] === $original['address'], '同一父级歧义不取第一条冒充匹配');
$reader->nodes = $nodes;
$input = array_merge($original, ['detailed_address' => '其他门牌']);
$result = $reader->project($input);
addressAssert($result['addressSelect'] === [] && $result['address'] === $original['address']
    && $result['detailed_address'] === '其他门牌', '两字段均有详细内容时不猜测拼接');
$input = array_merge($original, ['address' => '江苏省扬州市邗江区', 'detailed_address' => '某路1号']);
$result = $reader->project($input);
addressAssert($result['addressSelect'] === [1, 2, 3] && $result['detailed_address'] === '某路1号', '已拆好的详细地址保持原文');
$reader->nodes[2] = [['id' => 90, 'name' => '邗江区', 'level' => 4]];
$result = $reader->project($original);
addressAssert($result['addressSelect'] === [], '层级错误的同名节点不能当作区县');
