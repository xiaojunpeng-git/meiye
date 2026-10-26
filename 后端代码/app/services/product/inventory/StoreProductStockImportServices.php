<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
namespace app\services\product\inventory;

use app\dao\product\sku\StoreProductAttrValueDao;
use app\model\product\product\StoreProduct;
use app\model\product\sku\StoreProductAttrValue;
use app\services\BaseServices;
use app\services\other\Import\ImportRecordErrorServices;
use app\services\other\Import\ImportRecordServices;
use mohe\services\CacheService;
use mohe\services\SpreadsheetExcelService;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 库存入/出库 Excel 模板与整表预校验导入
 */
class StoreProductStockImportServices extends BaseServices
{
    /** 同步模板最大行数，超过须缩小筛选 */
    public const TEMPLATE_SYNC_MAX = 2000;
    /** 单次导入最大数据行 */
    public const IMPORT_MAX_ROWS = 5000;
    /** 预填多选商品上限 */
    public const TEMPLATE_PRODUCT_IDS_MAX = 500;

    /** @var string[] 表头 */
    public const HEADERS = [
        '商品ID',
        'SKU唯一值(unique禁止修改)',
        '商品名称',
        '规格',
        '商品编码',
        '条形码',
        '基本单位',
        '良品数量',
        '残次品数量',
        '业务日期(Y-m-d)',
        '出入库类型',
        '备注',
    ];

    public const SCENE_INITIAL_IN = 'initial_in';
    public const SCENE_IN = 'in';
    public const SCENE_OUT = 'out';

    public function __construct(StoreProductAttrValueDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 下载预填模板
     * 默认范围与 choose_type=94 对齐：产品、参与库存、上架/仓库、端归属、非供应商；
     * product_ids 非空时只导出所选商品全部有效 SKU，并校验 ID 全部合法。
     */
    public function downloadTemplate(string $scene, int $type = 0, int $relationId = 0, array $filter = []): array
    {
        $this->assertScene($scene);
        $productIds = $this->normalizeProductIds($filter['product_ids'] ?? []);
        if (count($productIds) > self::TEMPLATE_PRODUCT_IDS_MAX) {
            throw new ValidateException('一次最多选择 ' . self::TEMPLATE_PRODUCT_IDS_MAX . ' 个商品，请缩小选择后再下载');
        }
        if ($productIds) {
            $validCount = (int)Db::name('store_product')
                ->whereIn('id', $productIds)
                ->where('type', $type)
                ->where('relation_id', $relationId)
                ->where('product_type', 0)
                ->where('is_inventory', 1)
                ->where('is_del', 0)
                ->whereIn('is_show', [0, 1])
                ->count();
            if ($validCount !== count($productIds)) {
                throw new ValidateException('存在无效、越权或不可导出的商品，请重新选择后再下载');
            }
        }
        $where = [
            'type' => $type,
            'relation_id' => $relationId,
            'keyword' => trim((string)($filter['keyword'] ?? '')),
            'product_type' => 0,
            'is_show_in' => [0, 1],
            'exclude_supplier' => 1,
        ];
        if ($productIds) {
            $where['product_ids'] = $productIds;
        }
        $query = $this->dao->joinAttrSearch($where);
        $count = (clone $query)->count();
        if ($count <= 0) {
            throw new ValidateException('当前筛选条件下没有可参与库存管理的商品规格，请调整筛选后再下载');
        }
        if ($count > self::TEMPLATE_SYNC_MAX) {
            throw new ValidateException('符合条件的规格超过 ' . self::TEMPLATE_SYNC_MAX . ' 行，请缩小商品选择/关键字后再下载模板（避免卡顿）');
        }
        $list = $query->field('a.product_id,a.unique,a.suk,a.code,a.bar_code,p.store_name,p.unit_name')
            ->order('a.product_id asc,a.id asc')
            ->select()
            ->toArray();

        $defaultType = $this->defaultOrderTypeLabel($scene);
        $export = [];
        foreach ($list as $row) {
            $export[] = [
                (string)($row['product_id'] ?? ''),
                (string)($row['unique'] ?? ''),
                (string)($row['store_name'] ?? ''),
                (string)($row['suk'] ?? ''),
                (string)($row['code'] ?? ''),
                (string)($row['bar_code'] ?? ''),
                (string)($row['unit_name'] ?? ''),
                '',
                '',
                date('Y-m-d'),
                $defaultType,
                '',
            ];
        }
        $titleMap = [
            self::SCENE_INITIAL_IN => '初始入库导入模板',
            self::SCENE_IN => '普通入库导入模板',
            self::SCENE_OUT => '出库导入模板',
        ];
        $title = $titleMap[$scene];
        // 仅数字后缀，避免 microtime 小数点进入文件名（如 9.5443.xlsx）
        $filename = $title . '_' . date('YmdHis') . '_' . substr(str_replace('.', '', (string)microtime(true)), -6);
        $notice = '仅填写数量等录入列；商品ID与SKU唯一值禁止修改；单次导入最多'
            . self::IMPORT_MAX_ROWS . '行；整表校验通过才入账；同文件内容成功后不可重复导入';
        // 强制同步落盘，绕开 ExportServices::$maxLimit=1000 假异步分支
        $filePath = SpreadsheetExcelService::instance()
            ->setExcelHeader(self::HEADERS)
            ->setExcelTile($title, $title, $notice)
            ->setExcelContent($export)
            ->excelSave($filename, 'xlsx', true);
        $absolute = app()->getRootPath() . 'public' . $filePath;
        if (!$filePath || !is_file($absolute)) {
            throw new ValidateException('模板文件生成失败，请重试');
        }
        $fileName = basename((string)$filePath);
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            $fileName = $filename . '.xlsx';
        }
        // 不直接暴露 /phpExcel/*.xlsx：Web 规则常回退到 pc.html，导致浏览器拿到 HTML
        $downloadKey = $this->issueTemplateDownload($absolute, $fileName, $type, $relationId);
        return [
            'mode' => 'sync',
            'download_key' => $downloadKey,
            'file_name' => $fileName,
            'count' => $count,
            // 兼容旧前端；新前端必须走 template/file 接口拉 Blob，禁止 <a href> 直链
            'path' => '',
        ];
    }

