<?php
declare(strict_types=1);

namespace app\controller\store\system;

use app\controller\store\AuthController;
use app\services\system\TrainingDocumentServices;
use think\facade\App;

class TrainingDocument extends AuthController
{
    public function __construct(App $app, protected TrainingDocumentServices $service) { parent::__construct($app); }
    public function index()
    {
        $where = $this->request->getMore([['page', 1], ['limit', 20], ['keyword', ''], ['category', '']]);
        return $this->success($this->service->userList('store', (int)$this->storeId, array_filter(explode(',', (string)($this->storeStaffInfo['roles'] ?? ''))), $where));
    }
    public function download(int $id)
    {
        $file = $this->service->download($id, 'store', (int)$this->storeStaffId, (string)($this->storeStaffInfo['staff_name'] ?? '店员'), (int)$this->storeId, array_filter(explode(',', (string)($this->storeStaffInfo['roles'] ?? ''))));
        return download($file['file_path'], $file['file_name']);
    }
}
