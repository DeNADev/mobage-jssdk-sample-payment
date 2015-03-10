<?php
/**
 * The MIT License (MIT)
 * Copyright (c) 2014-2015 DeNA Co., Ltd.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
 
// this is an example of create bank transaction
require_once("../config.php");

function bankDebitPost ($body) {

    $http_method  = "POST";
    $url_fragment = "/bank/debit/@app";

    // generate Authentication Header
    $endpoint = BANK_END_POINT . $url_fragment;
    $auth_header = array(
        "Authorization: Bearer " . $_SESSION["access_token"],
        "Accept: */*",
        "Content-Type: application/json; charset=utf-8"
    );


    // access to platform server
    $curl = curl_init($endpoint);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS,json_encode($body));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FAILONERROR, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($curl, CURLOPT_ENCODING , "gzip");
    curl_setopt($curl, CURLOPT_HTTPHEADER, $auth_header);
    curl_setopt($curl, CURLINFO_HEADER_OUT, true);
    curl_setopt($curl, CURLOPT_HEADER, true);
    $response = curl_exec($curl);

    // request header
    $curl_header_req      = curl_getinfo($curl, CURLINFO_HEADER_OUT);
    // response header and body
    $curl_header_res_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $curl_header_res      = substr($response, 0, $curl_header_res_size);
    $curl_body_res        = substr($response, $curl_header_res_size);
    $http_status_code     = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    // parse response
    $decoded_response  = rawurldecode($curl_body_res);
    $parsed_parameters = json_decode($decoded_response, true);
    error_log('transaction_id is ' . $parsed_parameters['id'] . "\n", 3, ERROR_LOG_PATH);

    error_log("\n!!!Session!!!\n" . print_r($_SESSION,true) . "\n\n", 3, ERROR_LOG_PATH);
    error_log("\n!!!Request!!!\n" .$curl_header_req."\n".json_encode($body)."\n\n", 3, ERROR_LOG_PATH);
    error_log("\n!!!Response!!!\n".$response."\n\n", 3, ERROR_LOG_PATH);

    // If http status code isn't 201, die and respond as 500 Internal Server Error.
    if (!($http_status_code == 201)) {
        die;
    }
    return $parsed_parameters['id'];
}

?>
