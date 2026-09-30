<?php
/**
 * PHPMailer SMTP class (bundled minimal version for ChamaLedger)
 * Based on PHPMailer 6.x — MIT License
 */
namespace PHPMailer\PHPMailer;

class SMTP {
    const VERSION       = '6.8.0';
    const LE            = "\r\n";
    const DEFAULT_PORT  = 25;
    const MAX_LINE_LENGTH = 998;

    public $Timeout     = 30;
    public $Timelimit   = 300;
    public $do_debug    = 0;
    public $Debugoutput = 'echo';

    protected $smtp_conn;
    protected $error     = ['error' => '', 'detail' => '', 'smtp_code' => '', 'smtp_code_ex' => ''];
    protected $helo_rply = null;
    protected $server_caps = null;
    protected $last_reply  = '';

    public function connect($host, $port = null, $timeout = 30, $options = []) {
        if ($port === null) $port = self::DEFAULT_PORT;
        $this->setError('');
        if ($this->connected()) $this->close();
        $address = $host;
        $socket_context = stream_context_create($options);
        set_error_handler([$this, 'errorHandler']);
        $this->smtp_conn = stream_socket_client(
            $address . ':' . $port,
            $errno, $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $socket_context
        );
        restore_error_handler();
        if (!is_resource($this->smtp_conn)) {
            $this->setError('Failed to connect to server', '', $errno, $errstr);
            return false;
        }
        stream_set_timeout($this->smtp_conn, $timeout, 0);
        $announce = $this->get_lines();
        $this->dbgout('SERVER -> CLIENT: ', $announce);
        return true;
    }

