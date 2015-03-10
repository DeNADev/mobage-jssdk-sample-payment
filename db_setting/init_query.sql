use mobage_jssdk_sample;

# ----------------------------
#  アイテムマスター登録 (by運営)
# ----------------------------
begin;

#  itemsへの登録
insert ignore into items
 (id, name, price, description, imageUrl) values
 ('item_001', 'full_cure', 100, 'cure params', 'http://dena.com/images/logos/logo_mobage.png');

commit;