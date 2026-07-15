<?php
namespace app\controller\api\v1\metric;

use app\services\metric\MetricDictionaryServices;

class MetricDictionary
{
    /** @var MetricDictionaryServices */
    protected $services;

    public function __construct(MetricDictionaryServices $services)
    {
        $this->services = $services;
    }

    public function index()
    {
        return app('json')->success($this->services->getDefinitions());
    }

    public function tooltip($code = '')
    {
        $code = (string)$code;
        if ($code === '') {
            return app('json')->fail('指标编码不能为空');
        }
        return app('json')->success($this->services->getTooltip($code));
    }
}
