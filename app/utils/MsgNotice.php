<?php

namespace app\utils;
use PHPMailer\PHPMailer\PHPMailer;
use think\facade\Db;
use app\lib\CertHelper;
use app\lib\DeployHelper;

class MsgNotice
{
    private static $sitename = '聚合DNS管理系统';

    public static function send($action, $task, $result)
    {
        if ($action == 1) {
            $mail_title = 'DNS容灾切换-发生告警通知';
            $mail_content = '尊敬的用户，您好：<br/>您的域名 <b>'.$task['domain'].'</b> 的 <b>'.$task['main_value'].'</b> 记录发生了异常';
            if ($task['type'] == 2) {
                $mail_content .= '，已自动切换为备用解析记录 '.$task['backup_value'].' ';
            } elseif ($task['type'] == 1) {
                $mail_content .= '，已自动暂停解析';
            } else {
                $mail_content .= '，请及时处理';
            }
            if (!empty($result['errmsg'])) {
                $mail_content .= '。<br/>异常信息：<font color="warning">'.$result['errmsg'].'</font>';
            }
        } else {
            $mail_title = 'DNS容灾切换-恢复正常通知';
            $mail_content = '尊敬的用户，您好：<br/>您的域名 <b>'.$task['domain'].'</b> 的 <b>'.$task['main_value'].'</b> 记录已恢复正常';
            if ($task['type'] == 2) {
                $mail_content .= '，已自动切换回当前解析记录';
            } elseif ($task['type'] == 1) {
                $mail_content .= '，已自动开启解析';
            }
            $lasttime = convert_second(time() - $task['switchtime']);
            $mail_content .= '。<br/>异常持续时间：'.$lasttime;
        }
        if (!empty($task['remark'])) {
            $mail_title .= '('.$task['remark'].')';
        }
        if (!empty($task['remark'])) {
            $mail_content .= '<br/>备注：'.$task['remark'];
        }
        $mail_content .= '<br/><font color="grey">'.self::$sitename.'</font><br/><font color="grey">'.date('Y-m-d H:i:s').'</font>';

        if (config_get('notice_mail') == 1) {
            $mail_name = config_get('mail_recv') ? config_get('mail_recv') : config_get('mail_name');
            self::send_mail($mail_name, $mail_title, $mail_content);
        }
        if (config_get('notice_wxtpl') == 1) {
            $content = str_replace(['<br/>', '<b>', '</b>'], ["\n\n", '**', '**'], $mail_content);
            self::send_wechat_tplmsg($mail_title, strip_tags($content));
        }
        if (config_get('notice_tgbot') == 1) {
            $content = str_replace('<br/>', "\n", $mail_content);
            $content = "<strong>".$mail_title."</strong>\n".strip_tags($content);
            self::send_telegram_bot($content);
        }
        if (config_get('notice_webhook') == 1) {
            $content = str_replace(['<br/>', '<b>', '</b>'], ["\n", '**', '**'], $mail_content);
            self::send_webhook($mail_title, $content);
        }
    }

    public static function cert_order_send($id, $result)
    {
        $row = Db::name('cert_order')->field('id,aid,issuetime,expiretime,issuer,status,error')->where('id', $id)->find();
        if (!$row) return;
        $domainList = Db::name('cert_domain')->where('oid', $id)->column('domain');
        if (empty($domainList)) return;
        if ($row['aid'] == 0) {
            if (count($domainList) > 1) {
                $mail_title = $domainList[0] . '等' . count($domainList) . '个域名SSL证书即将到期提醒';
            } else {
                $mail_title = $domainList[0] . '域名SSL证书即将到期提醒';
            }
            $mail_content = '尊敬的用户，您好：您有一张SSL证书将在'.config_get('cert_renewdays', 7).'天后到期，该证书为手动续期证书，请及时续期！<br/><b>证书域名：</b> '.implode('、', $domainList).'<br/><b>签发时间：</b> '.$row['issuetime'].'<br/><b>到期时间：</b> '.$row['expiretime'].'<br/><b>颁发机构：</b> '.$row['issuer'];
        } else {
            $type = Db::name('cert_account')->where('id', $row['aid'])->value('type');
            if ($result) {
                if (count($domainList) > 1) {
                    $mail_title = $domainList[0] . '等' . count($domainList) . '个域名SSL证书签发成功通知';
                } else {
                    $mail_title = $domainList[0] . '域名SSL证书签发成功通知';
                }
                $mail_content = '尊敬的用户，您好：您的SSL证书已签发成功！<br/><b>证书账户：</b> '.CertHelper::$cert_config[$type]['name'].'('.$row['aid'].')<br/><b>证书域名：</b> '.implode('、', $domainList).'<br/><b>签发时间：</b> '.$row['issuetime'].'<br/><b>到期时间：</b> '.$row['expiretime'].'<br/><b>颁发机构：</b> '.$row['issuer'];
            } else {
                $status_arr = [0 => '失败', -1 => '购买证书失败', -2 => '创建订单失败', -3 => '添加DNS失败', -4 => '验证DNS失败', -5 => '验证订单失败', -6 => '订单验证未通过', -7 => '签发证书失败'];
                if(count($domainList) > 1){
                    $mail_title = $domainList[0].'等'.count($domainList).'个域名SSL证书'.$status_arr[$row['status']].'通知';
                }else{
                    $mail_title = $domainList[0].'域名SSL证书'.$status_arr[$row['status']].'通知';
                }
                $mail_content = '尊敬的用户，您好：您的SSL证书'.$status_arr[$row['status']].'！<br/><b>证书账户：</b> '.CertHelper::$cert_config[$type]['name'].'('.$row['aid'].')<br/><b>证书域名：</b> '.implode('、', $domainList).'<br/><b>失败时间：</b> '.date('Y-m-d H:i:s').'<br/><b>失败原因：</b> <font color="warning">'.$row['error'].'</font>';
            }
        }
        $mail_content .= '<br/><font color="grey">'.self::$sitename.'</font><br/><font color="grey">'.date('Y-m-d H:i:s').'</font>';

        self::cert_send($mail_title, $mail_content, $result);
        Db::name('cert_order')->where('id', $id)->update(['issend' => 1]);
    }

