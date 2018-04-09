<?php
//插入模式
define('DB_INSERT',1);
//替换模式
define('DB_REPLACE',2);
//插替模式
define('DB_STORE',3);
//表桶的大小
define('DB_BUCKET_SIZE',262144);
//键的长度（字节）
define('DB_KEY_SIZE',128);
//单条索引记录的长度
define('DB_INDEX_SIZE',DB_KEY_SIZE + 12);
//键重复
define('DB_KEY_EXISTS',1);
//执行失败
define('DB_FAILURE',-1);
//执行成功
define('DB_SUCCESS',0);

class Terax{
    //索引文件句柄
    private $idx_fp;
    //数据文件句柄
    private $dat_fp;
    //DB关闭情况
    private $closed;

    /**
     * 打开数据库
     *
     * @param $pathname //数据库名字
     * @return int
     */
    public function open($pathname)
    {
        //索引文件路径
        $idx_path = $pathname.'.idx';
        //数据文件路径
        $dat_path = $pathname.'.dat';
        //由索引文件的存在与否判断是否要初始化和以什么模式打开索引文件
        if (!file_exists($idx_path)){
            $init = true;
            $mode = "w+b";
        }else{
            $init = false;
            $mode = "r+b";
        }
        $this->idx_fp = fopen($idx_path,$mode);
        if (!$this->idx_fp){
            return DB_FAILURE;
        }
        //需要初始化则把索引块elem写入索引文件中
        if ($init){
            //把代表桶大小的数值个数值为0的长整型数字（占4个字节）写入文件中，占1MB空间
            $elem = pack('L',0x00000000);
            for($i=0; $i<DB_BUCKET_SIZE; $i++){
                fwrite($this->idx_fp,$elem,4);
            }
        }
        $this->dat_fp = fopen($dat_path,$mode);
        if (!$this->dat_fp){
            return DB_FAILURE;
        }
        return DB_SUCCESS;
    }

    /**
     * 根据键字符串计算hash值
     *
     * @param $string  //键字符串
     * @return int     //hash值
     */
    private function _hash($string)
    {
        //先通过MD5函数把字符串处理成一个32个字符的字符串，取前8个字符作为计算串
        //再利用Times33算法将其处理成一个整数并返回。该算法的优点在于分布比较均匀，速度非常快
        $string = substr(md5($string),0,8);
        $hash = 0;
        for($i=0; $i<8; $i++){
            $hash += 33*$hash+ord($string{$i});
        }
        return $hash&0x7FFFFFFF;
    }

    public function get($key)
    {
        $offset = ($this->_hash($key)%DB_BUCKET_SIZE)*4;
        fseek($this->idx_fp,$offset,SEEK_SET);
        $pos = unpack('L',fread($this->idx_fp,4));
        $pos = $pos[1];
        $found = false;
        $dataoff = '';
        $dataolen = '';
        while($pos){
            fseek($this->idx_fp,$pos,SEEK_SET);
            $block = fread($this->idx_fp,DB_INDEX_SIZE);
            $cpkey = substr($block,4,DB_KEY_SIZE);
            if (!strncmp($key,$cpkey,strlen($key))){
                $dataoff = unpack('L',substr($block,DB_KEY_SIZE + 4,4));
                $dataoff = $dataoff[1];
                $dataolen = unpack('L',substr($block,DB_KEY_SIZE + 8,4));
                $dataolen = $dataolen[1];
                $found = true;
                break;
            }
            $pos = unpack('L', substr($block, 0, 4));
            $pos = $pos[1];
        }
        if (!$found){ return NULL; }
        fseek($this->dat_fp,$dataoff,SEEK_SET);
        $data = fread($this->dat_fp,$dataolen);
        return $data;
    }

