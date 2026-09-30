<?php
/**
 * PHPMailer main class (bundled minimal version for ChamaLedger)
 * Based on PHPMailer 6.x — MIT License — https://github.com/PHPMailer/PHPMailer
 */
namespace PHPMailer\PHPMailer;

class PHPMailer {
    const VERSION          = '6.8.0';
    const ENCRYPTION_STARTTLS = 'tls';
    const ENCRYPTION_SMTPS    = 'ssl';

    public $exceptions  = false;
    public $CharSet     = 'UTF-8';
    public $ContentType = 'text/plain';
    public $Encoding    = '8bit';
    public $From        = '';
    public $FromName    = '';
    public $Sender      = '';
    public $Subject     = '';
    public $Body        = '';
    public $AltBody     = '';
    public $Ical        = '';
    public $MIMEBody    = '';
    public $MIMEHeader  = '';
    public $Host        = 'localhost';
    public $Port        = 25;
    public $Helo        = '';
    public $SMTPSecure  = '';
    public $SMTPAutoTLS = true;
    public $SMTPAuth    = false;
    public $SMTPOptions = [];
    public $Username    = '';
    public $Password    = '';
    public $AuthType    = '';
    public $Timeout     = 300;
    public $SMTPDebug   = 0;
    public $isSMTP_flag = false;
    public $Mailer      = 'mail';
    public $Sendmail    = '/usr/sbin/sendmail';
    public $ErrorInfo   = '';
    public $WordWrap    = 0;
    public $XMailer     = '';
    public $ConfirmReadingTo = '';
    public $Hostname    = '';
    public $MessageID   = '';
    public $MessageDate = '';
    public $Priority    = 3;
    public $DKIM_domain = '';
    public $DKIM_private= '';
    public $DKIM_selector='';
    public $DKIM_passphrase='';
    public $DKIM_identity='';
    public $DKIM_copyHeaderFields=true;
    public $DKIM_extraHeaders=[];

    protected $smtp         = null;
    protected $to           = [];
    protected $cc           = [];
    protected $bcc          = [];
    protected $ReplyTo      = [];
    protected $all_recipients = [];
    protected $RecipientsQueue = [];
    protected $ReplyToQueue = [];
    protected $attachment   = [];
    protected $CustomHeader = [];
    protected $lastMessageID= '';
    protected $message_type = '';
    protected $boundary     = [];
    protected $language     = [];
    protected $error_count  = 0;
    protected $sign_cert_file = '';
    protected $sign_key_file  = '';
    protected $sign_extracerts_file = '';
    protected $sign_key_pass = '';
    protected $exceptions_flag = false;
    protected $uniqueid = '';

    public function __construct($exceptions = null) {
        if ($exceptions !== null) $this->exceptions = (bool)$exceptions;
        $this->uniqueid = $this->generateId();
    }

    public function isSMTP()  { $this->Mailer = 'smtp'; }
    public function isMail()  { $this->Mailer = 'mail'; }

    public function addAddress($address, $name = '') { return $this->addOrEnqueueAnAddress('to', $address, $name); }
    public function addCC($address, $name = '')      { return $this->addOrEnqueueAnAddress('cc', $address, $name); }
    public function addBCC($address, $name = '')     { return $this->addOrEnqueueAnAddress('bcc', $address, $name); }
    public function addReplyTo($address, $name = '') { return $this->addOrEnqueueAnAddress('Reply-To', $address, $name); }

    protected function addOrEnqueueAnAddress($kind, $address, $name) {
        $address = trim($address);
        $name    = trim(preg_replace('/[\r\n]+/', '', $name));
        if (!$this->validateAddress($address)) {
            $this->setError('Invalid address: ' . $address);
            return false;
        }
        $lower = strtolower($address);
        if ($kind === 'Reply-To') {
            if (!array_key_exists($lower, $this->ReplyTo)) {
                $this->ReplyTo[$lower] = [$address, $name];
                return true;
            }
        } else {
            if (!array_key_exists($lower, $this->all_recipients)) {
                $this->{$kind}[] = [$address, $name];
                $this->all_recipients[$lower] = true;
                return true;
            }
        }
        return false;
    }

