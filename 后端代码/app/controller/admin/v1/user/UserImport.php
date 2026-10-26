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
namespace app\controller\admin\v1\user;

use app\services\other\Import\ImportRecordServices;
use app\controller\admin\AuthController;
use mohe\services\FileService;
use think\facade\App;

class UserImport extends AuthController
{

    /**
     * user constructor.
     * @param App $app
     * @param ImportRecordServices $services
     */
    public function __construct(App $app, ImportRecordServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 导入用户卡项
     * @return \think\Response
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    public function importCard()
    {
        $data = $this->request->getMore([
            ['file', ""],
            ['real_name', '']
        ]);

        if (!$data['file']) return app('json')->fail('请上传文件');
        $file = public_path() . substr($data['file'], 1);

        $cardData = app()->make(FileService::class)->readImportExcel($file, 2,'user_card');
        $cardCount = count($cardData);

        if ($cardCount == 0) {
            return app('json')->fail('导入数据不能为空');
        }

        if ($cardCount > ImportRecordServices::MAX_IMPORT_NUM) {
            return app('json')->fail('导入数据不能超过 ' . ImportRecordServices::MAX_IMPORT_NUM);
        }
        $dat['admin_id'] = $this->adminId;
        $dat['admin_name'] = $this->adminInfo['real_name'];
        $dat['real_name'] = $data['real_name'];
        $res = $this->services->batchUserImport($cardData, $dat,'user_card');

        $failCount = $res['errorCount'] ?? 0;
        $allCount = $cardCount;
        $id = $res['id'] ?? 0;

        if ($cardCount > ImportRecordServices::MAX_SINGLE_NUM) {
            $msg = '执行队列成功,稍后导入记录查看';
            $type = 2;
        } else {
            $msg = '导入成功';
            $type = 1;
        }

        return app('json')->success(compact('id', 'msg', 'failCount', 'allCount', 'type'));
    }
    /**
     * 导入用户
     * @return \think\Response
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    public function import()
    {
        $data = $this->request->getMore([
            ['file', ""],
            ['real_name', '']
        ]);

        if (!$data['file']) return app('json')->fail('请上传文件');
        $file = public_path() . substr($data['file'], 1);

        $cardData = app()->make(FileService::class)->readImportExcel($file, 2);

        $cardCount = count($cardData);

        if ($cardCount == 0) {
            return app('json')->fail('导入数据不能为空');
        }

        if ($cardCount > ImportRecordServices::MAX_IMPORT_NUM) {
            return app('json')->fail('导入数据不能超过 ' . ImportRecordServices::MAX_IMPORT_NUM);
        }
        $dat['admin_id'] = $this->adminId;
        $dat['admin_name'] = $this->adminInfo['real_name'];
        $dat['real_name'] = $data['real_name'];
        $res = $this->services->batchUserImport($cardData, $dat);

        $failCount = $res['errorCount'] ?? 0;
        $allCount = $cardCount;
        $id = $res['id'] ?? 0;

        if ($cardCount > ImportRecordServices::MAX_SINGLE_NUM) {
            $msg = '执行队列成功,稍后导入记录查看';
            $type = 2;
        } else {
            $msg = '导入成功';
            $type = 1;
        }

        return app('json')->success(compact('id', 'msg', 'failCount', 'allCount', 'type'));
    }

    /**
     * 列表
     * @return \think\Response
     * @throws \ReflectionException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function importList()
    {
        $where = $this->request->getMore([
            ['time', ''],
            ['name', '']
        ]);
        $where['import_type'] = 'user';
        $where['type'] = 0;
        $where['is_del'] = 0;
        $where['relation_id'] = 0;
        return app('json')->success($this->services->getImportList($where));
    }

    /**
     * 删除
     * @param $id
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function importDelete($id)
    {
        if (!$id) {
            return app('json')->fail('参数错误');
        }
        $res = $this->services->deleteImport($id);
        if($res) {
            return app('json')->success('删除成功！');
        }else{
            return app('json')->fail('删除失败！');
        }
    }


}