    public function set($key, $data)
    {
        $offset = ($this->_hash($key)%DB_BUCKET_SIZE)*4;
        $idxoff = fstat($this->idx_fp);
        $idxoff = intval($idxoff['size']);
        $datoff = fstat($this->dat_fp);
        $datoff = intval($datoff['size']);
        $keylen = strlen($key);
        if ($keylen>DB_KEY_SIZE){
            return DB_FAILURE;
        }
        $block = pack('L',0x00000000);
        $block.= $key;
        $space = DB_KEY_SIZE - $keylen;
        for($i = 0; $i<$space; $i++){
            $block.= pack('C',0x00);
        }
        $block.= pack('L',$datoff);
        $block.= pack('L',strlen($data));
        fseek($this->idx_fp,$offset,SEEK_SET);
        $pos = unpack('L',fread($this->idx_fp,4));
        $pos = $pos[1];
        if($pos == 0){
            fseek($this->idx_fp,$offset,SEEK_SET);
            fwrite($this->idx_fp,pack('L',$idxoff),4);
            fseek($this->idx_fp,0,SEEK_END);
            fwrite($this->idx_fp,$block,DB_INDEX_SIZE);
            fseek($this->dat_fp,0,SEEK_END);
            fwrite($this->dat_fp,$data,strlen($data));
            return DB_SUCCESS;
        }
        $prev = '';
        $found = false;
        while($pos){
            fseek($this->idx_fp,$pos,SEEK_SET);
            $tmp_block = fread($this->idx_fp,DB_INDEX_SIZE);
            $cpkey = substr($tmp_block,4,DB_KEY_SIZE);
            if (!strncmp($key,$cpkey,strlen($key))){
                $dataoff = unpack('L',substr($tmp_block,DB_KEY_SIZE + 4,4));
                $dataoff = $dataoff[1];
                $dataolen = unpack('L',substr($tmp_block,DB_KEY_SIZE + 8,4));
                $dataolen = $dataolen[1];
                $found = true;
                break;
            }
            $prev = $pos;
            $pos = unpack('L', substr($tmp_block, 0, 4));
            $pos = $pos[1];
        }
        if ($found){
            return DB_KEY_EXISTS;
        }
        fseek($this->idx_fp,$prev,SEEK_SET);
        fwrite($this->idx_fp,pack('L',$idxoff),4);
        fseek($this->idx_fp,0,SEEK_END);
        fwrite($this->idx_fp,$block,DB_INDEX_SIZE);
        fseek($this->dat_fp,0,SEEK_END);
        fwrite($this->dat_fp,$data,strlen($data));
        return DB_SUCCESS;
    }

    public function del($key)
    {
        $offset = ($this->_hash($key) % DB_BUCKET_SIZE)*4;
        fseek($this->idx_fp,$offset,SEEK_SET);
        $head = unpack('L',fread($this->idx_fp, 4));
        $head = $head[1];
        $curr = $head;
        $prev = 0;
        $found = false;
        $next = 0;
        while ($curr){
            fseek($this->idx_fp,$curr,SEEK_SET);
            $block = fread($this->idx_fp,DB_INDEX_SIZE);
            $next = unpack('L',substr($block,0,4));
            $next = $next[1];
            $cpkey = substr($block,4,DB_KEY_SIZE);
            if (!strncmp($key,$cpkey,strlen($key))){
                $found = true;
                break;
            }
            $prev = $next;
            $curr = $next;
        }
        if (!$found){ return DB_FAILURE; }
        if ($prev == 0){
            fseek($this->idx_fp,$offset,SEEK_SET);
            fwrite($this->idx_fp,pack('L',$next),4);
        }else{
            fseek($this->idx_fp,$prev,SEEK_SET);
            fwrite($this->idx_fp,pack('L',$next),4);
        }
        return DB_SUCCESS;
    }

    public function clear()
    {
        //var_dump($this->dat_fp);
        file_put_contents($this->dat_fp,'');
        echo 'ok';
    }

    public function close()
    {
        if (!$this->closed){
            fclose($this->idx_fp);
            fclose($this->dat_fp);
            $this->closed = true;
        }
    }
}
