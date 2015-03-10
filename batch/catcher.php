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
    require_once('../JWT/JWT.php');
    require_once('save_refresh_token.php');

    $unixtime = time();
    $half_an_hour_ago = $unixtime - (30 * 60);


    $order_list = array();
    try {
        // Check All OrderDB Records
        $dbh = new PDO(PDO_DSN, PDO_USERNAME, PDO_PASSWORD);
        $sql_for_select = "select order_id, user_id, client_id, transaction_id, order_state, created_at
            from order_db
            where order_state = 'authorized' and created_at < ? order by created_at;";
        $sth_for_select = $dbh->prepare($sql_for_select);
        $sth_for_select->execute(array($half_an_hour_ago));

        while ($order = $sth_for_select->fetch()) {
            $user_id        = $order['user_id'];
            $client_id      = $order['client_id'];
            $transaction_id = $order['transaction_id'];

            $refresh_token = getRefreshToken($user_id, $client_id);

            $params_for_get_access_token = array(
                'refresh_token' => $refresh_token,
                'user_id'       => $user_id,
                'client_id'     => $client_id
            );
            $access_token  = getAccessToken($params_for_get_access_token);

            $transaction   = bankDebitGet($transaction_id, $access_token);
            
            $state         = $transaction['state'];
            $order_id      = $order['order_id'];

            $sql_for_lock = 'select * from order_db where order_id = ? for update;';
            $sth_for_lock = $dbh->prepare($sql_for_lock);
            $dbh->beginTransaction();
            $sth_for_lock->execute(array($order_id));
            $locked_order = $sth_for_lock->fetch();


            // compare platform's state value and order_db's order_state value 
            if (($locked_order['order_state'] === $state)) {
                error_log('order_db order_state is already syncronized. state=' .
                        $locked_order['order_state'] . "\n", 3, ERROR_LOG_PATH);
                $dbh->rollBack();

            } elseif ($state === 'canceled' || $state === 'error') {
                // Update order_state from authorized to canceled/error
                $sql_for_cancel = 'update order_db
                    set order_state = ?
                    where order_id = ?;';
                $sth_for_cancel = $dbh->prepare($sql_for_cancel);
                $sth_for_cancel->execute(array($state, $order_id));
                $dbh->commit();

            } elseif ($state === 'closed') {

                // Give Items to User
                $sql_for_item = 'insert into user_items
                    (user_id, item_id, item_num) values
                    (?, ?, ?)
                    on duplicate key update item_num=item_num + ?;';
                $sth_for_item = $dbh->prepare($sql_for_item);
                $sth_for_item->execute(array(
                            $order['user_id'],                      // user_id
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
            }
        }
    } catch (PDOException $e) {
        error_log('PDO error ' . $e . "\n", 3, ERROR_LOG_PATH);
        die();
    }


function getAccessToken ($params) {
   
    $client_id = $params['client_id'];
    global $CLIENT_SECRETS;

    $token_endpoint_request_params = [
        'grant_type'    => 'refresh_token',
        'redirect_uri'  => REDIRECT_POST_ENDPOINT,
        'client_id'     => $client_id,
        'client_secret' => $CLIENT_SECRETS[$client_id],
        'refresh_token' => $params['refresh_token']
    ];

    $headers = [
        'Content-Type: application/x-www-form-urlencoded',
        'Connection: close',
    ];

    $options = [
        'http' => [
            'method'  => 'POST',
            'content' => http_build_query($token_endpoint_request_params),
            'header'  => implode("\r\n", $headers),
        ]
    ];

    $raw_token_response = file_get_contents(TOKEN_ENDPOINT_URL, false, stream_context_create($options));

    $token_response    = json_decode($raw_token_response);
    $access_token      = $token_response->access_token;
    $old_refresh_token = $params['refresh_token'];
    $new_refresh_token = $token_response->refresh_token;
    $user_id           = $params['user_id'];

    // if the new refresh_token is different from previous one,
    // you have to update the refresh_token on your DataBase.
    if (!($old_refresh_token === $new_refresh_token)) {
        $refresh_token_expires_at = time() + (90 * 24 * 3600); # valid for 90 days
        saveRefreshToken($user_id, $new_refresh_token, $refresh_token_expires_at);
    }

    return $access_token;
}

function getRefreshToken ($user_id, $client_id) {
    
    try {
        $dbh = new PDO(
                PDO_DSN, 
                PDO_USERNAME, 
                PDO_PASSWORD);
        $sql_for_token = 'select * from user_tokens where user_id = ? and client_id = ?;';
        $sth_for_token = $dbh->prepare($sql_for_token);
        $sth_for_token->execute(array($user_id, $client_id));
        $record = $sth_for_token->fetch();
        $refresh_token = $record['refresh_token'];

    } catch (PDOException $e) {
        error_log('PDO error ' . $e . "¥n", 3, ERROR_LOG_PATH);
        die();
    }   

    return $refresh_token;
}