    public function startTLS() {
        if (!$this->sendCommand('STARTTLS', 'STARTTLS', 220)) return false;
        $crypto_method = STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $crypto_method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLS_CLIENT;
        }
        set_error_handler([$this, 'errorHandler']);
        $crypto_ok = stream_socket_enable_crypto($this->smtp_conn, true, $crypto_method);
        restore_error_handler();
        return (bool)$crypto_ok;
    }

    public function authenticate($username, $password, $authtype = null, $OAuth = null) {
        if (!$this->server_caps) $this->ehlo('localhost');
        $authmethods = [];
        if (array_key_exists('AUTH', $this->server_caps)) {
            if (is_array($this->server_caps['AUTH'])) $authmethods = $this->server_caps['AUTH'];
        }
        if (empty($authtype)) {
            foreach (['XOAUTH2','LOGIN','PLAIN','CRAM-MD5'] as $method) {
                if (in_array($method, $authmethods)) { $authtype = $method; break; }
            }
            if (empty($authtype)) $authtype = 'LOGIN';
        }
        switch ($authtype) {
            case 'PLAIN':
                if (!$this->sendCommand('AUTH', 'AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password), 235)) return false;
                break;
            case 'LOGIN':
                if (!$this->sendCommand('AUTH', 'AUTH LOGIN', 334)) return false;
                if (!$this->sendCommand('User', base64_encode($username), 334)) return false;
                if (!$this->sendCommand('Password', base64_encode($password), 235)) return false;
                break;
            case 'CRAM-MD5':
                if (!$this->sendCommand('AUTH CRAM-MD5', 'AUTH CRAM-MD5', 334)) return false;
                $challenge = base64_decode(substr($this->last_reply, 4));
                $response  = base64_encode($username . ' ' . hash_hmac('md5', $challenge, $password));
                if (!$this->sendCommand('Username', $response, 235)) return false;
                break;
        }
        return true;
    }

    public function connected() {
        if (is_resource($this->smtp_conn)) {
            $sock_status = stream_get_meta_data($this->smtp_conn);
            return !$sock_status['eof'];
        }
        return false;
    }

    public function close() {
        $this->setError('');
        $this->server_caps = null;
        $this->helo_rply   = null;
        if (is_resource($this->smtp_conn)) {
            fclose($this->smtp_conn);
            $this->smtp_conn = null;
        }
    }

    public function data($msg_data) {
        if (!$this->sendCommand('DATA', 'DATA', 354)) return false;
        $lines    = explode("\n", str_replace(["\r\n", "\r"], "\n", $msg_data));
        $field    = substr($lines[0], 0, strpos($lines[0], ':'));
        $in_headers = !empty($field) && !strstr($field, ' ');
        $max_line_length = 998;
        $stream_buffer = '';
        foreach ($lines as $line) {
            $lines_out = [];
            if ($in_headers && $line === '') {
                $in_headers = false;
            }
            while (strlen($line) > $max_line_length) {
                $pos = strrpos(substr($line, 0, $max_line_length), ' ');
                if (!$pos) { $pos = $max_line_length - 1; $lines_out[] = substr($line, 0, $pos); $line = substr($line, $pos); }
                else { $lines_out[] = substr($line, 0, $pos); $line = substr($line, $pos + 1); }
            }
            $lines_out[] = $line;
            foreach ($lines_out as $line_out) {
                if (!empty($line_out) && $line_out[0] === '.') $line_out = '.' . $line_out;
                $stream_buffer .= $line_out . self::LE;
            }
        }
        $stream_buffer .= '.' . self::LE;
        $result = $this->client_send($stream_buffer, 'DATA');
        if (!$result) { $this->setError('DATA sending failed'); return false; }
        $savetimelimit = $this->Timelimit;
        $this->Timelimit *= 2;
        $result = $this->get_lines();
        $this->Timelimit = $savetimelimit;
        $this->last_reply = $result;
        $valid_codes = [250, 251];
        if (!in_array((int)substr($result, 0, 3), $valid_codes)) {
            $this->setError('DATA not accepted', '', substr($result, 0, 3));
            return false;
        }
        return true;
    }

    public function hello($host = '') { return $this->ehlo($host) or $this->helo($host); }

    public function ehlo($host = '') {
        if (!$this->sendCommand('EHLO', 'EHLO ' . $host, 250)) return false;
        $this->server_caps = ['HELO' => true];
        $lines = explode("\n", $this->last_reply);
        foreach ($lines as $n => $s) {
            if (!$n) continue;
            $s = trim(substr($s, 4));
            if (empty($s)) continue;
            $fields = explode(' ', $s);
            if (!empty($fields)) {
                $cap = array_shift($fields);
                $this->server_caps[$cap] = !empty($fields) ? $fields : true;
            }
        }
        return true;
    }

    public function helo($host = '') {
        if (!$this->sendCommand('HELO', 'HELO ' . $host, 250)) return false;
        $this->server_caps = ['HELO' => true];
        return true;
    }

    public function mail($from) { return $this->sendCommand('MAIL FROM', 'MAIL FROM:<' . $from . '>', 250); }
    public function quit($close = true) {
        $result = $this->sendCommand('QUIT', 'QUIT', 221);
        if ($close) $this->close();
        return $result;
    }
    public function recipient($address, $dsn = '') { return $this->sendCommand('RCPT TO', 'RCPT TO:<' . $address . '>', [250, 251]); }
    public function reset() { return $this->sendCommand('RESET', 'RSET', 250); }

    public function sendCommand($command, $commandstring, $expect) {
        if (!$this->connected()) { $this->setError($command . ' not connected'); return false; }
        $this->client_send($commandstring . self::LE, $command);
        $this->last_reply = $this->get_lines();
        $this->dbgout($command . ': ', $this->last_reply);
        $code = (int)substr($this->last_reply, 0, 3);
        $expect = (array)$expect;
        if (!in_array($code, $expect)) {
            $this->setError($command . ' command failed', '', $code, trim(substr($this->last_reply, 4)));
            return false;
        }
        return true;
    }

    public function client_send($data, $command = '') {
        $this->dbgout('CLIENT -> SERVER: ', $data);
        set_error_handler([$this, 'errorHandler']);
        $result = fwrite($this->smtp_conn, $data);
        restore_error_handler();
        return $result;
    }

    public function getError() { return $this->error; }
    public function getServerExtList() { return $this->server_caps; }
    public function getServerExt($name) {
        if (!$this->server_caps || !array_key_exists($name, $this->server_caps)) return null;
        return $this->server_caps[$name];
    }
    public function getLastReply() { return $this->last_reply; }

    protected function get_lines() {
        if (!is_resource($this->smtp_conn)) return '';
        $data    = '';
        $endtime = 0;
        stream_set_timeout($this->smtp_conn, $this->Timeout);
        if ($this->Timelimit > 0) $endtime = time() + $this->Timelimit;
        $selR = [$this->smtp_conn]; $selW = null;
        while (is_resource($this->smtp_conn) && !feof($this->smtp_conn)) {
            set_error_handler([$this, 'errorHandler']);
            $n = stream_select($selR, $selW, $selW, $this->Timelimit);
            restore_error_handler();
            if ($n === false) break;
            $str = fgets($this->smtp_conn, 515);
            $data .= $str;
            if (isset($str[3]) && $str[3] === ' ') break;
            $info = stream_get_meta_data($this->smtp_conn);
            if ($info['timed_out']) { $this->setError('SMTP timeout'); break; }
            if ($endtime && time() > $endtime) break;
        }
        return $data;
    }

    protected function setError($message, $detail = '', $smtp_code = '', $smtp_code_ex = '') {
        $this->error = ['error' => $message, 'detail' => $detail, 'smtp_code' => $smtp_code, 'smtp_code_ex' => $smtp_code_ex];
    }

    protected function dbgout($str, $data) {
        if ($this->do_debug <= 0) return;
        $output = trim($data);
        if ($this->do_debug >= 1) { echo $str . htmlspecialchars($output) . "\n"; }
    }

    protected function errorHandler($errno, $errmsg, $errfile = '', $errline = 0) {
        $notice = 'Connection: Failed to connect to server.';
        $this->setError($notice, $errno, $errmsg);
    }
}
