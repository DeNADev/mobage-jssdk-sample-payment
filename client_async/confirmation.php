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

error_log('Client-Side Asyncronous Confirmation has come!!' . "\n", 3, ERROR_LOG_PATH);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    // load json from request body
    $request_params = json_decode(file_get_contents('php://input'));

    // extract one transacton_id from array
    $transaction_id = $request_params->transactionIds[0];
    $access_token   = $_SESSION['access_token'];

    $transaction   = bankDebitGet($transaction_id, $access_token);

    $state         = $transaction['state'];

    try {
        $dbh = new PDO(PDO_DSN, PDO_USERNAME, PDO_PASSWORD);
        $sql_for_lock = 'select * from order_db where transaction_id = ? for update;';
        $sth_for_lock = $dbh->prepare($sql_for_lock);
        $dbh->beginTransaction();
        $sth_for_lock->execute(array($transaction_id));
        $locked_order = $sth_for_lock->fetch();
        $order_id     = $locked_order['order_id'];

        // compare platform's state value and order_db's order_state value 
        if (($locked_order['order_state'] === $state)) {
            error_log('order_db order_state is already syncronized. state=' .
                    $locked_order['order_state'] . "\n", 3, ERROR_LOG_PATH);
            $dbh->rollBack();
            render_json([ 'transactionId' => $transaction_id ]);
        } elseif ($state === 'canceled' || $state === 'error') {
            // Update order_state from authorized to canceled/error
            $sql_for_cancel = 'update order_db
                set order_state = ?
                where order_id = ?;';
            $sth_for_cancel = $dbh->prepare($sql_for_cancel);
            $sth_for_cancel->execute(array($state, $order_id));
            $dbh->commit();
            render_json([ 'transactionId' => $transaction_id ]);
        } elseif ($state === 'closed') {

            // Give Items to User
            $sql_for_item = 'insert into user_items
                (user_id, item_id, item_num) values
                (?, ?, ?)
                on duplicate key update item_num=item_num + ?;';
            $sth_for_item = $dbh->prepare($sql_for_item);
            $sth_for_item->execute(array(
                $locked_order['user_id'],                      // user_id
                $transaction['items'][0]['item']['id'], // item_id
                $transaction['items'][0]['quantity'],   // item_num
                $transaction['items'][0]['quantity']    // item_num
            ));

            // order_state を closed に更新
            $sql_for_close = 'update order_db
                set order_state = ?
                where order_id = ?;';
            $sth_for_close = $dbh->prepare($sql_for_close);
            $sth_for_close->execute(array($state, $order_id));
            $dbh->commit();
            render_json([ 'transactionId' => $transaction_id ]);
        }
    } catch (PDOException $e) {
        error_log('PDO error ' . $e . "\n", 3, ERROR_LOG_PATH);
        render_error_json();
    }
}

function render_json($params) {
    echo json_encode($params);
    exit;
}

function render_error_json() {
    http_response_code(400);
    return render_json([ 'success' => false ]);
}
