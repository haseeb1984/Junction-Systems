<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Logger;
use Psr\Log\LoggerInterface;use Psr\Log\LogLevel;
final class WpLogger implements LoggerInterface{
    public function emergency(string|\Stringable $m,array $c=[]):void{$this->log(LogLevel::EMERGENCY,$m,$c);}
    public function alert(string|\Stringable $m,array $c=[]):void{$this->log(LogLevel::ALERT,$m,$c);}
    public function critical(string|\Stringable $m,array $c=[]):void{$this->log(LogLevel::CRITICAL,$m,$c);}
    public function error(string|\Stringable $m,array $c=[]):void{$this->log(LogLevel::ERROR,$m,$c);}
    public function warning(string|\Stringable $m,array $c=[]):void{$this->log(LogLevel::WARNING,$m,$c);}
    public function notice(string|\Stringable $m,array $c=[]):void{$this->log(LogLevel::NOTICE,$m,$c);}
    public function info(string|\Stringable $m,array $c=[]):void{$this->log(LogLevel::INFO,$m,$c);}
    public function debug(string|\Stringable $m,array $c=[]):void{$this->log(LogLevel::DEBUG,$m,$c);}
    public function log(mixed $l,string|\Stringable $m,array $c=[]):void{
        if((!defined('WP_DEBUG')||!WP_DEBUG)&&in_array($l,[LogLevel::DEBUG,LogLevel::INFO],true))return;
        error_log('[PKStore]['.strtoupper($l).'] '.$m.(empty($c)?'':' '.json_encode($c,JSON_UNESCAPED_UNICODE)));
    }
}
