<?php

namespace app\lib;

class MsgNotice
{
    private static $sitename = '聚合DNS管理系统';

    public static function send($action, $task, $result)
    {
        if($action == 1){
            $mail_title = 'DNS容灾切换-发生告警通知';
            $mail_content = '尊敬的系统管理员，您好：<br/>您的域名 <b>'.$task['domain'].'</b> 的 <b>'.$task['main_value'].'</b> 记录发生了异常';
            if($task['type'] == 2){
                $mail_content .= '，已自动切换为备用解析记录 '.$task['backup_value'].' ';
            }elseif($task['type'] == 1){
                $mail_content .= '，已自动暂停解析';
            }else{
                $mail_content .= '，请及时处理';
            }
            if(!empty($result['errmsg'])){
                $mail_content .= '。<br/>异常信息：'.$result['errmsg'];
            }
        }else{
            $mail_title = 'DNS容灾切换-恢复正常通知';
            $mail_content = '尊敬的系统管理员，您好：<br/>您的域名 <b>'.$task['domain'].'</b> 的 <b>'.$task['main_value'].'</b> 记录已恢复正常';
            if($task['type'] == 2){
                $mail_content .= '，已自动切换回当前解析记录';
            }elseif($task['type'] == 1){
                $mail_content .= '，已自动开启解析';
            }
            $lasttime = convert_second(time() - $task['switchtime']);
            $mail_content .= '。<br/>异常持续时间：'.$lasttime;
        }
        if(!empty($task['remark'])) $mail_title .= '('.$task['remark'].')';
        if(!empty($task['remark'])) $mail_content .= '<br/>备注：'.$task['remark'];
        $mail_content .= '<br/>'.self::$sitename.'<br/>'.date('Y-m-d H:i:s');

        if(config_get('notice_mail') == 1){
            $mail_name = config_get('mail_recv')?config_get('mail_recv'):config_get('mail_name');
            self::send_mail($mail_name, $mail_title, $mail_content);
        }
        if(config_get('notice_wxtpl') == 1){
            $mail_content = str_replace(['<br/>', '<b>', '</b>'], ["\n\n", '**', '**'], $mail_content);
            self::send_wechat_tplmsg($mail_title, $mail_content);
        }
    }
    
    public static function send_mail($to, $sub, $msg){
        $mail_type = config_get('mail_type');
        if($mail_type == 1){
            $mail = new \app\lib\mail\Sendcloud(config_get('mail_apiuser'), config_get('mail_apikey'));
		    return $mail->send($to, $sub, $msg, config_get('mail_name'), self::$sitename);
        }elseif($mail_type == 2){
            $mail = new \app\lib\mail\Aliyun(config_get('mail_apiuser'), config_get('mail_apikey'));
            return $mail->send($to, $sub, $msg, config_get('mail_name'), self::$sitename);
        }else{
            $mail_name = config_get('mail_name');
            $mail_port = intval(config_get('mail_port'));
            $mail_smtp = config_get('mail_smtp');
            $mail_pwd = config_get('mail_pwd');
            if(!$mail_name || !$mail_port || !$mail_smtp || !$mail_pwd)return false;
            $mail = new \app\lib\mail\PHPMailer\PHPMailer(true);
            try{
                $mail->SMTPDebug = 0;
                $mail->CharSet = 'UTF-8';
                $mail->Timeout = 5;
                $mail->isSMTP();
                $mail->Host = $mail_smtp;
                $mail->SMTPAuth = true;
                $mail->Username = $mail_name;
                $mail->Password = $mail_pwd;
                if($mail_port == 587) $mail->SMTPSecure = 'tls';
                else if($mail_port >= 465) $mail->SMTPSecure = 'ssl';
                else $mail->SMTPAutoTLS = false;
                $mail->Port = $mail_port;
                $mail->setFrom($mail_name, self::$sitename);
                $mail->addAddress($to);
                $mail->addReplyTo($mail_name, self::$sitename);
                $mail->isHTML(true);
                $mail->Subject = $sub;
                $mail->Body = $msg;
                $mail->send();
                return true;
            } catch (\Exception $e) {
                return $mail->ErrorInfo;
            }
        }
    }

    public static function send_wechat_tplmsg($title, $content){
        $wechat_apptoken = config_get('wechat_apptoken');
        $wechat_appuid = config_get('wechat_appuid');
        if(!$wechat_apptoken||!$wechat_appuid)return false;
		$url = 'https://wxpusher.zjiecode.com/api/send/message';
		$post = ['appToken'=>$wechat_apptoken, 'content'=>$content, 'summary'=>$title, 'contentType'=>3, 'uids'=>[$wechat_appuid]];
		$result = get_curl($url, json_encode($post),0,0,0,0,0,['Content-Type: application/json; charset=UTF-8']);
		$arr = json_decode($result, true);
		if(isset($arr['success']) && $arr['success']==true){
			return true;
		}else{
			return $arr['msg'];
		}
    }
}