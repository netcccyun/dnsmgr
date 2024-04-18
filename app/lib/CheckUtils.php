<?php

namespace app\lib;

class CheckUtils
{
    public static function curl($url, $timeout)
    {
        $status = true;
        $errmsg = null;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $httpheader[] = "Accept: */*";
        $httpheader[] = "Accept-Language: zh-CN,zh;q=0.8";
        $httpheader[] = "Connection: close";
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.198 Safari/537.36');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_exec($ch);
        $errno = curl_errno($ch);
        if ($errno) {
            $status = false;
            $errmsg = curl_error($ch);
        }
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if($status && ($httpcode < 200 || $httpcode >= 400)){
            $status = false;
            $errmsg = 'http_code='.$httpcode;
        }
        $usetime = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        curl_close($ch);
        return ['status'=>$status, 'errmsg'=>$errmsg, 'usetime'=>$usetime];
    }

    public static function tcp($target, $port, $timeout){
        if(!filter_var($target,FILTER_VALIDATE_IP) && checkDomain($target)){
            $target = gethostbyname($target);
            if(!$target)return ['status'=>false, 'error'=>'DNS resolve failed', 'usetime'=>0];
        }
        $starttime = getMillisecond();
        $fp = @fsockopen($target, $port, $errCode, $errStr, $timeout);
        if ($fp) {
            $status = true;
            fclose($fp);
        } else {
            $status = false;
        }
        $endtime = getMillisecond();
        $usetime = $endtime-$starttime;
        return ['status'=>$status, 'errmsg'=>$errStr, 'usetime'=>$usetime];
    }

    public static function ping($target){
        if(!function_exists('exec'))return ['status'=>false, 'error'=>'exec函数不可用', 'usetime'=>0];
        if(!filter_var($target,FILTER_VALIDATE_IP) && checkDomain($target)){
            $target = gethostbyname($target);
            if(!$target)return ['status'=>false, 'error'=>'DNS resolve failed', 'usetime'=>0];
        }
        if(!filter_var($target,FILTER_VALIDATE_IP)){
            return ['status'=>false, 'error'=>'Invalid IP address', 'usetime'=>0];
        }
        $timeout = 1;
        exec('ping -c 1 -w '.$timeout.' '.$target.'', $output, $return_var);
        $usetime = !empty($output[1]) ? round(getSubstr($output[1], 'time=', ' ms')) : 0;
        $errmsg = null;
        if($return_var !== 0){
            $usetime = $usetime == 0 ? $timeout*1000 : $usetime;
            $errmsg = 'ping timeout';
        }
        return ['status'=>$return_var===0, 'errmsg'=>$errmsg, 'usetime'=>$usetime];
    }
}