    public function setFrom($address, $name = '', $auto = true) {
        $address = trim($address);
        $name    = trim(preg_replace('/[\r\n]+/', '', $name));
        if (!$this->validateAddress($address)) { $this->setError('Invalid address: ' . $address); return false; }
        $this->From     = $address;
        $this->FromName = $name;
        if ($auto && empty($this->Sender)) $this->Sender = $address;
        return true;
    }

    public function isHTML($isHtml = true) {
        if ($isHtml) {
            $this->ContentType = 'text/html';
        } else {
            $this->ContentType = 'text/plain';
        }
    }

    public function send() {
        try {
            if (!$this->preSend()) return false;
            return $this->postSend();
        } catch (Exception $exc) {
            $this->setError($exc->getMessage());
            if ($this->exceptions) throw $exc;
            return false;
        }
    }

    public function preSend() {
        $this->error_count = 0;
        $this->ErrorInfo   = '';
        $this->MIMEBody    = '';
        $this->MIMEHeader  = '';

        if (empty($this->From)) {
            if (!empty($_SERVER['SERVER_NAME'])) {
                $this->From = 'root@' . $_SERVER['SERVER_NAME'];
            } else {
                $this->From = 'root@localhost';
            }
        }
        if (empty($this->FromName)) $this->FromName = 'Root User';
        if (empty($this->to) && empty($this->cc) && empty($this->bcc)) {
            $this->setError('You must provide at least one recipient email address.');
            return false;
        }
        if (empty($this->Subject)) $this->Subject = '(no subject)';
        $this->MIMEHeader = $this->createHeader();
        $this->MIMEBody   = $this->createBody();
        return true;
    }

    public function postSend() {
        switch ($this->Mailer) {
            case 'smtp':    return $this->smtpSend($this->MIMEHeader, $this->MIMEBody);
            case 'sendmail':
            case 'qmail':   return $this->sendmailSend($this->MIMEHeader, $this->MIMEBody);
            default:        return $this->mailSend($this->MIMEHeader, $this->MIMEBody);
        }
    }

    protected function smtpSend($header, $body) {
        $bad_rcpt = [];
        if (!$this->smtpConnect($this->SMTPOptions)) {
            throw new Exception('SMTP connect() failed.');
        }
        $smtp_from = empty($this->Sender) ? $this->From : $this->Sender;
        if (!$this->smtp->mail($smtp_from)) {
            throw new Exception('SMTP Error: MAIL FROM command failed: ' . implode(', ', $this->smtp->getError()));
        }
        foreach (array_merge($this->to, $this->cc, $this->bcc) as $to) {
            if (!$this->smtp->recipient($to[0])) {
                $error       = $this->smtp->getError();
                $bad_rcpt[]  = ['to' => $to[0], 'error' => $error['detail']];
            }
        }
        if (count($bad_rcpt) > 0) {
            $errstr = '';
            foreach ($bad_rcpt as $bad) $errstr .= $bad['to'] . ': ' . $bad['error'];
            throw new Exception('SMTP Error: The following recipients failed: ' . $errstr);
        }
        if (!$this->smtp->data($header . self::LE . $body)) {
            throw new Exception('SMTP Error: DATA command failed: ' . implode(', ', $this->smtp->getError()));
        }
        $smtp_transaction_id = $this->smtp->getLastReply();
        if ($this->SMTPKeepAlive) $this->smtp->reset();
        else $this->smtp->quit();
        return true;
    }

    public $SMTPKeepAlive = false;

