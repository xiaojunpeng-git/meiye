<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\product\inventory\InventoryCrossSubjectTransferServices;

/** Capture only the search predicate: scope, pagination and document joins belong to the list callers. */
final class TransferSearchQuerySpy
{
    public array $terms = [];

    public function where($field, $value = null): self
    {
        if (is_callable($field)) {
            $nested = new self();
            $field($nested);
            $this->terms[] = ['GROUP', $nested->terms];
        } else {
            $this->terms[] = ['WHERE', $field, $value];
        }
        return $this;
    }

    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->terms[] = ['RAW', $sql, $bindings];
        return $this;
    }

    public function whereExists(callable $callback, string $logic = 'AND'): self
    {
        $nested = new self();
        $callback($nested);
        $this->terms[] = ['EXISTS', $logic, $nested->terms];
        return $this;
    }

    public function name(string $table): self
    {
        $this->terms[] = ['TABLE', $table];
        return $this;
    }

    public function alias(string $alias): self
    {
        $this->terms[] = ['ALIAS', $alias];
        return $this;
    }
}

$search = new ReflectionMethod(InventoryCrossSubjectTransferServices::class, 'applyListKeyword');
$service = new InventoryCrossSubjectTransferServices();
$empty = new TransferSearchQuerySpy();
$search->invoke($service, $empty, '   ');
if ($empty->terms !== []) throw new RuntimeException('empty_keyword_changed_query');

$query = new TransferSearchQuerySpy();
$search->invoke($service, $query, ' 防水防油散 ');
$group = $query->terms[0] ?? null;
$document = $group[1][0] ?? null;
$product = $group[1][1] ?? null;
if (count($query->terms) !== 1 || $group[0] !== 'GROUP'
    || $document[0] !== 'RAW'
    || strpos($document[1], 'd.transfer_no') === false
    || strpos($document[1], 'd.remark') === false
    || $document[2] !== ['transfer_search_no' => '%防水防油散%', 'transfer_search_remark' => '%防水防油散%']
    || $product[0] !== 'EXISTS' || $product[1] !== 'OR'
    || $product[2][0] !== ['TABLE', 'inventory_cross_transfer_line']
    || $product[2][1] !== ['ALIAS', 'search_line']
    || $product[2][2] !== ['RAW', 'search_line.document_id=d.id', []]
    || $product[2][3] !== ['WHERE', 'search_line.retired_at', 0]
    || strpos($product[2][4][1] ?? '', 'search_line.product_name_snapshot') === false
    || ($product[2][4][2] ?? null) !== ['transfer_search_product' => '%防水防油散%']) {
    throw new RuntimeException('transfer_product_search_contract_failed');
}

$source = (string)file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php');
if (substr_count($source, '$this->applyListKeyword($query, $keyword);') !== 2) {
    throw new RuntimeException('store_and_platform_search_diverged');
}
echo "CROSS_TRANSFER_PRODUCT_SEARCH_CONTRACT_OK\n";
