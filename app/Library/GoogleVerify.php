<?php

namespace App\Library;


class GoogleVerify
{
    public function check($token, $secret)
    {
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        $postData['secret'] = $secret;
        $postData['response'] = $token;
        foreach ($postData as $key => $value) {
            $postItems[] = $key . '=' . $value;
        }
        $postString = implode('&', $postItems);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postString);
        $content = curl_exec($ch);
        return json_decode($content);
    }
}