    /**
     * 签发短时下载凭证（缓存绝对路径，供鉴权接口流式返回）
     */
    protected function issueTemplateDownload(string $absolute, string $fileName, int $type, int $relationId): string
    {
        $key = md5(uniqid('stock_tpl_', true) . microtime(true));
        $ok = CacheService::set($key, [
            'path' => $absolute,
            'fileName' => $fileName,
            'type' => $type,
            'relation_id' => $relationId,
            'kind' => 'stock_import_template',
        ], 600);
        if (!$ok) {
            throw new ValidateException('下载凭证生成失败，请重试');
        }
        return $key;
    }

    /**
     * 鉴权后读取已生成模板并返回 Excel 下载响应（禁止 SPA/HTML 回退）
     * @return \think\response\File
     */
    public function streamTemplateFile(string $key, int $type, int $relationId)
    {
        $key = trim($key);
        if ($key === '') {
            throw new ValidateException('缺少下载凭证');
        }
        // TagSet 无 get()；与 PublicController::download 一致走 CacheService::get
        $file = CacheService::get($key);
        if (!is_array($file) || ($file['kind'] ?? '') !== 'stock_import_template') {
            throw new ValidateException('下载链接已失效，请重新导出');
        }
        if ((int)($file['type'] ?? -1) !== $type || (int)($file['relation_id'] ?? -1) !== $relationId) {
            throw new ValidateException('无权下载该文件');
        }
        $path = (string)($file['path'] ?? '');
        $fileName = (string)($file['fileName'] ?? '');
        if ($path === '' || $fileName === '' || !is_file($path)) {
            throw new ValidateException('文件不存在或已过期，请重新导出');
        }
        CacheService::delete($key);
        // 中文 Content-Disposition 在 Swoole 下易导致头错乱/被标成 text/html；下载名用 ASCII，展示名由前端 file_name 决定
        $asciiName = 'stock_import_template.xlsx';
        return download($path, $asciiName)
            ->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->force(true);
    }

