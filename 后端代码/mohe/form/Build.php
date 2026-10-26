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

namespace mohe\form;


use mohe\form\components\Addres;
use mohe\form\components\Alert;
use mohe\form\components\Card;
use mohe\form\components\CheckBox;
use mohe\form\components\DiyTable;
use mohe\form\components\Input;
use mohe\form\components\InputNumber;
use mohe\form\components\Map;
use mohe\form\components\Radio;
use mohe\form\components\Select;
use mohe\form\components\Switchs;
use mohe\form\components\Tabs;
use mohe\form\components\Time;
use mohe\form\components\UploadFrame;
use mohe\form\components\UploadImage;

/**
 * Class Build
 * @package mohe\form
 * @method Input input(string $field, string $title, $value = null)
 * @method Tabs tabs(array $options = [])
 * @method Card card(string $title)
 * @method InputNumber inputNum(string $field, string $title, $value = null)
 * @method Select select(string $field, string $title, $value = null)
 * @method UploadFrame uploadFrame(string $field, string $title, $value = null)
 * @method UploadImage uploadImage(string $field, string $title, $value = null)
 * @method Radio radio(string $field, string $title, $value = null)
 * @method CheckBox checkBox(string $field, string $title, $value = null)
 * @method Switchs switch (string $field, string $title, $value = null)
 * @method Alert alert (string $title, string $type = '', bool $closable = false, bool $showIcon = false)
 * @method DiyTable diyTable(string $field, string $title, array $value = [], array $options = [])
 * @method Map map(string $field, string $title, $value = null)
 * @method Addres addres(string $field, string $title, $value = null)
 * @method Time time(string $field, string $title, $value = null)
 */
class Build
{

    /**
     * 挂载组件
     * @var string[]
     */
    protected static $components = [
        'input' => Input::class,
        'tabs' => Tabs::class,
        'card' => Card::class,
        'inputNum' => InputNumber::class,
        'select' => Select::class,
        'uploadFrame' => UploadFrame::class,
        'uploadImage' => UploadImage::class,
        'radio' => Radio::class,
		'checkBox' => CheckBox::class,
        'switch' => Switchs::class,
        'alert' => Alert::class,
        'diyTable' => DiyTable::class,
        'addres' => Addres::class,
        'map' => Map::class,
        'time' => Time::class,
    ];

    /**
     * @var array
     */
    protected $rule = [];

    /**
     * 请求地址
     * @var
     */
    protected $url;

    /**
     * @var string
     */
    protected $method = 'POST';

    /**
     * @var array
     */
    protected $data = [];

    /**
     * Build constructor.
     * @param string|null $url
     * @param array $rule
     * @param string|null $method
     * @param array $data
     */
    public function __construct(string $url = null, array $rule = [], string $method = null, array $data = [])
    {
        $this->url = $url;
        $this->rule = $rule;
        $this->method = $method ?: 'POST';
        $this->data = $data;
    }

    /**
     * @param array $rule
     * @return $this
     */
    public function rule(array $rule = [])
    {
        $this->rule = $rule;
        return $this;
    }

    /**
     * @param string $url
     * @return $this
     */
    public function url(string $url)
    {
        $this->url = $url;
        return $this;
    }

    /**
     * @param string $method
     * @return $this
     */
    public function method(string $method)
    {
        $this->method = $method;
        return $this;
    }

    /**
     * @param array $data
     * @return Build
     */
    public function data(array $data)
    {
        $this->data = $data;
        return $this;
    }

    /**
     * 批量设置数据
     * @param $rule
     * @return mixed
     */
    public function setValue($rule)
    {
        if (!$this->data) {
            return $rule;
        }
        foreach ($rule as &$value) {
            if (isset($value['value']) && $value['value'] !== '' && isset($value['field'])) {
                $value['value'] = $this->data[$value['field']];
            }
            if (isset($value['options']) && $value['options']) {
                foreach ($value['options'] as $i => $option) {
                    if (isset($option['componentsModel']) && $option['componentsModel']) {
                        $value['options'][$i] = $this->setValue($option['componentsModel']);
                    }
                }
            }
            if (isset($value['control']) && $value['control']) {
                foreach ($value['control'] as $ii => $control) {
                    if (isset($control['componentsModel']) && $control['componentsModel']) {
                        $value['control'][$ii] = $this->setValue($control['componentsModel']);
                    }
                }
            }
            if (isset($value['componentsModel']) && $value['componentsModel']) {
                $value['componentsModel'] = $this->setValue($value['componentsModel']);
            }
        }
        return $rule;
    }

    /**
     * 提取验证值
     * @param $rule
     * @return array
     */
    protected function getValidate($rule)
    {
        $validate = [];
        foreach ($rule as $value) {
            if (isset($value['field']) && isset($value['validate']) && $value['validate']) {
                $validate[$value['field']] = $value['validate'];
            }
            if (isset($value['options']) && $value['options']) {
                foreach ($value['options'] as $option) {
                    if (isset($option['componentsModel']) && $option['componentsModel']) {
                        $validate = array_merge($validate, $this->getValidate($option['componentsModel']));
                    }
                }
            }
            if (isset($value['control']) && $value['control']) {
                foreach ($value['control'] as $control) {
                    if (isset($control['componentsModel']) && $control['componentsModel']) {
                        $validate = array_merge($validate, $this->getValidate($control['componentsModel']));
                    }
                }
            }
            if (isset($value['componentsModel']) && $value['componentsModel']) {
                $validate = array_merge($validate, $this->getValidate($value['componentsModel']));
            }
        }
        return $validate;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $rule = [];
        foreach ($this->rule as $item) {
            if ($item instanceof BuildInterface) {
                $rule[] = $item->toArray();
            }
        }
        $data = [
            'rules' => $this->setValue($rule),
            'validate' => $this->getValidate($rule),
            'url' => $this->url,
            'method' => $this->method
        ];
        $data['validate'] = $data['validate'] ?: (object)[];
        $this->url = null;
        $this->rule = [];
        $this->method = 'POST';
        return $data;
    }

    /**
     * @return false|string
     */
    public function toString()
    {
        return json_encode($this->toArray());
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        $compKeys = array_keys(self::$components);
        if (in_array($name, $compKeys)) {
            return new self::$components[$name](...$arguments);
        }
        throw new BuildException('Method does not exist');
    }
}
