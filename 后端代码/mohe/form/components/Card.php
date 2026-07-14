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
 * 卡片组件
 * Class Card
 * @package mohe\form\components
 */
class Card implements BuildInterface
{

    /**
     * 组件名
     */
    const NAME = 'card';

    /**
     * 规则
     * @var array
     */
    protected $rule = [
        'componentsModel' => [],
        'title' => '',
    ];

    /**
     * Card constructor.
     * @param string $title
     */
    public function __construct(string $title)
    {
        $this->rule['title'] = $title;
    }

    /**
     * 添加组件群
     * @param array $components
     * @return $this
     */
    public function components(array $components = [])
    {
        $this->rule['componentsModel'] = $components;
        return $this;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        $this->rule['name'] = self::NAME;
        $componentsModel = [];
        foreach ($this->rule['componentsModel'] as $item) {
            if ($item instanceof BuildInterface) {
                $componentsModel[] = $item->toArray();
            }
        }
        $this->rule['componentsModel'] = $componentsModel;
        return $this->rule;
    }
}