    public function smtpConnect($options = []) {
        if (is_null($this->smtp)) $this->smtp = $this->getSMTPInstance();
        if ($this->smtp->connected()) return true;

        $this->smtp->Timeout     = $this->Timeout;
        $this->smtp->Timelimit   = $this->Timeout;
        $this->smtp->do_debug    = $this->SMTPDebug;

        $hosts = explode(';', $this->Host);
        $lastException = null;

        foreach ($hosts as $hostentry) {
            $hostentry = trim($hostentry);
            if (empty($hostentry)) continue;
            $prefix = '';
            $secure = $this->SMTPSecure;
            if (substr($hostentry, 0, 3) === 'ssl') { $prefix = 'ssl://'; $hostentry = substr($hostentry, 6); $secure = 'ssl'; }
            elseif (substr($hostentry, 0, 3) === 'tls') { $hostentry = substr($hostentry, 6); $secure = 'tls'; }

            $sslContext = array_merge([
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ], $options['ssl'] ?? []);

            $options_merged = array_merge(['ssl' => $sslContext], $options);

            if ($secure === 'ssl') $prefix = 'ssl://';

            if (!$this->smtp->connect($prefix . $hostentry, $this->Port, $this->Timeout, $options_merged)) {
                continue;
            }

            try {
                $hello = $this->Helo ?: $this->serverHostname();
                $this->smtp->hello($hello);

                if ($this->SMTPAutoTLS && $secure !== 'ssl' && $this->smtp->getServerExt('STARTTLS')) {
                    if (!$this->smtp->startTLS()) throw new Exception('Failed to start TLS encryption.');
                    $this->smtp->hello($hello);
                }

                if ($this->SMTPAuth) {
                    if (!$this->smtp->authenticate($this->Username, $this->Password, $this->AuthType)) {
                        throw new Exception('SMTP authentication failed.');
                    }
                }
                return true;

            } catch (Exception $exc) {
                $lastException = $exc;
                $this->smtp->close();
            }
        }
        if ($this->exceptions && $lastException) throw $lastException;
        return false;
    }

    protected function mailSend($header, $body) {
        $toArr = [];
        foreach ($this->to as $toaddr) $toArr[] = $this->addrFormat($toaddr);
        $to = implode(', ', $toArr);
        $params = null;
        if (!empty($this->Sender) && ini_get('safe_mode') === '0') {
            $params = sprintf('-f%s', $this->Sender);
        }
        if ($this->Sender !== '' && !ini_get('safe_mode') && ini_get('sendmail_path')) {
            $old_from = ini_get('sendmail_from');
            ini_set('sendmail_from', $this->Sender);
        }
        $result = false;
        if ($params !== null) {
            $result = mail($to, $this->encodeHeader($this->Subject), $body, $header, $params);
        } else {
            $result = mail($to, $this->encodeHeader($this->Subject), $body, $header);
        }
        if (isset($old_from)) ini_set('sendmail_from', $old_from);
        if (!$result) throw new Exception('Could not instantiate mail function.');
        return true;
    }

    protected function sendmailSend($header, $body) {
        return $this->mailSend($header, $body);
    }

    public function getSMTPInstance() { return new SMTP(); }

    public function createHeader() {
        $result      = '';
        $this->uniqueid = $this->generateId();
        $this->boundary[1] = 'b1_' . $this->uniqueid;
        $this->boundary[2] = 'b2_' . $this->uniqueid;
        $this->boundary[3] = 'b3_' . $this->uniqueid;

        if (empty($this->MessageDate)) $this->MessageDate = self::rfcDate();
        $result .= $this->headerLine('Date', $this->MessageDate);

        if ($this->SingleTo) {
            foreach ($this->to as $toaddr) $this->SingleToArray[] = $this->addrFormat($toaddr);
        } else {
            if (count($this->to) > 0) $result .= $this->addrAppend('To', $this->to);
            elseif (count($this->cc) === 0) $result .= $this->headerLine('To', 'undisclosed-recipients:;');
        }
        $result .= $this->addrAppend('From', [[$this->From, $this->FromName]]);
        if (count($this->cc) > 0) $result .= $this->addrAppend('Cc', $this->cc);
        if (count($this->ReplyTo) > 0) $result .= $this->addrAppend('Reply-To', $this->ReplyTo);
        if ($this->Mailer !== 'mail') $result .= $this->headerLine('Subject', $this->encodeHeader($this->Subject));
        if (!empty($this->MessageID) && preg_match('/^<.*@.*>$/', $this->MessageID)) {
            $this->lastMessageID = $this->MessageID;
        } else {
            $this->lastMessageID = sprintf('<%s@%s>', $this->uniqueid, $this->serverHostname());
        }
        $result .= $this->headerLine('Message-ID', $this->lastMessageID);
        if (!empty($this->Priority)) $result .= $this->headerLine('X-Priority', $this->Priority);
        if (empty($this->XMailer)) {
            $result .= $this->headerLine('X-Mailer', 'PHPMailer ' . self::VERSION . ' (https://github.com/PHPMailer/PHPMailer)');
        } elseif ($this->XMailer) {
            $result .= $this->headerLine('X-Mailer', trim($this->XMailer));
        }
        if (!empty($this->ConfirmReadingTo)) $result .= $this->headerLine('Disposition-Notification-To', '<' . trim($this->ConfirmReadingTo) . '>');
        foreach ($this->CustomHeader as $custom_header) {
            $result .= $this->headerLine(trim($custom_header[0]), $this->encodeHeader(trim($custom_header[1])));
        }
        if (!$this->sign_key_file) {
            $result .= $this->headerLine('MIME-Version', '1.0');
            $result .= $this->getMailMIME();
        }
        return $result;
    }

