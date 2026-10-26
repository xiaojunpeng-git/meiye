<?php

namespace mohe\services\printer\storage;

use app\services\activity\table\TableQrcodeServices;
use mohe\basic\BasePrinter;

class FeiEYun extends BasePrinter
{

    /**
     * 初始化
     * @param array $config
     * @return mixed|void
     */
    protected function initialize(array $config)
    {

    }

    /**
     * 开始打印
     * @return bool|mixed|string
     * @throws \Exception
     */
    public function startPrinter()
    {
        if (!$this->printerContent) {
            return $this->setError('Missing print');
        }
        $time = time();
        $request = $this->accessToken->postRequest('http://api.feieyun.cn/Api/Open/', [
            'user' => $this->accessToken->feyUser,
            'stime' => $time,
            'sig' => sha1($this->accessToken->feyUser . $this->accessToken->feyUkey . $time),
            'apiname' => 'Open_printMsg',
            'sn' => $this->accessToken->feySn,
            'content' => $this->printerContent,
            'times' => $this->accessToken->times,
        ]);
        $res = json_decode($request, true);
        if ($res['msg'] == 'ok') {
            return $res;
        } else {
            return $this->setError($res['msg']);
        }
    }

    /**
     * 设置打印内容
     * @param $content
     * @return FeiEYun
     */
    public function setPrinterContent (string $content)
    {
        $this->printerContent = $content;
        return $this;
    }

}
