<?php
/////////////////////////////////////////////////////////////////////////////////////////////////////////////////
require_once ('ArrayCollection.php');
//SELECT email FROM users WHERE emails IS NOT NULL AND name IS NOT NULL
function getUserEmails(array $users)
{
    return (new ArrayCollection($users))
        ->filter(function($user){
            return $user->emails != null && $user->name != null;
        })
        ->map(function($user){
            return $user->emails;
        })
        ->toArray();
}

//将数组中的所有user的email串在一起
function getAllUserEmails(array $users)
{
    return (new ArrayCollection($users))
        ->filter(function($user){
            return $user->emails != null && $user->name != null;
        })
        ->reduce(function($result='', $user){  //$result为上一次迭代产生的值，$user是当前迭代的值。
            return $result.$user->email.',';
        });
}

////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
require_once ('Macroable.php');

//让类use该Macroable
class user
{
    use Macroable;

    private $data = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }
    public function echoHello(){ echo "echo hello"; }
}


$user = new user(['name' => 'marcus']);
//user类含有echoHelo函数
$user->echoHello();
//user类本身没有getAttrs函数，现要为其扩展该方法
$user::macro('getAttrs',function(){
    return $this->data;
});
//调用getAttrs方法，发现打印成功
var_dump($user->getAttrs());


