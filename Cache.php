<?php
//����ģʽ
define('DB_INSERT',1);
//�滻ģʽ
define('DB_REPLACE',2);
//����ģʽ
define('DB_STORE',3);
//��Ͱ�Ĵ�С
define('DB_BUCKET_SIZE',262144);
//���ĳ��ȣ��ֽڣ�
define('DB_KEY_SIZE',128);
//����������¼�ĳ���
define('DB_INDEX_SIZE',DB_KEY_SIZE + 12);
//���ظ�
define('DB_KEY_EXISTS',1);
//ִ��ʧ��
define('DB_FAILURE',-1);
//ִ�гɹ�
define('DB_SUCCESS',0);

class Terax{
    //�����ļ����
    private $idx_fp;
    //�����ļ����
    private $dat_fp;
    //DB�ر����
    private $closed;

    /**
     * �����ݿ�
     *
     * @param $pathname //���ݿ�����
     * @return int
     */
    public function open($pathname)
    {
        //�����ļ�·��
        $idx_path = $pathname.'.idx';
        //�����ļ�·��
        $dat_path = $pathname.'.dat';
        //�������ļ��Ĵ�������ж��Ƿ�Ҫ��ʼ������ʲôģʽ�������ļ�
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
        //��Ҫ��ʼ�����������elemд�������ļ���
        if ($init){
            //�Ѵ���Ͱ��С����ֵ����ֵΪ0�ĳ��������֣�ռ4���ֽڣ�д���ļ��У�ռ1MB�ռ�
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
     * ���ݼ��ַ�������hashֵ
     *
     * @param $string  //���ַ���
     * @return int     //hashֵ
     */
    private function _hash($string)
    {
        //��ͨ��MD5�������ַ��������һ��32���ַ����ַ�����ȡǰ8���ַ���Ϊ���㴮
        //������Times33�㷨���䴦���һ�����������ء����㷨���ŵ����ڷֲ��ȽϾ��ȣ��ٶȷǳ���
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
        var_dump($this->dat_fp);
//        file_put_contents($this->dat_fp,'');
//        echo 'ok';
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