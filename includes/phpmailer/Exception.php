<?php
namespace PHPMailer\PHPMailer;
class Exception extends \Exception {
    public function errorMessage() { return $this->getMessage(); }
}
