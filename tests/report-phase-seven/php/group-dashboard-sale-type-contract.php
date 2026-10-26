<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\query\metric\RegisteredMetricReadServices;

/** Tiny catalog fake isolates the current platform setting from a stale store copy. */
final class SaleTypeCatalogQuery
{
    private array $rows;
    private array $ids = [];

    public function __construct(array $rows) { $this->rows = $rows; }
    public function whereIn(string $column, array $ids): self { $this->ids = array_map('intval', $ids); return $this; }
    public function field(string $fields): self { return $this; }
    public function select(): object
    {
        $rows = array_values(array_filter($this->rows, fn(array $row): bool => in_array((int)$row['id'], $this->ids, true)));
        return new class($rows) {
            private array $rows;
            public function __construct(array $rows) { $this->rows = $rows; }
            public function toArray(): array { return $this->rows; }
        };
    }
}

$catalog = [
    ['id' => 101, 'pid' => 201, 'type' => 1, 'relation_id' => 8, 'product_type' => 5, 'card_statistics_type' => 0],
    ['id' => 201, 'pid' => 0, 'type' => 0, 'relation_id' => 0, 'product_type' => 5, 'card_statistics_type' => 1],
    ['id' => 202, 'pid' => 0, 'type' => 0, 'relation_id' => 0, 'product_type' => 5, 'card_statistics_type' => 0],
    ['id' => 203, 'pid' => 0, 'type' => 0, 'relation_id' => 0, 'product_type' => 0, 'card_statistics_type' => 1],
    // The custom-card shell is a project in the legacy catalog, not a new
    // statistical type. Platform settings still override stale store copies.
    ['id' => 8154, 'pid' => 0, 'type' => 0, 'relation_id' => 0, 'product_type' => 6, 'card_statistics_type' => 0],
    ['id' => 301, 'pid' => 8154, 'type' => 1, 'relation_id' => 8, 'product_type' => 6, 'card_statistics_type' => 1],
    ['id' => 302, 'pid' => 0, 'type' => 0, 'relation_id' => 0, 'product_type' => 6, 'card_statistics_type' => 0],
    ['id' => 303, 'pid' => 8154, 'type' => 0, 'relation_id' => 0, 'product_type' => 6, 'card_statistics_type' => 0],
];
$reader = new RegisteredMetricReadServices(
    static function (string $table) use ($catalog): SaleTypeCatalogQuery {
        if ($table !== 'store_product') throw new RuntimeException('Unexpected catalog table');
        return new SaleTypeCatalogQuery($catalog);
    },
    static function (): void {},
    static function (): void {}
);
$method = new ReflectionMethod($reader, 'dashboardCardStatisticsTypes');
$method->setAccessible(true);
$custom = [];
$types = $method->invokeArgs($reader, [[101, 202, 203, 8154, 301, 302, 303, 999], &$custom]);
if ($types !== [101 => 1, 202 => 0, 8154 => 0, 301 => 0]) {
    throw new RuntimeException('Card statistics must use the platform setting and exclude non-card or missing products');
}
if ($custom !== [8154 => true, 301 => true]) throw new RuntimeException('Only verified custom identities enter component projection');

// One receipt spanning two roots counts once in each, even with several
// projects in one root; a multi-card sale counts separate receipt identities.
$quantity = new ReflectionMethod($reader, 'addDashboardCustomCardQuantities');
$quantity->setAccessible(true);
$zero = ['sales_quantity'=>0,'cash_performance_cents'=>0];
$totals = ['normal_card'=>$zero,'experience_card'=>$zero];
$categories = [10=>['normal_card'=>$zero],20=>['normal_card'=>$zero]];
$rows = [];
foreach ([['A',101],['A',102],['A',201],['B',101],['',201]] as [$receipt,$category]) {
    $rows[] = ['fact_id'=>'sale','card_receipt_id'=>$receipt,'card_product_id'=>301,'category_id'=>$category];
}
$quantity->invokeArgs($reader, [$rows,$types,[101=>10,102=>10,201=>20],true,&$totals,&$categories]);
if ($totals['normal_card']['sales_quantity'] !== 2 || $categories[10]['normal_card']['sales_quantity'] !== 2
    || $categories[20]['normal_card']['sales_quantity'] !== 1) throw new RuntimeException('Cross-root receipt deduplication failed');
$totals['normal_card']['sales_quantity'] = 7;
$quantity->invokeArgs($reader, [$rows,$types,[101=>10,102=>10,201=>20],false,&$totals,&$categories]);
if ($totals['normal_card']['sales_quantity'] !== 7) throw new RuntimeException('Component rows must not change unfiltered top quantity');

// Allocation is the existing registered allocator, not a second formula.
// Odd cents remain conserved and zero-weight items receive no fabricated cash.
$allocate = new ReflectionMethod($reader, 'allocate');
$allocate->setAccessible(true);
$parts = $allocate->invoke($reader,10001,[['configured_amount_cents'=>1],['configured_amount_cents'=>2],['configured_amount_cents'=>0]]);
if (array_column($parts,'amount_cents') !== [3333,6668,0]) throw new RuntimeException('Custom cash allocation must conserve cents');
$allZero = $allocate->invoke($reader,101,[['configured_amount_cents'=>0],['configured_amount_cents'=>0]]);
if (array_column($allZero,'amount_cents') !== [50,51]) throw new RuntimeException('Zero-weight allocation must retain stable remainder');
echo "GROUP_DASHBOARD_SALE_TYPE_CONTRACT=PASS\n";