    /**
     * 规范化 product_ids：数组/逗号串 → 去重正整数
     */
    protected function normalizeProductIds($raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (is_string($raw)) {
            $raw = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /**
     * 本地相对路径拼接为可下载 URL；剥离 site_url 中的 /static/html/pc.html 等前端入口
     */
    protected function buildDownloadUrl(string $filePath): string
    {
        $filePath = trim($filePath);
        if ($filePath === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $filePath)) {
            return $filePath;
        }
        $site = (string)sys_config('site_url');
        $parts = parse_url($site) ?: [];
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return rtrim($site, '/') . '/' . ltrim($filePath, '/');
        }
        $origin = $scheme . '://' . $host;
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return rtrim($origin, '/') . '/' . ltrim($filePath, '/');
    }

    /**
     * 整表预校验后一次入账
     */
    public function importFile(string $scene, string $filePath, int $type, int $relationId, int $adminId, string $adminName = '', string $originName = ''): array
    {
        $this->assertScene($scene);
        if (!$filePath || !is_file($filePath)) {
            throw new ValidateException('请上传 xlsx 文件');
        }
        $fileHash = md5_file($filePath) ?: '';
        if ($fileHash === '') {
            throw new ValidateException('无法计算文件指纹，请重新上传');
        }

        [$idempotentId, $attemptToken] = $this->claimIdempotent($scene, $type, $relationId, $fileHash, $adminId);
        $importKey = $this->buildImportKey($scene, $type, $relationId, $fileHash);

        try {
            $rows = $this->readExcelRows($filePath);
            if (!$rows) {
                throw new ValidateException('数据不能为空');
            }
            if (count($rows) > self::IMPORT_MAX_ROWS) {
                throw new ValidateException('单次导入不能超过 ' . self::IMPORT_MAX_ROWS . ' 行');
            }

            [$errors, $details, $stockTime, $orderType, $remarkParts] = $this->validateRows($scene, $rows, $type, $relationId);
            $importType = $this->importTypeCode($scene);
            /** @var ImportRecordServices $recordServices */
            $recordServices = app()->make(ImportRecordServices::class);

            // 预校验失败：写失败记录并释放占位（允许改表重试）
            if ($errors || !$details) {
                $failCount = $errors ? count($errors) : 1;
                $record = $recordServices->save([
                    'name' => $originName ? (string)pathinfo($originName, PATHINFO_FILENAME) : $this->sceneTitle($scene),
                    'import_type' => $importType,
                    'type' => $type,
                    'relation_id' => $relationId,
                    'total_count' => count($rows),
                    'fail_count' => $failCount,
                    'status' => -1,
                    'admin_id' => $adminId,
                    'admin_name' => $adminName ?: '系统',
                    'add_time' => time(),
                ]);
                $recordId = (int)$record->id;
                if ($errors) {
                    /** @var ImportRecordErrorServices $errorServices */
                    $errorServices = app()->make(ImportRecordErrorServices::class);
                    $saveErrors = [];
                    foreach ($errors as $err) {
                        $saveErrors[] = [
                            'record_id' => $recordId,
                            'original_data' => json_encode($err, JSON_UNESCAPED_UNICODE),
                            'fail_msg' => mb_substr($err['fail_msg'], 0, 500),
                        ];
                    }
                    $errorServices->saveAll($saveErrors);
                }
                $this->markIdempotent($idempotentId, $attemptToken, -1, $recordId);
                if ($errors) {
                    $msg = $errors[0]['fail_msg'] ?? '导入校验失败';
                    if (count($errors) > 1) {
                        $msg .= '（共 ' . count($errors) . ' 处错误，详见导入记录）';
                    }
                    throw new ValidateException($msg);
                }
                throw new ValidateException('没有可导入的有效数据行（请至少填写一行数量）');
            }

            $remark = '[Excel导入]' . ($remarkParts ? (' ' . implode('；', array_slice(array_unique($remarkParts), 0, 5))) : '');
            $stockType = $scene === self::SCENE_OUT ? 2 : 1;
            $payload = [
                'order_type' => $orderType,
                'stock_time' => $stockTime,
                'remark' => mb_substr($remark, 0, 200),
                'import_key' => $importKey,
            ];
            if ($stockType === 1) {
                $payload['in_product_detail'] = $details;
            } else {
                $payload['out_product_detail'] = $details;
            }

            // 入账关键路径：幂等确认 + 库存单/库存 + 导入记录成功 + 幂等成功 同一事务
            // 失败记录不得写入本事务（回滚后须在 catch 中另开事务重建）
            $recordId = 0;
            Db::transaction(function () use (
                $scene,
                $idempotentId,
                $attemptToken,
                $importType,
                $type,
                $relationId,
                $adminId,
                $adminName,
                $originName,
                $rows,
                $details,
                $stockType,
                $payload,
                $recordServices,
                &$recordId
            ) {
                $owned = Db::name('stock_import_idempotent')
                    ->where('id', $idempotentId)
                    ->where('attempt_token', $attemptToken)
                    ->where('status', 0)
                    ->lock(true)
                    ->find();
                if (!$owned) {
                    throw new ValidateException('导入状态已变更，请勿重复提交');
                }

                $record = $recordServices->save([
                    'name' => $originName ? (string)pathinfo($originName, PATHINFO_FILENAME) : $this->sceneTitle($scene),
                    'import_type' => $importType,
                    'type' => $type,
                    'relation_id' => $relationId,
                    'total_count' => count($rows),
                    'fail_count' => 0,
                    'status' => 0,
                    'admin_id' => $adminId,
                    'admin_name' => $adminName ?: '系统',
                    'add_time' => time(),
                ]);
                $recordId = (int)$record->id;

                /** @var StoreProductStockOrderServices $orderServices */
                $orderServices = app()->make(StoreProductStockOrderServices::class);
                // isTran=false：并入本外层事务，避免库存先提交、幂等后标成功
                $orderServices->saveData($stockType, $payload, $type, $relationId, $adminId, true, false);

                $recordServices->update($recordId, [
                    'status' => 1,
                    'fail_count' => 0,
                    'jump_count' => max(0, count($rows) - count($details)),
                ]);

                $affected = Db::name('stock_import_idempotent')
                    ->where('id', $idempotentId)
                    ->where('attempt_token', $attemptToken)
                    ->where('status', 0)
                    ->update([
                        'status' => 1,
                        'record_id' => $recordId,
                        'update_time' => time(),
                    ]);
                if (!$affected) {
                    throw new ValidateException('导入状态已变更，请勿重复提交');
                }
            });

            return [
                'record_id' => $recordId,
                'success_count' => count($details),
                'order_type' => $orderType,
                'stock_time' => $stockTime,
            ];
        } catch (\Throwable $e) {
            // 预校验失败路径已写失败记录并标幂等失败；入账事务回滚后 status 仍为 0，须在此重建失败记录
            $stillProcessing = Db::name('stock_import_idempotent')
                ->where('id', $idempotentId)
                ->where('attempt_token', $attemptToken)
                ->where('status', 0)
                ->find();
            if ($stillProcessing) {
                $totalCount = isset($rows) && is_array($rows) ? count($rows) : 0;
                $this->saveFailRecordAndMarkIdempotent(
                    $idempotentId,
                    $attemptToken,
                    $scene,
                    $type,
                    $relationId,
                    $adminId,
                    $adminName,
                    $originName,
                    $totalCount,
                    $e
                );
            }
            throw $e;
        }
    }

