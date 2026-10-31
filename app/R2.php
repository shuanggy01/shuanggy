<?php
declare(strict_types=1);
/** Minimal S3 SigV4 client. Requests only reach the validated R2 account endpoint. */
final class R2 {
    private string $host;
    public function __construct(private array $c) {
        if(!preg_match('/^[a-f0-9]{32}$/i',$c['account']??'')||!preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/',$c['bucket']??'')||empty($c['access'])||empty($c['secret'])) throw new RuntimeException('Lengkapi koneksi R2 terlebih dahulu.');
        $this->host=$c['account'].'.r2.cloudflarestorage.com';
    }
    private function path(string $key): string {return '/'.$this->c['bucket'].($key!==''?'/'.implode('/',array_map('rawurlencode',explode('/',$key))):'');}
    private function query(array $query): string {ksort($query,SORT_STRING);return http_build_query($query,'','&',PHP_QUERY_RFC3986);}
    private function sign(string $date,string $str): string {
        $key=hash_hmac('sha256',$date,'AWS4'.$this->c['secret'],true);
        foreach(['auto','s3','aws4_request'] as $part)$key=hash_hmac('sha256',$part,$key,true);
        return hash_hmac('sha256',$str,$key);
    }
    public function presign(string $method,string $key,array $query=[],string $mime='',int $expires=900,?string $now=null): string {
        $now??=gmdate('Ymd\THis\Z');$date=substr($now,0,8);$scope=$date.'/auto/s3/aws4_request';
        $headers=$mime!==''?'content-type;host':'host';$canonicalHeaders=($mime!==''?'content-type:'.$mime."\n":'').'host:'.$this->host."\n";
        $query+=['X-Amz-Algorithm'=>'AWS4-HMAC-SHA256','X-Amz-Credential'=>$this->c['access'].'/'.$scope,'X-Amz-Date'=>$now,'X-Amz-Expires'=>(string)$expires,'X-Amz-SignedHeaders'=>$headers];
        $canonical=$method."\n".$this->path($key)."\n".$this->query($query)."\n".$canonicalHeaders."\n".$headers."\nUNSIGNED-PAYLOAD";
        $signature=$this->sign($date,"AWS4-HMAC-SHA256\n$now\n$scope\n".hash('sha256',$canonical));
        return 'https://'.$this->host.$this->path($key).'?'.$this->query($query).'&X-Amz-Signature='.$signature;
    }
    public function request(string $method,string $key='',array $query=[],string $body='',string $mime=''): array {
        $now=gmdate('Ymd\THis\Z');$date=substr($now,0,8);$scope=$date.'/auto/s3/aws4_request';$hash=hash('sha256',$body);
        $headers=['host'=>$this->host,'x-amz-content-sha256'=>$hash,'x-amz-date'=>$now];if($mime!=='')$headers['content-type']=$mime;ksort($headers);
        $canonicalHeaders='';foreach($headers as $k=>$v)$canonicalHeaders.=$k.':'.$v."\n";
        $signed=implode(';',array_keys($headers));$qs=$this->query($query);
        $canonical=$method."\n".$this->path($key)."\n$qs\n$canonicalHeaders\n$signed\n$hash";
        $signature=$this->sign($date,"AWS4-HMAC-SHA256\n$now\n$scope\n".hash('sha256',$canonical));
        $out=[];foreach($headers as $k=>$v)$out[]=$k.': '.$v;
        $out[]='Authorization: AWS4-HMAC-SHA256 Credential='.$this->c['access'].'/'.$scope.', SignedHeaders='.$signed.', Signature='.$signature;
        $responseHeaders=[];$ch=curl_init('https://'.$this->host.$this->path($key).($qs!==''?'?'.$qs:''));
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$out,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$responseHeaders){$pair=explode(':',$line,2);if(count($pair)===2)$responseHeaders[strtolower(trim($pair[0]))]=trim($pair[1]);return strlen($line);}]);
        if($method==='HEAD')curl_setopt($ch,CURLOPT_NOBODY,true);
        elseif(in_array($method,['POST','PUT'],true))curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
        $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_errno($ch);curl_close($ch);
        if($response===false||$err)throw new RuntimeException('R2 tidak terjangkau. Cek koneksi server dan coba lagi.');
        if($status<200||$status>=300||str_contains((string)$response,'<Error>')) {
            $code='';if(preg_match('/<Code>([A-Za-z0-9]+)<\/Code>/',(string)$response,$m))$code=' '.$m[1];
            throw new RuntimeException('R2 HTTP '.$status.$code.'. Periksa bucket, izin token, dan jam server.');
        }
        return ['body'=>(string)$response,'headers'=>$responseHeaders,'status'=>$status];
    }
}
