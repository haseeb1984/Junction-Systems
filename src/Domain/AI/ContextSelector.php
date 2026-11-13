<?php
declare(strict_types=1);
namespace PkStore\Domain\AI;
final class ContextSelector {
    private const STOP=['what','is','the','a','an','of','in','on','for','do','you','have','does','can','i','me','my','want','need','looking','tell','show','price','cost','much','how','this','that','and','or','with','any','available','stock','get','buy','kya','hai','ka','ki','ke','mein','ko','aur','ya','nahi','ap','aap','mujhe','chahiye','kitna'];
    private const CAT=['phone'=>'mobile-phones','mobile'=>'mobile-phones','smartphone'=>'mobile-phones','laptop'=>'laptops','shoe'=>'footwear','shoes'=>'footwear','sneaker'=>'footwear','shirt'=>'clothing','dress'=>'clothing','kurta'=>'clothing','kameez'=>'clothing','headphone'=>'audio','earphone'=>'audio','watch'=>'watches','ebook'=>'ebooks','pdf'=>'ebooks','course'=>'courses','software'=>'software','game'=>'games'];
    public function extract(string $m):array { $c=strtolower(preg_replace('/[^\w\s]/u',' ',$m)??''); $t=preg_split('/\s+/',trim($c),-1,PREG_SPLIT_NO_EMPTY)?:[]; $k=array_filter($t,fn($w)=>strlen($w)>2&&!in_array($w,self::STOP,true)); $u=array_unique(array_values($k)); usort($u,fn($a,$b)=>strlen($b)<=>strlen($a)); return array_slice($u,0,5); }
    public function detectCategoryIntent(string $m):?string { $l=strtolower($m); foreach(self::CAT as $t=>$s){if(str_contains($l,$t))return $s;} return null; }
}