    public $SingleTo = false;
    public $SingleToArray = [];

    public function getMailMIME() {
        $result = '';
        $ismultipart = true;
        switch ($this->message_type) {
            case 'inline':
                $result .= $this->headerLine('Content-Type', 'multipart/related; boundary="' . $this->boundary[1] . '"'); break;
            case 'attach':
            case 'inline_attach':
            case 'alt_attach':
            case 'alt_inline_attach':
                $result .= $this->headerLine('Content-Type', 'multipart/mixed; boundary="' . $this->boundary[1] . '"'); break;
            case 'alt':
            case 'alt_inline':
                $result .= $this->headerLine('Content-Type', 'multipart/alternative; boundary="' . $this->boundary[1] . '"'); break;
            default:
                $result .= $this->textLine('Content-Type: ' . $this->ContentType . '; charset=' . $this->CharSet);
                $result .= $this->headerLine('Content-Transfer-Encoding', $this->Encoding);
                $ismultipart = false;
                break;
        }
        if ($ismultipart) $result .= $this->headerLine('Content-Transfer-Encoding', '7bit');
        return $result;
    }

    public function createBody() {
        $body = '';
        $this->message_type = $this->setMessageType();
        switch ($this->message_type) {
            case 'alt':
            case 'alt_inline':
                $body .= $this->getBoundary($this->boundary[1], '', 'text/plain', '') . $this->encodeString($this->AltBody ?: strip_tags($this->Body), $this->Encoding) . self::LE;
                $body .= $this->getBoundary($this->boundary[1], '', $this->ContentType, '') . $this->encodeString($this->Body, $this->Encoding) . self::LE;
                $body .= $this->endBoundary($this->boundary[1]);
                break;
            default:
                $body .= $this->encodeString($this->Body, $this->Encoding);
                break;
        }
        return $body;
    }

    protected function getBoundary($boundary, $charSet, $contentType, $encoding) {
        $result = '';
        if ($charSet === '') $charSet = $this->CharSet;
        if ($contentType === '') $contentType = $this->ContentType;
        if ($encoding === '') $encoding = $this->Encoding;
        $result .= $this->textLine('--' . $boundary);
        $result .= 'Content-Type: ' . $contentType . '; charset=' . $charSet . self::LE;
        $result .= $this->headerLine('Content-Transfer-Encoding', $encoding);
        $result .= self::LE;
        return $result;
    }

    protected function endBoundary($boundary) { return self::LE . '--' . $boundary . '--' . self::LE; }

    protected function setMessageType() {
        $type = [];
        if ($this->alternativeExists()) $type[] = 'alt';
        return implode('_', $type);
    }

    public function headerLine($name, $value) { return $name . ': ' . $value . static::LE; }
    public function textLine($value)           { return $value . static::LE; }
    public function addrAppend($type, $addr)   { $addresses = []; foreach ($addr as $address) $addresses[] = $this->addrFormat($address); return $type . ': ' . implode(', ', $addresses) . static::LE; }
    public function addrFormat($addr)          { return empty($addr[1]) ? $this->secureHeader($addr[0]) : $this->encodeHeader($this->secureHeader($addr[1]), 'phrase') . ' <' . $this->secureHeader($addr[0]) . '>'; }