    public static function cert_deploy_send($id, $result)
    {
        $row = Db::name('cert_deploy')->field('id,aid,oid,remark,status,error')->where('id', $id)->find();
        if (!$row) return;
        $account = Db::name('cert_account')->field('id,type,name,remark')->where('id', $row['aid'])->find();
        $domainList = Db::name('cert_domain')->where('oid', $row['oid'])->column('domain');
        $typename = DeployHelper::$deploy_config[$account['type']]['name'];
        $mail_title = $typename;
        if(!empty($row['remark'])) $mail_title .= '('.$row['remark'].')';
        $mail_title .= 'SSL证书部署'.($result?'成功':'失败').'通知';
        if ($result) {
            $mail_content = '尊敬的用户，您好：您的SSL证书已成功部署到'.$typename.'！<br/><b>自动部署账户：</b> ['.$account['id'].']'.$typename.'('.($account['remark']?$account['remark']:$account['name']).')<br/><b>关联SSL证书：</b> ['.$row['oid'].']'.implode('、', $domainList).'<br/><b>任务备注：</b> '.($row['remark']?$row['remark']:'无');
        } else {
            $mail_content = '尊敬的用户，您好：您的SSL证书部署失败！<br/><b>失败原因：</b> <font color="warning">'.$row['error'].'</font><br/><b>自动部署账户：</b> ['.$account['id'].']'.$typename.'('.($account['remark']?$account['remark']:$account['name']).')<br/><b>关联SSL证书：</b> ['.$row['oid'].']'.implode('、', $domainList).'<br/><b>任务备注：</b> '.($row['remark']?$row['remark']:'无');
        }
        $mail_content .= '<br/><font color="grey">'.self::$sitename.'</font><br/><font color="grey">'.date('Y-m-d H:i:s').'</font>';

        self::cert_send($mail_title, $mail_content, $result);
        Db::name('cert_deploy')->where('id', $id)->update(['issend' => 1]);
    }

    private static function cert_send($mail_title, $mail_content, $result)
    {
        if (config_get('cert_notice_mail') == 1 || config_get('cert_notice_mail') == 2 && !$result) {
            $mail_name = config_get('mail_recv') ? config_get('mail_recv') : config_get('mail_name');
            self::send_mail($mail_name, $mail_title, $mail_content);
        }
        if (config_get('cert_notice_wxtpl') == 1 || config_get('cert_notice_wxtpl') == 2 && !$result) {
            $content = str_replace(['<br/>', '<b>', '</b>'], ["\n\n", '**', '**'], $mail_content);
            self::send_wechat_tplmsg($mail_title, strip_tags($content));
        }
        if (config_get('cert_notice_tgbot') == 1 || config_get('cert_notice_tgbot') == 2 && !$result) {
            $content = str_replace('<br/>', "\n", $mail_content);
            $content = "<strong>".$mail_title."</strong>\n".strip_tags($content);
            self::send_telegram_bot($content);
        }
        if (config_get('cert_notice_webhook') == 1) {
            $content = str_replace(['*', '<br/>', '<b>', '</b>'], ['\*', "\n", '**', '**'], $mail_content);
            self::send_webhook($mail_title, $content);
        }
    }

