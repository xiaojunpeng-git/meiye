<?php
declare(strict_types=1);

namespace app\controller\admin\v1\system;

use app\controller\admin\AuthController;
use app\services\system\TrainingDocumentServices;
use mohe\services\UploadService;
use think\exception\ValidateException;
use think\facade\App;

class TrainingDocument extends AuthController
{
    public function __construct(App $app, protected TrainingDocumentServices $service) { parent::__construct($app); }

    public function index()
    {
        return $this->success($this->service->adminList($this->request->getMore([['page', 1], ['limit', 20], ['keyword', ''], ['category', ''], ['status', '']])));
    }

    public function save()
    {
        $data = $this->request->postMore([['id', 0], ['title', ''], ['category', ''], ['summary', ''], ['file_name', ''], ['file_path', ''], ['file_ext', ''], ['file_size', 0], ['version', 'V1.0'], ['client_types', 'all'], ['role_ids', ''], ['store_ids', ''], ['is_required', 0], ['allow_download', 1], ['status', 0]]);
        $id = $this->service->save($data, (int)$this->adminId, (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? '管理员'));
        return $this->success('保存成功', compact('id'));
    }

    public function status(int $id)
    {
        $this->service->setStatus($id, (int)$this->request->post('status', 0));
        return $this->success('状态已更新');
    }

    /** 上传培训文档：只接受办公文档与 PDF，最大 50MB。 */
    public function upload()
    {
        $file = $this->request->file('file');
        if (!$file) throw new ValidateException('请选择要上传的资料文件');
        $upload = UploadService::init()->to('training_document')->setAuthThumb(false)->validate([
            'filesize' => 50 * 1024 * 1024,
            'fileExt' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx']
        ]);
        $res = $upload->move('file');
        if (!$res) throw new ValidateException($upload->getError() ?: '资料上传失败');
        return $this->success('上传成功', [
            'file_name' => (string)$res->realName,
            'file_path' => (string)$res->filePath,
            'file_ext' => strtolower(pathinfo((string)$res->realName, PATHINFO_EXTENSION)),
            'file_size' => (int)$file->getSize(),
        ]);
    }

    public function download(int $id)
    {
        $file = $this->service->download($id, 'admin', (int)$this->adminId, (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? '管理员'), 0, (array)($this->adminInfo['roles'] ?? []));
        return download($file['file_path'], $file['file_name']);
    }
}