    /**
     * 入账回滚后：失败记录 + 幂等失败同事务；写失败保护原始入账异常
     */
    protected function saveFailRecordAndMarkIdempotent(
        int $idempotentId,
        string $attemptToken,
        string $scene,
        int $type,
        int $relationId,
        int $adminId,
        string $adminName,
        string $originName,
        int $totalCount,
        \Throwable $e
    ): void {
        try {
            Db::transaction(function () use (
                $idempotentId,
                $attemptToken,
                $scene,
                $type,
                $relationId,
                $adminId,
                $adminName,
                $originName,
                $totalCount,
                $e
            ) {
                $failMsg = mb_substr('入账失败：' . $e->getMessage(), 0, 500);
                /** @var ImportRecordServices $recordServices */
                $recordServices = app()->make(ImportRecordServices::class);
                $record = $recordServices->save([
                    'name' => $originName ? (string)pathinfo($originName, PATHINFO_FILENAME) : $this->sceneTitle($scene),
                    'import_type' => $this->importTypeCode($scene),
                    'type' => $type,
                    'relation_id' => $relationId,
                    'total_count' => max(0, $totalCount),
                    'fail_count' => 1,
                    'status' => -1,
                    'admin_id' => $adminId,
                    'admin_name' => $adminName ?: '系统',
                    'add_time' => time(),
                ]);
                $recordId = (int)$record->id;
                /** @var ImportRecordErrorServices $errorServices */
                $errorServices = app()->make(ImportRecordErrorServices::class);
                $errorServices->save([
                    'record_id' => $recordId,
                    'original_data' => json_encode([
                        'scene' => $scene,
                        'fail_msg' => $e->getMessage(),
                    ], JSON_UNESCAPED_UNICODE),
                    'fail_msg' => $failMsg,
                ]);
                $affected = Db::name('stock_import_idempotent')
                    ->where('id', $idempotentId)
                    ->where('attempt_token', $attemptToken)
                    ->where('status', 0)
                    ->update([
                        'status' => -1,
                        'record_id' => $recordId,
                        'update_time' => time(),
                    ]);
                if (!$affected) {
                    throw new ValidateException('导入状态已变更');
                }
            });
        } catch (\Throwable $writeEx) {
            // 保留原始入账错误给调用方；尽量单独标失败以免永久处理中
            try {
                $this->markIdempotent($idempotentId, $attemptToken, -1, 0);
            } catch (\Throwable $ignore) {
            }
        }
    }

