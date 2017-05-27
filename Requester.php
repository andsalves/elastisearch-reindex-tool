<?php

/**
 * Class Requester
 * @author Andsalves <ands.alves.nunes@gmail.com>
 */
class Requester {

    public function request($url, array $data = [], $method = 'POST') {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if (boolval($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json; charset=utf-8'
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $resultJson = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!($content = json_decode($resultJson, true))) {
            $content = array(
                'error' => ['reason' => $resultJson]
            );
        }

        return array(
            'status' => $httpcode,
            'content' => $content
        );
    }

}