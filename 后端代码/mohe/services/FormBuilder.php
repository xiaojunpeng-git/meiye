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

namespace mohe\services;

use FormBuilder\Factory\Iview as Form;
use FormBuilder\UI\Iview\Components\InputNumber;

/**
 * Form Builder
 * Class FormBuilder
 * @package mohe\services
 */
class FormBuilder extends Form
{

    public static function setOptions($call)
    {
        if (is_array($call)) {
            return $call;
        } else {
            return $call();
        }

    }

	public static function number($field,$title,$value = null){
		$number = new InputNumber($field, $title, $value);
		if (!$number->getProp('max')) {
			if ($field == 'sort') {
				$number->max(99999);
				if (!$number->getProp('min')) {
					$number->min(0);
				}
			} else {
				$number->max(9999999999);
			}
		}
		return $number;
	}
}