    public static function expire_notice_send($day, $list)
    {
        $mail_title = '您有'.count($list).'个域名即将在'.$day.'天后到期';
        $mail_content = '尊敬的用户，您好：您有'.count($list).'个域名即将在'.$day.'天后到期！<br/><b>域名&到期时间：</b><br/>';
        foreach ($list as $domain) {
            $mail_content .= '<b>'.$domain['name'].'</b> - '.$domain['expiretime'].'<br/>';
        }
        $mail_content .= '<br/><font color="grey">'.self::$sitename.'</font><br/><font color="grey">'.date('Y-m-d H:i:s').'</font>';

        if (config_get('expire_notice_mail') == 1 || config_get('expire_notice_mail') == 2) {
            $mail_name = config_get('mail_recv') ? config_get('mail_recv') : config_get('mail_name');
            self::send_mail($mail_name, $mail_title, $mail_content);
        }
        if (config_get('expire_notice_wxtpl') == 1 || config_get('expire_notice_wxtpl') == 2) {
            $content = str_replace(['<br/>', '<b>', '</b>'], ["\n\n", '**', '**'], $mail_content);
            self::send_wechat_tplmsg($mail_title, strip_tags($content));
        }
        if (config_get('expire_notice_tgbot') == 1 || config_get('expire_notice_tgbot') == 2) {
            $content = str_replace('<br/>', "\n", $mail_content);
            $content = "<strong>".$mail_title."</strong>\n".strip_tags($content);
            self::send_telegram_bot($content);
        }
        if (config_get('expire_notice_webhook') == 1) {
            $content = str_replace(['*', '<br/>', '<b>', '</b>'], ['\*', "\n", '**', '**'], $mail_content);
            self::send_webhook($mail_title, $content);
        }
    }