    protected function buildImportKey(string $scene, int $type, int $relationId, string $fileHash): string
    {
        return $scene . '|' . $type . '|' . $relationId . '|' . $fileHash;
    }

    /**
     * 原子占位：返回 [id, attempt_token]
     * 成功永久不可再导；仅失败可重试；处理中禁止超时抢走
     * @return array{0:int,1:string}
     */
    protected function claimIdempotent(string $scene, int $type, int $relationId, string $fileHash, int $adminId): array
    {
        $now = time();
        $token = bin2hex(random_bytes(16));
        try {
            return Db::transaction(function () use ($scene, $type, $relationId, $fileHash, $adminId, $now, $token) {
                $row = Db::name('stock_import_idempotent')
                    ->where('scene', $scene)
                    ->where('type', $type)
                    ->where('relation_id', $relationId)
                    ->where('file_hash', $fileHash)
                    ->lock(true)
                    ->find();
                if ($row) {
                    $status = (int)$row['status'];
                    if ($status === 1) {
                        throw new ValidateException('相同文件已成功导入过，请勿重复提交');
                    }
                    if ($status === 0) {
                        throw new ValidateException('相同文件正在导入中，请稍后再试');
                    }
                    // 仅失败可重试
                    Db::name('stock_import_idempotent')->where('id', (int)$row['id'])->update([
                        'status' => 0,
                        'attempt_token' => $token,
                        'admin_id' => $adminId,
                        'record_id' => 0,
                        'add_time' => $now,
                        'update_time' => $now,
                    ]);
                    return [(int)$row['id'], $token];
                }
                $id = (int)Db::name('stock_import_idempotent')->insertGetId([
                    'scene' => $scene,
                    'type' => $type,
                    'relation_id' => $relationId,
                    'file_hash' => $fileHash,
                    'attempt_token' => $token,
                    'status' => 0,
                    'record_id' => 0,
                    'admin_id' => $adminId,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
                return [$id, $token];
            });
        } catch (ValidateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate') !== false || stripos($msg, '1062') !== false) {
                $row = Db::name('stock_import_idempotent')
                    ->where('scene', $scene)
                    ->where('type', $type)
                    ->where('relation_id', $relationId)
                    ->where('file_hash', $fileHash)
                    ->find();
                if ($row) {
                    $status = (int)$row['status'];
                    if ($status === 1) {
                        throw new ValidateException('相同文件已成功导入过，请勿重复提交');
                    }
                    if ($status === 0) {
                        throw new ValidateException('相同文件正在导入中，请稍后再试');
                    }
                }
                throw new ValidateException('相同文件正在处理，请稍后再试');
            }
            throw $e;
        }
    }

    /**
     * 仅当 attempt_token 匹配且仍为处理中时更新状态
     */
    protected function markIdempotent(int $id, string $attemptToken, int $status, int $recordId = 0): void
    {
        if ($id <= 0 || $attemptToken === '') {
            return;
        }
        $update = [
            'status' => $status,
            'update_time' => time(),
        ];
        if ($recordId > 0) {
            $update['record_id'] = $recordId;
        }
        Db::name('stock_import_idempotent')
            ->where('id', $id)
            ->where('attempt_token', $attemptToken)
            ->where('status', 0)
            ->update($update);
    }

    /**
     * 预校验：批量加载商品/SKU，避免逐行查库
     * @return array{0:array,1:array,2:string,3:int,4:array}
     */
    protected function validateRows(string $scene, array $rows, int $type, int $relationId): array
    {
        $parsed = [];
        $errors = [];
        $seenUnique = [];
        $productIds = [];
        $uniques = [];

        foreach ($rows as $item) {
            $excelRow = (int)$item['excel_row'];
            $data = $item['data'];
            $productId = (int)trim((string)($data[0] ?? ''));
            $unique = trim((string)($data[1] ?? ''));
            $goodQty = trim((string)($data[7] ?? ''));
            $defQty = trim((string)($data[8] ?? ''));
            $bizDate = trim((string)($data[9] ?? ''));
            $typeLabel = trim((string)($data[10] ?? ''));
            $remark = trim((string)($data[11] ?? ''));

            // 空行，或模板预填行未填任何数量：跳过（只导入填写了数量的规格）
            if ($goodQty === '' && $defQty === '') {
                continue;
            }

            $rowErrors = [];
            if ($productId <= 0) {
                $rowErrors[] = '商品ID无效';
            }
            if ($unique === '') {
                $rowErrors[] = 'SKU唯一值不能为空';
            }
            if ($unique !== '' && isset($seenUnique[$unique])) {
                $rowErrors[] = 'SKU 与第' . $seenUnique[$unique] . '行重复';
            }
            $good = $goodQty === '' ? 0.0 : (float)$goodQty;
            $def = $defQty === '' ? 0.0 : (float)$defQty;
            if ($goodQty !== '' && (!is_numeric($goodQty) || $good < 0)) {
                $rowErrors[] = '良品数量须为不小于0的数字';
            }
            if ($defQty !== '' && (!is_numeric($defQty) || $def < 0)) {
                $rowErrors[] = '残次品数量须为不小于0的数字';
            }
            if ($goodQty !== '' || $defQty !== '') {
                if ($good == 0.0 && $def == 0.0) {
                    $rowErrors[] = '良品与残次品数量不能同为0';
                }
            }
            if ($bizDate === '') {
                $rowErrors[] = '业务日期不能为空';
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bizDate) || !strtotime($bizDate)) {
                $rowErrors[] = '业务日期格式须为 Y-m-d';
            }

            $parsedType = $this->parseOrderType($scene, $typeLabel);
            if ($parsedType <= 0) {
                $rowErrors[] = '出入库类型不正确';
            }

            if ($rowErrors) {
                $errors[] = [
                    'excel_row' => $excelRow,
                    'fail_msg' => '第' . $excelRow . '行：' . implode('；', $rowErrors),
                    'product_id' => $productId,
                    'unique' => $unique,
                ];
                continue;
            }

            $seenUnique[$unique] = $excelRow;
            if ($productId > 0) {
                $productIds[$productId] = $productId;
            }
            if ($unique !== '') {
                $uniques[$unique] = $unique;
            }
            $parsed[] = [
                'excel_row' => $excelRow,
                'product_id' => $productId,
                'unique' => $unique,
                'good' => $good,
                'def' => $def,
                'biz_date' => $bizDate,
                'order_type' => $parsedType,
                'remark' => $remark,
            ];
        }

        $productMap = [];
        if ($productIds) {
            $productRows = StoreProduct::whereIn('id', array_values($productIds))->select()->toArray();
            foreach ($productRows as $prow) {
                $productMap[(int)$prow['id']] = $prow;
            }
        }
        $attrMap = [];
        if ($uniques) {
            $attrRows = StoreProductAttrValue::whereIn('unique', array_values($uniques))
                ->where('type', 0)
                ->select()
                ->toArray();
            foreach ($attrRows as $arow) {
                $attrMap[(int)$arow['product_id'] . ':' . (string)$arow['unique']] = $arow;
            }
        }

        $details = [];
        $stockTime = '';
        $orderType = $this->defaultOrderTypeValue($scene);
        $remarkParts = [];

        foreach ($parsed as $row) {
            $excelRow = (int)$row['excel_row'];
            $productId = (int)$row['product_id'];
            $unique = (string)$row['unique'];
            $rowErrors = [];
            $attr = $attrMap[$productId . ':' . $unique] ?? null;
            $product = $productMap[$productId] ?? null;
            if (!$attr) {
                $rowErrors[] = 'SKU 不存在或不属于该商品';
            } elseif (!$product || (int)($product['is_del'] ?? 1) === 1) {
                $rowErrors[] = '商品不存在或已删除';
            } elseif ((int)($product['is_inventory'] ?? 0) !== 1) {
                $rowErrors[] = 'SKU 已关闭参与库存';
            } elseif ((int)($product['type'] ?? -1) !== $type || (int)($product['relation_id'] ?? -1) !== $relationId) {
                $rowErrors[] = '商品不属于当前平台/门店';
            } elseif ($scene === self::SCENE_OUT) {
                $allowNeg = (int)($product['allow_negative_stock'] ?? 1) === 1;
                $curStock = (float)($attr['stock'] ?? 0);
                $curDef = (float)($attr['defective_stock'] ?? 0);
                if (!$allowNeg && (float)$row['good'] > $curStock) {
                    $rowErrors[] = '良品库存不足';
                }
                if ((float)$row['def'] > $curDef) {
                    $rowErrors[] = '残次品库存不足';
                }
            }

            if ($rowErrors) {
                $errors[] = [
                    'excel_row' => $excelRow,
                    'fail_msg' => '第' . $excelRow . '行：' . implode('；', $rowErrors),
                    'product_id' => $productId,
                    'unique' => $unique,
                ];
                continue;
            }

            if ($stockTime === '') {
                $stockTime = $row['biz_date'];
                $orderType = (int)$row['order_type'];
            } elseif ($stockTime !== $row['biz_date']) {
                $errors[] = [
                    'excel_row' => $excelRow,
                    'fail_msg' => '第' . $excelRow . '行：同一文件业务日期须一致（当前为 ' . $stockTime . '）',
                    'product_id' => $productId,
                    'unique' => $unique,
                ];
                continue;
            } elseif ($orderType !== (int)$row['order_type']) {
                $errors[] = [
                    'excel_row' => $excelRow,
                    'fail_msg' => '第' . $excelRow . '行：同一文件出入库类型须一致',
                    'product_id' => $productId,
                    'unique' => $unique,
                ];
                continue;
            }
            if ($row['remark'] !== '') {
                $remarkParts[] = $row['remark'];
            }
            /** @var StockQtyValidateServices $qtyValidate */
            $qtyValidate = app()->make(StockQtyValidateServices::class);
            $scale = $qtyValidate->resolveScale(
                (int)($product['product_type'] ?? 0),
                (int)($product['is_inventory'] ?? 0),
                (int)($product['salon_stock_enabled'] ?? 0)
            );
            $goodsLabel = (string)($product['store_name'] ?? '') . '/' . (string)($attr['suk'] ?? $unique);
            try {
                $goodQty = $qtyValidate->assertQty($row['good'] === '' || $row['good'] === null ? 0 : $row['good'], $scale, $goodsLabel, $excelRow);
                $defQty = $qtyValidate->assertQty($row['def'] === '' || $row['def'] === null ? 0 : $row['def'], $scale, $goodsLabel . '(残次)', $excelRow);
            } catch (\mohe\exceptions\AdminException $e) {
                $errors[] = [
                    'excel_row' => $excelRow,
                    'fail_msg' => $e->getMessage(),
                    'product_id' => $productId,
                    'unique' => $unique,
                ];
                continue;
            }
            $details[] = [
                'product_id' => $productId,
                'unique' => $unique,
                'stock' => $goodQty,
                'defective_stock' => $defQty,
                'excel_row' => $excelRow,
            ];
        }

        return [$errors, $details, $stockTime, $orderType, $remarkParts];
    }

