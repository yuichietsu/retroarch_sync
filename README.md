## 概要

PCからAndroidにadbでファイルを転送するためのスクリプトです。

## インストール

```
# git clone https://github.com/yuichietsu/adb_sync/
# cd adb_sync
# composer install
```

## 使い方

### 比較

```
# composer run sync -- diff /mnt/d/tmp/test 192.168.11.44:/storage/B42F-0FFA/test
```

PCにだけファイルがある場合

```
[SRC ONLY]
file1.txt
list.txt
```

Androidにだけファイルがある場合

```
[DST ONLY]
image1.jpg
image2.jpg
```

PCとAndroidの両方にあるがハッシュ(md5)が異なる場合

```
[HASH NOT MATCH]
file1.txt
```

### 送信

```
# composer run sync -- send /mnt/d/tmp/test 192.168.11.44:/storage/B42F-0FFA/test
```

Androidにないファイルのみ送信する

### 更新

```
# composer run sync -- update /mnt/d/tmp/test 192.168.11.44:/storage/B42F-0FFA/test
```

- Androidにないファイルを送信する。
- ハッシュが一致しないファイルを送信する。

### 同期

```
# composer run sync -- sync /mnt/d/tmp/test 192.168.11.44:/storage/B42F-0FFA/test
```

- Androidにないファイルを送信する。
- ハッシュが一致しないファイルを送信する。
- Androidしか存在しないファイルを削除する。

## 免責

開発者は、ソフトウェアの使用に関連するいかなる損害についても一切の責任を負いません。  
