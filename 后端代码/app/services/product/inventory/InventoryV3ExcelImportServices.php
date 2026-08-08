<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;
use mohe\services\SpreadsheetExcelService;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * V3 stock Excel import. It borrows the established template/upload workflow,
 * but deliberately writes only the V3 batch authority through the manual
 * inbound/outbound services. Legacy stock ledger tables are never touched.
 */
final class InventoryV3ExcelImportServices
{
    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';
    private const TEMPLATE_MAX_ROWS = 2000;
    private const IMPORT_MAX_ROWS = 5000;
    private const UPLOAD_MAX_BYTES = 20 * 1024 * 1024;

    private const INBOUND_HEADERS = [
        '商品ID', 'SKU唯一值(unique禁止修改)', '商品名称', '规格', '商品编码', '条形码', '基本单位',
        '良品数量', '残次品数量', '业务日期(Y-m-d)', '入库类型', '批次号', '生产日期(Y-m-d)',
        '到期日(Y-m-d)', '入库单价(元)', '备注',
    ];
    private const OUTBOUND_HEADERS = [
        '商品ID', 'SKU唯一值(unique禁止修改)', '商品名称', '规格', '商品编码', '条形码', '基本单位',
        '良品数量', '残次品数量', '业务日期(Y-m-d)', '出库类型', '备注',
    ];

    public function downloadTemplate(string $direction, int $storeId)
    {
        $this->assertDirection($direction);
        $rows = $this->catalogRows($storeId, true);
        if (count($rows) > self::TEMPLATE_MAX_ROWS) {
            throw new ValidateException('可导出商品超过' . self::TEMPLATE_MAX_ROWS . '行，请先在商品管理缩小库存商品范围');
        }
        $today = date('Y-m-d');
        $content = [];
        foreach ($rows as $row) {
            $base = [
                (string)$row['product_id'], (string)$row['sku_unique'], (string)$row['product_name'],
                (string)$row['sku_name'], (string)$row['product_code'], (string)$row['barcode'],
                (string)$row['stock_unit'], '', '', $today,
            ];
            if ($direction === self::DIRECTION_INBOUND) {
                $content[] = array_merge($base, ['采购入库', '', '', '', '', '']);
            } else {
                $content[] = array_merge($base, ['其他出库', '']);
            }
        }
        $title = $direction === self::DIRECTION_INBOUND ? 'V3入库导入模板' : 'V3出库导入模板';
        $notice = '只填写数量和业务字段；商品ID、SKU唯一值禁止修改；残次品数量非零将整行拒绝；'
            . '同一文件须使用同一业务日期和类型；整表校验通过才写入V3批次库存。';
        $name = $title . '_' . date('YmdHis') . '_' . substr((string)microtime(true), -6);
        $path = SpreadsheetExcelService::instance()
            ->setExcelHeader($this->headers($direction))
            ->setExcelTile($title, $title, $notice)
            ->setExcelContent($content)
            ->excelSave($name, 'xlsx', true);
        $absolute = app()->getRootPath() . 'public' . $path;
        if (!$path || !is_file($absolute)) {
            throw new ValidateException('模板文件生成失败，请重试');
        }
        return download($absolute, 'inventory_v3_import_template.xlsx')
            ->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->force(true);
    }