    protected function assertScene(string $scene): void
    {
        if (!in_array($scene, [self::SCENE_INITIAL_IN, self::SCENE_IN, self::SCENE_OUT], true)) {
            throw new ValidateException('导入场景不正确');
        }
    }

    protected function sceneTitle(string $scene): string
    {
        return [
            self::SCENE_INITIAL_IN => '初始入库导入',
            self::SCENE_IN => '入库导入',
            self::SCENE_OUT => '出库导入',
        ][$scene] ?? '库存导入';
    }

    protected function importTypeCode(string $scene): string
    {
        return [
            self::SCENE_INITIAL_IN => 'stock_initial_in',
            self::SCENE_IN => 'stock_in',
            self::SCENE_OUT => 'stock_out',
        ][$scene];
    }

    protected function defaultOrderTypeLabel(string $scene): string
    {
        if ($scene === self::SCENE_INITIAL_IN) {
            return '6-初始入库';
        }
        if ($scene === self::SCENE_IN) {
            return '1-采购入库';
        }
        return '6-其他出库';
    }

    protected function defaultOrderTypeValue(string $scene): int
    {
        if ($scene === self::SCENE_INITIAL_IN) {
            return 6;
        }
        if ($scene === self::SCENE_IN) {
            return 1;
        }
        return 6;
    }

