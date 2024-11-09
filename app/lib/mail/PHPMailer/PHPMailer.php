<?php

namespace app\lib\mail\PHPMailer;

class PHPMailer
{
    public const CHARSET_ASCII = 'us-ascii';
    public const CHARSET_ISO88591 = 'iso-8859-1';
    public const CHARSET_UTF8 = 'utf-8';
    public const CONTENT_TYPE_PLAINTEXT = 'text/plain';
    public const CONTENT_TYPE_TEXT_CALENDAR = 'text/calendar';
    public const CONTENT_TYPE_TEXT_HTML = 'text/html';
    public const CONTENT_TYPE_MULTIPART_ALTERNATIVE = 'multipart/alternative';
    public const CONTENT_TYPE_MULTIPART_MIXED = 'multipart/mixed';
    public const CONTENT_TYPE_MULTIPART_RELATED = 'multipart/related';
    public const ENCODING_7BIT = '7bit';
    public const ENCODING_8BIT = '8bit';
    public const ENCODING_BASE64 = 'base64';
    public const ENCODING_BINARY = 'binary';
    public const ENCODING_QUOTED_PRINTABLE = 'quoted-printable';
    public const ENCRYPTION_STARTTLS = 'tls';
    public const ENCRYPTION_SMTPS = 'ssl';
    public const ICAL_METHOD_REQUEST = 'REQUEST';
    public const ICAL_METHOD_PUBLISH = 'PUBLISH';
    public const ICAL_METHOD_REPLY = 'REPLY';
    public const ICAL_METHOD_ADD = 'ADD';
    public const ICAL_METHOD_CANCEL = 'CANCEL';
    public const ICAL_METHOD_REFRESH = 'REFRESH';
    public const ICAL_METHOD_COUNTER = 'COUNTER';
    public const ICAL_METHOD_DECLINECOUNTER = 'DECLINECOUNTER';
    public $Priority;
    public $CharSet = self::CHARSET_ISO88591;
    public $ContentType = self::CONTENT_TYPE_PLAINTEXT;
    public $Encoding = self::ENCODING_8BIT;
    public $ErrorInfo = '';
    public $From = '';
    public $FromName = '';
    public $Sender = '';
    public $Subject = '';
    public $Body = '';
    public $AltBody = '';
    public $Ical = '';
    protected static $IcalMethods = [self::ICAL_METHOD_REQUEST, self::ICAL_METHOD_PUBLISH, self::ICAL_METHOD_REPLY, self::ICAL_METHOD_ADD, self::ICAL_METHOD_CANCEL, self::ICAL_METHOD_REFRESH, self::ICAL_METHOD_COUNTER, self::ICAL_METHOD_DECLINECOUNTER,];
    protected $MIMEBody = '';
    protected $MIMEHeader = '';
    protected $mailHeader = '';
    public $WordWrap = 0;
    public $Mailer = 'mail';
    public $Sendmail = '/usr/sbin/sendmail';
    public $UseSendmailOptions = true;
    public $ConfirmReadingTo = '';
    public $Hostname = '';
    public $MessageID = '';
    public $MessageDate = '';
    public $Host = 'localhost';
    public $Port = 25;
    public $Helo = '';
    public $SMTPSecure = '';
    public $SMTPAutoTLS = true;
    public $SMTPAuth = false;
    public $SMTPOptions = [];
    public $Username = '';
    public $Password = '';
    public $AuthType = '';
    protected $SMTPXClient = [];
    protected $oauth;
    public $Timeout = 300;
    public $dsn = '';
    public $SMTPDebug = 0;
    public $Debugoutput = 'echo';
    public $SMTPKeepAlive = false;
    public $SingleTo = false;
    protected $SingleToArray = [];
    public $do_verp = false;
    public $AllowEmpty = false;
    public $DKIM_selector = '';
    public $DKIM_identity = '';
    public $DKIM_passphrase = '';
    public $DKIM_domain = '';
    public $DKIM_copyHeaderFields = true;
    public $DKIM_extraHeaders = [];
    public $DKIM_private = '';
    public $DKIM_private_string = '';
    public $action_function = '';
    public $XMailer = '';
    public static $validator = 'php';
    protected $smtp;
    protected $to = [];
    protected $cc = [];
    protected $bcc = [];
    protected $ReplyTo = [];
    protected $all_recipients = [];
    protected $RecipientsQueue = [];
    protected $ReplyToQueue = [];
    protected $attachment = [];
    protected $CustomHeader = [];
    protected $lastMessageID = '';
    protected $message_type = '';
    protected $boundary = [];
    protected $language = [];
    protected $error_count = 0;
    protected $sign_cert_file = '';
    protected $sign_key_file = '';
    protected $sign_extracerts_file = '';
    protected $sign_key_pass = '';
    protected $exceptions = false;
    protected $uniqueid = '';
    public const VERSION = '6.9.1';
    public const STOP_MESSAGE = 0;
    public const STOP_CONTINUE = 1;
    public const STOP_CRITICAL = 2;
    public const CRLF = "\r\n";
    public const FWS = ' ';
    protected static $LE = self::CRLF;
    public const MAIL_MAX_LINE_LENGTH = 63;
    public const MAX_LINE_LENGTH = 998;
    public const STD_LINE_LENGTH = 76;
    public function __construct($exceptions = null)
    {
        if (null !== $exceptions) {
            $this->exceptions = (bool) $exceptions;
        } $this->Debugoutput = (strpos(PHP_SAPI, 'cli') !== false ? 'echo' : 'html');
    } public function __destruct()
    {
        $this->smtpClose();
    } private function mailPassthru($to, $subject, $body, $header, $params)
    {
        if ((int)ini_get('mbstring.func_overload') & 1) {
            $subject = $this->secureHeader($subject);
        } else {
            $subject = $this->encodeHeader($this->secureHeader($subject));
        } $this->edebug('Sending with mail()');
        $this->edebug('Sendmail path: ' . ini_get('sendmail_path'));
        $this->edebug("Envelope sender: {$this->Sender}");
        $this->edebug("To: {$to}");
        $this->edebug("Subject: {$subject}");
        $this->edebug("Headers: {$header}");
        if (!$this->UseSendmailOptions || null === $params) {
            $result = @mail($to, $subject, $body, $header);
        } else {
            $this->edebug("Additional params: {$params}");
            $result = @mail($to, $subject, $body, $header, $params);
        } $this->edebug('Result: ' . ($result ? 'true' : 'false'));
        return $result;
    } protected function edebug($str)
    {
        if ($this->SMTPDebug <= 0) {
            return;
        } if ($this->Debugoutput instanceof \Psr\Log\LoggerInterface) {
            $this->Debugoutput->debug(rtrim($str, "\r\n"));
            return;
        } if (is_callable($this->Debugoutput) && !in_array($this->Debugoutput, ['error_log', 'html', 'echo'])) {
            call_user_func($this->Debugoutput, $str, $this->SMTPDebug);
            return;
        } switch ($this->Debugoutput) {
            case 'error_log': error_log($str);
                break;
            case 'html': echo htmlentities(preg_replace('/[\r\n]+/', '', $str), ENT_QUOTES, 'UTF-8'), "<br>\n";
                break;
            case 'echo': default: $str = preg_replace('/\r\n|\r/m', "\n", $str);
                echo gmdate('Y-m-d H:i:s'), "\t", trim(str_replace("\n", "\n                   \t                  ", trim($str))), "\n";
        }
    } public function isHTML($isHtml = true)
    {
        if ($isHtml) {
            $this->ContentType = static::CONTENT_TYPE_TEXT_HTML;
        } else {
            $this->ContentType = static::CONTENT_TYPE_PLAINTEXT;
        }
    } public function isSMTP()
    {
        $this->Mailer = 'smtp';
    } public function isMail()
    {
        $this->Mailer = 'mail';
    } public function isSendmail()
    {
        $ini_sendmail_path = ini_get('sendmail_path');
        if (false === stripos($ini_sendmail_path, 'sendmail')) {
            $this->Sendmail = '/usr/sbin/sendmail';
        } else {
            $this->Sendmail = $ini_sendmail_path;
        } $this->Mailer = 'sendmail';
    } public function isQmail()
    {
        $ini_sendmail_path = ini_get('sendmail_path');
        if (false === stripos($ini_sendmail_path, 'qmail')) {
            $this->Sendmail = '/var/qmail/bin/qmail-inject';
        } else {
            $this->Sendmail = $ini_sendmail_path;
        } $this->Mailer = 'qmail';
    } public function addAddress($address, $name = '')
    {
        return $this->addOrEnqueueAnAddress('to', $address, $name);
    } public function addCC($address, $name = '')
    {
        return $this->addOrEnqueueAnAddress('cc', $address, $name);
    } public function addBCC($address, $name = '')
    {
        return $this->addOrEnqueueAnAddress('bcc', $address, $name);
    } public function addReplyTo($address, $name = '')
    {
        return $this->addOrEnqueueAnAddress('Reply-To', $address, $name);
    } protected function addOrEnqueueAnAddress($kind, $address, $name)
    {
        $pos = false;
        if ($address !== null) {
            $address = trim($address);
            $pos = strrpos($address, '@');
        } if (false === $pos) {
            $error_message = sprintf('%s (%s): %s', $this->lang('invalid_address'), $kind, $address);
            $this->setError($error_message);
            $this->edebug($error_message);
            if ($this->exceptions) {
                throw new Exception($error_message);
            } return false;
        } if ($name !== null && is_string($name)) {
            $name = trim(preg_replace('/[\r\n]+/', '', $name));
        } else {
            $name = '';
        } $params = [$kind, $address, $name];
        if (static::idnSupported() && $this->has8bitChars(substr($address, ++$pos))) {
            if ('Reply-To' !== $kind) {
                if (!array_key_exists($address, $this->RecipientsQueue)) {
                    $this->RecipientsQueue[$address] = $params;
                    return true;
                }
            } elseif (!array_key_exists($address, $this->ReplyToQueue)) {
                $this->ReplyToQueue[$address] = $params;
                return true;
            } return false;
        } return call_user_func_array([$this, 'addAnAddress'], $params);
    } public function setBoundaries()
    {
        $this->uniqueid = $this->generateId();
        $this->boundary[1] = 'b1=_' . $this->uniqueid;
        $this->boundary[2] = 'b2=_' . $this->uniqueid;
        $this->boundary[3] = 'b3=_' . $this->uniqueid;
    } protected function addAnAddress($kind, $address, $name = '')
    {
        if (!in_array($kind, ['to', 'cc', 'bcc', 'Reply-To'])) {
            $error_message = sprintf('%s: %s', $this->lang('Invalid recipient kind'), $kind);
            $this->setError($error_message);
            $this->edebug($error_message);
            if ($this->exceptions) {
                throw new Exception($error_message);
            } return false;
        } if (!static::validateAddress($address)) {
            $error_message = sprintf('%s (%s): %s', $this->lang('invalid_address'), $kind, $address);
            $this->setError($error_message);
            $this->edebug($error_message);
            if ($this->exceptions) {
                throw new Exception($error_message);
            } return false;
        } if ('Reply-To' !== $kind) {
            if (!array_key_exists(strtolower($address), $this->all_recipients)) {
                $this->{$kind}[] = [$address, $name];
                $this->all_recipients[strtolower($address)] = true;
                return true;
            }
        } elseif (!array_key_exists(strtolower($address), $this->ReplyTo)) {
            $this->ReplyTo[strtolower($address)] = [$address, $name];
            return true;
        } return false;
    } public static function parseAddresses($addrstr, $useimap = true, $charset = self::CHARSET_ISO88591)
    {
        $addresses = [];
        if ($useimap && function_exists('imap_rfc822_parse_adrlist')) {
            $list = imap_rfc822_parse_adrlist($addrstr, '');
            imap_errors();
            foreach ($list as $address) {
                if ('.SYNTAX-ERROR.' !== $address->host && static::validateAddress($address->mailbox . '@' . $address->host)) {
                    if (property_exists($address, 'personal') && defined('MB_CASE_UPPER') && preg_match('/^=\?.*\?=$/s', $address->personal)) {
                        $origCharset = mb_internal_encoding();
                        mb_internal_encoding($charset);
                        $address->personal = str_replace('_', '=20', $address->personal);
                        $address->personal = mb_decode_mimeheader($address->personal);
                        mb_internal_encoding($origCharset);
                    } $addresses[] = ['name' => (property_exists($address, 'personal') ? $address->personal : ''), 'address' => $address->mailbox . '@' . $address->host,];
                }
            }
        } else {
            $list = explode(',', $addrstr);
            foreach ($list as $address) {
                $address = trim($address);
                if (strpos($address, '<') === false) {
                    if (static::validateAddress($address)) {
                        $addresses[] = ['name' => '', 'address' => $address,];
                    }
                } else {
                    list($name, $email) = explode('<', $address);
                    $email = trim(str_replace('>', '', $email));
                    $name = trim($name);
                    if (static::validateAddress($email)) {
                        if (defined('MB_CASE_UPPER') && preg_match('/^=\?.*\?=$/s', $name)) {
                            $origCharset = mb_internal_encoding();
                            mb_internal_encoding($charset);
                            $name = str_replace('_', '=20', $name);
                            $name = mb_decode_mimeheader($name);
                            mb_internal_encoding($origCharset);
                        } $addresses[] = ['name' => trim($name, '\'" '), 'address' => $email,];
                    }
                }
            }
        } return $addresses;
    } public function setFrom($address, $name = '', $auto = true)
    {
        $address = trim((string)$address);
        $name = trim(preg_replace('/[\r\n]+/', '', $name));
        $pos = strrpos($address, '@');
        if ((false === $pos) || ((!$this->has8bitChars(substr($address, ++$pos)) || !static::idnSupported()) && !static::validateAddress($address))) {
            $error_message = sprintf('%s (From): %s', $this->lang('invalid_address'), $address);
            $this->setError($error_message);
            $this->edebug($error_message);
            if ($this->exceptions) {
                throw new Exception($error_message);
            } return false;
        } $this->From = $address;
        $this->FromName = $name;
        if ($auto && empty($this->Sender)) {
            $this->Sender = $address;
        } return true;
    } public function getLastMessageID()
    {
        return $this->lastMessageID;
    } public static function validateAddress($address, $patternselect = null)
    {
        if (null === $patternselect) {
            $patternselect = static::$validator;
        } if (is_callable($patternselect) && !is_string($patternselect)) {
            return call_user_func($patternselect, $address);
        } if (strpos($address, "\n") !== false || strpos($address, "\r") !== false) {
            return false;
        } switch ($patternselect) {
            case 'pcre': case 'pcre8': return (bool) preg_match('/^(?!(?>(?1)"?(?>\\\[ -~]|[^"])"?(?1)){255,})(?!(?>(?1)"?(?>\\\[ -~]|[^"])"?(?1)){65,}@)' . '((?>(?>(?>((?>(?>(?>\x0D\x0A)?[\t ])+|(?>[\t ]*\x0D\x0A)?[\t ]+)?)(\((?>(?2)' . '(?>[\x01-\x08\x0B\x0C\x0E-\'*-\[\]-\x7F]|\\\[\x00-\x7F]|(?3)))*(?2)\)))+(?2))|(?2))?)' . '([!#-\'*+\/-9=?^-~-]+|"(?>(?2)(?>[\x01-\x08\x0B\x0C\x0E-!#-\[\]-\x7F]|\\\[\x00-\x7F]))*' . '(?2)")(?>(?1)\.(?1)(?4))*(?1)@(?!(?1)[a-z0-9-]{64,})(?1)(?>([a-z0-9](?>[a-z0-9-]*[a-z0-9])?)' . '(?>(?1)\.(?!(?1)[a-z0-9-]{64,})(?1)(?5)){0,126}|\[(?:(?>IPv6:(?>([a-f0-9]{1,4})(?>:(?6)){7}' . '|(?!(?:.*[a-f0-9][:\]]){8,})((?6)(?>:(?6)){0,6})?::(?7)?))|(?>(?>IPv6:(?>(?6)(?>:(?6)){5}:' . '|(?!(?:.*[a-f0-9]:){6,})(?8)?::(?>((?6)(?>:(?6)){0,4}):)?))?(25[0-5]|2[0-4][0-9]|1[0-9]{2}' . '|[1-9]?[0-9])(?>\.(?9)){3}))\])(?1)$/isD', $address);
            case 'html5': return (bool) preg_match('/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}' . '[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/sD', $address);
            case 'php': default: return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
        }
    } public static function idnSupported()
    {
        return function_exists('idn_to_ascii') && function_exists('mb_convert_encoding');
    } public function punyencodeAddress($address)
    {
        $pos = strrpos($address, '@');
        if (!empty($this->CharSet) && false !== $pos && static::idnSupported()) {
            $domain = substr($address, ++$pos);
            if ($this->has8bitChars($domain) && @mb_check_encoding($domain, $this->CharSet)) {
                $domain = mb_convert_encoding($domain, self::CHARSET_UTF8, $this->CharSet);
                $errorcode = 0;
                if (defined('INTL_IDNA_VARIANT_UTS46')) {
                    $punycode = idn_to_ascii($domain, \IDNA_DEFAULT | \IDNA_USE_STD3_RULES | \IDNA_CHECK_BIDI | \IDNA_CHECK_CONTEXTJ | \IDNA_NONTRANSITIONAL_TO_ASCII, \INTL_IDNA_VARIANT_UTS46);
                } elseif (defined('INTL_IDNA_VARIANT_2003')) {
                    $punycode = idn_to_ascii($domain, $errorcode, \INTL_IDNA_VARIANT_2003);
                } else {
                    $punycode = idn_to_ascii($domain, $errorcode);
                } if (false !== $punycode) {
                    return substr($address, 0, $pos) . $punycode;
                }
            }
        } return $address;
    } public function send()
    {
        try {
            if (!$this->preSend()) {
                return false;
            } return $this->postSend();
        } catch (Exception $exc) {
            $this->mailHeader = '';
            $this->setError($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            } return false;
        }
    } public function preSend()
    {
        if ('smtp' === $this->Mailer || ('mail' === $this->Mailer && (\PHP_VERSION_ID >= 80000 || stripos(PHP_OS, 'WIN') === 0))) {
            static::setLE(self::CRLF);
        } else {
            static::setLE(PHP_EOL);
        } if ('mail' === $this->Mailer && ((\PHP_VERSION_ID >= 70000 && \PHP_VERSION_ID < 70017) || (\PHP_VERSION_ID >= 70100 && \PHP_VERSION_ID < 70103)) && ini_get('mail.add_x_header') === '1' && stripos(PHP_OS, 'WIN') === 0) {
            trigger_error($this->lang('buggy_php'), E_USER_WARNING);
        } try {
            $this->error_count = 0;
            $this->mailHeader = '';
            foreach (array_merge($this->RecipientsQueue, $this->ReplyToQueue) as $params) {
                $params[1] = $this->punyencodeAddress($params[1]);
                call_user_func_array([$this, 'addAnAddress'], $params);
            } if (count($this->to) + count($this->cc) + count($this->bcc) < 1) {
                throw new Exception($this->lang('provide_address'), self::STOP_CRITICAL);
            } foreach (['From', 'Sender', 'ConfirmReadingTo'] as $address_kind) {
                if ($this->{$address_kind} === null) {
                    $this->{$address_kind} = '';
                    continue;
                } $this->{$address_kind} = trim($this->{$address_kind});
                if (empty($this->{$address_kind})) {
                    continue;
                } $this->{$address_kind} = $this->punyencodeAddress($this->{$address_kind});
                if (!static::validateAddress($this->{$address_kind})) {
                    $error_message = sprintf('%s (%s): %s', $this->lang('invalid_address'), $address_kind, $this->{$address_kind});
                    $this->setError($error_message);
                    $this->edebug($error_message);
                    if ($this->exceptions) {
                        throw new Exception($error_message);
                    } return false;
                }
            } if ($this->alternativeExists()) {
                $this->ContentType = static::CONTENT_TYPE_MULTIPART_ALTERNATIVE;
            } $this->setMessageType();
            if (!$this->AllowEmpty && empty($this->Body)) {
                throw new Exception($this->lang('empty_message'), self::STOP_CRITICAL);
            } $this->Subject = trim($this->Subject);
            $this->MIMEHeader = '';
            $this->MIMEBody = $this->createBody();
            $tempheaders = $this->MIMEHeader;
            $this->MIMEHeader = $this->createHeader();
            $this->MIMEHeader .= $tempheaders;
            if ('mail' === $this->Mailer) {
                if (count($this->to) > 0) {
                    $this->mailHeader .= $this->addrAppend('To', $this->to);
                } else {
                    $this->mailHeader .= $this->headerLine('To', 'undisclosed-recipients:;');
                } $this->mailHeader .= $this->headerLine('Subject', $this->encodeHeader($this->secureHeader($this->Subject)));
            } if (!empty($this->DKIM_domain) && !empty($this->DKIM_selector) && (!empty($this->DKIM_private_string) || (!empty($this->DKIM_private) && static::isPermittedPath($this->DKIM_private) && file_exists($this->DKIM_private)))) {
                $header_dkim = $this->DKIM_Add($this->MIMEHeader . $this->mailHeader, $this->encodeHeader($this->secureHeader($this->Subject)), $this->MIMEBody);
                $this->MIMEHeader = static::stripTrailingWSP($this->MIMEHeader) . static::$LE . static::normalizeBreaks($header_dkim) . static::$LE;
            } return true;
        } catch (Exception $exc) {
            $this->setError($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            } return false;
        }
    } public function postSend()
    {
        try {
            switch ($this->Mailer) {
                case 'sendmail': case 'qmail': return $this->sendmailSend($this->MIMEHeader, $this->MIMEBody);
                case 'smtp': return $this->smtpSend($this->MIMEHeader, $this->MIMEBody);
                case 'mail': return $this->mailSend($this->MIMEHeader, $this->MIMEBody);
                default: $sendMethod = $this->Mailer . 'Send';
                    if (method_exists($this, $sendMethod)) {
                        return $this->{$sendMethod}($this->MIMEHeader, $this->MIMEBody);
                    } return $this->mailSend($this->MIMEHeader, $this->MIMEBody);
            }
        } catch (Exception $exc) {
            $this->setError($exc->getMessage());
            $this->edebug($exc->getMessage());
            if ($this->Mailer === 'smtp' && $this->SMTPKeepAlive == true && $this->smtp->connected()) {
                $this->smtp->reset();
            } if ($this->exceptions) {
                throw $exc;
            }
        } return false;
    } protected function sendmailSend($header, $body)
    {
        if ($this->Mailer === 'qmail') {
            $this->edebug('Sending with qmail');
        } else {
            $this->edebug('Sending with sendmail');
        } $header = static::stripTrailingWSP($header) . static::$LE . static::$LE;
        $sendmail_from_value = ini_get('sendmail_from');
        if (empty($this->Sender) && !empty($sendmail_from_value)) {
            $this->Sender = ini_get('sendmail_from');
        } if (!empty($this->Sender) && static::validateAddress($this->Sender) && self::isShellSafe($this->Sender)) {
            if ($this->Mailer === 'qmail') {
                $sendmailFmt = '%s -f%s';
            } else {
                $sendmailFmt = '%s -oi -f%s -t';
            }
        } else {
            $sendmailFmt = '%s -oi -t';
        } $sendmail = sprintf($sendmailFmt, escapeshellcmd($this->Sendmail), $this->Sender);
        $this->edebug('Sendmail path: ' . $this->Sendmail);
        $this->edebug('Sendmail command: ' . $sendmail);
        $this->edebug('Envelope sender: ' . $this->Sender);
        $this->edebug("Headers: {$header}");
        if ($this->SingleTo) {
            foreach ($this->SingleToArray as $toAddr) {
                $mail = @popen($sendmail, 'w');
                if (!$mail) {
                    throw new Exception($this->lang('execute') . $this->Sendmail, self::STOP_CRITICAL);
                } $this->edebug("To: {$toAddr}");
                fwrite($mail, 'To: ' . $toAddr . "\n");
                fwrite($mail, $header);
                fwrite($mail, $body);
                $result = pclose($mail);
                $addrinfo = static::parseAddresses($toAddr, true, $this->CharSet);
                $this->doCallback(($result === 0), [[$addrinfo['address'], $addrinfo['name']]], $this->cc, $this->bcc, $this->Subject, $body, $this->From, []);
                $this->edebug("Result: " . ($result === 0 ? 'true' : 'false'));
                if (0 !== $result) {
                    throw new Exception($this->lang('execute') . $this->Sendmail, self::STOP_CRITICAL);
                }
            }
        } else {
            $mail = @popen($sendmail, 'w');
            if (!$mail) {
                throw new Exception($this->lang('execute') . $this->Sendmail, self::STOP_CRITICAL);
            } fwrite($mail, $header);
            fwrite($mail, $body);
            $result = pclose($mail);
            $this->doCallback(($result === 0), $this->to, $this->cc, $this->bcc, $this->Subject, $body, $this->From, []);
            $this->edebug("Result: " . ($result === 0 ? 'true' : 'false'));
            if (0 !== $result) {
                throw new Exception($this->lang('execute') . $this->Sendmail, self::STOP_CRITICAL);
            }
        } return true;
    } protected static function isShellSafe($string)
    {
        if (!function_exists('escapeshellarg') || !function_exists('escapeshellcmd')) {
            return false;
        } if (escapeshellcmd($string) !== $string || !in_array(escapeshellarg($string), ["'$string'", "\"$string\""])) {
            return false;
        } $length = strlen($string);
        for ($i = 0; $i < $length; ++$i) {
            $c = $string[$i];
            if (!ctype_alnum($c) && strpos('@_-.', $c) === false) {
                return false;
            }
        } return true;
    } protected static function isPermittedPath($path)
    {
        return !preg_match('#^[a-z][a-z\d+.-]*://#i', $path);
    } protected static function fileIsAccessible($path)
    {
        if (!static::isPermittedPath($path)) {
            return false;
        } $readable = is_file($path);
        if (strpos($path, '\\\\') !== 0) {
            $readable = $readable && is_readable($path);
        } return $readable;
    } protected function mailSend($header, $body)
    {
        $header = static::stripTrailingWSP($header) . static::$LE . static::$LE;
        $toArr = [];
        foreach ($this->to as $toaddr) {
            $toArr[] = $this->addrFormat($toaddr);
        } $to = trim(implode(', ', $toArr));
        if ($to === '') {
            $to = 'undisclosed-recipients:;';
        } $params = null;
        $sendmail_from_value = ini_get('sendmail_from');
        if (empty($this->Sender) && !empty($sendmail_from_value)) {
            $this->Sender = ini_get('sendmail_from');
        } if (!empty($this->Sender) && static::validateAddress($this->Sender)) {
            if (self::isShellSafe($this->Sender)) {
                $params = sprintf('-f%s', $this->Sender);
            } $old_from = ini_get('sendmail_from');
            ini_set('sendmail_from', $this->Sender);
        } $result = false;
        if ($this->SingleTo && count($toArr) > 1) {
            foreach ($toArr as $toAddr) {
                $result = $this->mailPassthru($toAddr, $this->Subject, $body, $header, $params);
                $addrinfo = static::parseAddresses($toAddr, true, $this->CharSet);
                $this->doCallback($result, [[$addrinfo['address'], $addrinfo['name']]], $this->cc, $this->bcc, $this->Subject, $body, $this->From, []);
            }
        } else {
            $result = $this->mailPassthru($to, $this->Subject, $body, $header, $params);
            $this->doCallback($result, $this->to, $this->cc, $this->bcc, $this->Subject, $body, $this->From, []);
        } if (isset($old_from)) {
            ini_set('sendmail_from', $old_from);
        } if (!$result) {
            throw new Exception($this->lang('instantiate'), self::STOP_CRITICAL);
        } return true;
    } public function getSMTPInstance()
    {
        if (!is_object($this->smtp)) {
            $this->smtp = new SMTP();
        } return $this->smtp;
    } public function setSMTPInstance(SMTP $smtp)
    {
        $this->smtp = $smtp;
        return $this->smtp;
    } public function setSMTPXclientAttribute($name, $value)
    {
        if (!in_array($name, SMTP::$xclient_allowed_attributes)) {
            return false;
        } if (isset($this->SMTPXClient[$name]) && $value === null) {
            unset($this->SMTPXClient[$name]);
        } elseif ($value !== null) {
            $this->SMTPXClient[$name] = $value;
        } return true;
    } public function getSMTPXclientAttributes()
    {
        return $this->SMTPXClient;
    } protected function smtpSend($header, $body)
    {
        $header = static::stripTrailingWSP($header) . static::$LE . static::$LE;
        $bad_rcpt = [];
        if (!$this->smtpConnect($this->SMTPOptions)) {
            throw new Exception($this->lang('smtp_connect_failed'), self::STOP_CRITICAL);
        } if ('' === $this->Sender) {
            $smtp_from = $this->From;
        } else {
            $smtp_from = $this->Sender;
        } if (count($this->SMTPXClient)) {
            $this->smtp->xclient($this->SMTPXClient);
        } if (!$this->smtp->mail($smtp_from)) {
            $this->setError($this->lang('from_failed') . $smtp_from . ' : ' . implode(',', $this->smtp->getError()));
            throw new Exception($this->ErrorInfo, self::STOP_CRITICAL);
        } $callbacks = [];
        foreach ([$this->to, $this->cc, $this->bcc] as $togroup) {
            foreach ($togroup as $to) {
                if (!$this->smtp->recipient($to[0], $this->dsn)) {
                    $error = $this->smtp->getError();
                    $bad_rcpt[] = ['to' => $to[0], 'error' => $error['detail']];
                    $isSent = false;
                } else {
                    $isSent = true;
                } $callbacks[] = ['issent' => $isSent, 'to' => $to[0], 'name' => $to[1]];
            }
        } if ((count($this->all_recipients) > count($bad_rcpt)) && !$this->smtp->data($header . $body)) {
            throw new Exception($this->lang('data_not_accepted'), self::STOP_CRITICAL);
        } $smtp_transaction_id = $this->smtp->getLastTransactionID();
        if ($this->SMTPKeepAlive) {
            $this->smtp->reset();
        } else {
            $this->smtp->quit();
            $this->smtp->close();
        } foreach ($callbacks as $cb) {
            $this->doCallback($cb['issent'], [[$cb['to'], $cb['name']]], [], [], $this->Subject, $body, $this->From, ['smtp_transaction_id' => $smtp_transaction_id]);
        } if (count($bad_rcpt) > 0) {
            $errstr = '';
            foreach ($bad_rcpt as $bad) {
                $errstr .= $bad['to'] . ': ' . $bad['error'];
            } throw new Exception($this->lang('recipients_failed') . $errstr, self::STOP_CONTINUE);
        } return true;
    } public function smtpConnect($options = null)
    {
        if (null === $this->smtp) {
            $this->smtp = $this->getSMTPInstance();
        } if (null === $options) {
            $options = $this->SMTPOptions;
        } if ($this->smtp->connected()) {
            return true;
        } $this->smtp->setTimeout($this->Timeout);
        $this->smtp->setDebugLevel($this->SMTPDebug);
        $this->smtp->setDebugOutput($this->Debugoutput);
        $this->smtp->setVerp($this->do_verp);
        if ($this->Host === null) {
            $this->Host = 'localhost';
        } $hosts = explode(';', $this->Host);
        $lastexception = null;
        foreach ($hosts as $hostentry) {
            $hostinfo = [];
            if (!preg_match('/^(?:(ssl|tls):\/\/)?(.+?)(?::(\d+))?$/', trim($hostentry), $hostinfo)) {
                $this->edebug($this->lang('invalid_hostentry') . ' ' . trim($hostentry));
                continue;
            } if (!static::isValidHost($hostinfo[2])) {
                $this->edebug($this->lang('invalid_host') . ' ' . $hostinfo[2]);
                continue;
            } $prefix = '';
            $secure = $this->SMTPSecure;
            $tls = (static::ENCRYPTION_STARTTLS === $this->SMTPSecure);
            if ('ssl' === $hostinfo[1] || ('' === $hostinfo[1] && static::ENCRYPTION_SMTPS === $this->SMTPSecure)) {
                $prefix = 'ssl://';
                $tls = false;
                $secure = static::ENCRYPTION_SMTPS;
            } elseif ('tls' === $hostinfo[1]) {
                $tls = true;
                $secure = static::ENCRYPTION_STARTTLS;
            } $sslext = defined('OPENSSL_ALGO_SHA256');
            if (static::ENCRYPTION_STARTTLS === $secure || static::ENCRYPTION_SMTPS === $secure) {
                if (!$sslext) {
                    throw new Exception($this->lang('extension_missing') . 'openssl', self::STOP_CRITICAL);
                }
            } $host = $hostinfo[2];
            $port = $this->Port;
            if (array_key_exists(3, $hostinfo) && is_numeric($hostinfo[3]) && $hostinfo[3] > 0 && $hostinfo[3] < 65536) {
                $port = (int) $hostinfo[3];
            } if ($this->smtp->connect($prefix . $host, $port, $this->Timeout, $options)) {
                try {
                    if ($this->Helo) {
                        $hello = $this->Helo;
                    } else {
                        $hello = $this->serverHostname();
                    } $this->smtp->hello($hello);
                    if ($this->SMTPAutoTLS && $this->Host !== 'localhost' && $sslext && $secure !== 'ssl' && $this->smtp->getServerExt('STARTTLS')) {
                        $tls = true;
                    } if ($tls) {
                        if (!$this->smtp->startTLS()) {
                            $message = $this->getSmtpErrorMessage('connect_host');
                            throw new Exception($message);
                        } $this->smtp->hello($hello);
                    } if ($this->SMTPAuth && !$this->smtp->authenticate($this->Username, $this->Password, $this->AuthType, $this->oauth)) {
                        throw new Exception($this->lang('authenticate'));
                    } return true;
                } catch (Exception $exc) {
                    $lastexception = $exc;
                    $this->edebug($exc->getMessage());
                    $this->smtp->quit();
                }
            }
        } $this->smtp->close();
        if ($this->exceptions && null !== $lastexception) {
            throw $lastexception;
        } if ($this->exceptions) {
            $message = $this->getSmtpErrorMessage('connect_host');
            throw new Exception($message);
        } return false;
    } public function smtpClose()
    {
        if ((null !== $this->smtp) && $this->smtp->connected()) {
            $this->smtp->quit();
            $this->smtp->close();
        }
    } public function setLanguage($langcode = 'en', $lang_path = '')
    {
        $renamed_langcodes = ['br' => 'pt_br', 'cz' => 'cs', 'dk' => 'da', 'no' => 'nb', 'se' => 'sv', 'rs' => 'sr', 'tg' => 'tl', 'am' => 'hy',];
        if (array_key_exists($langcode, $renamed_langcodes)) {
            $langcode = $renamed_langcodes[$langcode];
        } $PHPMAILER_LANG = ['authenticate' => 'SMTP Error: Could not authenticate.', 'buggy_php' => 'Your version of PHP is affected by a bug that may result in corrupted messages.' . ' To fix it, switch to sending using SMTP, disable the mail.add_x_header option in' . ' your php.ini, switch to MacOS or Linux, or upgrade your PHP to version 7.0.17+ or 7.1.3+.', 'connect_host' => 'SMTP Error: Could not connect to SMTP host.', 'data_not_accepted' => 'SMTP Error: data not accepted.', 'empty_message' => 'Message body empty', 'encoding' => 'Unknown encoding: ', 'execute' => 'Could not execute: ', 'extension_missing' => 'Extension missing: ', 'file_access' => 'Could not access file: ', 'file_open' => 'File Error: Could not open file: ', 'from_failed' => 'The following From address failed: ', 'instantiate' => 'Could not instantiate mail function.', 'invalid_address' => 'Invalid address: ', 'invalid_header' => 'Invalid header name or value', 'invalid_hostentry' => 'Invalid hostentry: ', 'invalid_host' => 'Invalid host: ', 'mailer_not_supported' => ' mailer is not supported.', 'provide_address' => 'You must provide at least one recipient email address.', 'recipients_failed' => 'SMTP Error: The following recipients failed: ', 'signing' => 'Signing Error: ', 'smtp_code' => 'SMTP code: ', 'smtp_code_ex' => 'Additional SMTP info: ', 'smtp_connect_failed' => 'SMTP connect() failed.', 'smtp_detail' => 'Detail: ', 'smtp_error' => 'SMTP server error: ', 'variable_set' => 'Cannot set or reset variable: ',];
        $PHPMAILER_LANG['authenticate'] = 'SMTP登录失败：邮箱账号或密码错误。';
        $PHPMAILER_LANG['buggy_php'] = '您的 PHP 版本存在漏洞，可能会导致消息损坏。为修复此问题，请切换到使用 SMTP 发送，在您的 php.ini 中禁用 mail.add_x_header 选项。切换到 MacOS 或 Linux，或将您的 PHP 升级到 7.0.17+ 或 7.1.3+ 版本。';
        $PHPMAILER_LANG['connect_host'] = '无法连接到SMTP服务器。';
        $PHPMAILER_LANG['data_not_accepted'] = '数据不被接受。';
        $PHPMAILER_LANG['empty_message'] = '邮件正文为空。';
        $PHPMAILER_LANG['encoding'] = '未知编码：';
        $PHPMAILER_LANG['execute'] = '无法执行：';
        $PHPMAILER_LANG['extension_missing'] = '缺少扩展名：';
        $PHPMAILER_LANG['file_access'] = '无法访问文件：';
        $PHPMAILER_LANG['file_open'] = '文件错误：无法打开文件：';
        $PHPMAILER_LANG['from_failed'] = '发送地址错误：';
        $PHPMAILER_LANG['instantiate'] = '未知函数调用。';
        $PHPMAILER_LANG['invalid_address'] = '发送失败，电子邮箱地址是无效的：';
        $PHPMAILER_LANG['mailer_not_supported'] = '发信客户端不被支持。';
        $PHPMAILER_LANG['provide_address'] = '必须提供至少一个收件人地址。';
        $PHPMAILER_LANG['recipients_failed'] = '收件人地址错误：';
        $PHPMAILER_LANG['smtp_connect_failed'] = 'SMTP服务器连接失败：SMTP服务器地址或端口错误。';
        $PHPMAILER_LANG['smtp_error'] = 'SMTP服务器出错：';
        $PHPMAILER_LANG['variable_set'] = '无法设置或重置变量：';
        $PHPMAILER_LANG['invalid_header'] = '无效的标题名称或值';
        $PHPMAILER_LANG['invalid_hostentry'] = '无效的hostentry： ';
        $PHPMAILER_LANG['invalid_host'] = '无效的主机：';
        $PHPMAILER_LANG['signing'] = '签名错误：';
        $PHPMAILER_LANG['smtp_code'] = 'SMTP代码： ';
        $PHPMAILER_LANG['smtp_code_ex'] = '附加SMTP信息： ';
        $PHPMAILER_LANG['smtp_detail'] = '详情:';
        if (empty($lang_path)) {
            $lang_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR;
        } $foundlang = true;
        $langcode = strtolower($langcode);
        if (!preg_match('/^(?P<lang>[a-z]{2})(?P<script>_[a-z]{4})?(?P<country>_[a-z]{2})?$/', $langcode, $matches) && $langcode !== 'en') {
            $foundlang = false;
            $langcode = 'en';
        } if ('en' !== $langcode) {
            $langcodes = [];
            if (!empty($matches['script']) && !empty($matches['country'])) {
                $langcodes[] = $matches['lang'] . $matches['script'] . $matches['country'];
            } if (!empty($matches['country'])) {
                $langcodes[] = $matches['lang'] . $matches['country'];
            } if (!empty($matches['script'])) {
                $langcodes[] = $matches['lang'] . $matches['script'];
            } $langcodes[] = $matches['lang'];
            $foundFile = false;
            foreach ($langcodes as $code) {
                $lang_file = $lang_path . 'phpmailer.lang-' . $code . '.php';
                if (static::fileIsAccessible($lang_file)) {
                    $foundFile = true;
                    break;
                }
            } if ($foundFile === false) {
                $foundlang = false;
            } else {
                $lines = file($lang_file);
                foreach ($lines as $line) {
                    $matches = [];
                    if (preg_match('/^\$PHPMAILER_LANG\[\'([a-z\d_]+)\'\]\s*=\s*(["\'])(.+)*?\2;/', $line, $matches) && array_key_exists($matches[1], $PHPMAILER_LANG)) {
                        $PHPMAILER_LANG[$matches[1]] = (string)$matches[3];
                    }
                }
            }
        } $this->language = $PHPMAILER_LANG;
        return $foundlang;
    } public function getTranslations()
    {
        if (empty($this->language)) {
            $this->setLanguage();
        } return $this->language;
    } public function addrAppend($type, $addr)
    {
        $addresses = [];
        foreach ($addr as $address) {
            $addresses[] = $this->addrFormat($address);
        } return $type . ': ' . implode(', ', $addresses) . static::$LE;
    } public function addrFormat($addr)
    {
        if (!isset($addr[1]) || ($addr[1] === '')) {
            return $this->secureHeader($addr[0]);
        } return $this->encodeHeader($this->secureHeader($addr[1]), 'phrase') . ' <' . $this->secureHeader($addr[0]) . '>';
    } public function wrapText($message, $length, $qp_mode = false)
    {
        if ($qp_mode) {
            $soft_break = sprintf(' =%s', static::$LE);
        } else {
            $soft_break = static::$LE;
        } $is_utf8 = static::CHARSET_UTF8 === strtolower($this->CharSet);
        $lelen = strlen(static::$LE);
        $crlflen = strlen(static::$LE);
        $message = static::normalizeBreaks($message);
        if (substr($message, -$lelen) === static::$LE) {
            $message = substr($message, 0, -$lelen);
        } $lines = explode(static::$LE, $message);
        $message = '';
        foreach ($lines as $line) {
            $words = explode(' ', $line);
            $buf = '';
            $firstword = true;
            foreach ($words as $word) {
                if ($qp_mode && (strlen($word) > $length)) {
                    $space_left = $length - strlen($buf) - $crlflen;
                    if (!$firstword) {
                        if ($space_left > 20) {
                            $len = $space_left;
                            if ($is_utf8) {
                                $len = $this->utf8CharBoundary($word, $len);
                            } elseif ('=' === substr($word, $len - 1, 1)) {
                                --$len;
                            } elseif ('=' === substr($word, $len - 2, 1)) {
                                $len -= 2;
                            } $part = substr($word, 0, $len);
                            $word = substr($word, $len);
                            $buf .= ' ' . $part;
                            $message .= $buf . sprintf('=%s', static::$LE);
                        } else {
                            $message .= $buf . $soft_break;
                        } $buf = '';
                    } while ($word !== '') {
                        if ($length <= 0) {
                            break;
                        } $len = $length;
                        if ($is_utf8) {
                            $len = $this->utf8CharBoundary($word, $len);
                        } elseif ('=' === substr($word, $len - 1, 1)) {
                            --$len;
                        } elseif ('=' === substr($word, $len - 2, 1)) {
                            $len -= 2;
                        } $part = substr($word, 0, $len);
                        $word = (string) substr($word, $len);
                        if ($word !== '') {
                            $message .= $part . sprintf('=%s', static::$LE);
                        } else {
                            $buf = $part;
                        }
                    }
                } else {
                    $buf_o = $buf;
                    if (!$firstword) {
                        $buf .= ' ';
                    } $buf .= $word;
                    if ('' !== $buf_o && strlen($buf) > $length) {
                        $message .= $buf_o . $soft_break;
                        $buf = $word;
                    }
                } $firstword = false;
            } $message .= $buf . static::$LE;
        } return $message;
    } public function utf8CharBoundary($encodedText, $maxLength)
    {
        $foundSplitPos = false;
        $lookBack = 3;
        while (!$foundSplitPos) {
            $lastChunk = substr($encodedText, $maxLength - $lookBack, $lookBack);
            $encodedCharPos = strpos($lastChunk, '=');
            if (false !== $encodedCharPos) {
                $hex = substr($encodedText, $maxLength - $lookBack + $encodedCharPos + 1, 2);
                $dec = hexdec($hex);
                if ($dec < 128) {
                    if ($encodedCharPos > 0) {
                        $maxLength -= $lookBack - $encodedCharPos;
                    } $foundSplitPos = true;
                } elseif ($dec >= 192) {
                    $maxLength -= $lookBack - $encodedCharPos;
                    $foundSplitPos = true;
                } elseif ($dec < 192) {
                    $lookBack += 3;
                }
            } else {
                $foundSplitPos = true;
            }
        } return $maxLength;
    } public function setWordWrap()
    {
        if ($this->WordWrap < 1) {
            return;
        } switch ($this->message_type) {
            case 'alt': case 'alt_inline': case 'alt_attach': case 'alt_inline_attach': $this->AltBody = $this->wrapText($this->AltBody, $this->WordWrap);
                break;
            default: $this->Body = $this->wrapText($this->Body, $this->WordWrap);
                break;
        }
    } public function createHeader()
    {
        $result = '';
        $result .= $this->headerLine('Date', '' === $this->MessageDate ? self::rfcDate() : $this->MessageDate);
        if ('mail' !== $this->Mailer) {
            if ($this->SingleTo) {
                foreach ($this->to as $toaddr) {
                    $this->SingleToArray[] = $this->addrFormat($toaddr);
                }
            } elseif (count($this->to) > 0) {
                $result .= $this->addrAppend('To', $this->to);
            } elseif (count($this->cc) === 0) {
                $result .= $this->headerLine('To', 'undisclosed-recipients:;');
            }
        } $result .= $this->addrAppend('From', [[trim($this->From), $this->FromName]]);
        if (count($this->cc) > 0) {
            $result .= $this->addrAppend('Cc', $this->cc);
        } if (('sendmail' === $this->Mailer || 'qmail' === $this->Mailer || 'mail' === $this->Mailer) && count($this->bcc) > 0) {
            $result .= $this->addrAppend('Bcc', $this->bcc);
        } if (count($this->ReplyTo) > 0) {
            $result .= $this->addrAppend('Reply-To', $this->ReplyTo);
        } if ('mail' !== $this->Mailer) {
            $result .= $this->headerLine('Subject', $this->encodeHeader($this->secureHeader($this->Subject)));
        } if ('' !== $this->MessageID && preg_match('/^<((([a-z\d!#$%&\'*+\/=?^_`{|}~-]+(\.[a-z\d!#$%&\'*+\/=?^_`{|}~-]+)*)' . '|("(([\x01-\x08\x0B\x0C\x0E-\x1F\x7F]|[\x21\x23-\x5B\x5D-\x7E])' . '|(\\[\x01-\x09\x0B\x0C\x0E-\x7F]))*"))@(([a-z\d!#$%&\'*+\/=?^_`{|}~-]+' . '(\.[a-z\d!#$%&\'*+\/=?^_`{|}~-]+)*)|(\[(([\x01-\x08\x0B\x0C\x0E-\x1F\x7F]' . '|[\x21-\x5A\x5E-\x7E])|(\\[\x01-\x09\x0B\x0C\x0E-\x7F]))*\])))>$/Di', $this->MessageID)) {
            $this->lastMessageID = $this->MessageID;
        } else {
            $this->lastMessageID = sprintf('<%s@%s>', $this->uniqueid, $this->serverHostname());
        } $result .= $this->headerLine('Message-ID', $this->lastMessageID);
        if (null !== $this->Priority) {
            $result .= $this->headerLine('X-Priority', $this->Priority);
        } if ('' === $this->XMailer) {
            $result .= $this->headerLine('X-Mailer', 'PHPMailer ' . self::VERSION . '');
        } elseif (is_string($this->XMailer) && trim($this->XMailer) !== '') {
            $result .= $this->headerLine('X-Mailer', trim($this->XMailer));
        } if ('' !== $this->ConfirmReadingTo) {
            $result .= $this->headerLine('Disposition-Notification-To', '<' . $this->ConfirmReadingTo . '>');
        } foreach ($this->CustomHeader as $header) {
            $result .= $this->headerLine(trim($header[0]), $this->encodeHeader(trim($header[1])));
        } if (!$this->sign_key_file) {
            $result .= $this->headerLine('MIME-Version', '1.0');
            $result .= $this->getMailMIME();
        } return $result;
    } public function getMailMIME()
    {
        $result = '';
        $ismultipart = true;
        switch ($this->message_type) {
            case 'inline': $result .= $this->headerLine('Content-Type', static::CONTENT_TYPE_MULTIPART_RELATED . ';');
                $result .= $this->textLine(' boundary="' . $this->boundary[1] . '"');
                break;
            case 'attach': case 'inline_attach': case 'alt_attach': case 'alt_inline_attach': $result .= $this->headerLine('Content-Type', static::CONTENT_TYPE_MULTIPART_MIXED . ';');
                $result .= $this->textLine(' boundary="' . $this->boundary[1] . '"');
                break;
            case 'alt': case 'alt_inline': $result .= $this->headerLine('Content-Type', static::CONTENT_TYPE_MULTIPART_ALTERNATIVE . ';');
                $result .= $this->textLine(' boundary="' . $this->boundary[1] . '"');
                break;
            default: $result .= $this->textLine('Content-Type: ' . $this->ContentType . '; charset=' . $this->CharSet);
                $ismultipart = false;
                break;
        } if (static::ENCODING_7BIT !== $this->Encoding) {
            if ($ismultipart) {
                if (static::ENCODING_8BIT === $this->Encoding) {
                    $result .= $this->headerLine('Content-Transfer-Encoding', static::ENCODING_8BIT);
                }
            } else {
                $result .= $this->headerLine('Content-Transfer-Encoding', $this->Encoding);
            }
        } return $result;
    } public function getSentMIMEMessage()
    {
        return static::stripTrailingWSP($this->MIMEHeader . $this->mailHeader) . static::$LE . static::$LE . $this->MIMEBody;
    } protected function generateId()
    {
        $len = 32;
        $bytes = '';
        if (function_exists('random_bytes')) {
            try {
                $bytes = random_bytes($len);
            } catch (\Exception $e) {
            }
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes($len);
        } if ($bytes === '') {
            $bytes = hash('sha256', uniqid((string) mt_rand(), true), true);
        } return str_replace(['=', '+', '/'], '', base64_encode(hash('sha256', $bytes, true)));
    } public function createBody()
    {
        $body = '';
        $this->setBoundaries();
        if ($this->sign_key_file) {
            $body .= $this->getMailMIME() . static::$LE;
        } $this->setWordWrap();
        $bodyEncoding = $this->Encoding;
        $bodyCharSet = $this->CharSet;
        if (static::ENCODING_8BIT === $bodyEncoding && !$this->has8bitChars($this->Body)) {
            $bodyEncoding = static::ENCODING_7BIT;
            $bodyCharSet = static::CHARSET_ASCII;
        } if (static::ENCODING_BASE64 !== $this->Encoding && static::hasLineLongerThanMax($this->Body)) {
            $bodyEncoding = static::ENCODING_QUOTED_PRINTABLE;
        } $altBodyEncoding = $this->Encoding;
        $altBodyCharSet = $this->CharSet;
        if (static::ENCODING_8BIT === $altBodyEncoding && !$this->has8bitChars($this->AltBody)) {
            $altBodyEncoding = static::ENCODING_7BIT;
            $altBodyCharSet = static::CHARSET_ASCII;
        } if (static::ENCODING_BASE64 !== $altBodyEncoding && static::hasLineLongerThanMax($this->AltBody)) {
            $altBodyEncoding = static::ENCODING_QUOTED_PRINTABLE;
        } $mimepre = '';
        switch ($this->message_type) {
            case 'inline': $body .= $mimepre;
                $body .= $this->getBoundary($this->boundary[1], $bodyCharSet, '', $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= static::$LE;
                $body .= $this->attachAll('inline', $this->boundary[1]);
                break;
            case 'attach': $body .= $mimepre;
                $body .= $this->getBoundary($this->boundary[1], $bodyCharSet, '', $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= static::$LE;
                $body .= $this->attachAll('attachment', $this->boundary[1]);
                break;
            case 'inline_attach': $body .= $mimepre;
                $body .= $this->textLine('--' . $this->boundary[1]);
                $body .= $this->headerLine('Content-Type', static::CONTENT_TYPE_MULTIPART_RELATED . ';');
                $body .= $this->textLine(' boundary="' . $this->boundary[2] . '";');
                $body .= $this->textLine(' type="' . static::CONTENT_TYPE_TEXT_HTML . '"');
                $body .= static::$LE;
                $body .= $this->getBoundary($this->boundary[2], $bodyCharSet, '', $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= static::$LE;
                $body .= $this->attachAll('inline', $this->boundary[2]);
                $body .= static::$LE;
                $body .= $this->attachAll('attachment', $this->boundary[1]);
                break;
            case 'alt': $body .= $mimepre;
                $body .= $this->getBoundary($this->boundary[1], $altBodyCharSet, static::CONTENT_TYPE_PLAINTEXT, $altBodyEncoding);
                $body .= $this->encodeString($this->AltBody, $altBodyEncoding);
                $body .= static::$LE;
                $body .= $this->getBoundary($this->boundary[1], $bodyCharSet, static::CONTENT_TYPE_TEXT_HTML, $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= static::$LE;
                if (!empty($this->Ical)) {
                    $method = static::ICAL_METHOD_REQUEST;
                    foreach (static::$IcalMethods as $imethod) {
                        if (stripos($this->Ical, 'METHOD:' . $imethod) !== false) {
                            $method = $imethod;
                            break;
                        }
                    } $body .= $this->getBoundary($this->boundary[1], '', static::CONTENT_TYPE_TEXT_CALENDAR . '; method=' . $method, '');
                    $body .= $this->encodeString($this->Ical, $this->Encoding);
                    $body .= static::$LE;
                } $body .= $this->endBoundary($this->boundary[1]);
                break;
            case 'alt_inline': $body .= $mimepre;
                $body .= $this->getBoundary($this->boundary[1], $altBodyCharSet, static::CONTENT_TYPE_PLAINTEXT, $altBodyEncoding);
                $body .= $this->encodeString($this->AltBody, $altBodyEncoding);
                $body .= static::$LE;
                $body .= $this->textLine('--' . $this->boundary[1]);
                $body .= $this->headerLine('Content-Type', static::CONTENT_TYPE_MULTIPART_RELATED . ';');
                $body .= $this->textLine(' boundary="' . $this->boundary[2] . '";');
                $body .= $this->textLine(' type="' . static::CONTENT_TYPE_TEXT_HTML . '"');
                $body .= static::$LE;
                $body .= $this->getBoundary($this->boundary[2], $bodyCharSet, static::CONTENT_TYPE_TEXT_HTML, $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= static::$LE;
                $body .= $this->attachAll('inline', $this->boundary[2]);
                $body .= static::$LE;
                $body .= $this->endBoundary($this->boundary[1]);
                break;
            case 'alt_attach': $body .= $mimepre;
                $body .= $this->textLine('--' . $this->boundary[1]);
                $body .= $this->headerLine('Content-Type', static::CONTENT_TYPE_MULTIPART_ALTERNATIVE . ';');
                $body .= $this->textLine(' boundary="' . $this->boundary[2] . '"');
                $body .= static::$LE;
                $body .= $this->getBoundary($this->boundary[2], $altBodyCharSet, static::CONTENT_TYPE_PLAINTEXT, $altBodyEncoding);
                $body .= $this->encodeString($this->AltBody, $altBodyEncoding);
                $body .= static::$LE;
                $body .= $this->getBoundary($this->boundary[2], $bodyCharSet, static::CONTENT_TYPE_TEXT_HTML, $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= static::$LE;
                if (!empty($this->Ical)) {
                    $method = static::ICAL_METHOD_REQUEST;
                    foreach (static::$IcalMethods as $imethod) {
                        if (stripos($this->Ical, 'METHOD:' . $imethod) !== false) {
                            $method = $imethod;
                            break;
                        }
                    } $body .= $this->getBoundary($this->boundary[2], '', static::CONTENT_TYPE_TEXT_CALENDAR . '; method=' . $method, '');
                    $body .= $this->encodeString($this->Ical, $this->Encoding);
                } $body .= $this->endBoundary($this->boundary[2]);
                $body .= static::$LE;
                $body .= $this->attachAll('attachment', $this->boundary[1]);
                break;
            case 'alt_inline_attach': $body .= $mimepre;
                $body .= $this->textLine('--' . $this->boundary[1]);
                $body .= $this->headerLine('Content-Type', static::CONTENT_TYPE_MULTIPART_ALTERNATIVE . ';');
                $body .= $this->textLine(' boundary="' . $this->boundary[2] . '"');
                $body .= static::$LE;
                $body .= $this->getBoundary($this->boundary[2], $altBodyCharSet, static::CONTENT_TYPE_PLAINTEXT, $altBodyEncoding);
                $body .= $this->encodeString($this->AltBody, $altBodyEncoding);
                $body .= static::$LE;
                $body .= $this->textLine('--' . $this->boundary[2]);
                $body .= $this->headerLine('Content-Type', static::CONTENT_TYPE_MULTIPART_RELATED . ';');
                $body .= $this->textLine(' boundary="' . $this->boundary[3] . '";');
                $body .= $this->textLine(' type="' . static::CONTENT_TYPE_TEXT_HTML . '"');
                $body .= static::$LE;
                $body .= $this->getBoundary($this->boundary[3], $bodyCharSet, static::CONTENT_TYPE_TEXT_HTML, $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= static::$LE;
                $body .= $this->attachAll('inline', $this->boundary[3]);
                $body .= static::$LE;
                $body .= $this->endBoundary($this->boundary[2]);
                $body .= static::$LE;
                $body .= $this->attachAll('attachment', $this->boundary[1]);
                break;
            default: $this->Encoding = $bodyEncoding;
                $body .= $this->encodeString($this->Body, $this->Encoding);
                break;
        } if ($this->isError()) {
            $body = '';
            if ($this->exceptions) {
                throw new Exception($this->lang('empty_message'), self::STOP_CRITICAL);
            }
        } elseif ($this->sign_key_file) {
            try {
                if (!defined('PKCS7_TEXT')) {
                    throw new Exception($this->lang('extension_missing') . 'openssl');
                } $file = tempnam(sys_get_temp_dir(), 'srcsign');
                $signed = tempnam(sys_get_temp_dir(), 'mailsign');
                file_put_contents($file, $body);
                if (empty($this->sign_extracerts_file)) {
                    $sign = @openssl_pkcs7_sign($file, $signed, 'file://' . realpath($this->sign_cert_file), ['file://' . realpath($this->sign_key_file), $this->sign_key_pass], []);
                } else {
                    $sign = @openssl_pkcs7_sign($file, $signed, 'file://' . realpath($this->sign_cert_file), ['file://' . realpath($this->sign_key_file), $this->sign_key_pass], [], PKCS7_DETACHED, $this->sign_extracerts_file);
                } @unlink($file);
                if ($sign) {
                    $body = file_get_contents($signed);
                    @unlink($signed);
                    $parts = explode("\n\n", $body, 2);
                    $this->MIMEHeader .= $parts[0] . static::$LE . static::$LE;
                    $body = $parts[1];
                } else {
                    @unlink($signed);
                    throw new Exception($this->lang('signing') . openssl_error_string());
                }
            } catch (Exception $exc) {
                $body = '';
                if ($this->exceptions) {
                    throw $exc;
                }
            }
        } return $body;
    } public function getBoundaries()
    {
        if (empty($this->boundary)) {
            $this->setBoundaries();
        } return $this->boundary;
    } protected function getBoundary($boundary, $charSet, $contentType, $encoding)
    {
        $result = '';
        if ('' === $charSet) {
            $charSet = $this->CharSet;
        } if ('' === $contentType) {
            $contentType = $this->ContentType;
        } if ('' === $encoding) {
            $encoding = $this->Encoding;
        } $result .= $this->textLine('--' . $boundary);
        $result .= sprintf('Content-Type: %s; charset=%s', $contentType, $charSet);
        $result .= static::$LE;
        if (static::ENCODING_7BIT !== $encoding) {
            $result .= $this->headerLine('Content-Transfer-Encoding', $encoding);
        } $result .= static::$LE;
        return $result;
    } protected function endBoundary($boundary)
    {
        return static::$LE . '--' . $boundary . '--' . static::$LE;
    } protected function setMessageType()
    {
        $type = [];
        if ($this->alternativeExists()) {
            $type[] = 'alt';
        } if ($this->inlineImageExists()) {
            $type[] = 'inline';
        } if ($this->attachmentExists()) {
            $type[] = 'attach';
        } $this->message_type = implode('_', $type);
        if ('' === $this->message_type) {
            $this->message_type = 'plain';
        }
    } public function headerLine($name, $value)
    {
        return $name . ': ' . $value . static::$LE;
    } public function textLine($value)
    {
        return $value . static::$LE;
    } public function addAttachment($path, $name = '', $encoding = self::ENCODING_BASE64, $type = '', $disposition = 'attachment')
    {
        try {
            if (!static::fileIsAccessible($path)) {
                throw new Exception($this->lang('file_access') . $path, self::STOP_CONTINUE);
            } if ('' === $type) {
                $type = static::filenameToType($path);
            } $filename = (string) static::mb_pathinfo($path, PATHINFO_BASENAME);
            if ('' === $name) {
                $name = $filename;
            } if (!$this->validateEncoding($encoding)) {
                throw new Exception($this->lang('encoding') . $encoding);
            } $this->attachment[] = [0 => $path, 1 => $filename, 2 => $name, 3 => $encoding, 4 => $type, 5 => false, 6 => $disposition, 7 => $name,];
        } catch (Exception $exc) {
            $this->setError($exc->getMessage());
            $this->edebug($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            } return false;
        } return true;
    } public function getAttachments()
    {
        return $this->attachment;
    } protected function attachAll($disposition_type, $boundary)
    {
        $mime = [];
        $cidUniq = [];
        $incl = [];
        foreach ($this->attachment as $attachment) {
            if ($attachment[6] === $disposition_type) {
                $string = '';
                $path = '';
                $bString = $attachment[5];
                if ($bString) {
                    $string = $attachment[0];
                } else {
                    $path = $attachment[0];
                } $inclhash = hash('sha256', serialize($attachment));
                if (in_array($inclhash, $incl, true)) {
                    continue;
                } $incl[] = $inclhash;
                $name = $attachment[2];
                $encoding = $attachment[3];
                $type = $attachment[4];
                $disposition = $attachment[6];
                $cid = $attachment[7];
                if ('inline' === $disposition && array_key_exists($cid, $cidUniq)) {
                    continue;
                } $cidUniq[$cid] = true;
                $mime[] = sprintf('--%s%s', $boundary, static::$LE);
                if (!empty($name)) {
                    $mime[] = sprintf('Content-Type: %s; name=%s%s', $type, static::quotedString($this->encodeHeader($this->secureHeader($name))), static::$LE);
                } else {
                    $mime[] = sprintf('Content-Type: %s%s', $type, static::$LE);
                } if (static::ENCODING_7BIT !== $encoding) {
                    $mime[] = sprintf('Content-Transfer-Encoding: %s%s', $encoding, static::$LE);
                } if ((string) $cid !== '' && $disposition === 'inline') {
                    $mime[] = 'Content-ID: <' . $this->encodeHeader($this->secureHeader($cid)) . '>' . static::$LE;
                } if (!empty($disposition)) {
                    $encoded_name = $this->encodeHeader($this->secureHeader($name));
                    if (!empty($encoded_name)) {
                        $mime[] = sprintf('Content-Disposition: %s; filename=%s%s', $disposition, static::quotedString($encoded_name), static::$LE . static::$LE);
                    } else {
                        $mime[] = sprintf('Content-Disposition: %s%s', $disposition, static::$LE . static::$LE);
                    }
                } else {
                    $mime[] = static::$LE;
                } if ($bString) {
                    $mime[] = $this->encodeString($string, $encoding);
                } else {
                    $mime[] = $this->encodeFile($path, $encoding);
                } if ($this->isError()) {
                    return '';
                } $mime[] = static::$LE;
            }
        } $mime[] = sprintf('--%s--%s', $boundary, static::$LE);
        return implode('', $mime);
    } protected function encodeFile($path, $encoding = self::ENCODING_BASE64)
    {
        try {
            if (!static::fileIsAccessible($path)) {
                throw new Exception($this->lang('file_open') . $path, self::STOP_CONTINUE);
            } $file_buffer = file_get_contents($path);
            if (false === $file_buffer) {
                throw new Exception($this->lang('file_open') . $path, self::STOP_CONTINUE);
            } $file_buffer = $this->encodeString($file_buffer, $encoding);
            return $file_buffer;
        } catch (Exception $exc) {
            $this->setError($exc->getMessage());
            $this->edebug($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            } return '';
        }
    } public function encodeString($str, $encoding = self::ENCODING_BASE64)
    {
        $encoded = '';
        switch (strtolower($encoding)) {
            case static::ENCODING_BASE64: $encoded = chunk_split(base64_encode($str), static::STD_LINE_LENGTH, static::$LE);
                break;
            case static::ENCODING_7BIT: case static::ENCODING_8BIT: $encoded = static::normalizeBreaks($str);
                if (substr($encoded, - (strlen(static::$LE))) !== static::$LE) {
                    $encoded .= static::$LE;
                } break;
            case static::ENCODING_BINARY: $encoded = $str;
                break;
            case static::ENCODING_QUOTED_PRINTABLE: $encoded = $this->encodeQP($str);
                break;
            default: $this->setError($this->lang('encoding') . $encoding);
                if ($this->exceptions) {
                    throw new Exception($this->lang('encoding') . $encoding);
                } break;
        } return $encoded;
    } public function encodeHeader($str, $position = 'text')
    {
        $matchcount = 0;
        switch (strtolower($position)) {
            case 'phrase': if (!preg_match('/[\200-\377]/', $str)) {
                $encoded = addcslashes($str, "\0..\37\177\\\"");
                if (($str === $encoded) && !preg_match('/[^A-Za-z0-9!#$%&\'*+\/=?^_`{|}~ -]/', $str)) {
                    return $encoded;
                } return "\"$encoded\"";
            } $matchcount = preg_match_all('/[^\040\041\043-\133\135-\176]/', $str, $matches);
                break;
            case 'comment': $matchcount = preg_match_all('/[()"]/', $str, $matches);
                // no break
            case 'text': default: $matchcount += preg_match_all('/[\000-\010\013\014\016-\037\177-\377]/', $str, $matches);
                break;
        } if ($this->has8bitChars($str)) {
            $charset = $this->CharSet;
        } else {
            $charset = static::CHARSET_ASCII;
        } $overhead = 8 + strlen($charset);
        if ('mail' === $this->Mailer) {
            $maxlen = static::MAIL_MAX_LINE_LENGTH - $overhead;
        } else {
            $maxlen = static::MAX_LINE_LENGTH - $overhead;
        } if ($matchcount > strlen($str) / 3) {
            $encoding = 'B';
        } elseif ($matchcount > 0) {
            $encoding = 'Q';
        } elseif (strlen($str) > $maxlen) {
            $encoding = 'Q';
        } else {
            $encoding = false;
        } switch ($encoding) {
            case 'B': if ($this->hasMultiBytes($str)) {
                $encoded = $this->base64EncodeWrapMB($str, "\n");
            } else {
                $encoded = base64_encode($str);
                $maxlen -= $maxlen % 4;
                $encoded = trim(chunk_split($encoded, $maxlen, "\n"));
            } $encoded = preg_replace('/^(.*)$/m', ' =?' . $charset . "?$encoding?\\1?=", $encoded);
                break;
            case 'Q': $encoded = $this->encodeQ($str, $position);
                $encoded = $this->wrapText($encoded, $maxlen, true);
                $encoded = str_replace('=' . static::$LE, "\n", trim($encoded));
                $encoded = preg_replace('/^(.*)$/m', ' =?' . $charset . "?$encoding?\\1?=", $encoded);
                break;
            default: return $str;
        } return trim(static::normalizeBreaks($encoded));
    } public function hasMultiBytes($str)
    {
        if (function_exists('mb_strlen')) {
            return strlen($str) > mb_strlen($str, $this->CharSet);
        } return false;
    } public function has8bitChars($text)
    {
        return (bool) preg_match('/[\x80-\xFF]/', $text);
    } public function base64EncodeWrapMB($str, $linebreak = null)
    {
        $start = '=?' . $this->CharSet . '?B?';
        $end = '?=';
        $encoded = '';
        if (null === $linebreak) {
            $linebreak = static::$LE;
        } $mb_length = mb_strlen($str, $this->CharSet);
        $length = 75 - strlen($start) - strlen($end);
        $ratio = $mb_length / strlen($str);
        $avgLength = floor($length * $ratio * .75);
        $offset = 0;
        for ($i = 0; $i < $mb_length; $i += $offset) {
            $lookBack = 0;
            do {
                $offset = $avgLength - $lookBack;
                $chunk = mb_substr($str, $i, $offset, $this->CharSet);
                $chunk = base64_encode($chunk);
                ++$lookBack;
            } while (strlen($chunk) > $length);
            $encoded .= $chunk . $linebreak;
        } return substr($encoded, 0, -strlen($linebreak));
    } public function encodeQP($string)
    {
        return static::normalizeBreaks(quoted_printable_encode($string));
    } public function encodeQ($str, $position = 'text')
    {
        $pattern = '';
        $encoded = str_replace(["\r", "\n"], '', $str);
        switch (strtolower($position)) {
            case 'phrase': $pattern = '^A-Za-z0-9!*+\/ -';
                break;
            case 'comment': $pattern = '\(\)"';
                // no break
            case 'text': default: $pattern = '\000-\011\013\014\016-\037\075\077\137\177-\377' . $pattern;
                break;
        } $matches = [];
        if (preg_match_all("/[{$pattern}]/", $encoded, $matches)) {
            $eqkey = array_search('=', $matches[0], true);
            if (false !== $eqkey) {
                unset($matches[0][$eqkey]);
                array_unshift($matches[0], '=');
            } foreach (array_unique($matches[0]) as $char) {
                $encoded = str_replace($char, '=' . sprintf('%02X', ord($char)), $encoded);
            }
        } return str_replace(' ', '_', $encoded);
    } public function addStringAttachment($string, $filename, $encoding = self::ENCODING_BASE64, $type = '', $disposition = 'attachment')
    {
        try {
            if ('' === $type) {
                $type = static::filenameToType($filename);
            } if (!$this->validateEncoding($encoding)) {
                throw new Exception($this->lang('encoding') . $encoding);
            } $this->attachment[] = [0 => $string, 1 => $filename, 2 => static::mb_pathinfo($filename, PATHINFO_BASENAME), 3 => $encoding, 4 => $type, 5 => true, 6 => $disposition, 7 => 0,];
        } catch (Exception $exc) {
            $this->setError($exc->getMessage());
            $this->edebug($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            } return false;
        } return true;
    } public function addEmbeddedImage($path, $cid, $name = '', $encoding = self::ENCODING_BASE64, $type = '', $disposition = 'inline')
    {
        try {
            if (!static::fileIsAccessible($path)) {
                throw new Exception($this->lang('file_access') . $path, self::STOP_CONTINUE);
            } if ('' === $type) {
                $type = static::filenameToType($path);
            } if (!$this->validateEncoding($encoding)) {
                throw new Exception($this->lang('encoding') . $encoding);
            } $filename = (string) static::mb_pathinfo($path, PATHINFO_BASENAME);
            if ('' === $name) {
                $name = $filename;
            } $this->attachment[] = [0 => $path, 1 => $filename, 2 => $name, 3 => $encoding, 4 => $type, 5 => false, 6 => $disposition, 7 => $cid,];
        } catch (Exception $exc) {
            $this->setError($exc->getMessage());
            $this->edebug($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            } return false;
        } return true;
    } public function addStringEmbeddedImage($string, $cid, $name = '', $encoding = self::ENCODING_BASE64, $type = '', $disposition = 'inline')
    {
        try {
            if ('' === $type && !empty($name)) {
                $type = static::filenameToType($name);
            } if (!$this->validateEncoding($encoding)) {
                throw new Exception($this->lang('encoding') . $encoding);
            } $this->attachment[] = [0 => $string, 1 => $name, 2 => $name, 3 => $encoding, 4 => $type, 5 => true, 6 => $disposition, 7 => $cid,];
        } catch (Exception $exc) {
            $this->setError($exc->getMessage());
            $this->edebug($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            } return false;
        } return true;
    } protected function validateEncoding($encoding)
    {
        return in_array($encoding, [self::ENCODING_7BIT, self::ENCODING_QUOTED_PRINTABLE, self::ENCODING_BASE64, self::ENCODING_8BIT, self::ENCODING_BINARY,], true);
    } protected function cidExists($cid)
    {
        foreach ($this->attachment as $attachment) {
            if ('inline' === $attachment[6] && $cid === $attachment[7]) {
                return true;
            }
        } return false;
    } public function inlineImageExists()
    {
        foreach ($this->attachment as $attachment) {
            if ('inline' === $attachment[6]) {
                return true;
            }
        } return false;
    } public function attachmentExists()
    {
        foreach ($this->attachment as $attachment) {
            if ('attachment' === $attachment[6]) {
                return true;
            }
        } return false;
    } public function alternativeExists()
    {
        return !empty($this->AltBody);
    } public function clearQueuedAddresses($kind)
    {
        $this->RecipientsQueue = array_filter($this->RecipientsQueue, static function ($params) use ($kind) { return $params[0] !== $kind; });
    } public function clearAddresses()
    {
        foreach ($this->to as $to) {
            unset($this->all_recipients[strtolower($to[0])]);
        } $this->to = [];
        $this->clearQueuedAddresses('to');
    } public function clearCCs()
    {
        foreach ($this->cc as $cc) {
            unset($this->all_recipients[strtolower($cc[0])]);
        } $this->cc = [];
        $this->clearQueuedAddresses('cc');
    } public function clearBCCs()
    {
        foreach ($this->bcc as $bcc) {
            unset($this->all_recipients[strtolower($bcc[0])]);
        } $this->bcc = [];
        $this->clearQueuedAddresses('bcc');
    } public function clearReplyTos()
    {
        $this->ReplyTo = [];
        $this->ReplyToQueue = [];
    } public function clearAllRecipients()
    {
        $this->to = [];
        $this->cc = [];
        $this->bcc = [];
        $this->all_recipients = [];
        $this->RecipientsQueue = [];
    } public function clearAttachments()
    {
        $this->attachment = [];
    } public function clearCustomHeaders()
    {
        $this->CustomHeader = [];
    } public function clearCustomHeader($name, $value = null)
    {
        if (null === $value && strpos($name, ':') !== false) {
            list($name, $value) = explode(':', $name, 2);
        } $name = trim($name);
        $value = (null === $value) ? null : trim($value);
        foreach ($this->CustomHeader as $k => $pair) {
            if ($pair[0] == $name) {
                if (null === $value || $pair[1] == $value) {
                    unset($this->CustomHeader[$k]);
                }
            }
        } return true;
    } public function replaceCustomHeader($name, $value = null)
    {
        if (null === $value && strpos($name, ':') !== false) {
            list($name, $value) = explode(':', $name, 2);
        } $name = trim($name);
        $value = (null === $value) ? '' : trim($value);
        $replaced = false;
        foreach ($this->CustomHeader as $k => $pair) {
            if ($pair[0] == $name) {
                if ($replaced) {
                    unset($this->CustomHeader[$k]);
                    continue;
                } if (strpbrk($name . $value, "\r\n") !== false) {
                    if ($this->exceptions) {
                        throw new Exception($this->lang('invalid_header'));
                    } return false;
                } $this->CustomHeader[$k] = [$name, $value];
                $replaced = true;
            }
        } return true;
    } protected function setError($msg)
    {
        ++$this->error_count;
        if ('smtp' === $this->Mailer && null !== $this->smtp) {
            $lasterror = $this->smtp->getError();
            if (!empty($lasterror['error'])) {
                $msg .= $this->lang('smtp_error') . $lasterror['error'];
                if (!empty($lasterror['detail'])) {
                    $msg .= ' ' . $this->lang('smtp_detail') . $lasterror['detail'];
                } if (!empty($lasterror['smtp_code'])) {
                    $msg .= ' ' . $this->lang('smtp_code') . $lasterror['smtp_code'];
                } if (!empty($lasterror['smtp_code_ex'])) {
                    $msg .= ' ' . $this->lang('smtp_code_ex') . $lasterror['smtp_code_ex'];
                }
            }
        } $this->ErrorInfo = $msg;
    } public static function rfcDate()
    {
        date_default_timezone_set(@date_default_timezone_get());
        return date('D, j M Y H:i:s O');
    } protected function serverHostname()
    {
        $result = '';
        if (!empty($this->Hostname)) {
            $result = $this->Hostname;
        } elseif (isset($_SERVER) && array_key_exists('SERVER_NAME', $_SERVER)) {
            $result = $_SERVER['SERVER_NAME'];
        } elseif (function_exists('gethostname') && gethostname() !== false) {
            $result = gethostname();
        } elseif (php_uname('n') !== false) {
            $result = php_uname('n');
        } if (!static::isValidHost($result)) {
            return 'localhost.localdomain';
        } return $result;
    } public static function isValidHost($host)
    {
        if (empty($host) || !is_string($host) || strlen($host) > 256 || !preg_match('/^([a-zA-Z\d.-]*|\[[a-fA-F\d:]+\])$/', $host)) {
            return false;
        } if (strlen($host) > 2 && substr($host, 0, 1) === '[' && substr($host, -1, 1) === ']') {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        } if (is_numeric(str_replace('.', '', $host))) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        } return filter_var('https://' . $host, FILTER_VALIDATE_URL) !== false;
    } protected function lang($key)
    {
        if (count($this->language) < 1) {
            $this->setLanguage();
        } if (array_key_exists($key, $this->language)) {
            if ('smtp_connect_failed' === $key) {
                return $this->language[$key];
            } return $this->language[$key];
        } return $key;
    } private function getSmtpErrorMessage($base_key)
    {
        $message = $this->lang($base_key);
        $error = $this->smtp->getError();
        if (!empty($error['error'])) {
            $message .= ' ' . $error['error'];
            if (!empty($error['detail'])) {
                $message .= ' ' . $error['detail'];
            }
        } return $message;
    } public function isError()
    {
        return $this->error_count > 0;
    } public function addCustomHeader($name, $value = null)
    {
        if (null === $value && strpos($name, ':') !== false) {
            list($name, $value) = explode(':', $name, 2);
        } $name = trim($name);
        $value = (null === $value) ? '' : trim($value);
        if (empty($name) || strpbrk($name . $value, "\r\n") !== false) {
            if ($this->exceptions) {
                throw new Exception($this->lang('invalid_header'));
            } return false;
        } $this->CustomHeader[] = [$name, $value];
        return true;
    } public function getCustomHeaders()
    {
        return $this->CustomHeader;
    } public function msgHTML($message, $basedir = '', $advanced = false)
    {
        preg_match_all('/(?<!-)(src|background)=["\'](.*)["\']/Ui', $message, $images);
        if (array_key_exists(2, $images)) {
            if (strlen($basedir) > 1 && '/' !== substr($basedir, -1)) {
                $basedir .= '/';
            } foreach ($images[2] as $imgindex => $url) {
                $match = [];
                if (preg_match('#^data:(image/(?:jpe?g|gif|png));?(base64)?,(.+)#', $url, $match)) {
                    if (count($match) === 4 && static::ENCODING_BASE64 === $match[2]) {
                        $data = base64_decode($match[3]);
                    } elseif ('' === $match[2]) {
                        $data = rawurldecode($match[3]);
                    } else {
                        continue;
                    } $cid = substr(hash('sha256', $data), 0, 32) . '@phpmailer.0';
                    if (!$this->cidExists($cid)) {
                        $this->addStringEmbeddedImage($data, $cid, 'embed' . $imgindex, static::ENCODING_BASE64, $match[1]);
                    } $message = str_replace($images[0][$imgindex], $images[1][$imgindex] . '="cid:' . $cid . '"', $message);
                    continue;
                } if (!empty($basedir) && (strpos($url, '..') === false) && 0 !== strpos($url, 'cid:') && !preg_match('#^[a-z][a-z0-9+.-]*:?//#i', $url)) {
                    $filename = static::mb_pathinfo($url, PATHINFO_BASENAME);
                    $directory = dirname($url);
                    if ('.' === $directory) {
                        $directory = '';
                    } $cid = substr(hash('sha256', $url), 0, 32) . '@phpmailer.0';
                    if (strlen($basedir) > 1 && '/' !== substr($basedir, -1)) {
                        $basedir .= '/';
                    } if (strlen($directory) > 1 && '/' !== substr($directory, -1)) {
                        $directory .= '/';
                    } if ($this->addEmbeddedImage($basedir . $directory . $filename, $cid, $filename, static::ENCODING_BASE64, static::_mime_types((string) static::mb_pathinfo($filename, PATHINFO_EXTENSION)))) {
                        $message = preg_replace('/' . $images[1][$imgindex] . '=["\']' . preg_quote($url, '/') . '["\']/Ui', $images[1][$imgindex] . '="cid:' . $cid . '"', $message);
                    }
                }
            }
        } $this->isHTML();
        $this->Body = static::normalizeBreaks($message);
        $this->AltBody = static::normalizeBreaks($this->html2text($message, $advanced));
        if (!$this->alternativeExists()) {
            $this->AltBody = 'This is an HTML-only message. To view it, activate HTML in your email application.' . static::$LE;
        } return $this->Body;
    } public function html2text($html, $advanced = false)
    {
        if (is_callable($advanced)) {
            return call_user_func($advanced, $html);
        } return html_entity_decode(trim(strip_tags(preg_replace('/<(head|title|style|script)[^>]*>.*?<\/\\1>/si', '', $html))), ENT_QUOTES, $this->CharSet);
    } public static function _mime_types($ext = '')
    {
        $mimes = ['xl' => 'application/excel', 'js' => 'application/javascript', 'hqx' => 'application/mac-binhex40', 'cpt' => 'application/mac-compactpro', 'bin' => 'application/macbinary', 'doc' => 'application/msword', 'word' => 'application/msword', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xltx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template', 'potx' => 'application/vnd.openxmlformats-officedocument.presentationml.template', 'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'sldx' => 'application/vnd.openxmlformats-officedocument.presentationml.slide', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template', 'xlam' => 'application/vnd.ms-excel.addin.macroEnabled.12', 'xlsb' => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12', 'class' => 'application/octet-stream', 'dll' => 'application/octet-stream', 'dms' => 'application/octet-stream', 'exe' => 'application/octet-stream', 'lha' => 'application/octet-stream', 'lzh' => 'application/octet-stream', 'psd' => 'application/octet-stream', 'sea' => 'application/octet-stream', 'so' => 'application/octet-stream', 'oda' => 'application/oda', 'pdf' => 'application/pdf', 'ai' => 'application/postscript', 'eps' => 'application/postscript', 'ps' => 'application/postscript', 'smi' => 'application/smil', 'smil' => 'application/smil', 'mif' => 'application/vnd.mif', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint', 'wbxml' => 'application/vnd.wap.wbxml', 'wmlc' => 'application/vnd.wap.wmlc', 'dcr' => 'application/x-director', 'dir' => 'application/x-director', 'dxr' => 'application/x-director', 'dvi' => 'application/x-dvi', 'gtar' => 'application/x-gtar', 'php3' => 'application/x-httpd-php', 'php4' => 'application/x-httpd-php', 'php' => 'application/x-httpd-php', 'phtml' => 'application/x-httpd-php', 'phps' => 'application/x-httpd-php-source', 'swf' => 'application/x-shockwave-flash', 'sit' => 'application/x-stuffit', 'tar' => 'application/x-tar', 'tgz' => 'application/x-tar', 'xht' => 'application/xhtml+xml', 'xhtml' => 'application/xhtml+xml', 'zip' => 'application/zip', 'mid' => 'audio/midi', 'midi' => 'audio/midi', 'mp2' => 'audio/mpeg', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'mpga' => 'audio/mpeg', 'aif' => 'audio/x-aiff', 'aifc' => 'audio/x-aiff', 'aiff' => 'audio/x-aiff', 'ram' => 'audio/x-pn-realaudio', 'rm' => 'audio/x-pn-realaudio', 'rpm' => 'audio/x-pn-realaudio-plugin', 'ra' => 'audio/x-realaudio', 'wav' => 'audio/x-wav', 'mka' => 'audio/x-matroska', 'bmp' => 'image/bmp', 'gif' => 'image/gif', 'jpeg' => 'image/jpeg', 'jpe' => 'image/jpeg', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'tiff' => 'image/tiff', 'tif' => 'image/tiff', 'webp' => 'image/webp', 'avif' => 'image/avif', 'heif' => 'image/heif', 'heifs' => 'image/heif-sequence', 'heic' => 'image/heic', 'heics' => 'image/heic-sequence', 'eml' => 'message/rfc822', 'css' => 'text/css', 'html' => 'text/html', 'htm' => 'text/html', 'shtml' => 'text/html', 'log' => 'text/plain', 'text' => 'text/plain', 'txt' => 'text/plain', 'rtx' => 'text/richtext', 'rtf' => 'text/rtf', 'vcf' => 'text/vcard', 'vcard' => 'text/vcard', 'ics' => 'text/calendar', 'xml' => 'text/xml', 'xsl' => 'text/xml', 'csv' => 'text/csv', 'wmv' => 'video/x-ms-wmv', 'mpeg' => 'video/mpeg', 'mpe' => 'video/mpeg', 'mpg' => 'video/mpeg', 'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime', 'qt' => 'video/quicktime', 'rv' => 'video/vnd.rn-realvideo', 'avi' => 'video/x-msvideo', 'movie' => 'video/x-sgi-movie', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska',];
        $ext = strtolower($ext);
        if (array_key_exists($ext, $mimes)) {
            return $mimes[$ext];
        } return 'application/octet-stream';
    } public static function filenameToType($filename)
    {
        $qpos = strpos($filename, '?');
        if (false !== $qpos) {
            $filename = substr($filename, 0, $qpos);
        } $ext = static::mb_pathinfo($filename, PATHINFO_EXTENSION);
        return static::_mime_types($ext);
    } public static function mb_pathinfo($path, $options = null)
    {
        $ret = ['dirname' => '', 'basename' => '', 'extension' => '', 'filename' => ''];
        $pathinfo = [];
        if (preg_match('#^(.*?)[\\\\/]*(([^/\\\\]*?)(\.([^.\\\\/]+?)|))[\\\\/.]*$#m', $path, $pathinfo)) {
            if (array_key_exists(1, $pathinfo)) {
                $ret['dirname'] = $pathinfo[1];
            } if (array_key_exists(2, $pathinfo)) {
                $ret['basename'] = $pathinfo[2];
            } if (array_key_exists(5, $pathinfo)) {
                $ret['extension'] = $pathinfo[5];
            } if (array_key_exists(3, $pathinfo)) {
                $ret['filename'] = $pathinfo[3];
            }
        } switch ($options) {
            case PATHINFO_DIRNAME: case 'dirname': return $ret['dirname'];
            case PATHINFO_BASENAME: case 'basename': return $ret['basename'];
            case PATHINFO_EXTENSION: case 'extension': return $ret['extension'];
            case PATHINFO_FILENAME: case 'filename': return $ret['filename'];
            default: return $ret;
        }
    } public function set($name, $value = '')
    {
        if (property_exists($this, $name)) {
            $this->{$name} = $value;
            return true;
        } $this->setError($this->lang('variable_set') . $name);
        return false;
    } public function secureHeader($str)
    {
        return trim(str_replace(["\r", "\n"], '', $str));
    } public static function normalizeBreaks($text, $breaktype = null)
    {
        if (null === $breaktype) {
            $breaktype = static::$LE;
        } $text = str_replace([self::CRLF, "\r"], "\n", $text);
        if ("\n" !== $breaktype) {
            $text = str_replace("\n", $breaktype, $text);
        } return $text;
    } public static function stripTrailingWSP($text)
    {
        return rtrim($text, " \r\n\t");
    } public static function stripTrailingBreaks($text)
    {
        return rtrim($text, "\r\n");
    } public static function getLE()
    {
        return static::$LE;
    } protected static function setLE($le)
    {
        static::$LE = $le;
    } public function sign($cert_filename, $key_filename, $key_pass, $extracerts_filename = '')
    {
        $this->sign_cert_file = $cert_filename;
        $this->sign_key_file = $key_filename;
        $this->sign_key_pass = $key_pass;
        $this->sign_extracerts_file = $extracerts_filename;
    } public function DKIM_QP($txt)
    {
        $line = '';
        $len = strlen($txt);
        for ($i = 0; $i < $len; ++$i) {
            $ord = ord($txt[$i]);
            if (((0x21 <= $ord) && ($ord <= 0x3A)) || $ord === 0x3C || ((0x3E <= $ord) && ($ord <= 0x7E))) {
                $line .= $txt[$i];
            } else {
                $line .= '=' . sprintf('%02X', $ord);
            }
        } return $line;
    } public function DKIM_Sign($signHeader)
    {
        if (!defined('PKCS7_TEXT')) {
            if ($this->exceptions) {
                throw new Exception($this->lang('extension_missing') . 'openssl');
            } return '';
        } $privKeyStr = !empty($this->DKIM_private_string) ? $this->DKIM_private_string : file_get_contents($this->DKIM_private);
        if ('' !== $this->DKIM_passphrase) {
            $privKey = openssl_pkey_get_private($privKeyStr, $this->DKIM_passphrase);
        } else {
            $privKey = openssl_pkey_get_private($privKeyStr);
        } if (openssl_sign($signHeader, $signature, $privKey, 'sha256WithRSAEncryption')) {
            if (\PHP_MAJOR_VERSION < 8) {
                openssl_pkey_free($privKey);
            } return base64_encode($signature);
        } if (\PHP_MAJOR_VERSION < 8) {
            openssl_pkey_free($privKey);
        } return '';
    } public function DKIM_HeaderC($signHeader)
    {
        $signHeader = static::normalizeBreaks($signHeader, self::CRLF);
        $signHeader = preg_replace('/\r\n[ \t]+/', ' ', $signHeader);
        $lines = explode(self::CRLF, $signHeader);
        foreach ($lines as $key => $line) {
            if (strpos($line, ':') === false) {
                continue;
            } list($heading, $value) = explode(':', $line, 2);
            $heading = strtolower($heading);
            $value = preg_replace('/[ \t]+/', ' ', $value);
            $lines[$key] = trim($heading, " \t") . ':' . trim($value, " \t");
        } return implode(self::CRLF, $lines);
    } public function DKIM_BodyC($body)
    {
        if (empty($body)) {
            return self::CRLF;
        } $body = static::normalizeBreaks($body, self::CRLF);
        return static::stripTrailingBreaks($body) . self::CRLF;
    } public function DKIM_Add($headers_line, $subject, $body)
    {
        $DKIMsignatureType = 'rsa-sha256';
        $DKIMcanonicalization = 'relaxed/simple';
        $DKIMquery = 'dns/txt';
        $DKIMtime = time();
        $autoSignHeaders = ['from', 'to', 'cc', 'date', 'subject', 'reply-to', 'message-id', 'content-type', 'mime-version', 'x-mailer',];
        if (stripos($headers_line, 'Subject') === false) {
            $headers_line .= 'Subject: ' . $subject . static::$LE;
        } $headerLines = explode(static::$LE, $headers_line);
        $currentHeaderLabel = '';
        $currentHeaderValue = '';
        $parsedHeaders = [];
        $headerLineIndex = 0;
        $headerLineCount = count($headerLines);
        foreach ($headerLines as $headerLine) {
            $matches = [];
            if (preg_match('/^([^ \t]*?)(?::[ \t]*)(.*)$/', $headerLine, $matches)) {
                if ($currentHeaderLabel !== '') {
                    $parsedHeaders[] = ['label' => $currentHeaderLabel, 'value' => $currentHeaderValue];
                } $currentHeaderLabel = $matches[1];
                $currentHeaderValue = $matches[2];
            } elseif (preg_match('/^[ \t]+(.*)$/', $headerLine, $matches)) {
                $currentHeaderValue .= ' ' . $matches[1];
            } ++$headerLineIndex;
            if ($headerLineIndex >= $headerLineCount) {
                $parsedHeaders[] = ['label' => $currentHeaderLabel, 'value' => $currentHeaderValue];
            }
        } $copiedHeaders = [];
        $headersToSignKeys = [];
        $headersToSign = [];
        foreach ($parsedHeaders as $header) {
            if (in_array(strtolower($header['label']), $autoSignHeaders, true)) {
                $headersToSignKeys[] = $header['label'];
                $headersToSign[] = $header['label'] . ': ' . $header['value'];
                if ($this->DKIM_copyHeaderFields) {
                    $copiedHeaders[] = $header['label'] . ':' . str_replace('|', '=7C', $this->DKIM_QP($header['value']));
                } continue;
            } if (in_array($header['label'], $this->DKIM_extraHeaders, true)) {
                foreach ($this->CustomHeader as $customHeader) {
                    if ($customHeader[0] === $header['label']) {
                        $headersToSignKeys[] = $header['label'];
                        $headersToSign[] = $header['label'] . ': ' . $header['value'];
                        if ($this->DKIM_copyHeaderFields) {
                            $copiedHeaders[] = $header['label'] . ':' . str_replace('|', '=7C', $this->DKIM_QP($header['value']));
                        } continue 2;
                    }
                }
            }
        } $copiedHeaderFields = '';
        if ($this->DKIM_copyHeaderFields && count($copiedHeaders) > 0) {
            $copiedHeaderFields = ' z=';
            $first = true;
            foreach ($copiedHeaders as $copiedHeader) {
                if (!$first) {
                    $copiedHeaderFields .= static::$LE . ' |';
                } if (strlen($copiedHeader) > self::STD_LINE_LENGTH - 3) {
                    $copiedHeaderFields .= substr(chunk_split($copiedHeader, self::STD_LINE_LENGTH - 3, static::$LE . self::FWS), 0, -strlen(static::$LE . self::FWS));
                } else {
                    $copiedHeaderFields .= $copiedHeader;
                } $first = false;
            } $copiedHeaderFields .= ';' . static::$LE;
        } $headerKeys = ' h=' . implode(':', $headersToSignKeys) . ';' . static::$LE;
        $headerValues = implode(static::$LE, $headersToSign);
        $body = $this->DKIM_BodyC($body);
        $DKIMb64 = base64_encode(pack('H*', hash('sha256', $body)));
        $ident = '';
        if ('' !== $this->DKIM_identity) {
            $ident = ' i=' . $this->DKIM_identity . ';' . static::$LE;
        } $dkimSignatureHeader = 'DKIM-Signature: v=1;' . ' d=' . $this->DKIM_domain . ';' . ' s=' . $this->DKIM_selector . ';' . static::$LE . ' a=' . $DKIMsignatureType . ';' . ' q=' . $DKIMquery . ';' . ' t=' . $DKIMtime . ';' . ' c=' . $DKIMcanonicalization . ';' . static::$LE . $headerKeys . $ident . $copiedHeaderFields . ' bh=' . $DKIMb64 . ';' . static::$LE . ' b=';
        $canonicalizedHeaders = $this->DKIM_HeaderC($headerValues . static::$LE . $dkimSignatureHeader);
        $signature = $this->DKIM_Sign($canonicalizedHeaders);
        $signature = trim(chunk_split($signature, self::STD_LINE_LENGTH - 3, static::$LE . self::FWS));
        return static::normalizeBreaks($dkimSignatureHeader . $signature);
    } public static function hasLineLongerThanMax($str)
    {
        return (bool) preg_match('/^(.{' . (self::MAX_LINE_LENGTH + strlen(static::$LE)) . ',})/m', $str);
    } public static function quotedString($str)
    {
        if (preg_match('/[ ()<>@,;:"\/\[\]?=]/', $str)) {
            return '"' . str_replace('"', '\\"', $str) . '"';
        } return $str;
    } public function getToAddresses()
    {
        return $this->to;
    } public function getCcAddresses()
    {
        return $this->cc;
    } public function getBccAddresses()
    {
        return $this->bcc;
    } public function getReplyToAddresses()
    {
        return $this->ReplyTo;
    } public function getAllRecipientAddresses()
    {
        return $this->all_recipients;
    } protected function doCallback($isSent, $to, $cc, $bcc, $subject, $body, $from, $extra)
    {
        if (!empty($this->action_function) && is_callable($this->action_function)) {
            call_user_func($this->action_function, $isSent, $to, $cc, $bcc, $subject, $body, $from, $extra);
        }
    } public function getOAuth()
    {
        return $this->oauth;
    } public function setOAuth(OAuthTokenProvider $oauth)
    {
        $this->oauth = $oauth;
    }
}
