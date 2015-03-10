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
require_once('../bank_api_connectors/bank_debit_post.php');

$unixtime = time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    // load json from request body
    $request_params = json_decode(file_get_contents('php://input'), true);


    // select from item master data
    $item_id  = $request_params['itemId'];
    $quantity = $request_params['quantity'];

    try {
        $dbh = new PDO(PDO_DSN, PDO_USERNAME, PDO_PASSWORD);
        $sql = "select * from items where id = ?";
        $sth = $dbh->prepare($sql);
        $sth->execute(array($item_id));
        $fetch_item = $sth->fetch();
    } catch (PDOException $e) {
        error_log('PDO error ' . $e . "\n", 3, ERROR_LOG_PATH);
        die();
    }


    // Prepare for making transaction
    $body = array(
        'items' => array(
            array(
                'item' => array(
                    'id'          => $item_id,
                    'name'        => $fetch_item['name'],
                    'price'       => $fetch_item['price'],
                    'description' => $fetch_item['description'],
                    'imageUrl'    => $fetch_item['imageUrl']
                ),
                'quantity' => $quantity,
            ),
        ),
        'comment' => $fetch_item['name'] . "x" . $quantity . "個(Mobageユーザ通帳に記載されるコメントです)",
        'state'   => "new"
    );


    // Bank Debit API endpoint request
    $transaction_id = bankDebitPost($body);


    try {
        // publish order_id
        $sql = 'update sequence_order_db set order_id = LAST_INSERT_ID(order_id+1);';
        $sth = $dbh->prepare($sql);
        $dbh->beginTransaction();
        $sth->execute();
        $order_id = $dbh->lastInsertId();
        // regist new order on order_db
        $sql = 'insert ignore into order_db
        (order_id, transaction_id, user_id, client_id, created_at) values
        (?,?,?,?,?);';
        $sth = $dbh->prepare($sql);
        $sth->execute(array($order_id, $transaction_id, $_SESSION['user_id'], CLIENT_ID, $unixtime));
        $dbh->commit();
        
    } catch (PDOException $e) {
        error_log('PDO error ' . $e . "\n", 3, ERROR_LOG_PATH);
        die();
    }   

    $response = [
        'transactionId' => $transaction_id,
        'orderId'       => $order_id
    ];
    echo json_encode($response);
}
