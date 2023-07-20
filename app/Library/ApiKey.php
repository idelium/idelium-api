<?php

namespace App\Library;


class ApiKey
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  string  $request
     * @return string|null
     */
    public function generateApiSignature()
    {
        $signature = bin2hex(random_bytes(64));
        return base64_encode($signature);
    }

}