    public function encodeHeader($str, $position = 'text') {
        $matchcount = preg_match_all('/[\x80-\xFF]/', $str, $matches);
        if (!$matchcount && !preg_match('/[^\040\041\043-\133\135-\176]/', $str)) return $str;
        return $this->encodeQ($str, $position);
    }

    public function encodeQ($str, $position = 'text') {
        $pattern = '';
        switch (strtolower($position)) {
            case 'phrase': $pattern = '^A-Za-z0-9!*+\/ -'; break;
            case 'comment': $pattern = '\(\)"'; // fall-through
            case 'text':
            default: $pattern = '\000-\011\013\014\016-\037\075\077\137\177-\377' . $pattern; break;
        }
        $encoded = preg_replace_callback('/[' . $pattern . ']/', function($match) { return sprintf('=%02X', ord($match[0])); }, $str);
        $encoded = str_replace(' ', '_', $encoded);
        return '=?' . $this->CharSet . '?Q?' . $encoded . '?=';
    }

    public function encodeString($str, $encoding = 'base64') {
        switch (strtolower($encoding)) {
            case 'base64':       return chunk_split(base64_encode($str), 76, self::LE);
            case '7bit':
            case '8bit':         return $this->fixEOL($str);
            case 'binary':       return $str;
            case 'quoted-printable': return quoted_printable_encode($str);
            default:             $this->setError('Unknown encoding: ' . $encoding); return '';
        }
    }

    public function validateAddress($address, $patternselect = null) {
        return (bool)filter_var($address, FILTER_VALIDATE_EMAIL);
    }

    public static function rfcDate() {
        $tz     = date('Z');
        $tzs    = ($tz < 0) ? '-' : '+';
        $tz     = abs($tz);
        $tz     = (int)($tz / 3600) * 100 + ($tz % 3600) / 60;
        return sprintf('%s %s%04d', date('D, j M Y H:i:s'), $tzs, $tz);
    }

    protected function serverHostname() {
        if (!empty($this->Hostname)) return $this->Hostname;
        if (!empty($_SERVER['SERVER_NAME'])) return $_SERVER['SERVER_NAME'];
        if (function_exists('gethostname') && gethostname() !== false) return gethostname();
        return 'localhost.localdomain';
    }

    protected function secureHeader($str) { return trim(str_replace(["\r", "\n"], '', $str)); }
    protected function fixEOL($str)       { $nstr = str_replace(["\r\n", "\r"], "\n", $str); return str_replace("\n", static::LE, $nstr); }
    protected function generateId()       { return bin2hex(random_bytes(16)); }
    protected function alternativeExists(){ return !empty($this->AltBody); }

    public function addCustomHeader($name, $value = null) {
        if ($value === null) { $this->CustomHeader[] = explode(':', $name, 2); }
        else { $this->CustomHeader[] = [$name, $value]; }
    }

    protected function setError($msg) {
        ++$this->error_count;
        $this->ErrorInfo = $msg;
        if ($this->exceptions) throw new Exception($msg);
    }

    public function clearAddresses()    { $this->to  = []; foreach ($this->to  as $to)  unset($this->all_recipients[strtolower($to[0])]); }
    public function clearCCs()          { $this->cc  = []; }
    public function clearBCCs()         { $this->bcc = []; }
    public function clearReplyTos()     { $this->ReplyTo = []; }
    public function clearAllRecipients(){ $this->to = []; $this->cc = []; $this->bcc = []; $this->all_recipients = []; $this->RecipientsQueue = []; }
    public function clearAttachments()  { $this->attachment = []; }
    public function clearCustomHeaders(){ $this->CustomHeader = []; }

    public function smtpClose() { if (is_a($this->smtp, 'PHPMailer\\PHPMailer\\SMTP') && $this->smtp->connected()) { $this->smtp->quit(); $this->smtp->close(); } }

    const LE = "\r\n";
}
