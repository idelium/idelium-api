<?php

namespace App\Library;

use Illuminate\Support\Facades\Http;

class GoogleVerify
{
    public function passes(?string $token, string $secret): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $response = Http::asForm()
            ->timeout(5)
            ->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $token,
            ]);

        return $response->successful() && $response->json('success') === true;
    }
}