    public static function send_mail($to, $sub, $msg)
    {
        $mail_type = config_get('mail_type');
        if ($mail_type == 1) {
            $mail = new \app\lib\mail\Sendcloud(config_get('mail_apiuser'), config_get('mail_apikey'));
            return $mail->send($to, $sub, $msg, config_get('mail_name'), self::$sitename);
        } elseif ($mail_type == 2) {
            $mail = new \app\lib\mail\Aliyun(config_get('mail_apiuser'), config_get('mail_apikey'));
            return $mail->send($to, $sub, $msg, config_get('mail_name'), self::$sitename);
        } else {
            $mail_name = config_get('mail_name');
            $mail_port = intval(config_get('mail_port'));
            $mail_smtp = config_get('mail_smtp');
            $mail_pwd = config_get('mail_pwd');
            if (!$mail_name || !$mail_port || !$mail_smtp || !$mail_pwd) return false;
            $mail = new PHPMailer(true);
            $mail->setLanguage('zh_cn');
            try {
                $mail->SMTPDebug = 0;
                $mail->CharSet = 'UTF-8';
                $mail->Timeout = 5;
                $mail->isSMTP();
                $mail->Host = $mail_smtp;
                $mail->SMTPAuth = true;
                $mail->Username = $mail_name;
                $mail->Password = $mail_pwd;
                if ($mail_port == 587) $mail->SMTPSecure = 'tls';
                else if ($mail_port >= 465) $mail->SMTPSecure = 'ssl';
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

    public static function send_wechat_tplmsg($title, $content)
    {
        $wechat_apptoken = config_get('wechat_apptoken');
        $wechat_appuid = config_get('wechat_appuid');
        if (!$wechat_apptoken || !$wechat_appuid) return false;
        $url = 'https://wxpusher.zjiecode.com/api/send/message';
        $post = ['appToken' => $wechat_apptoken, 'content' => $content, 'summary' => $title, 'contentType' => 3, 'uids' => [$wechat_appuid]];
        $result = get_curl($url, json_encode($post), 0, 0, 0, 0, ['Content-Type' => 'application/json; charset=UTF-8']);
        $arr = json_decode($result, true);
        if (isset($arr['success']) && $arr['success'] == true) {
            return true;
        } else {
            return $arr['msg'] ?? '请求失败';
        }
    }

    public static function send_telegram_bot($content)
    {
        $tgbot_token = config_get('tgbot_token');
        $tgbot_chatid = config_get('tgbot_chatid');
        if (!$tgbot_token || !$tgbot_chatid) return false;
        $tgbot_url = 'https://api.telegram.org';
        if (config_get('tgbot_proxy') == 2) {
            $tgbot_url_n = config_get('tgbot_url');
            if (!empty($tgbot_url_n)) {
                $tgbot_url = rtrim($tgbot_url_n, '/');
            }
        }
        $url = $tgbot_url.'/bot'.$tgbot_token.'/sendMessage';
        $post = ['chat_id' => $tgbot_chatid, 'text' => $content, 'parse_mode' => 'HTML'];
        $result = self::telegram_curl($url, http_build_query($post));
        $arr = json_decode($result, true);
        if (isset($arr['ok']) && $arr['ok'] == true) {
            return true;
        } else {
            return $arr['description'] ?? '请求失败';
        }
    }

    public static function send_webhook($title, $content)
    {
        $url = config_get('webhook_url');
        $atuser = config_get('webhook_user');
        if (!$url || !parse_url($url)) return false;
        if (strpos($url, 'oapi.dingtalk.com')) {
            $content = '### '.$title."  \n ".str_replace("\n", "  \n ", $content);
            $post = [
                'msgtype' => 'markdown',
                'markdown' => [
                    'title' => $title,
                    'text' => $content,
                ],
            ];
            if (!empty($atuser)) {
                if ($atuser == 'all') {
                    $post['at'] = ['isAtAll' => true];
                } else {
                    $atusers = explode(',', $atuser);
                    $post['at'] = ['atMobiles' => $atusers, 'isAtAll' => false];
                }
            }
        } elseif (strpos($url, 'qyapi.weixin.qq.com')) {
            $content = '## '.$title."\n".$content;
            $post = [
                'msgtype' => 'markdown',
                'markdown' => [
                    'content' => $content,
                ],
            ];
        } elseif (strpos($url, 'open.feishu.cn') || strpos($url, 'open.larksuite.com')) {
            $content = str_replace('<font color="warning">', '<font color="red">', $content);
            if (!empty($atuser)) {
                if ($atuser == 'all') {
                    $content .= "\n".'<at id=all></at> ';
                } else {
                    $atusers = explode(',', $atuser);
                    $content .= "\n";
                    foreach ($atusers as $u) {
                        $content .= '<at user_id="'.$u.'"></at> ';
                    }
                }
            }
            $template = 'blue';
            if(strpos($title, '发生告警') !== false || strpos($title, '失败') !== false) $template = 'red';
            else if(strpos($title, '恢复正常') !== false) $template = 'green';
            else if(strpos($title, '到期提醒') !== false) $template = 'yellow';
            $post = [
                'msg_type' => 'interactive',
                'card' => [
                    'schema' => '2.0',
                    'config' => [
                        'update_multi' => true,
                        'style' => [
                            'text_size' => [
                                'normal_v2' => [
                                    'default' => 'normal',
                                    'pc' => 'normal',
                                    'mobile' => 'heading',
                                ],
                            ],
                        ],
                    ],
                    'header' => [
                        'title' => [
                            'tag' => 'plain_text',
                            'content' => $title,
                        ],
                        'subtitle' => [
                            'tag' => 'plain_text',
                            'content' => '',
                        ],
                        'template' => $template,
                        'padding' => '12px 12px 12px 12px',
                    ],
                    'body' => [
                        'direction' => 'vertical',
                        'padding' => '12px 12px 12px 12px',
                        'elements' => [
                            [
                                'tag' => 'markdown',
                                'content' => $content,
                                'text_align' => 'left',
                                'text_size' => 'normal_v2',
                                'margin' => '0px 0px 0px 0px',
                            ]
                        ],
                    ],
                ],
            ];
        } else {
            return '不支持的Webhook地址';
        }
        $result = get_curl($url, json_encode($post), 0, 0, 0, 0, ['Content-Type' => 'application/json; charset=UTF-8']);
        $arr = json_decode($result, true);
        if (isset($arr['errcode']) && $arr['errcode'] == 0 || isset($arr['code']) && $arr['code'] == 0) {
            return true;
        } else {
            return $arr['errmsg'] ?? (isset($arr['msg']) ? $arr['msg'] : '请求失败');
        }
    }

    private static function telegram_curl($url, $post)
    {
        $ch = curl_init();
        if (config_get('tgbot_proxy') == 1) {
            curl_set_proxy($ch);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $httpheader[] = "Accept: */*";
        $httpheader[] = "Accept-Encoding: gzip,deflate,sdch";
        $httpheader[] = "Accept-Language: zh-CN,zh;q=0.8";
        $httpheader[] = "Connection: close";
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Linux; U; Android 4.0.4; es-mx; HTC_One_X Build/IMM76D) AppleWebKit/534.30 (KHTML, like Gecko) Version/4.0");
        curl_setopt($ch, CURLOPT_ENCODING, "gzip");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $ret = curl_exec($ch);
        curl_close($ch);
        return $ret;
    }
}
