<?php

namespace App\Library;


class TestLauncher
{
    public function getHeaders($respHeaders) {
        $headers = array();

        $headerText = substr($respHeaders, 0, strpos($respHeaders, "\r\n\r\n"));

        foreach (explode("\r\n", $headerText) as $i => $line) {
            if ($i === 0) {
                $headers['http_code'] = $line;
            } else {
                list ($key, $value) = explode(': ', $line);

                $headers[$key] = $value;
            }
        }

        return $headers;
}
    public function launch($host,$browser,$idTestCycle, $idProject,$environment,$key)
    {
        $ch = curl_init($host.'/launchtest');
        $data=array(
            'idTestCycle'=>$idTestCycle,
            'idProject' => $idProject,
            'environment' => $environment,
            'browser'=>$browser,
            'key' => $key,
            'host' => $host,
        );
        $data_string = json_encode($data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array(
                'Content-Type:application/json',
                'Content-Length: ' . strlen($data_string)
            )
        );
        $content = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $header = substr($content, 0, $headerSize);
        $header = $this->getHeaders($header);

        // extract body
        return substr($content, $headerSize);
    }
}
