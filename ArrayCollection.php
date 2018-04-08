<?php
//使用 map、filter和reduce来减少使用foreach
class ArrayCollection
{
    protected $items;

    public function __construct($items)
    {
        $this->items = $items;
    }

    //闭包处理
    public function map($callback)
    {
        return new static(array_map($callback,$this->items));
    }

    //过滤
    public function filter($callback)
    {
        return new static(array_filter($callback,$this->items));
    }

    //多合一
    public function reduce($callback)
    {
        return array_filter($this->items,$callback);
    }

    public function toArray()
    {
        return $this->items;
    }
}
