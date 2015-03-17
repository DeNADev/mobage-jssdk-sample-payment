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
require_once('../common.php');
require_once('../JWT/JWT.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');


    // load json from request body
    $jwt_body = file_get_contents('php://input');
    if (empty($jwt_body)) {
        echo json_encode([ 'result' => false ]);
        return;
    }


    // decode JSON Web Token
    try {
        checkSignatureAlgorithm($jwt_body);
        $jwt_claims = JWT::decode($jwt_body, PUBLIC_KEY);
    } catch (Exception $e) {
        error_log('jwt_decode failed because ' . $e . "\n", 3, ERROR_LOG_PATH);
        render_error_json();
    }


    //----------------------
    // Validate JSON Web Token

    $unixtime = time();
    // validate 'iss'(Issuer Claim)
    if (!($jwt_claims->iss === ISSUER_FOR_WIDGET_SERVICE)) {
        error_log('Issuer Claim is invalid. iss=' . $jwt_claims->iss . ', ISSUER=' . ISSUER_FOR_WIDGET_SERVICE . "\n", 3, ERROR_LOG_PATH);
        render_error_json();
    }
    // validate 'aud'(Audience Claim)
    if (!($jwt_claims->aud === CLIENT_ID)) {
        error_log('Audience Claim is invalid. aud=' . $jwt_claims->aud . ', CLIENT_ID=' . CLIENT_ID . "\n", 3, ERROR_LOG_PATH);
        render_error_json();
    }
    // validate 'iat'(Issued At)
    if (!($jwt_claims->iat <= $unixtime)) {
        error_log('Issed At is newer than now. iat=' . $jwt_claims->iat . ', now=' . $unixtime . "\n", 3, ERROR_LOG_PATH);
        render_error_json();
    }
    // validate 'user_id'
    if (!($jwt_claims->sub) === $_SESSION['user_id']) {
        error_log('MobageUserId is invalid. sub=' . $jwt_claims->sub . ', user_id=' . $_SESSION['user_id'] . "\n", 3, ERROR_LOG_PATH);
        render_error_json();
    }
    // validate whether 'extra.result.payment.state' is 'closed' or not
    if (!($jwt_claims->extra->result->payment->state === 'closed')) {
        error_log('payment state is not closed' . "\n", 3, ERROR_LOG_PATH);
        render_error_json();
    }


    try {
        // verification of order_id and transaction_id
        $dbh = new PDO(PDO_DSN, PDO_USERNAME, PDO_PASSWORD);
        $sql = 'select * from order_db where order_id = ? for update;';
        $sth = $dbh->prepare($sql);
        $dbh->beginTransaction();
        $sth->execute(array($jwt_claims->extra->result->order_id));
        $order = $sth->fetch();
        if (!($order['transaction_id'] === $jwt_claims->extra->result->payment->id)) {
            error_log('transaction_id is invalid.' . "\n", 3, ERROR_LOG_PATH);
            $dbh->rollBack();
            die();
        }
        if (!($order['order_state'] === 'authorized')) {
            error_log('order_state is not authorized.' . "\n", 3, ERROR_LOG_PATH);
            $dbh->rollBack();
            die();
        }


        // Give Items to user if the verification was OK.
        $sql = 'insert into user_items
                (user_id, item_id, item_num) values
                (?, ?, ?)
                on duplicate key update item_num=item_num + ?;';
        $sth = $dbh->prepare($sql);
        $sth->execute(array(
            $jwt_claims->sub,                                        // user_id
            $jwt_claims->extra->result->payment->items[0]->item->id, // item_id
            $jwt_claims->extra->result->payment->items[0]->quantity, // item_num
            $jwt_claims->extra->result->payment->items[0]->quantity  // item_num
        ));


        // Update the record on OrderDB
        $sql = 'update order_db
                set order_state = ?
                where order_id = ?;';
        $sth = $dbh->prepare($sql);
        $sth->execute(array('closed', $jwt_claims->extra->result->order_id));
        $dbh->commit();    

    } catch (PDOException $e) {
        error_log('PDO error ' . $e . "\n", 3, ERROR_LOG_PATH);
        die();
    }

    render_json([ 'success' => true, 'user_id' => $user_id ]);
}

function render_json($params) {
    echo json_encode($params);
    exit;
}

function render_error_json() {
    return render_json([ 'success' => false ]);
}
