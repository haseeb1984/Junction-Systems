<?php
declare(strict_types=1);
namespace PkStore\Application\AI;
use PkStore\Domain\AI\{ContextSelector,ProductRepositoryInterface,PromptComposer};
final class ProductContextService {
    private const MAX=5;
    public function __construct(private readonly ProductRepositoryInterface $repo, private readonly ContextSelector $selector, private readonly PromptComposer $composer) {}
    public function buildSystemPrompt(string $msg):string {
        $meta=$this->repo->getStoreMetadata();
        $products=$this->resolve($msg);
        return $this->composer->build($products,$meta);
    }
    private function resolve(string $msg):array {
        $kw=$this->selector->extract($msg);
        if(!empty($kw)){$p=$this->repo->findByKeywords($kw,self::MAX);if(!empty($p))return $p;}
        $cat=$this->selector->detectCategoryIntent($msg);
        if($cat!==null)return $this->repo->findByCategory($cat,self::MAX);
        return [];
    }
}
