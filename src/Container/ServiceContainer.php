<?php
declare(strict_types=1);
namespace PkStore\Container;
final class ServiceContainer{
    private array $b=[],$s=[],$sm=[];
    private static ?self $i=null;
    private function __construct(){}
    public static function getInstance():self{if(self::$i===null)self::$i=new self();return self::$i;}
    public function singleton(string $a,\Closure $f):void{$this->b[$a]=$f;$this->sm[$a]=true;}
    public function bind(string $a,\Closure $f):void{$this->b[$a]=$f;$this->sm[$a]=false;}
    public function make(string $a):object{if(!isset($this->b[$a]))throw new \RuntimeException("No binding for [{$a}].");if(($this->sm[$a]??false)&&isset($this->s[$a]))return $this->s[$a];$o=($this->b[$a])($this);if($this->sm[$a]??false)$this->s[$a]=$o;return $o;}
    public function has(string $a):bool{return isset($this->b[$a]);}
}
