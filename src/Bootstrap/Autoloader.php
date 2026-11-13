<?php
declare(strict_types=1);
namespace PkStore\Bootstrap;
final class Autoloader{
    private static array $roots=[];
    public static function register(string $baseDir,string $prefix):void{self::$roots[rtrim($prefix,'\\').'\\']=[rtrim($baseDir,'/').'/',strlen(rtrim($prefix,'\\').'\\')];spl_autoload_register([self::class,'load'],true,false);}
    public static function load(string $class):void{foreach(self::$roots as $pfx=>[$dir,$len]){if(strncmp($class,$pfx,$len)===0){$f=$dir.str_replace('\\','/',substr($class,$len)).'.php';if(file_exists($f)){require_once $f;return;}}}}
}
