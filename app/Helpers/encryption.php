<?php

use CodeIgniter\HTTP\RequestInterface;

class EncryptionHelper
{
    private $key;

    public function __construct(RequestInterface $request)
    {
        $this->generateKey();
    }

    private function generateKey()
    {
        $this->key = openssl_random_pseudo_bytes(32);
    }

    public function encrypt($data)
    {
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    public function decrypt($encrypted)
    {
        $iv = base64_decode(substr($encrypted, 0, openssl_cipher_iv_length('aes-256-cbc')));
        $decrypted = openssl_decrypt(base64_decode(substr($encrypted, openssl_cipher_iv_length('aes-256-cbc'))), 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $iv);
        return $decrypted;
    }
}
