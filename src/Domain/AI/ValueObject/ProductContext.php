<?php
declare(strict_types=1);
namespace PkStore\Domain\AI\ValueObject;
final readonly class ProductContext {
    public function __construct(public readonly string $sku, public readonly string $name, public readonly int $pricePkr, public readonly ?int $salePricePkr, public readonly string $stockStatus, public readonly int $stockQuantity, public readonly string $shortDescription, public readonly string $categoryNames, public readonly array $attributes, public readonly bool $isFeatured, public readonly ?string $brand) {}
    public function toMinifiedArray():array {
        $d=['sku'=>$this->sku,'name'=>$this->name,'px'=>$this->pricePkr,'stock'=>$this->stockStatus,'cat'=>$this->categoryNames,'desc'=>mb_substr($this->shortDescription,0,200)];
        if($this->salePricePkr!==null)$d['sale']=$this->salePricePkr;
        if(!empty($this->attributes))$d['attrs']=$this->attributes;
        if($this->brand!==null)$d['brand']=$this->brand;
        return $d;
    }
}
