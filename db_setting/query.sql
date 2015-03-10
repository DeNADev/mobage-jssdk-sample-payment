#----------------------------
# アイテム購入処理 (事前にアイテム登録が行われていない場合)
#----------------------------
#### 決済トランザクションの作成
# transactionの作成に必要なitemのmaster dataを取得
select * from items where id = 'item_001';  # slaveで取得

## transaction作成request -> response (platformへのAPIで取得)

#### Order データの作成, OrderIDの取得 ####
## transaction_id 受け取り、order_idを自前で発行する
begin;

update sequence_order_db set order_id = LAST_INSERT_ID(order_id+1);

select LAST_INSERT_ID(); # プログラムロジック内の$order_idに格納する

insert ignore into order_db
(order_id, transaction_id, user_id, created_at) values
(LAST_INSERT_ID(), 123456, 151442, 1401116400); 

commit;

###  Platform 側の処理とプログラムロジック内での処理####
# モバコイン決済実施、あとはアイテム付与するだけになる
# API の response で transaction state の値を獲得している。
# JWTでsignature検証して、値が妥当か確認が必要
# 以下は確認が完了した前提

begin;

# select for updateで行ロックする(平行して更新処理されないように排他ロックする)
select * from order_db where order_id = 1 for update;


# response.status == closed のとき
    # order_db.order_state == closed のとき
    # ⇒アイテム付与済みなので処理終了。
    rollback;

    # order_db.order_state == canceled / error のとき
    # ⇒ありえない状況
    rollback;

    # order_db.order_state == authorized のとき
    # ⇒決済処理OKでまだアイテム付与していないので付与する
    insert into user_items
      (user_id, item_id, item_num) values
      (151442, 'item_001', 2)
      on duplicate key update item_num=item_num + 2; # quantity

    update order_db
      set order_state = 'closed'
      where order_id = 1;　# order_idは先ほど発行したもの

    commit;

# response.status == canceled/error のとき
    # order_db.order_state == closed のとき
    #  ⇒ありえない状況
    rollback;

    # order_db.order_state == canceled / error
    # ⇒既に更新処理済み
    rollback;
    
    # order_db.order_state == authorized のとき
    # ⇒決済失敗しているのでcanceledに変更する
    update order_db
      set order_state = 'canceled'  # canceled / error
      where order_id = 1;

    commit;


#----------------------------
# アイテム所持確認
#----------------------------
select * from user_items where user_id = 151442 limit 0, 100; # offset, limit


#----------------------------
# Server 側での非同期確認処理
#----------------------------
#  図41の300, 301が完了している前提(bank transaction state==closedの前提)
# MBAのlocal環境では確認できないので、自前のサーバたてる必要あり。
begin;

# select for updateで行ロックする(平行して更新処理されないように排他ロックする)
select order_state from order_db where order_id = 1 for update;

    # order_db.order_state == closed のとき
    # ⇒アイテム付与済みなので処理終了。成功レスポンス(200 OK)を返す
    rollback;

    # order_db.order_state == canceled / error のとき
    # ⇒ありえない状況
    rollback;

    # order_db.order_state == authorized のとき
    insert into user_items
      (user_id, item_id, item_num) values
      (151442, 'item_001', 2)
      on duplicate key update item_num=item_num + 2;

    update order_db
      set order_state = 'closed'
      where order_id = 1;

    commit;

    # 成功レスポンス(200 OK)を返す


#----------------------------
# Client 側での非同期確認処理
#----------------------------
# 図43 409まで完了している前提
begin;

# select for updateで行ロックする(平行して更新処理されないように排他ロックする)
select order_state from order_db where order_id = 1 for update;

# response.status == closed のとき
    # order_db.order_state == closed のとき
    # ⇒アイテム付与済みなので処理終了。mobage.bank.clearPaymentBacklog()を呼ぶ
    rollback;

    # order_db.order_state == canceled / error のとき
    # ⇒ありえない状況
    rollback;

    # order_db.order_state == authorized のとき
    # ⇒決済処理OKでまだアイテム付与していないので付与する
    insert into user_items
      (user_id, item_id, item_num) values
      (151442, 'item_001', 2)
      on duplicate key update item_num=item_num + 2; # quantity

    update order_db
      set order_state = 'closed'
      where order_id = 1;　# order_idは先ほど発行したもの

    commit;
    
    # mobage.bank.clearPaymentBackLog()を呼ぶ

# response.status == canceled/error のとき
    # order_db.order_state == closed のとき
    #  ⇒ありえない状況
    rollback;

    # order_db.order_state == canceled / error
    # ⇒既に更新処理済み
    rollback;
    
    # order_db.order_state == authorized のとき
    # ⇒決済失敗しているのでcanceledに変更する
    update order_db
      set order_state = 'canceled'  # canceled / error
      where order_id = 1;

    commit;

# response.status == authorized のとき
# ⇒まだ決済処理中なので特に何もしない。処理終了。


#----------------------------
# バッチを用いた非同期確認処理
#----------------------------
# アイテム付与されていないものを確認 (index i2を利用したSQL)
select order_id, user_id, transaction_id, order_state, created_at
from order_db
where order_state = 'authorized' and created_at < 1401114600 /*30min_before*/ order by created_at;

# 取得したレコードぶんだけ、BankDebitAPIでステータス確認
# レコード別にBankDebitAPIのstateをみて処理を以下のように変える
begin;

# select for updateで行ロックする(平行して更新処理されないように排他ロックする)
select order_state from order_db where order_id = 1 for update;

# response.status == closed のとき
    # order_db.order_state == closed のとき
    # ⇒アイテム付与済みなので処理終了。(行ロック前に付与された)
    rollback;

    # order_db.order_state == canceled / error のとき
    # ⇒ありえない状況
    rollback;

    # order_db.order_state == authorized のとき
    # ⇒まだアイテム付与していないので付与する
    insert into user_items
      (user_id, item_id, item_num) values
      (151442, 'item_001', 2)
      on duplicate key update item_num=item_num + 2; # quantity

    update order_db
      set order_state = 'closed'
      where order_id = 1;　# order_idは先ほど発行したもの

    commit;

# response.status == canceled/error のとき
    # order_db.order_state == closed のとき
    #  ⇒ありえない状況
    rollback;

    # order_db.order_state == canceled / error
    # ⇒既に更新処理済み
    rollback;
    
    # order_db.order_state == authorized のとき
    # ⇒決済失敗しているのでcanceledに変更する
    update order_db
      set order_state = 'canceled'  # canceled / error
      where order_id = 1;

    commit;

# response.status == authorized のとき
# ⇒ありえない状況
