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
 * 自定义表格
 * Class DiyTable
 * @package mohe\form\components
 */
class DiyTable extends BaseComponent implements BuildInterface
{
    /**
     * 组件名称
     */
    const NAME = 'diyTable';

    //内部表格自定义类型
    const TYPE = ['input', 'select', 'inputNumber', 'switch'];

    /**
     * 规则
     * @var string[]
     */
    protected $rule = [
        'title' => '',
        'value' => [],
        'type' => '',
        'field' => '',
        'options' => [],
        'info' => '',
    ];

    /**
     * DiyTable constructor.
     * @param string $field
     * @param string $title
     * @param array $value
     * @param array $options
     */
    public function __construct(string $field, string $title, array $value = [], array $options = [])
    {
        $this->rule['title'] = $title;
        $this->rule['field'] = $field;
        $this->rule['options'] = $options;
        $this->rule['value'] = $value;
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
     * 设置列
     * @param string $name
     * @param string $key
     * @param string $type
     * @param array $props
     * @return $this
     */
    public function column(string $name, string $key, string $type = 'input', array $props = [])
    {
        $this->rule['options'][] = ['name' => $name, 'key' => $key, 'type' => $type, 'props' => $props];
        return $this;
    }

    public function toArray(): array
    {
        $this->rule['name'] = self::NAME;
        $this->before();
        return $this->rule;
    }
}
