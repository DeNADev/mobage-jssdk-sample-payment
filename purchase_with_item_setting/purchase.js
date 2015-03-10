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
 
(function() {
    var purchaseButton, clientData;

    var html = document.getElementsByTagName('html')[0];
    clientData = {
        clientId     : html.dataset.clientId,
        redirectUri  : html.dataset.redirectUri,
        logoutUri    : html.dataset.logoutUri,
        state        : html.dataset.state,
        sessionState : html.dataset.sessionState
    };

    // This will be called from JavaScript SDK
    document.addEventListener("mobageReady", function() {
        mobage.init({
            clientId:    clientData.clientId,
            redirectUri: clientData.redirectUri
        });

        purchaseButton = document.getElementById('purchase');

        var item     = document.getElementById('item');
        var itemId  = item.name;
        var quantity = item.value;

        if (clientData.sessionState) {
            mobage.event.subscribe('oauth.sessionStateChange', clientData.sessionState, function(result) {
                if (result === 'changed') {
                    location.href = clientData.logoutUri;
                }   
            }); 
            console.log('sessionState is OK');

            purchaseButton.style.cssText = "visibility:visible";
            purchaseButton.addEventListener('click', function() {
                createItemOrder(itemId, quantity);
            }); 

        } else if (clientData.state) {
            var params = { state : clientData.state };
            mobage.oauth.getConnectedStatus(params, function(err, result) {
                if (result) {
	                sendToRedirectURI(result);
                    purchaseButton.style.cssText = "visibility:visible";
                    purchaseButton.addEventListener('click', function() {
                        createItemOrder(itemId, quantity);
                    });
                } else {
                    console.log('getConnected Status Error');
                    purchaseButton.style.cssText = "visibility:hidden";
                }
            });
        }
    });


    // Request GameServer to publish Transaction
    function createItemOrder(itemId, quantity) {
        var req = new XMLHttpRequest();
        var payload = {
            'itemId':   itemId,
            'quantity': quantity
        };
        req.open('POST', 'order.php');
        req.setRequestHeader('Content-Type', 'application/json;charset=UTF-8');
        req.addEventListener('load', function() {
            executeItemPayment(req);
        } , false);
        req.send(JSON.stringify(payload));
    }


    // Make Payment
    function executeItemPayment(req) {
        if (req.status != 200) {
            window.alert("failed to initialize payment procedures.");
            return;
        }
        var order = JSON.parse(req.response);

        var widget = mobage.ui.open (
            'payment',
            {
                'transactionId' : order.transactionId,
                'orderId'       : order.orderId
            },
            function(error, result) {
                if (error) {
                    // Request GameServer to update OrderDB status.
                    // The status on OrderDB should be updated from authorized to canceled/error.
                    var cancel_req = new XMLHttpRequest();
                    cancel_req.open('POST', 'cancel.php');
                    cancel_req.setRequestHeader('Content-Type', 'application/json;charset=UTF-8');
                    cancel_req.addEventListener('load', function() {
                        // Clear transaction from Client Storage.
                        mobage.bank.clearPaymentBacklog(order.transactionId);
                    } , false);
                    cancel_req.send(JSON.stringify(order));
                    return;
                }
                fulfillItemOrder(result.response, result.signedResponse);
            }
        );
    }


    // Give Items to User
    function fulfillItemOrder (response, signedResponse) {
        
        var req = new XMLHttpRequest();
        req.open('POST', 'fulfill_order.php');
        req.setRequestHeader('Content-Type', 'application/jwt');
        req.addEventListener('load', function() {
            fulfillItemOrderCallback(req, response, signedResponse);
        } , false);
        req.send(signedResponse);
        
    }


    // Call back after giving Items to User
    function fulfillItemOrderCallback(req, response, signedResponse) {
        if (req.status != 200) {
            window.alert('アイテム付与処理に失敗しました');
            return;
        }
        mobage.bank.clearPaymentBacklog(response.result.payment.id);
    }


    // Update Login session    
    function sendToRedirectURI(result) {

        var response = result.response;
        var payload  = {
            code          : response.code,
            state         : response.state,
            session_state : response.session_state
        };

        var req = new XMLHttpRequest();
        req.open('POST', clientData.redirectUri);
        req.setRequestHeader('Content-Type', 'application/json;charset=UTF-8');
        req.addEventListener('load', function() {
            // ログイン処理後のハンドリング
        } , false);
        req.send(JSON.stringify(payload));
    }

})(this.self || global);
