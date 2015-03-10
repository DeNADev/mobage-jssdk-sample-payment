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
 
require_once('../config.php');
require_once('../bank_api_connectors/bank_debit_get.php');

$unixtime = time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    // load json from request body
    $request_params = json_decode(file_get_contents('php://input'), true);

    // select from item master data
    $transaction_id = $request_params['transactionId'];
    $order_id       = $request_params['orderId'];

    try {
        $dbh = new PDO(PDO_DSN, PDO_USERNAME, PDO_PASSWORD);
        $sql = "select * from order_db where order_id = ?";
        $sth = $dbh->prepare($sql);
        $sth->execute(array($order_id));
        $order = $sth->fetch();
    } catch (PDOException $e) {
        error_log('PDO error ' . $e . "\n", 3, ERROR_LOG_PATH);
        die();
    }


    // validation
    if (!($transaction_id === $order['transaction_id'])) {
        error_log('transaction_id is invalid.' . "\n", 3, ERROR_LOG_PATH);
        die();
    }
    if (!($order['order_state'] === 'authorized')) {
        error_log('order_db order_state is not "authorized".' . "\n", 3, ERROR_LOG_PATH);
       // die();
    }

    // Bank Debit API endpoint request
    $response = bankDebitGet($transaction_id, $_SESSION['access_token']);

    // If Platform's state is not canceled, cancel response would be invalid.
    if (!($response['state'] === 'canceled' || $response['state'] === 'error')) {
        error_log('Platform Transaction state is not "canceled" or "error".' . "\n", 3, ERROR_LOG_PATH);
        die();
    }


    try {
        // lock the record of order on OrderDB
        $sql = 'select * from order_db where order_id = ? for update;';
        $sth = $dbh->prepare($sql);
        $dbh->beginTransaction();
        $sth->execute(array($order_id));
        $locked_order = $sth->fetch();


        // If the locked order state is not 'authorized',
        // someone must have updated the order state in the meantime. 
        if (!($locked_order['order_state'] === 'authorized')) {
            error_log('order_db order_state is not "authorized". order_state=' . $locked_order['order_state'] . "\n", 3, ERROR_LOG_PATH);
            $dbh->rollBack();
            die();
        }


        // Update order_state
        $sql = 'update order_db
                set order_state = ?
                where order_id = ?;';
        $sth = $dbh->prepare($sql);
        $sth->execute(array($response['state'], $order_id));
        $dbh->commit();
    } catch (PDOException $e) {
        error_log('PDO error ' . $e . "\n", 3, ERROR_LOG_PATH);
        die();
    }   

    echo json_encode([ 'cancel' => 'success' ]);
}
