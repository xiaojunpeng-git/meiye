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


use mohe\form\BuildInterface;

/**
 * Tabs 组件
 * Class Tabs
 * @package mohe\form\components
 */
class Tabs implements BuildInterface
{

    //组件名
    const NAME = 'tabs';

    /**
     * 规则
     * @var array[]
     */
    protected $rule = [
        'options' => []
    ];

    /**
     * Tabs constructor.
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->rule['options'] = $options;
    }

    /**
     * 添加单个选项卡组件群
     * @param string $label
     * @param array $components
     * @return $this
     */
    public function option(string $label, array $components = [])
    {
        $this->rule['options'][] = ['label' => $label, 'componentsModel' => $components];
        return $this;
    }

    /**
     * @return array|array[]
     */
    public function toArray(): array
    {
        $this->rule['name'] = self::NAME;
        $options = [];
        foreach ($this->rule['options'] as $option) {
            $data = ['label' => $option['label'], 'componentsModel' => []];
            foreach ($option['componentsModel'] as $item) {
                if ($item instanceof BuildInterface) {
                    $data['componentsModel'][] = $item->toArray();
                }
            }
            $options[] = $data;
        }
        $this->rule['options'] = $options;
        return $this->rule;
    }
}
