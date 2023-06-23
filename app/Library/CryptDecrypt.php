<?php

namespace App\Library;


class CryptDecrypt
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  string  $request
     * @return string|null
     */
    public function encrypt($stringToEncrypt)
    {
       return openssl_encrypt($stringToEncrypt, "AES-128-ECB", env('IDELIUM_CRYPT_PASSWORD'));
    }
    public function decrypt($encryptedString)
    {
        return openssl_decrypt($encryptedString, "AES-128-ECB", env('IDELIUM_CRYPT_PASSWORD'));
    }
}
