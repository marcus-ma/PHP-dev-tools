<?php
//在不修改类的情况，对类进行函数扩展
trait Macroable
{
    protected static $macros = [];

    public static function macro($method, $callback)
    {
        static::$macros[$method] = $callback;
    }

    //当调用类本身不存在的函数就会自动调用该函数
    public function __call($method , $args)
    {
        if (!array_key_exists($method,static::$macros)){
            throw new Exception("method {$method} don`t set");
        }
        $macro = static::$macros[$method];

        //若$method为匿名函数
	    if ($macro instanceof Closure){
            //使用bindTo($this,static::class)的目是能让回调函数可以使用该类本身的方法或属性
            //如果想要使用其他类的方法或属性，此处也可以修改bindTo(new OtherClass,OtherClass::class)
         return call_user_func_array($macro->bindTo($this,static::class),$args);
        }

	    return call_user_func_array($macro, $args);
    }

}