    /**
     * Stores an import workbook outside the image attachment workflow. The
     * attachment endpoint deliberately validates images, so it must not be
     * reused for inventory Excel files.
     *
     * @return array{src:string,real_name:string}
     */
    public function storeUploadedFile($file): array
    {
        if (!$file || !method_exists($file, 'getOriginalName') || !method_exists($file, 'getPathname')) {
            throw new ValidateException('请选择xlsx文件');
        }
        $name = trim((string)$file->getOriginalName());
        if (!preg_match('/\.xlsx$/i', $name)) {
            throw new ValidateException('仅支持xlsx格式的导入文件');
        }
        $size = method_exists($file, 'getSize') ? (int)$file->getSize() : 0;
        if ($size <= 0 || $size > self::UPLOAD_MAX_BYTES) {
            throw new ValidateException('导入文件不能为空且不能超过20MB');
        }
        $source = (string)$file->getPathname();
        if ($source === '' || !is_file($source)) {
            throw new ValidateException('上传文件读取失败，请重新选择');
        }
        $directory = public_path() . 'uploads/inventory-import/' . date('Ym') . '/';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ValidateException('导入文件暂存目录创建失败');
        }
        try {
            $token = bin2hex(random_bytes(16));
        } catch (\Throwable $exception) {
            $token = md5(uniqid('inventory-import-', true));
        }
        $fileName = 'inventory-import-' . date('YmdHis') . '-' . $token . '.xlsx';
        $destination = $directory . $fileName;
        if (!move_uploaded_file($source, $destination) && !@rename($source, $destination)) {
            throw new ValidateException('导入文件保存失败，请重新上传');
        }
        return ['src' => '/uploads/inventory-import/' . date('Ym') . '/' . $fileName, 'real_name' => mb_substr($name, 0, 180)];
    }

    public function importFile(string $direction, int $storeId, int $operatorId, string $fileRef, string $originName = ''): array
    {
        $this->assertDirection($direction);
        if ($storeId <= 0 || $operatorId <= 0) {
            throw new ValidateException('当前门店登录状态无效，请重新登录');
        }
        $filePath = $this->resolveUploadedFile($fileRef);
        $fileHash = md5_file($filePath) ?: '';
        if ($fileHash === '') {
            throw new ValidateException('无法读取导入文件，请重新上传');
        }

        $record = $this->claimRecord($direction, $storeId, $operatorId, $fileHash, $originName);
        try {
            $rows = $this->readExcelRows($filePath, $direction);
            if (!$rows) throw new ValidateException('数据不能为空');
            if (count($rows) > self::IMPORT_MAX_ROWS) throw new ValidateException('单次导入不能超过' . self::IMPORT_MAX_ROWS . '行');
            [$errors, $command, $businessType] = $this->validateRows($direction, $storeId, $rows);
            if ($errors) {
                $this->failRecord((int)$record['id'], $errors, count($rows), '导入校验失败');
                $message = (string)$errors[0]['message'];
                if (count($errors) > 1) $message .= '（共' . count($errors) . '处错误，详见导入记录）';
                throw new ValidateException($message);
            }

            $authorityInput = [
                'idempotency_key' => 'inventory-import-' . ($direction === self::DIRECTION_INBOUND ? 'in' : 'out') . '-' . $storeId . '-' . $fileHash,
                'business_date' => (string)$command['business_date'],
                'remark' => $this->buildRemark($businessType, (array)$command['remark_parts']),
                'lines' => (array)$command['lines'],
            ];
            Db::name('inventory_v3_import_record')->where('id', (int)$record['id'])->where('status', 'PROCESSING')->update([
                'business_type' => $businessType, 'total_count' => count($authorityInput['lines']), 'updated_at' => time(),
            ]);

            $result = Db::transaction(function () use ($direction, $storeId, $operatorId, $record, $authorityInput, $businessType): array {
                $owned = Db::name('inventory_v3_import_record')
                    ->where('id', (int)$record['id'])->where('status', 'PROCESSING')->lock(true)->find();
                if (!$owned) throw new ValidateException('导入状态已变更，请刷新导入记录后重试');
                $authority = $direction === self::DIRECTION_INBOUND
                    ? (new InventoryManualInboundServices())->createForImport($storeId, $operatorId, $authorityInput)
                    : (new InventoryManualOutboundServices())->createForImport($storeId, $operatorId, $authorityInput);
                Db::name('inventory_v3_import_record')->where('id', (int)$record['id'])->update([
                    'business_type' => $businessType,
                    'total_count' => count($authorityInput['lines']),
                    'success_count' => count($authorityInput['lines']),
                    'failure_count' => 0,
                    'document_no' => (string)($authority['document_no'] ?? ''),
                    'status' => 'SUCCEEDED',
                    'failure_message' => '',
                    'updated_at' => time(),
                ]);
                return $authority;
            });
            return [
                'record_id' => (int)$record['id'],
                'document_no' => (string)($result['document_no'] ?? ''),
                'success_count' => count($authorityInput['lines']),
                'idempotent' => !empty($result['lines']) && !empty($result['lines'][0]['idempotent']),
            ];
        } catch (\Throwable $exception) {
            $this->markRuntimeFailure((int)$record['id'], $exception);
            throw $exception;
        }
    }

    public function list(int $storeId, int $page = 1, int $limit = 20, string $direction = ''): array
    {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $query = Db::name('inventory_v3_import_record')->where('store_id', $storeId);
        if ($direction !== '') {
            $this->assertDirection($direction);
            $query->where('direction', $direction);
        }
        $count = (int)(clone $query)->count();
        $list = $query->order('id desc')->page($page, $limit)->select()->toArray();
        return ['count' => $count, 'list' => $list];
    }

    public function detail(int $storeId, int $recordId): array
    {
        $record = Db::name('inventory_v3_import_record')->where('id', $recordId)->where('store_id', $storeId)->find();
        if (!$record) throw new ValidateException('导入记录不存在或无权查看');
        $errors = Db::name('inventory_v3_import_error')->where('record_id', $recordId)->order('excel_row asc,id asc')->select()->toArray();
        return ['record' => $record, 'errors' => $errors];
    }

    /** Platform batch entry: one uploaded workbook is applied to each explicitly selected store. */
    public function importForPlatform(string $direction, array $allowedStoreIds, array $targetStoreIds, int $operatorId, string $fileRef, string $originName = ''): array
    {
        $this->assertDirection($direction);
        $allowed = array_values(array_unique(array_filter(array_map('intval', $allowedStoreIds), static fn (int $id): bool => $id > 0)));
        $targets = array_values(array_unique(array_filter(array_map('intval', $targetStoreIds), static fn (int $id): bool => $id > 0)));
        if (!$targets) throw new ValidateException('请选择至少一个门店。');
        if (array_diff($targets, $allowed)) throw new ValidateException('所选门店超出当前平台库存权限范围。');
        if (count($targets) > 100) throw new ValidateException('单次最多导入100家门店。');
        $results = [];
        foreach ($targets as $storeId) {
            $results[] = ['store_id' => $storeId] + $this->importFile($direction, $storeId, $operatorId, $fileRef, $originName);
        }
        return ['targets' => $results, 'success_count' => count($results), 'target_count' => count($targets)];
    }

    /** Generate one workbook containing catalog rows for every selected store. */
    public function downloadPlatformTemplate(string $direction, array $allowedTargets, array $targetTargets)
    {
        $this->assertDirection($direction);
        $allowed = array_values(array_unique(array_map('strval', $allowedTargets)));
        $targets = array_values(array_unique(array_map('strval', $targetTargets)));
        if (!$targets || array_diff($targets, $allowed)) throw new ValidateException('请选择有权限的库存仓。');
        $content = [];
        foreach ($targets as $target) {
            [$partyType, $rawId] = array_pad(explode(':', $target, 2), 2, '0');
            $partyType = strtoupper($partyType); $locationId = (int)$rawId;
            if (!in_array($partyType, ['HQ', 'STORE'], true) || ($partyType === 'STORE' && $locationId <= 0) || ($partyType === 'HQ' && $locationId <= 0)) throw new ValidateException('库存仓标识无效。');
            foreach ($this->catalogRows($partyType === 'STORE' ? $locationId : 0, true, [], [], $partyType) as $row) {
                $base = [$target . ':' . (string)$row['product_id'], (string)$row['product_id'], (string)$row['sku_unique'], (string)$row['product_name'], (string)$row['sku_name'], (string)$row['product_code'], (string)$row['barcode'], (string)$row['stock_unit'], '', '', date('Y-m-d')];
                $content[] = $direction === self::DIRECTION_INBOUND ? array_merge($base, ['采购入库', '', '', '', '', '']) : array_merge($base, ['其他出库', '']);
            }
        }
        $title = $direction === self::DIRECTION_INBOUND ? '平台多门店入库导入模板' : '平台多门店出库导入模板';
        $path = SpreadsheetExcelService::instance()->setExcelHeader(array_merge(['库存仓标识(禁止修改)'], $this->headers($direction)))->setExcelTile($title, $title, '库存仓标识禁止修改；平台将按标识分仓生成独立正式单据；整表校验通过后才写入V3库存。')->setExcelContent($content)->excelSave($title . '_' . date('YmdHis'), 'xlsx', true);
        $absolute = app()->getRootPath() . 'public' . $path;
        if (!$path || !is_file($absolute)) throw new ValidateException('模板文件生成失败，请重试');
        return download($absolute, 'inventory_v3_platform_import_template.xlsx')->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->force(true);
    }

    /** Parse a platform workbook and route each row to the warehouse encoded in column 1. */
    public function importPlatformFile(string $direction, array $allowedTargets, array $adminInfo, string $fileRef, string $originName = ''): array
    {
        $this->assertDirection($direction);
        $filePath = $this->resolveUploadedFile($fileRef);
        $allowed = array_values(array_unique(array_map('strval', $allowedTargets)));
        $groups = [];
        foreach ($this->readPlatformExcelRows($filePath, $direction) as $line) {
            // Marker format is TARGET_TYPE:LOCATION_ID:PRODUCT_ID. Keep the
            // first two segments as the warehouse key; the product id is
            // parsed by the normal row validator after removing this column.
            $marker = explode(':', trim((string)$line['data'][0]), 3);
            $target = count($marker) >= 2 ? trim($marker[0]) . ':' . trim($marker[1]) : '';
            if ($target === '' || !in_array($target, $allowed, true)) throw new ValidateException('模板中存在无权限的库存仓标识。');
            $line['data'] = array_slice($line['data'], 1);
            $groups[$target][] = $line;
        }
        if (!$groups) throw new ValidateException('数据不能为空');
        $prepared = [];
        foreach ($groups as $target => $rows) {
            [$partyType, $rawId] = array_pad(explode(':', $target, 2), 2, '0');
            $partyType = strtoupper($partyType); $locationId = (int)$rawId;
            if (!in_array($partyType, ['HQ', 'STORE'], true) || $locationId <= 0) throw new ValidateException('模板中存在无效库存仓标识。');
            [$errors, $command, $businessType] = $this->validateRows($direction, $partyType === 'STORE' ? $locationId : 0, $rows, $partyType);
            if ($errors) throw new ValidateException((string)$errors[0]['message']);
            $prepared[] = [$partyType, $locationId, $command, $businessType];
        }
        $fileHash = md5_file($filePath) ?: '';
        $results = [];
        foreach ($prepared as [$partyType, $locationId, $command, $businessType]) {
            $results[] = ['party_type' => $partyType, 'location_id' => $locationId] + $this->persistPreparedImport($direction, $partyType, $locationId, $adminInfo, $fileHash, $originName, $command, $businessType);
        }
        return ['targets' => $results, 'target_count' => count($results), 'success_count' => count($results)];
    }

    private function persistPreparedImport(string $direction, string $partyType, int $locationId, array $adminInfo, string $fileHash, string $originName, array $command, string $businessType): array
    {
        $operatorId = (int)($adminInfo['uid'] ?? $adminInfo['id'] ?? 0);
        $storeId = $partyType === 'HQ' ? 0 : $locationId;
        // A single workbook can target more than one warehouse. Scope the
        // idempotency/record hash by target so one HQ/store group cannot mask
        // another group in the same upload.
        $targetFileHash = hash('sha256', $fileHash . '|' . $partyType . '|' . $locationId);
        $record = $this->claimRecord($direction, $storeId, $operatorId, $targetFileHash, $originName);
        $input = ['idempotency_key' => 'inventory-platform-import-' . $direction . '-' . $partyType . '-' . $locationId . '-' . $targetFileHash, 'business_date' => (string)$command['business_date'], 'remark' => $this->buildRemark($businessType, (array)$command['remark_parts']), 'lines' => (array)$command['lines']];
        $authority = Db::transaction(function () use ($direction, $partyType, $locationId, $adminInfo, $storeId, $operatorId, $record, $input, $businessType): array {
            if ($partyType === 'HQ') {
                $resolved = (new InventoryHqLocationServices())->writableLocation($adminInfo, $locationId);
                $scope = (new InventoryHqLocationServices())->scope((array)$resolved['location'], $operatorId);
                $authority = $direction === self::DIRECTION_INBOUND ? (new InventoryManualInboundServices())->createForHeadquarters($scope, (array)$resolved['location'], $input, 5000) : (new InventoryManualOutboundServices())->createForHeadquarters($scope, (array)$resolved['location'], $input, 5000);
            } else {
                $authority = $direction === self::DIRECTION_INBOUND ? (new InventoryManualInboundServices())->createForImport($storeId, $operatorId, $input) : (new InventoryManualOutboundServices())->createForImport($storeId, $operatorId, $input);
            }
            Db::name('inventory_v3_import_record')->where('id', (int)$record['id'])->update(['business_type' => $businessType, 'total_count' => count($input['lines']), 'success_count' => count($input['lines']), 'failure_count' => 0, 'document_no' => (string)($authority['document_no'] ?? ''), 'status' => 'SUCCEEDED', 'updated_at' => time()]);
            return $authority;
        });
        return ['record_id' => (int)$record['id'], 'document_no' => (string)($authority['document_no'] ?? ''), 'success_count' => count($input['lines'])];
    }

    private function claimRecord(string $direction, int $storeId, int $operatorId, string $fileHash, string $originName): array
    {
        $now = time();
        try {
            return Db::transaction(function () use ($direction, $storeId, $operatorId, $fileHash, $originName, $now): array {
                $row = Db::name('inventory_v3_import_record')
                    ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                    ->where('store_id', $storeId)->where('direction', $direction)->where('file_hash', $fileHash)->lock(true)->find();
                if ($row && (string)$row['status'] === 'SUCCEEDED') {
                    throw new ValidateException('相同文件已成功导入过，请勿重复提交');
                }
                if ($row && (string)$row['status'] === 'PROCESSING') {
                    throw new ValidateException('相同文件正在导入中，请稍后再试');
                }
                if ($row) {
                    Db::name('inventory_v3_import_record')->where('id', (int)$row['id'])->update([
                        'operator_id' => $operatorId, 'source_file_name' => $this->fileName($originName),
                        'status' => 'PROCESSING', 'business_type' => '', 'total_count' => 0, 'success_count' => 0,
                        'failure_count' => 0, 'document_no' => '', 'failure_message' => '', 'updated_at' => $now,
                    ]);
                    Db::name('inventory_v3_import_error')->where('record_id', (int)$row['id'])->delete();
                    return ['id' => (int)$row['id']];
                }
                $id = Db::name('inventory_v3_import_record')->insertGetId([
                    'tenant_id' => CashierV3ScopeResolver::TENANT_SCOPE_ID, 'store_id' => $storeId, 'direction' => $direction,
                    'file_hash' => $fileHash, 'source_file_name' => $this->fileName($originName), 'business_type' => '',
                    'total_count' => 0, 'success_count' => 0, 'failure_count' => 0, 'document_no' => '', 'status' => 'PROCESSING',
                    'failure_message' => '', 'operator_id' => $operatorId, 'created_at' => $now, 'updated_at' => $now,
                ]);
                return ['id' => (int)$id];
            });
        } catch (ValidateException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ValidateException('导入文件正在处理，请稍后重试');
        }
    }

    private function failRecord(int $recordId, array $errors, int $totalCount, string $message): void
    {
        Db::transaction(function () use ($recordId, $errors, $totalCount, $message): void {
            $owned = Db::name('inventory_v3_import_record')->where('id', $recordId)->where('status', 'PROCESSING')->lock(true)->find();
            if (!$owned) return;
            foreach ($errors as $error) {
                Db::name('inventory_v3_import_error')->insert([
                    'record_id' => $recordId, 'excel_row' => (int)$error['row'], 'error_message' => mb_substr((string)$error['message'], 0, 500),
                    'row_snapshot' => json_encode($error['data'] ?? [], JSON_UNESCAPED_UNICODE), 'created_at' => time(),
                ]);
            }
            Db::name('inventory_v3_import_record')->where('id', $recordId)->update([
                'total_count' => $totalCount, 'failure_count' => count($errors), 'status' => 'FAILED',
                'failure_message' => mb_substr($message, 0, 500), 'updated_at' => time(),
            ]);
        });
    }

    private function markRuntimeFailure(int $recordId, \Throwable $exception): void
    {
        try {
            Db::transaction(function () use ($recordId, $exception): void {
                $record = Db::name('inventory_v3_import_record')->where('id', $recordId)->where('status', 'PROCESSING')->lock(true)->find();
                if (!$record) return;
                Db::name('inventory_v3_import_error')->insert([
                    'record_id' => $recordId, 'excel_row' => 0, 'error_message' => mb_substr($exception->getMessage(), 0, 500),
                    'row_snapshot' => '{}', 'created_at' => time(),
                ]);
                Db::name('inventory_v3_import_record')->where('id', $recordId)->update([
                    'failure_count' => 1, 'status' => 'FAILED', 'failure_message' => mb_substr($exception->getMessage(), 0, 500), 'updated_at' => time(),
                ]);
            });
        } catch (\Throwable $ignore) {
        }
    }

    /** @return array{0:array,1:array,2:string} */
    private function validateRows(string $direction, int $storeId, array $rows, string $partyType = 'STORE'): array
    {
        $errors = [];
        $parsed = [];
        $productIds = [];
        $uniques = [];
        $seen = [];
        foreach ($rows as $row) {
            $data = $row['data'];
            $quantity = trim((string)($data[7] ?? ''));
            $defective = trim((string)($data[8] ?? ''));
            if ($quantity === '' && $defective === '') continue;
            $rowErrors = [];
            $productId = (int)trim((string)($data[0] ?? ''));
            $skuUnique = trim((string)($data[1] ?? ''));
            if ($productId <= 0) $rowErrors[] = '商品ID无效';
            if ($skuUnique === '') $rowErrors[] = 'SKU唯一值不能为空';
            if ($skuUnique !== '' && isset($seen[$skuUnique])) $rowErrors[] = 'SKU唯一值与第' . $seen[$skuUnique] . '行重复';
            if (!preg_match('/^\d+(?:\.\d{1,4})?$/D', $quantity) || (float)$quantity <= 0) $rowErrors[] = '良品数量须为大于0的数字';
            if ($defective !== '' && (!preg_match('/^\d+(?:\.\d{1,4})?$/D', $defective) || (float)$defective != 0.0)) $rowErrors[] = '残次品数量必须为0；残次品不允许导入V3可用批次库存';
            $date = trim((string)($data[9] ?? ''));
            if (!$this->validDate($date)) $rowErrors[] = '业务日期格式须为Y-m-d';
            $type = trim((string)($data[10] ?? ''));
            if (!$this->businessType($direction, $type)) $rowErrors[] = '出入库类型不正确';
            $line = ['row' => (int)$row['row'], 'data' => $data];
            if ($direction === self::DIRECTION_INBOUND) {
                foreach ([[11, '批次号'], [12, '生产日期'], [13, '到期日'], [14, '入库单价']] as $required) {
                    if (trim((string)($data[$required[0]] ?? '')) === '') $rowErrors[] = $required[1] . '不能为空';
                }
                if (trim((string)($data[11] ?? '')) !== '' && strlen(trim((string)$data[11])) > 64) $rowErrors[] = '批次号过长';
                if (trim((string)($data[12] ?? '')) !== '' && !$this->validDate(trim((string)$data[12]))) $rowErrors[] = '生产日期格式须为Y-m-d';
                if (trim((string)($data[13] ?? '')) !== '' && !$this->validDate(trim((string)$data[13]))) $rowErrors[] = '到期日格式须为Y-m-d';
                if ($this->validDate(trim((string)($data[12] ?? ''))) && $this->validDate(trim((string)($data[13] ?? ''))) && (string)$data[12] > (string)$data[13]) $rowErrors[] = '生产日期不能晚于到期日';
                if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', trim((string)($data[14] ?? '')))) $rowErrors[] = '入库单价须为非负金额，最多两位小数';
            }
            if ($rowErrors) {
                $errors[] = ['row' => (int)$row['row'], 'message' => '第' . $row['row'] . '行：' . implode('；', $rowErrors), 'data' => $data];
                continue;
            }
            $seen[$skuUnique] = (int)$row['row'];
            $productIds[$productId] = $productId;
            $uniques[$skuUnique] = $skuUnique;
            $parsed[] = ['row' => (int)$row['row'], 'data' => $data, 'product_id' => $productId, 'sku_unique' => $skuUnique, 'quantity' => $quantity, 'date' => $date, 'type' => $type];
        }
        if (!$parsed && !$errors) $errors[] = ['row' => 0, 'message' => '请至少填写一行良品数量', 'data' => []];
        $catalog = $this->catalogRows($storeId, false, array_values($productIds), array_values($uniques), $partyType);
        $catalogMap = [];
        foreach ($catalog as $item) $catalogMap[(int)$item['product_id'] . ':' . (string)$item['sku_unique']] = $item;
        $businessDate = '';
        $businessType = '';
        $lines = [];
        $remarks = [];
        foreach ($parsed as $line) {
            $data = $line['data'];
            $catalogRow = $catalogMap[$line['product_id'] . ':' . $line['sku_unique']] ?? null;
            $rowErrors = [];
            if (!$catalogRow) $rowErrors[] = 'SKU不存在、不属于当前门店或未启用库存';
            if ($businessDate === '') {
                $businessDate = $line['date'];
                $businessType = $this->businessType($direction, $line['type']);
            } elseif ($businessDate !== $line['date']) {
                $rowErrors[] = '同一文件业务日期须一致（当前为' . $businessDate . '）';
            } elseif ($businessType !== $this->businessType($direction, $line['type'])) {
                $rowErrors[] = '同一文件出入库类型须一致';
            }
            if ($catalogRow) {
                try {
                    InventoryEntitlementCompletionContract::decimalToUnits($line['quantity'], (int)$catalogRow['quantity_scale']);
                } catch (\Throwable $exception) {
                    $rowErrors[] = '良品数量不符合该商品单位精度';
                }
            }
            if ($rowErrors) {
                $errors[] = ['row' => $line['row'], 'message' => '第' . $line['row'] . '行：' . implode('；', $rowErrors), 'data' => $data];
                continue;
            }
            if ($direction === self::DIRECTION_INBOUND) {
                $payload = [
                    'product_id' => (int)$catalogRow['product_id'], 'sku_id' => (int)$catalogRow['sku_id'],
                    'sku_unique' => (string)$catalogRow['sku_unique'], 'batch_no' => trim((string)$data[11]),
                    'quantity' => $line['quantity'], 'unit_cost' => trim((string)$data[14]),
                    'manufactured_date' => trim((string)$data[12]), 'expire_date' => trim((string)$data[13]),
                ];
                $remarks[] = trim((string)($data[15] ?? ''));
            } else {
                $payload = [
                    'product_id' => (int)$catalogRow['product_id'], 'sku_id' => (int)$catalogRow['sku_id'],
                    'sku_unique' => (string)$catalogRow['sku_unique'], 'quantity' => $line['quantity'],
                ];
                $remarks[] = trim((string)($data[11] ?? ''));
            }
            $lines[] = $payload;
        }
        return [$errors, ['business_date' => $businessDate, 'remark' => '', 'remark_parts' => $remarks, 'lines' => $lines], $businessType];
    }

    private function catalogRows(int $storeId, bool $all, array $productIds = [], array $uniques = [], string $partyType = 'STORE'): array
    {
        $partyType = strtoupper($partyType);
        $query = Db::name('store_product')->alias('p')
            ->join('store_product_attr_value a', 'a.product_id=p.id')
            ->where('p.type', $partyType === 'HQ' ? 0 : 1)->where('p.relation_id', $partyType === 'HQ' ? 0 : $storeId)->where('p.is_del', 0)->where('p.is_inventory', 1)->where('a.type', 0)
            ->field('p.id product_id,p.store_name product_name,p.code product_code,p.salon_stock_enabled,p.unit_name,a.id sku_id,a.unique sku_unique,a.suk sku_name,a.bar_code barcode,a.stock_unit');
        if (!$all) {
            if (!$productIds || !$uniques) return [];
            $query->whereIn('p.id', $productIds)->whereIn('a.unique', $uniques);
        }
        $rows = $query->order('p.id asc,a.id asc')->select()->toArray();
        foreach ($rows as &$row) {
            $row['quantity_scale'] = (int)($row['salon_stock_enabled'] ?? 0) === 1 ? 2 : 0;
            $row['stock_unit'] = trim((string)($row['stock_unit'] ?: $row['unit_name']));
        }
        unset($row);
        return $rows;
    }

    private function readExcelRows(string $filePath, string $direction): array
    {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
            $sheet = $reader->load($filePath)->getActiveSheet();
            $highest = (int)$sheet->getHighestRow();
            $headerRow = 0;
            for ($row = 1; $row <= min(5, $highest); $row++) {
                if (trim((string)$sheet->getCellByColumnAndRow(1, $row)->getValue()) === '商品ID') { $headerRow = $row; break; }
            }
            if ($headerRow === 0) throw new ValidateException('导入文件格式不正确：未找到商品ID表头');
            $headers = $this->headers($direction);
            foreach ($headers as $index => $header) {
                if (trim((string)$sheet->getCellByColumnAndRow($index + 1, $headerRow)->getValue()) !== $header) {
                    throw new ValidateException('导入文件格式不正确：第' . ($index + 1) . '列表头应为「' . $header . '」');
                }
            }
            $rows = [];
            for ($row = $headerRow + 1; $row <= $highest; $row++) {
                $data = []; $nonEmpty = false;
                for ($column = 1; $column <= count($headers); $column++) {
                    $value = trim((string)$sheet->getCellByColumnAndRow($column, $row)->getFormattedValue());
                    if ($value !== '') $nonEmpty = true;
                    $data[] = $value;
                }
                if ($nonEmpty) $rows[] = ['row' => $row, 'data' => $data];
            }
            return $rows;
        } catch (ValidateException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ValidateException('读取Excel失败，请确认使用下载的xlsx模板');
        }
    }

    private function readPlatformExcelRows(string $filePath, string $direction): array
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
        $sheet = $reader->load($filePath)->getActiveSheet();
        $highest = (int)$sheet->getHighestRow(); $headers = array_merge(['库存仓标识(禁止修改)'], $this->headers($direction)); $headerRow = 0;
        for ($row = 1; $row <= min(5, $highest); $row++) if (trim((string)$sheet->getCellByColumnAndRow(1, $row)->getValue()) === $headers[0]) { $headerRow = $row; break; }
        if ($headerRow === 0) throw new ValidateException('导入文件格式不正确：未找到库存仓标识表头');
        foreach ($headers as $index => $header) if (trim((string)$sheet->getCellByColumnAndRow($index + 1, $headerRow)->getValue()) !== $header) throw new ValidateException('导入文件格式不正确：第' . ($index + 1) . '列表头不匹配');
        $rows = [];
        for ($row = $headerRow + 1; $row <= $highest; $row++) { $data = []; $nonEmpty = false; foreach ($headers as $column => $_) { $value = trim((string)$sheet->getCellByColumnAndRow($column + 1, $row)->getFormattedValue()); if ($value !== '') $nonEmpty = true; $data[] = $value; } if ($nonEmpty) $rows[] = ['row' => $row, 'data' => $data]; }
        return $rows;
    }

    private function resolveUploadedFile(string $fileRef): string
    {
        $fileRef = trim(rawurldecode(parse_url($fileRef, PHP_URL_PATH) ?: ''));
        if (!preg_match('/\.xlsx$/i', $fileRef)) throw new ValidateException('必须上传xlsx文件');
        $root = realpath(public_path());
        if (!$root || str_contains($fileRef, "\0")) throw new ValidateException('上传文件不存在或已过期，请重新上传');
        $relative = ltrim($fileRef, '/');
        // Upload replies vary between /attach/... and /public/attach/...;
        // normalize both forms but never accept a filesystem path from the client.
        $candidates = [$relative];
        if (str_starts_with($relative, 'public/')) $candidates[] = substr($relative, 7);
        foreach ($candidates as $candidateRelative) {
            if ($candidateRelative === '' || str_contains($candidateRelative, '..')) continue;
            $candidate = realpath($root . DIRECTORY_SEPARATOR . $candidateRelative);
            if ($candidate && str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) && is_file($candidate)) return $candidate;
        }
        throw new ValidateException('上传文件不存在或已过期，请重新上传');
    }

    private function headers(string $direction): array { return $direction === self::DIRECTION_INBOUND ? self::INBOUND_HEADERS : self::OUTBOUND_HEADERS; }
    private function assertDirection(string $direction): void { if (!in_array($direction, [self::DIRECTION_INBOUND, self::DIRECTION_OUTBOUND], true)) throw new ValidateException('导入方向不正确'); }
    private function validDate(string $value): bool { $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value); return $date && $date->format('Y-m-d') === $value; }
    private function fileName(string $value): string { $name = trim((string)pathinfo($value, PATHINFO_FILENAME)); return mb_substr($name === '' ? '库存导入' : $name, 0, 120); }
    private function buildRemark(string $type, array $parts): string { $parts = array_values(array_unique(array_filter(array_map('trim', $parts)))); return mb_substr('[Excel导入][' . $type . ']' . ($parts ? ' ' . implode('；', array_slice($parts, 0, 5)) : ''), 0, 500); }
    private function businessType(string $direction, string $label): string
    {
        $label = trim($label);
        $inbound = ['初始入库', '采购入库', '退货入库', '其他入库'];
        $outbound = ['过期退货', '试用出库', '报废出库', '其他出库'];
        return in_array($label, $direction === self::DIRECTION_INBOUND ? $inbound : $outbound, true) ? $label : '';
    }
}
