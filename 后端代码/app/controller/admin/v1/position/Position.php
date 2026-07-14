<?php

namespace app\controller\admin\v1\position;
use app\controller\admin\AuthController;
use app\model\store\SystemStore;
use app\Request;
use app\services\position\PositionServices;
use app\services\yeji\YejiPkServices;
use think\facade\App;
use think\facade\Db;
use mohe\services\FormBuilder as Form;
use mohe\traits\OptionTrait;
use think\facade\Route as Url;
/**
 * Class OtherOrder
 * @package app\controller\admin\v1\order
 */
class Position extends AuthController
{
    /**
     * StoreOrder constructor.
     * @param App $app
     */
    public function __construct(App $app, PositionServices $service)
    {
        parent::__construct($app);
        $this->services = $service;
    }

    /**
     * 职位列表
     * @param Request $request
     * @return mixed
     */
    public function positionList(Request $request)
    {
        $where = $request->getMore([
            ['name', ''],
        ]);
        return app('json')->success($this->services->getList($where));
    }

    //编辑职位
    public function editPosition(Request $request)
    {
        $position = [];
        $data = $request->getMore([
            ['id', 0],
        ]);
        $id=$data['id'] ?? 0;
        if ($id) {
            $position=\app\model\position\Position::where("id",$id)->find();
        }
        $field[] = Form::input('name', '职位', $position['name'] ?? '')->maxlength(10);
        $field[] = Form::switches('status', '显示：', (string)($position['status'] ?? '1'))->falseValue('0')->trueValue('1')->openStr('打开')->closeStr('关闭')->size('large');
        return $this->success(create_form('保存职位', $field, Url::buildUrl('/position/savePosition/' . $id), 'POST'));
    }

    public function savePosition(int $id,Request $request){
        $data = $request->postMore([
            ['name', ''],
            ['status', 0],
        ]);
        if(!empty($id)){
            $position=\app\model\position\Position::where("id",$id)->find();
        }else{
            $position=new \app\model\position\Position();
        }
        $position->name=$data['name'];
        $position->status=$data['status'];
        $position->save();
        return $this->success('保存成功');
    }

    public function delPosition($id){
         \app\model\position\Position::where("id",$id)->delete();
        return $this->success('删除成功');
    }

    /**
     * 修改状态
     * @param $id
     * @param $status
     * @return mixed
     */
    public function set_status($id, $status)
    {
        if ($status == '' || $id == 0) {
            return $this->fail('参数错误');
        }
        $this->services->update($id, ['status' => $status]);
        return $this->success($status == 0 ? '隐藏成功' : '显示成功');
    }
}
