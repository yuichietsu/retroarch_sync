## 目次

- [概要](#概要)
- [前提条件](#前提条件)
  - [転送元(PC)](#転送元pc)
  - [転送先(Fire TV Stick)](#転送先fire-tv-stick)
- [使用方法](#使用方法)
- [設定](#設定)
  - [`\Menrui\AdbSyncRetroArch`のメンバプロパティ](#menruiadbsyncretroarchのメンバプロパティ)
  - [`syncGames()`メソッドの引数](#syncgamesメソッドの引数)
    - [転送モード](#転送モード)
    - [転送モードオプション](#転送モードオプション)
      - [圧縮関連オプション](#圧縮関連オプション)
      - [フィルターオプション](#フィルターオプション)
      - [ROMの依存とバリエーション](#romの依存とバリエーション)
      - [ディレクトリ管理](#ディレクトリ管理)
      - [イメージインデックスファイル](#イメージインデックスファイル)
- [免責](#免責)

## 概要

RetroArchのためにPCからFire TV Stickにゲームを転送するためのスクリプトです。   
次のような問題点を解決するためのスクリプトです。

- 手動でゲームをコピーするのは面倒です
- PCのディレクトリをsmabaでマウントできません
- rsyncは使えません
- Fire TV StickのストレージにはPCに保存している全部のゲームは入りません

スクリプト内部では`rm -rf`などのコマンドを使うのでシステムを破壊する可能性があります。  
特にゲームを格納するコンテンツディレクトリは同期のたびにファイルを削除するため、設定ファイルやセーブファイルなどを保存しないようにしてください。

## 前提条件

### 転送元(PC)

スクリプトを実行するPCでは、次のコマンドを使用します。

- adb
- find
- rm
- zip (オプション)
  - PCのzipを展開してから転送したい場合に必要です（FDDとかCD系は展開していないとうまく動きません）
  - PCの7zをzipに変換して転送したい場合に必要です（Lemuroidがzipのみサポートしているので実装してみました）
- 7z (オプション)
  - PCの7zを展開してから転送したい場合に必要です（FDDとかCD系は展開していないとうまく動きません）
- chdman (オプション)
  - cue/gdiなどのディスクイメージをchdに変換して転送する場合に必要です
- ciso (オプション)
  - cso形式に変換して転送する場合に必要です

転送元のスクリプトは、WindowsのWLSで動作させる前提で作っています。

### 転送先(Fire TV Stick)

- 開発者オプションが有効になっています
- 転送元のPCを接続を許可しています

## 使用方法

使い方の一例を記載します。

```php
$sync = new \Menrui\AdbSyncRetroArch(
    '192.168.11.44:5555',   // Fire TV Stick
);

$sync->srcPath = '/mnt/d/files/roms/rebuild';                                // PCの転送元のディレクトリ、サブディレクトリにmame/とかnes/とかあります
$sync->dstPath = '/storage/B42F-0FFA/Android/data/com.retroarch/files/ROM';  // Fire TV Stickの転送先のディレクトリ
$sync->statesPaths = [                                                       // セーブデータの保存先
    '/storage/B42F-0FFA/RetroArch/states',
];
$sync->favoritesPaths = [                                                   // お気に入りの保存先
    '/storage/B42F-0FFA/RetroArch/content_favorites.lpl',
];

$sync->syncGames(
    [
        'mame'          => 'rand:4g,lock',       // 4GBを超えない範囲でランダムに選び出して送ります。セーブしたゲームは削除しません
        'nes'           => 'full:1g1r,excl(BIOS)', // 1G1Rで全部送ります。BIOSは除外します
        'psx'           => 'rand:4g,chd,disks',   // 4GBを超えない範囲でchdに変換してランダムで送ります。複数枚のディスクはまとめて送ります
    ],
);
```

## 設定

### `\Menrui\AdbSyncRetroArch`のメンバプロパティ

#### srcPath : string
転送元のPCのディレクトリ（必須）

#### dstPath : string
転送先のFire TV Stickのディレクトリ（必須）

#### statesPaths : array
Fire TV StickのRetroArchのstateファイルの保存先のディレクトリです。セーブしたゲームはFire TV Stickから削除しないようにロックする判定に使用します。

#### favoritesPaths : array
Fire TV StickのRetroArchのお気に入りファイルの保存先です。お気に入りに登録したゲームはFire TV Stickから削除しないようにロックする判定に使用します。

#### lockDays : int
セーブしたゲームをFire TV Stickから削除しない日数（デフォルト14）

#### retryCount : int
adbでコマンドを実行するとき、たまに失敗するみたいなので、再試行する回数（デフォルト5）

#### retrySleep : int
adbのコマンドの再試行をする前に待機する秒数（デフォルト60）

### `syncGames()`メソッドの引数

連想配列のキーはsrcPathのサブディレクトリ、バリューは転送モードを指定します。
バリューは以下のいずれかの形式で指定できます：

1. 文字列: 転送モードとオプションを指定
   ```php
   'mame' => 'rand:4g,lock'
   ```

2. 配列: 転送モードとオプションを配列で指定
   ```php
   'mame' => ['mode' => 'rand', 'size' => 4 * 1024 * 1024 * 1024, 'lock' => '*']
   ```

3. コールバック関数: 転送対象のファイルを動的に決定
   ```php
   'pc98' => function ($sync)
   {
       $options = $sync->parseOptions('full:ext,cmd(np2kai),1f1z(7z),index,rename(pc98/fdd)');

       if ($data = readMameXml('/mnt/d/files/roms/dats/pc98.xml')) {
           $options['cmd'] = [...$options['cmd'], ...$data];
           $options['excl'] = $data['clones'];
       }
       return $options;
   }
   ```

   コールバック関数は以下のいずれかの形式で指定できます：
   - `AdbSyncRetroArch`オブジェクトを受け取り、オプションを動的に生成する関数
   - `parseOptions()`メソッドを使用して文字列の指定を配列に変換し、その配列を加工して返す関数

   また、MAMEのXMLファイルからゲーム情報を読み込む関数の例：
   ```php
   function readMameXml($datFile)
   {
       $data = null;
       if (file_exists($datFile)) {
           $xml = simplexml_load_file($datFile);
           foreach ($xml->software as $sw) {
               $name  = (string)$sw['name'];
               $clone = $sw['cloneof'] ?? false;
               $title = (string)$sw->description;
               foreach ($sw->info as $info) {
                   if ((string)$info['name'] === 'alt_title') {
                       $title = (string)$info['value'];
                       break;
                   }
               }
               $disks = [];
               foreach ($sw->part as $part) {
                   $disks[] = (string)$part->dataarea->rom['name'];
               }
               $title && ($data['title'][$name] = sanitizeAndroidFilename($title));
               $disks && ($data['disks'][$name] = $disks);
               $clone && ($data['clones'][] = $name);
           }
       }
       return $data;
   }
   ```

   `sanitizeAndroidFilename()`はAndroidのファイルシステムで利用できるパスに変換する独自の関数です。実装の詳細は省略します。

#### 転送モード

##### full
PCのゲームを全て転送するモードです。  
PCにないゲームはFire TV Stickから削除されます。  
指定可能なオプション: ext, lock, zip, excl, incl, 1g1r, index, rename, official, list, disks, cmd, m3u, deps, clones, cso, chd

##### rand
PCのゲームをランダムで選出して転送するモードです。  
ランダムで選ばれた以外のゲームはFire TV Stickから削除されます。  
指定可能なオプション: ((ゲーム数)または(サイズ)), ext, lock, zip, excl, incl, 1g1r, index, rename, official, list, disks, cmd, m3u, deps, clones, cso, chd, 1f1z

#### 転送モードオプション

##### (ゲーム数), (サイズ)
randモードの場合に、転送するゲーム数またはファイルの総サイズ数を指定します。  
数字だけの場合はゲーム数、単位（k, m, g）をつけた場合はサイズとして扱われます。

##### lock
通常、転送対象ではないゲームはFire TV Stickから削除されますが、ステートセーブしたゲームとお気に入りに登録したゲームは削除しないようにします。  
デフォルトでセーブしてから14日間保護します。

##### 圧縮関連オプション
PCの7z/zipファイルを展開してから転送します。  
圧縮関連オプションの適用優先順位は以下の通りです：

###### distill
7z/zip内のファイルを展開してから、指定したファイルだけを圧縮し直して転送します。  
ホワイトリストは連想配列で指定し、キーにファイル名（拡張子なし）、バリューにホワイトリストのファイルを指定します。  
このオプションは`syncGames()`では文字列として指定できません。  
主にMAMEのMerged ROMセットからオリジナルのROMセットのみに作り直すために使用します。

###### zip
7zのファイルをzipに圧縮しなおして転送します。

###### cso
7z/zip内のisoファイルをcsoに圧縮してから転送します。

###### chd
7z/zip内のcue/gid/isoファイルをchdに圧縮してから転送します。  
ccdの場合はcueに変換を試みてからchdに圧縮します。

###### ext
7z/zipファイルを展開してから転送します。  
拡張子なし名前のディレクトリの下にファイルを展開します。

##### 1f1z
PCで7z/zip内のファイルが1つだけの場合は、そのファイルを指定した拡張子で圧縮し直して転送します。  
圧縮形式は`1f1z(7z)`のように指定できます。デフォルトはzipです。

##### フィルターオプション
転送対象のファイルを制御するためのオプションです。

###### list
リストに含まれないファイルは転送されません。

###### incl
リストに含まれるファイルは優先して転送されます（randモードの場合のみ適用）。

###### excl
リストに含まれるファイルは転送から除外されます。

###### official
ファイル名をチェックして、公式版のみを転送します。  
unl, pirateなどのタグが付いているゲームは除外します。

優先順位は list > official > excl > incl の順で適用されます。

##### ROMの依存とバリエーション
ROMの依存関係やバリエーションを管理するためのオプションです。

###### 1g1r
同じゲームの複数のバージョンがある場合、1つだけを選んで転送します。  
優先順位は以下のような順序で判定します：
1. クラック版
2. 公式版
3. officialオプションで指定されたバージョン
4. その他のバージョン

また、ファイル名に含まれる国名やdemoなどの文字列も考慮して判定します。
国名は日本版を優先し、リビジョン番号（rev 2など）は最新のものを優先します。
demoやbetaなどの文字列が含まれるバージョンは優先度が低くなります。

###### deps
依存するゲームを自動で転送します。  
フィルターオプションから外れているゲームでも、依存するゲームは転送されます。  
このオプションは`syncGames()`では文字列として指定できません。  
配列で指定する場合は、次の連想配列で指定します：
```php
$options['deps'] => [
    'child_game' => ['parent_game', 'bios'],
]
```
キーのゲームが依存するゲームをバリューに列挙します。

###### clones
Merged ROMセットの場合、一つの圧縮ファイルにオリジナルとクローンがまとめて入っていますが、クローンの空のzipを同じディレクトリに置いておくことで、クローンゲームを起動することができます。  
このオプションは`syncGames()`では文字列として指定できません。  
配列で指定する場合は、次の連想配列で指定します：
```php
$options['clones'] => [
    'merged_rom' => ['clone1', 'clone2'],
]
```
キーにMerged ROMセット名、バリューにクローンの配列を指定します。

##### ディレクトリ管理
転送先のディレクトリ構造を制御するためのオプションです。

###### index
ゲームをアルファベット順にインデックス化して転送します。  
例: nes/0-9/, nes/A/, nes/B/, ...  
インデックスに使用する文字数は`index(2)`のように指定できます。未指定の場合は1文字です。

###### rename
転送先のディレクトリ名を変更します。  
カッコ内に指定した文字列が転送先のディレクトリ名となります。

##### イメージインデックスファイル
複数のディスクイメージを持つゲームのために、イメージファイルのリストを生成します。

###### cmd
コマンドファイルを生成します。  
文字列で指定する場合は、カッコ内にcmdファイルの先頭に記載するプログラム名を指定します。  
例: cmd(np2kai)  
配列で指定する場合は、次の連想配列で指定します：
```php
$options['cmd'] => [
    'exe' => プログラム名,
    'title' => cmdファイルのファイル名（オプション）,
    'disks' => プログラムに渡すイメージファイルリスト（オプション）,
]
```

###### m3u
m3uファイルを生成します。  
文字列で指定する場合は、カッコ内にオプションを指定することはありません。  
配列で指定する場合は、次の連想配列で指定します：
```php
$options['m3u'] => [
    'title' => m3uファイルのファイル名（オプション）,
    'disks' => プログラムに渡すイメージファイルリスト（オプション）,
]
```

###### disks
転送後にファイル名から推測してm3uを自動で生成します。  
randモードの場合、送信元のファイル名から複数枚で構成されるゲームはまとめて送信します。  
まとめた結果randで指定した数やサイズを超えることがあります。  

## 免責

開発者は、ソフトウェアの使用に関連するいかなる損害についても一切の責任を負いません。

