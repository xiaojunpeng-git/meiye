<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace mohe\form\components;


use mohe\form\BaseComponent;
use mohe\form\BuildInterface;

/**
 * 地图组件
 * Class Map
 * @package mohe\form\components
 */
class Map extends BaseComponent implements BuildInterface
{

    const NAME = 'map';

    /**
     * @var string[]
     */
    protected $rule = [
        'title' => '',
        'field' => '',
        'value' => '',
        'info' => '',
    ];

    /**
     * Map constructor.
     * @param string $field
     * @param string $title
     * @param string $value
     */
    public function __construct(string $field, string $title, string $value = null)
    {
        $this->rule['field'] = $field;
        $this->rule['title'] = $title;
        $this->rule['value'] = empty($value) ? '' : $value;
    }

    /**
     * @param string $info
     * @return $this
     */
    public function info(string $info)
    {
        $this->rule['info'] = $info;
        return $this;
    }

    /**
     * @return array|string[]
     */
    public function toArray(): array
    {
        $this->rule['name'] = self::NAME;
        $this->before();
        return $this->rule;
    }
}
