mobage-jssdk-sample-payment
===========================
## 動作確認環境

* Mac Book Air OS 10.9.2
* PHP 5.4.24
* MySQL 5.6.17
* [jwt](https://github.com/F21/jwt) ライブラリ（PHP 5.4.8 以上必要）

※ JSSDKではDOM-based XSSに対するセキュリティ上の対策としてContent Security Policyを導入しており、それによってinlineなJavaScriptの実行を禁止しています。Chromeのextensionの中にはこちらに抵触する処理を行うものがあるため、PCなどのChromeブラウザにて試す際には、Secret Modeで実行してください

## このサンプルの目的
Mobage JS SDK を利用したアイテム課金処理に必要な処理をイメージしやすいように実装したサンプルです。

このサンプルは以下のような購入フローに関するサンプル2つと、
* アイテム購入処理と付与
 * 事前にアイテム登録が行われている場合
 * 事前にアイテム登録が行われていない場合

以下のような、購入途中で処理が止まって「モバコイン引き落とされたけどアイテム付与されていない」といった状態にあるユーザーを救済するサンプル3つで構成されています。

* アイテム購入が失敗した時の処理
 * Server 側での非同期確認処理
 * Client 側での非同期確認処理
 * バッチを用いた非同期確認処理

### 関連ドキュメント
こちらではサンプルを動かす簡単な手順をまとめていますが、Mobage JS SDK の詳細については「[Mobage JS SDK 開発ドキュメント](https://docs.mobage.com/display/JPJSSDK/Guide_for_Engineers)」を参照してください。

## サンプルを動かすまでの手順
### サンプルをインストールする
このサンプルは PHP のファイル群から構成されています。PHP が実行されるディレクトリにコピーします。

### ログインサンプルで Mobage Connect にログインする
ログインサンプル「mobage-jssdk-sample-login」を先にインストールし、ログインできる状態にしておきます。

また、デベロッパーサイトでの登録もログインサンプルの設定として行っておきます。

### config.phpを編集する
#### BASE_URIについて
BASE_URIは必ず
http://localhost
のように通信方式とホスト名のみで記載しましょう。

もしデフォルトディレクトリ以外にサンプルコードを配置した場合、適宜変更しましょう。

#### CLIENT_ID/CLIENT_SECRETについて
Mobage Developers Japanで発行されたClient_ID, Client_Secretを記載しましょう。

* SPWEB_CLIENT_ID / SPWEB_CLIENT_SECRET
 * スマートフォンブラウザ環境での CLIENT_ID / CLIENT_SECRET です。
* ANDROID_CLIENT_ID / ANDROID_CLIENT_SECRET
 * Shell App SDK for Android 環境での CLIENT_ID / CLIENT_SECRET です。
* IOS_CLIENT_ID / IOS_CLIENT_SECRET
 * Shell App SDK for iOS 環境での CLIENT_ID / CLIENT_SECRET です。


#### URI設定について
CLIENT_URI, REDIRECT_POST_ENDPOINT, REDIRECT_GET_ENDPOINTなどの値は、
Mobage Developers Japanであらかじめ登録した値を記載しましょう。
もしデフォルトディレクトリ以外にサンプルコードを置いた場合、こちらは適宜変更してください。


###PHP, MySQLがインストールされた環境を用意する
以下がインストールされた環境を用意しましょう。
 * PHP 5.4.24
 * MySQL 5.6.17

※サンプルコードの推奨実行環境です


### JWTライブラリを準備する
公開鍵 X509 formatで以下のライブラリを利用しています。  

https://github.com/F21/jwt

こちらのライブラリをダウンロードして以下のように配置しましょう。 
なお、こちらのライブラリを動作させるためには PHP 5.4.8 以上が必要です。

```
mobage-jssdk-sample-payment/JWT/JWT.php
```

### Databaseの設定を記載する
Database の設定値を変更する為に、config.php を開きます。
```
$ vi mobage-jssdk-sample-payment/config.php
```

PDO_DSN、PDO_USERNAME、PDO_PASSWORD に独自 Databaseの設定値を記載しましょう。
```
// The following parameters are the setting on database.
define('PDO_DSN',      'mysql:dbname=mobage_jssdk_sample;host=localhost');
define('PDO_USERNAME', 'YOUR_USERNAME');
define('PDO_PASSWORD', 'YOUR_PASSWORD');
```

### GameServer でのテーブル設定を行う
以下の手順で、Game Server の Database にテーブルを登録します。
```
$ cd /Library/WebServer/Documents/mobage-jssdk-sample-payment/db_setting/

$ mysql -u YOUR_USERNAME -p YOUR_PASSWORD < ddl.sql

$ mysql -u YOUR_USERNAME -p YOUR_PASSWORD < init_query.sql 

$ mysql -u YOUR_USERNAME -p YOUR_PASSWORD

mysql> use mobage_jssdk_sample;

mysql> show tables;
+-------------------------------+
| Tables_in_mobage_jssdk_sample |
+-------------------------------+
| items                         |
| order_db                      |
| sequence_order_db             |
| user_items                    |
| user_tokens                   |
+-------------------------------+
5 rows in set (0.00 sec)

mysql> select * from items;
+----------+-----------+-------+-------------+----------------------------------------------+
| id       | name      | price | description | imageUrl                                     |
+----------+-----------+-------+-------------+----------------------------------------------+
| item_001 | full_cure |   100 | cure params | http://dena.com/images/logos/logo_mobage.png |
+----------+-----------+-------+-------------+----------------------------------------------+
1 row in set (0.00 sec)
```

### Refresh Token を Database へ保存するための準備をする
ログインサンプル「mobage-jssdk-sample-login」にて、refresh_tokenをGameServerに保存できるようにファイルを編集します。
```
下記2ファイルを編集します
mobage-jssdk-sample-login/hybrid_flow/login_cb_post.php
mobage-jssdk-sample-login/authorization_code_grant_flow/login_cb_get.php

以下の3箇所のコメントを取ります
//require_once('../others/save_refresh_token.php');

// $refresh_toke_expires_at = time() + (90 * 24 * 3600); # valid for 90 days
//saveRefreshToken($_SESSION['user_id'], $_SESSION['refresh_token'], $refresh_toke_expires_at);

※ Refresh Token の有効期間は目安なので、89日など若干少なめに設定してもらえると確実です。
※ 有効期間内でもユーザーの行動次第(アンインストールや退会など)で失効することがあります。

いったんログアウトし、再度ログインします。

DBにrefresh_tokenが保存されているか確認します。
$ mysql -u YOUR_USERNAME -p YOUR_PASSWORD
> use mobage_jssdk_sample;
> select * from user_tokens¥G
*************************** 1. row ***************************
user_id: XXXXXX
client_id: XXXXXXXX-X
refresh_token: XXXXXX
expire_at: XXXXX
1 row in set (0.00 sec)
```

## 動作確認 (アイテム購入について)
アイテム購入について動作確認します。
事前にSandbox環境でのアカウント作成、Sandbox環境アカウントへのモバコイン付与を行っておきましょう。
### 事前にアイテム登録が行われている購入フローを確認する
#### デベロッパーサイトでアイテム情報の登録
以下に従って、デベロッパーサイトにアイテムデータを登録します。
* ダッシュボードの左メニューより「アプリケーション」>「アプリケーション一覧」画面を開きます。
* 「アプリケーション一覧」から開発しているアプリケーションを選択します。
* ダッシュボードの左メニューより「アイテムの追加」を選択します。
* 登録したいアイテムの情報を入力します。
* 入力が完了したアイテムは、「アイテム管理」によって管理することができます。

#### アイテムの購入
アイテム課金のTOPページにアクセスして、「Purchase Items (With Item Setting)」を選び、アイテム課金を行います。

http://localhost/mobage-jssdk-sample-payment/


### 事前にアイテム登録が行われていない購入フローを確認する
#### アイテムの購入
アイテム課金のTOPページにアクセスして、「Purchase Items (Without Item Setting)」を選び、アイテム課金を行います。

http://localhost/mobage-jssdk-sample-payment/

### 購入後に結果を確認する
それぞれの購入フローについて、購入後以下のように実際に付与されたか確認します。
（このサンプルでは、ユーザーの保持アイテムテーブルと、注文情報であるOrder テーブルを確認します）
```
$ mysql -u YOUR_USERNAME -p YOUR_PASSWORD

mysql> use mobage_jssdk_sample;

mysql> select * from user_items;
+---------+----------+----------+
| user_id | item_id  | item_num |
+---------+----------+----------+
|  151442 | item_001 |        2 |
+---------+----------+----------+
1 row in set (0.00 sec)

mysql> select * from order_db;
+----------+-------------+--------------------------------------+---------+------------+------------+
| order_id | order_state | transaction_id                       | user_id | client_id  | created_at |
+----------+-------------+--------------------------------------+---------+------------+------------+
|        1 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1403074112 |
+----------+-------------+--------------------------------------+---------+------------+------------+
1 row in set (0.00 sec)
```

## 動作確認 (アイテム非同期確認処理について)
アイテム非同期確認処理の動作確認を行うためには、「モバコインは引き落とされたがアイテムは付与されていない」という状況をつくる必要があります。

Platformからモバコインの引き落とし通知を受けた後、Game Server にアイテム付与リクエストを送信する、「アイテム付与リクエスト送信」処理を止めることで上記のような状況を意図的につくります。

上記のような状況をつくるために、編集が必要なファイルは以下の2つです。
* mobage-jssdk-sample-payment/purchase_with_item_setting/purchase.js
* mobage-jssdk-sample-payment/purchase_without_item_setting/purchase.js

こちらの下記の箇所「req.send(signedResponse);」をコメントアウトします

```
    // Give Items to User
    function fulfillItemOrder (response, signedResponse) {
        
        var req = new XMLHttpRequest();
        req.open('POST', 'fulfill_order.php');
        req.setRequestHeader('Content-Type', 'application/jwt');
        req.addEventListener('load', function() {
            fulfillItemOrderCallback(req, response, signedResponse);
        } , false);
        //req.send(signedResponse);
        
    }
```
このコメントアウトにより、モバコインは引き落とされたのにアイテムを付与されていないという状況を作り出すことができます。

### Server 側での非同期確認処理を確認する
#### Platformからの通知を受け取れるGame Server の準備
Server 側の非同期確認処理では、Game Server にて Platformからの通知を受け取ります。

Platform からの通知を受け取れる Game Server を準備しましょう。

※この Server 側での非同期確認処理サンプルは、Amazon EC2 にて動作確認を行いました。


#### デベロッパーサイトでSubscriber Callback URIの登録
Platform からの通知を受け取る Game Server 側の URI を設定します。
*  ダッシュボードの左メニューより「アプリケーション」>「アプリケーション一覧」画面を開きます。
*  「アプリケーション一覧」から開発しているアプリケーションを選択します。
*  表示されたページの上部にある「SPWeb」タブを選択します。
*  SPWebのページ中央部、「Mobage Connect 情報」の右側にある「情報を変更」を押下します。
*  Mobage Connect情報の複数あるタブの中から、「アイテム購入非同期確認処理設定」を選択します。
*  「Subscriber Callback URI」に、Platformからのリクエストを受け取るゲームサーバ側のURIを設定します。

#### アイテム付与されていない状況の作成
Subscriber Callback URI の登録が完了したら、「コインは引き落とされるがアイテムは付与されない」という状況をつくります。

purchase.js に記載された、Game Server にアイテム付与リクエストを送る「req.send(signedResponse);」をコメントアウトし、その状態でアイテムを購入します。

#### Platform からの通知の確認

モバコインの引き落としが完了すると、Platformからデベロッパーサイトに登録したURIにリクエストが送信されされます。

サンプルから出力されるログにて「Server-Side Async Confirmation has come !!」というメッセージの表示を確認しつつ、Order DB と ユーザーの所持アイテムも確認しましょう。

```
$ mysql -u YOUR_USERNAME -p YOUR_PASSWORD

mysql> use mobage_jssdk_sample;

mysql> select * from user_items;
+---------+----------+----------+
| user_id | item_id  | item_num |
+---------+----------+----------+
|  151442 | item_001 |        3 |
+---------+----------+----------+
1 row in set (0.00 sec)

mysql> select * from order_db;
+----------+-------------+--------------------------------------+---------+------------+------------+
| order_id | order_state | transaction_id                       | user_id | client_id  | created_at |
+----------+-------------+--------------------------------------+---------+------------+------------+
|        1 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1403074112 |
|        2 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1403169425 |
+----------+-------------+--------------------------------------+---------+------------+------------+
2 row in set (0.00 sec)
```

上記のように、ユーザにアイテムが付与され、Order DB が更新されていることを確認することができます。


### Client 側での非同期確認処理を確認する
#### アイテム付与されていない状況の作成
「コインは引き落とされるがアイテムは付与されない」という状況をつくります。

purchase.js に記載された、Game Server にアイテム付与リクエストを送る「req.send(signedResponse);」をコメントアウトし、その状態でアイテムを購入します。

なお、Server 側での非同期確認処理を既に実装済みの場合は、動作確認で競合しないように、Server 側での非同期確認処理が平行して実施されないような対応をお願いします。

#### Client 側での非同期確認処理の実行
下記のページにアクセスし、「Client-Side Asynchronous Confirmation」へページ遷移すると、遷移先ページにてClient 側での非同期確認処理が実施されます。
http://localhost/mobage-jssdk-sample-payment/

サンプルから出力されるログにて「Client-Side Asyncronous Confirmation has come!!」というメッセージの表示を確認しつつ、Order DB と ユーザーの所持アイテムも確認しましょう。
```
$ mysql -u YOUR_USERNAME -p YOUR_PASSWORD

mysql> use mobage_jssdk_sample;

mysql> select * from user_items;
+---------+----------+----------+
| user_id | item_id  | item_num |
+---------+----------+----------+
|  151442 | item_001 |        5 |
+---------+----------+----------+
1 row in set (0.00 sec)

mysql> select * from order_db;
+----------+-------------+--------------------------------------+---------+------------+------------+
| order_id | order_state | transaction_id                       | user_id | client_id  | created_at |
+----------+-------------+--------------------------------------+---------+------------+------------+
|        1 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1403074112 |
|        2 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1403169425 |
|        3 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1403503238 |
+----------+-------------+--------------------------------------+---------+------------+------------+
3 row in set (0.00 sec)
```

上記のように、ユーザにアイテムが付与され、Order DB が更新されていることを確認することができます。

### Batch を用いた非同期確認処理を確認する
#### Batch での確認を行うための準備
Batch でトランザクション状況の同期処理と付与漏れの追加付与処理を確認するために、購入とキャンセルを数回ずつ繰り返し、下記のような Order DB の状況を作り出します。

その後、アイテム付与等が失敗した状況を模擬的に作成するため、mysqlで Order DB の order_status を直接 'authorized' に変更します。

※実際のところ購入処理が成功して、アイテムは付与されていますが、これは動作確認テストなので、その点は無視します。

また、仕様上 30 分以前のトラザクションだけを処理の対象にするので、 create_at のタイムスタンプを強制的に 30 分前に戻します。
```
$ mysql -u YOUR_USERNAME -p YOUR_PASSWORD

> use mobage_jssdk_sample;

> select * from order_db;
+----------+-------------+--------------------------------------+---------+------------+------------+
| order_id | order_state | transaction_id                       | user_id | client_id  | created_at |
+----------+-------------+--------------------------------------+---------+------------+------------+
|        1 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1403169403 |
|        2 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1403169425 |
|        3 | canceled    | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1403503238 |
|        4 | canceled    | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1403503247 |
|        5 | canceled    | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1403503253 |
|        6 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1403586140 |
|        7 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404364464 |
|        8 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404805754 |
|        9 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404893864 |
|       10 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404894160 |
+----------+-------------+--------------------------------------+---------+------------+------------+
10 rows in set (0.00 sec)

> select * from user_items;
+---------+----------+----------+
| user_id | item_id  | item_num |
+---------+----------+----------+
|  XXXXXX | item_001 |        7 |
+---------+----------+----------+
1 row in set (0.00 sec)

> update order_db set order_state='authorized';
Query OK, 10 rows affected (0.00 sec)
Rows matched: 10  Changed: 10  Warnings: 0

> update order_db set created_at=UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 minute));
Query OK, 10 rows affected (0.00 sec)
Rows matched: 10  Changed: 10  Warnings: 0

> select * from order_db;
+----------+-------------+--------------------------------------+---------+------------+------------+
| order_id | order_state | transaction_id                       | user_id | client_id  | created_at |
+----------+-------------+--------------------------------------+---------+------------+------------+
|        1 | authorized  | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|        2 | authorized  | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|        3 | authorized  | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|        4 | authorized  | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|        5 | authorized  | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|        6 | authorized  | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|        7 | authorized  | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|        8 | authorized  | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|        9 | authorized  | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|       10 | authorized  | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
+----------+-------------+--------------------------------------+---------+------------+------------+
10 rows in set (0.00 sec)
```

#### Batchの実行と確認
Batchを実行して、Order DB が更新され、アイテムが再度付与されている事を確認する
```
$ cd /Library/WebServer/Documents/mobage-jssdk-sample-payment/batch

batchの実行
$ sudo catcher.php

アイテムが付与されたか再度確認
$ mysql -u YOUR_USERNAME -p YOUR_PASSWORD

> use mobage_jssdk_sample;

> select * from order_db;
+----------+-------------+--------------------------------------+---------+------------+------------+
| order_id | order_state | transaction_id                       | user_id | client_id  | created_at |
+----------+-------------+--------------------------------------+---------+------------+------------+
|        1 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|        2 | canceled    | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|        3 | canceled    | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|        4 | canceled    | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|        5 | canceled    | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|        6 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|        7 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|        8 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|        9 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
|       10 | closed      | XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX |  XXXXXX | XXXXXXXX-X | 1404892360 |
+----------+-------------+--------------------------------------+---------+------------+------------+
10 rows in set (0.00 sec)

> select * from user_items;
+---------+----------+----------+
| user_id | item_id  | item_num |
+---------+----------+----------+
|  XXXXXX | item_001 |       14 |
+---------+----------+----------+
1 row in set (0.00 sec)
```

## その他
### サーバサイドでのSQLの一覧を確認する
このサンプルコードにてサーバサイドで実行されるSQLについては、
 * mobage-jssdk-sample-payment/db_setting/query.sql
にまとめていますので、SQLだけ確認したい場合はそちらを参照ください。
