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

namespace mohe\services\wechat\contract;

/**
 * 配置
 * Interface ConfigHandlerInterface
 * @package mohe\services\wechat\contract
 */
interface ConfigHandlerInterface
{

    /**
     * 设置
     * @param string $key
     * @param $value
     * @return mixed
     */
    public function set(string $key, $value);

    /**
     * 获取单个
     * @param string|null $key
     * @return mixed
     */
    public function get(string $key = null);

    /**
     * 获取全部
     * @return array
     */
    public function all(): array;
}
