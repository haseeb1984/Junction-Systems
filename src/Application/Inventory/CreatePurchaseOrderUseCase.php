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
            $productId=filter_var($item['product_id']??null,FILTER_VALIDATE_INT);
            $quantity=filter_var($item['quantity']??null,FILTER_VALIDATE_INT);
            $cost=filter_var($item['unit_cost_pkr']??null,FILTER_VALIDATE_INT);
            if (!$productId || !$quantity || $productId<1 || $quantity<1 || $cost===false || $cost<0) throw new \InvalidArgumentException('Each purchase item needs a product, positive whole quantity and non-negative whole unit cost.');
            $product=$this->products->findById($productId);
            if (!$product || !$product->isActive) throw new \InvalidArgumentException("Active inventory product {$productId} was not found.");
            if (isset($seen[$productId])) throw new \InvalidArgumentException('Duplicate products must be combined into one purchase order line.');
            $seen[$productId]=true; $clean[]=['product_id'=>$productId,'quantity'=>$quantity,'unit_cost_pkr'=>$cost];
        }
        return $this->orders->create(PurchaseOrder::create($supplierId,$locationId,trim($notes)),$clean);
    }
}
