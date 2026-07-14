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
namespace app\controller\store\table;

use app\model\activity\table\TableQrcode;
use think\facade\App;
use app\controller\store\AuthController;
use app\services\activity\table\TableSeatsServices;
use app\services\activity\table\TableQrcodeServices;
use app\services\other\CategoryServices;


/**
 * 桌码
 * Class TableCode
 * @package app\controller\store\table
 */
class TableCode extends AuthController
{
    /**
     * TableQrcodeServices constructor.
     * @param App $app
     * @param TableQrcodeServices $qrcodeServices
     */
    public function __construct(App $app, TableQrcodeServices $qrcodeServices)
    {
        parent::__construct($app);
        $this->qrcodeServices = $qrcodeServices;
    }

    /**获取餐桌座位数列表
     * @param TableSeatsServices $services
     * @return \think\Response
     */
    public function getTableSeats(TableSeatsServices $services)
    {
        $list = $services->TableSeatsList((int)$this->storeId);
        return app('json')->successful($list);
    }

    /**获取单个餐桌座位数
     * @param TableSeatsServices $services
     * @param $id
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getSeats(TableSeatsServices $services, $id)
    {
        $data = $services->get($id);
        return app('json')->successful($data);
    }

    /**添加、编辑餐桌座位数
     * @param TableSeatsServices $services
     * @param $id
     * @return \think\Response
     */
    public function setTableSeats(TableSeatsServices $services, $id)
    {
        $data = $this->request->getMore([
            ['number', 0]
        ]);
        if ($id) {
            $res = $services->update($id, ['number' => $data['number']]);
        } else {
            $data['store_id'] = (int)$this->storeId;
            $data['add_time'] = time();
            $res = $services->save($data);
        }
        if ($res) {
            return app('json')->success($id ? '修改成功' : '添加成功');
        } else {
            return app('json')->fail($id ? '修改失败' : '添加失败');
        }
    }

    /**删除餐桌座位数
     * @param TableSeatsServices $services
     * @param $id
     * @return \think\Response
     */
    public function delTableSeats(TableSeatsServices $services, $id)
    {
        $res = $services->delete($id);
        if ($res) {
            return app('json')->success('删除成功');
        } else {
            return app('json')->fail('删除失败');
        }
    }

    /**获取桌码分类列表
     * @param CategoryServices $services
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getTableCodeClassify(CategoryServices $services)
    {
        $where = ['type' => 1, 'relation_id' => $this->storeId, 'group' => CategoryServices::TABLE_CODE_GROUP, 'is_show' => 1];
        $list = $services->getCateList($where);
        foreach ($list['data'] as $key => &$itme) {
            $itme['add_time'] = date('Y-m-d H:i:s', $itme['add_time']);
            $itme['sum'] = $this->qrcodeServices->count(['cate_id' => $itme['id'], 'store_id' => $this->storeId, 'is_del' => 0]);
        }
        return app('json')->successful($list);
    }

    /**获取单个桌码分类
     * @param CategoryServices $services
     * @param $id
     * @return \think\Response
     */
    public function getOneClassify(CategoryServices $services, $id)
    {
        $data = $services->get($id);
        return app('json')->successful($data);
    }

    /**添加、编辑桌码分类
     * @param CategoryServices $services
     * @param $id
     * @return \think\Response
     */
    public function setTableCodeClassify(CategoryServices $services, $id)
    {
        $data = $this->request->getMore([
            ['name', '']
        ]);
        if ($id) {
            $res = $services->update($id, ['name' => $data['name']]);
        } else {
            $data['pid'] = 0;
            $data['type'] = 1;
            $data['group'] = CategoryServices::TABLE_CODE_GROUP;
            $data['is_show'] = 1;
            $data['relation_id'] = (int)$this->storeId;
            $data['add_time'] = time();
            $res = $services->save($data);
        }
        if ($res) {
            return app('json')->success($id ? '修改成功' : '添加成功');
        } else {
            return app('json')->fail($id ? '修改失败' : '添加失败');
        }
    }

    /**删除桌码分类
     * @param CategoryServices $services
     * @param $id
     * @return \think\Response
     */
    public function delTableCodeClassify(CategoryServices $services, $id)
    {
        $res = $services->delete($id);
        if ($res) {
            return app('json')->success('删除成功');
        } else {
            return app('json')->fail('删除失败');
        }
    }

    /**桌码添加、编辑
     * @param $id
     * @return \think\Response
     */
    public function addTableQrcode($id)
    {
        $data = $this->request->postMore([
            ['cate_id', 0],
            ['seat_num', 0],
            ['number', []],
            ['is_using', 0],
            ['remarks', '']
        ]);
        if ($id) {
            unset($data['number']);
            $res = $this->qrcodeServices->update($id, $data);
        } else {
            $data['store_id'] = (int)$this->storeId;
            $data['add_time'] = time();
            $numId = TableQrcode::where('store_id', (int)$this->storeId)
                ->where('is_del', 0)
                ->order('table_number', 'desc')
                ->value('table_number');
            $data['table_number'] = bcadd((string)($numId ?: '0'), '1');
            $res = $this->qrcodeServices->save($data);
        }
        if ($res) {
            return app('json')->success($id ? '修改成功' : '添加成功');
        } else {
            return app('json')->fail($id ? '修改失败' : '添加失败');
        }
    }


    /**获取单个桌码
     * @param $id
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOneTableQrcodey($id)
    {
        $data = $this->qrcodeServices->get($id);
        return app('json')->successful($data);
    }

    /**删除桌码
     * @param $id
     * @return \think\Response
     */
    public function delTableQrcodey($id)
    {
        $res = $this->qrcodeServices->update($id, ['is_del' => 1]);
        if ($res) {
            return app('json')->success('删除成功');
        } else {
            return app('json')->fail('删除失败');
        }
    }

    /**获取桌码列表
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getTableQrcodeyList()
    {
        $where = $this->request->getMore([
            ['cate_id', '']
        ]);
        $list = $this->qrcodeServices->tableQrcodeyList($where, (int)$this->storeId);
        return app('json')->successful($list);
    }

    /**桌码操作启用
     * @param $id
     * @return \think\Response
     */
    public function updateUsing($id)
    {
        $where = $this->request->getMore([
            ['is_using', 0]
        ]);
        $res = $this->qrcodeServices->update($id, ['is_using' => $where['is_using']]);
        if ($res) {
            return app('json')->success('操作成功');
        } else {
            return app('json')->fail('操作失败');
        }
    }
}
