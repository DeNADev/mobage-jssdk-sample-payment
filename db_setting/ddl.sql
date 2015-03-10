drop   database if exists mobage_jssdk_sample;
create database           mobage_jssdk_sample;
use                       mobage_jssdk_sample;


# item master
# idはトランザクションとOrder情報を紐づけるユニークな情報
# アプリ側で指定しておく
create table items (
  id          varchar(256) ASCII not null,
  name        varchar(30)  not null,
  price       int unsigned not null,
  description varchar(256),
  imageUrl    varchar(256)
) engine = InnoDB;
alter table items
  add primary key (id);


create table user_items (
  user_id  int unsigned not null,
  item_id  varchar(256) ASCII not null,
  item_num int unsigned not null
) engine = InnoDB;
alter table user_items
  add primary key (user_id, item_id);


create table order_db (
  order_id          int unsigned not null,
  order_state       varchar(10) default 'authorized' not null, # authorized, error, canceled, closed
  transaction_id    varchar(256) ASCII not null,
  user_id           int unsigned not null,
  client_id         varchar(256) ASCII not null,
  created_at        int unsigned not null
) engine = InnoDB;
alter table order_db
  add primary key     (order_id),
  add unique index i1 (transaction_id),
  add index        i2 (order_state, created_at);

create table sequence_order_db (
  order_id int unsigned default 0 not null
) engine = MyISAM;
insert into sequence_order_db (order_id) values (0);


create table user_tokens (
  user_id       int unsigned not null,
  client_id     varchar(256) ASCII not null,
  refresh_token text         not null,
  expires_at    int unsigned not null
) engine = InnoDB;
alter table user_tokens 
  add primary key (user_id,client_id);
