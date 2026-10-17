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
$types = $method->invoke($reader, [101, 202, 203, 999]);
if ($types !== [101 => 1, 202 => 0]) {
    throw new RuntimeException('Card statistics must use the platform setting and exclude non-card or missing products');
}
echo "GROUP_DASHBOARD_SALE_TYPE_CONTRACT=PASS\n";