    /**
     * 初始入库仅精确接受：空 / 6 / 6-初始入库 / 初始入库
     * 普通入库/出库仅接受白名单标签，禁止出入库命名空间混用
     */
    protected function parseOrderType(string $scene, string $label): int
    {
        $label = trim($label);
        if ($scene === self::SCENE_INITIAL_IN) {
            return in_array($label, ['', '6', '6-初始入库', '初始入库'], true) ? 6 : 0;
        }
        if ($scene === self::SCENE_IN) {
            $map = [
                '' => 1,
                '1' => 1,
                '1-采购入库' => 1,
                '采购入库' => 1,
                '2' => 2,
                '2-其他入库' => 2,
                '其他入库' => 2,
            ];
            return $map[$label] ?? 0;
        }
        $map = [
            '' => 6,
            '2' => 2,
            '2-过期退货' => 2,
            '过期退货' => 2,
            '3' => 3,
            '3-试用出库' => 3,
            '试用出库' => 3,
            '4' => 4,
            '4-报废出库' => 4,
            '报废出库' => 4,
            '6' => 6,
            '6-其他出库' => 6,
            '其他出库' => 6,
        ];
        return $map[$label] ?? 0;
    }

    /**
     * 读取 xlsx，保留 Excel 真实行号
     * @return array<int, array{excel_row:int,data:array}>
     */
    protected function readExcelRows(string $filePath): array
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            throw new ValidateException('必须上传 xlsx 格式文件');
        }
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
            $spreadsheet = $reader->load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = (int)$sheet->getHighestRow();
            if ($highestRow < 2) {
                throw new ValidateException('数据不能为空');
            }
            $headerRow = 1;
            for ($r = 1; $r <= min(5, $highestRow); $r++) {
                $v = trim((string)$sheet->getCellByColumnAndRow(1, $r)->getValue());
                // 须精确匹配表头「商品ID」；说明行文案也含「商品ID」不能用 strpos
                if ($v === '商品ID') {
                    $headerRow = $r;
                    break;
                }
            }
            $headers = [];
            for ($c = 1; $c <= count(self::HEADERS); $c++) {
                $headers[] = trim((string)$sheet->getCellByColumnAndRow($c, $headerRow)->getValue());
            }
            if (($headers[0] ?? '') !== '商品ID') {
                throw new ValidateException('导入文件格式不正确：首列须为「商品ID」');
            }
            $rows = [];
            for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
                $data = [];
                $empty = true;
                for ($c = 1; $c <= count(self::HEADERS); $c++) {
                    $val = $sheet->getCellByColumnAndRow($c, $r)->getFormattedValue();
                    if ($val === null) {
                        $val = '';
                    }
                    $val = trim((string)$val);
                    if ($val !== '') {
                        $empty = false;
                    }
                    $data[] = $val;
                }
                if ($empty) {
                    continue;
                }
                $rows[] = ['excel_row' => $r, 'data' => $data];
            }
            return $rows;
        } catch (ValidateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ValidateException('读取 Excel 失败：' . $e->getMessage());
        }
    }
}
