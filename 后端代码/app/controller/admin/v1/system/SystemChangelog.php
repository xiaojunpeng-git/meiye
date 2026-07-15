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

namespace app\controller\admin\v1\system;

use app\controller\admin\AuthController;
use app\services\system\SystemChangelogServices;
use think\facade\App;

/**
 * 系统更新日志
 * Class SystemChangelog
 * @package app\controller\admin\v1\system
 */
class SystemChangelog extends AuthController
{
    /**
     * SystemChangelog constructor.
     * @param App $app
     * @param SystemChangelogServices $services
     */
    public function __construct(App $app, SystemChangelogServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 日志列表
     * @return \think\Response
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['status', ''],
            ['title', ''],
            ['version', ''],
            ['platforms', ''],
            ['publish_date', ''],
            ['publish_date_start', ''],
            ['publish_date_end', ''],
            ['keyword', ''],
            ['release_key', ''],
        ]);
        return $this->success($this->services->getAdminList($where));
    }

    /**
     * 日志详情
     * @param int $id
     * @return \think\Response
     */
    public function read($id)
    {
        [$withAudit] = $this->request->getMore([
            ['with_audit', 0],
        ], true);
        return $this->success($this->services->getDetail((int)$id, (bool)$withAudit));
    }

    /**
     * 新增草稿
     * @return \think\Response
     */
    public function save()
    {
        $data = $this->request->postMore([
            ['title', ''],
            ['version', ''],
            ['summary', ''],
            ['publish_date', ''],
            ['platforms', ''],
            ['is_important', 0],
            ['is_popup', 0],
            ['sort', 0],
            ['internal_note', ''],
            ['release_key', ''],
            ['status', 0],
            ['publish_time', 0],
            ['items', []],
        ]);
        $this->validate($data, \app\validate\admin\setting\SystemChangelogValidate::class, 'save');
        $id = $this->services->saveDraft($data, (int)$this->adminId, 0);
        return $this->success('保存成功', ['id' => $id]);
    }

    /**
     * 编辑草稿/待发布/已发布
     * @param int $id
     * @return \think\Response
     */
    public function update($id)
    {
        $data = $this->request->postMore([
            ['title', ''],
            ['version', ''],
            ['summary', ''],
            ['publish_date', ''],
            ['platforms', ''],
            ['is_important', 0],
            ['is_popup', 0],
            ['sort', 0],
            ['internal_note', ''],
            ['release_key', ''],
            ['status', ''],
            ['publish_time', 0],
            ['items', []],
        ]);
        $this->validate($data, \app\validate\admin\setting\SystemChangelogValidate::class, 'save');
        $newId = $this->services->saveDraft($data, (int)$this->adminId, (int)$id);
        return $this->success('保存成功', ['id' => $newId]);
    }

    /**
     * 发布
     * @param int $id
     * @return \think\Response
     */
    public function publish($id)
    {
        $this->services->publish((int)$id, (int)$this->adminId);
        return $this->success('发布成功');
    }

    /**
     * 下架
     * @param int $id
     * @return \think\Response
     */
    public function offline($id)
    {
        [$reason] = $this->request->postMore([
            ['reason', ''],
        ], true);
        $this->services->offline((int)$id, (int)$this->adminId, (string)$reason);
        return $this->success('下架成功');
    }

    /**
     * 删除草稿
     * @param int $id
     * @return \think\Response
     */
    public function delete($id)
    {
        $this->services->deleteDraft((int)$id, (int)$this->adminId);
        return $this->success('删除成功');
    }

    /**
     * 复制为新草稿
     * @param int $id
     * @return \think\Response
     */
    public function copy($id)
    {
        $newId = $this->services->copyAsDraft((int)$id, (int)$this->adminId);
        return $this->success('复制成功', ['id' => $newId]);
    }

    /**
     * 按 release_key 幂等写入并可选发布
     * @return \think\Response
     */
    public function upsertRelease()
    {
        $payload = $this->request->postMore([
            ['release_key', ''],
            ['title', ''],
            ['version', ''],
            ['summary', ''],
            ['publish_date', ''],
            ['platforms', ''],
            ['is_important', 0],
            ['is_popup', 0],
            ['sort', 0],
            ['internal_note', ''],
            ['publish_time', 0],
            ['auto_publish', 0],
            ['items', []],
        ]);
        $this->validate($payload, \app\validate\admin\setting\SystemChangelogValidate::class, 'upsert');
        $result = $this->services->upsertByReleaseKey($payload, (int)$this->adminId);
        return $this->success('写入成功', $result);
    }
}
