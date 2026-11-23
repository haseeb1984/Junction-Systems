<?php
declare(strict_types=1);
namespace PkStore\Application\Inventory;
use PkStore\Domain\Inventory\Entity\PurchaseOrder;
use PkStore\Domain\Inventory\Repository\{InventoryProductRepositoryInterface,LocationRepositoryInterface,PurchaseOrderRepositoryInterface};
final class CreatePurchaseOrderUseCase {
    public function __construct(private readonly InventoryProductRepositoryInterface $products, private readonly LocationRepositoryInterface $locations, private readonly PurchaseOrderRepositoryInterface $orders) {}
    public function execute(int $supplierId,int $locationId,array $items,string $notes=''): int {
        if ($supplierId<=0 || !$this->orders->supplierExists($supplierId)) throw new \InvalidArgumentException('Select a valid active supplier.');
        $location=$this->locations->findById($locationId);
        if (!$location || !$location->isActive) throw new \InvalidArgumentException('Select an active receiving location.');
        if (!$items) throw new \InvalidArgumentException('A purchase order must contain at least one item.');
        $clean=[]; $seen=[];
        foreach ($items as $item) {
            $productId=(int)($item['product_id']??0); $quantity=(int)($item['quantity']??0); $cost=(int)($item['unit_cost_pkr']??-1);
            if ($productId<=0 || $quantity<=0 || $cost<0) throw new \InvalidArgumentException('Each purchase item needs a product, positive quantity and non-negative unit cost.');
            if (!$this->products->findById($productId)) throw new \InvalidArgumentException("Inventory product {$productId} was not found.");
            if (isset($seen[$productId])) throw new \InvalidArgumentException('Duplicate products must be combined into one purchase order line.');
            $seen[$productId]=true; $clean[]=['product_id'=>$productId,'quantity'=>$quantity,'unit_cost_pkr'=>$cost];
        }
        return $this->orders->create(PurchaseOrder::create($supplierId,$locationId,trim($notes)),$clean);
    }
}
