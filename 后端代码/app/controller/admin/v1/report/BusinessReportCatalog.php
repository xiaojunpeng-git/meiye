<?php
namespace app\controller\admin\v1\report;

use app\controller\admin\AuthController;
use app\services\report\BusinessReportCatalogServices;

class BusinessReportCatalog extends AuthController
{
    public function index(BusinessReportCatalogServices $services)
    {
        return $this->success($services->list());
    }
}
