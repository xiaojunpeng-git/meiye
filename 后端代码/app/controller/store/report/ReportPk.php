<?php

namespace app\controller\store\report;
use app\controller\store\AuthController;
use app\model\store\SystemStore;
use app\Request;
use app\services\yeji\YejiPkServices;
use think\facade\App;
use think\facade\Db;

/**
 * Class OtherOrder
 * @package app\controller\admin\v1\order
 */
class ReportPk extends AuthController
{
    /**
     * StoreOrder constructor.
     * @param App $app
     */
    public function __construct(App $app, YejiPkServices $service)
    {
        parent::__construct($app);
        $this->services = $service;
    }
      //销售数据
      public function pkInfo(Request $request){
            $data = $request->getMore([
                ['date', '']
            ]);
            if(empty($data['date'])){
                $data['date']=date("Y-m");
            }
           $result=$this->services->pkData($data);
           return $this->success("ok",$result);
      }

      //修改目标
    public function savePk(Request $request){
        $data = $request->postMore([
            ['store_id', ''],
            ['goal', ''],
            ['date', ''],
        ]);
        $goal=$data['goal'] ?? 0;
        unset($data['goal']);
        $this->services->saveGoal($data,$goal);
        return $this->success("成功");
    }
}
