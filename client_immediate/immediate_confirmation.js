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
    var clientData;

    var html = document.getElementsByTagName('html')[0];
    clientData = {
        clientId     : html.dataset.clientId,
        redirectUri  : html.dataset.redirectUri,
        state        : html.dataset.state,
        sessionState : html.dataset.sessionState
    };

    // This will be called from JavaScript SDK
    document.addEventListener("mobageReady", function() {
        mobage.init({
            clientId:    clientData.clientId,
            redirectUri: clientData.redirectUri
        });

        if (clientData.state) {
            var params = { state : clientData.state };
            mobage.oauth.getConnectedStatus(params, function(err, result) {
                if (result) {
                    sendToRedirectURI(result);
                    var transactionIds = mobage.bank.getPaymentAllTransactionIds();
                    if(transactionIds.length > 0) {
                        for(var i = 0; i < transactionIds.length(); i++) {
                            confirmTransaction(transactionIds[i]);
                        }
                    }
                } else {
                    console.log('getConnected Status Error');
                }
            });
        }
    });


    // Request GameServer to confirm Transaction
    function confirmTransaction(transactionId) {
        var req = new XMLHttpRequest();
        var payload = {
            'transactionId': transactionId
        };
        req.open('POST', 'confirmation.php');
        req.setRequestHeader('Content-Type', 'application/json;charset=UTF-8');
        req.addEventListener('load', function() {
            confirmPaymentBacklogCallback(req);
        } , false);
        req.send(JSON.stringify(payload));
    }


    // Call back from Game Server
    function confirmPaymentBacklogCallback(req) {
        if (req.status == 200) {
            var res = JSON.parse(req.responseText);
            console.log('confirmPaymentBacklogCallback has come!');
            console.log(res);
            mobage.bank.clearPaymentBacklog(res.transactionId);
        }
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
            // Handling after Login Procedures
        } , false);
        req.send(JSON.stringify(payload));
    }

})(this.self || global);
