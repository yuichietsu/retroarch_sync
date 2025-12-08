## 概要

RetroArchのためにPCからFire TV Stickにゲームを転送するスクリプトです。以下のような問題を解決します。

- 手動でゲームをコピーするのは面倒
- PCのディレクトリをsambaでマウントできない
- rsyncが使用できない
- Fire TV StickのストレージにPCのゲーム全てを保存できない

⚠️ **警告** スクリプトは`rm -rf`などの破壊的なコマンドを使用するため、システムに損害を与える可能性があります。特にゲームを格納するコンテンツディレクトリは同期のたびにファイルを削除するため、設定ファイルやセーブファイルなどをこのディレクトリに保存しないでください。

## 前提条件

### 転送元 (PC)

スクリプトを実行するPCでは、次のコマンドが必要です。

- adb
- find
- rm
- zip (オプション)
  - 7z/zipファイルを展開してから転送する場合に必要（FDD、CD系は展開が必須）
  - 7zをzipに変換して転送する場合に必要（Lemuroidはzipのみ対応）
- 7z (オプション)
  - 7zファイルを展開してから転送する場合に必要（FDD、CD系は展開が必須）
- chdman (オプション)
  - cue/gdiなどのディスクイメージをchdに変換して転送する場合に必要
- ciso (オプション)
  - isoファイルをcso形式に変換して転送する場合に必要

このスクリプトはWindows環境のWSL (Windows Subsystem for Linux) での実行を想定しています。

### 転送先 (Fire TV Stick)

- 開発者オプションが有効になっている
- PCからの接続を許可している

## 使用方法

基本的な使い方の例：

```php
$sync = new \Menrui\AdbSyncRetroArch(
    '192.168.11.44:5555',   // Fire TV Stick
);

$sync->srcPath = '/mnt/d/files/roms/rebuild';                                // PCの転送元ディレクトリ（サブディレクトリに mame/、nes/ など）
$sync->dstPath = '/storage/B42F-0FFA/Android/data/com.retroarch/files/ROM';  // Fire TV Stickの転送先ディレクトリ
$sync->statesPaths = [                                                       // セーブデータの保存先
    '/storage/B42F-0FFA/RetroArch/states',
];
$sync->favoritesPaths = [                                                   // お気に入りの保存先
    '/storage/B42F-0FFA/RetroArch/content_favorites.lpl',
];

$sync->syncGames(
    [
        'mame'          => 'rand:4g,lock',       // 4GB以内でランダムに選出して転送。セーブ済みゲームは保持
        'nes'           => 'full:1g1r,excl(BIOS)', // 1G1Rで全て転送。BIOSは除外
        'psx'           => 'rand:4g,chd,disks',   // 4GB以内でchdに変換して転送。複数ディスクは一括
    ],
);
```

## 転送内容の具体例

### 例1: `'nes' => 'full:index,1g1r,excl(BIOS)'`

**転送元 (PC):**
```
/mnt/d/files/roms/rebuild/nes/
├── BIOS.zip
├── 1942.zip
├── Super Mario Bros. (USA).zip
├── Super Mario Bros. (Japan).zip
├── Pac-Man (USA).zip
├── Pac-Man (Europe).zip
└── Pac-Man (Japan).zip
```

**処理:**
1. `excl(BIOS)` : BIOS.zip を除外
2. `1g1r` : 複数バージョンから1つを選出（日本版を優先）
   - 1942.zip はバージョン1つ
   - Super Mario Bros. (Japan).zip を採用
   - Pac-Man (Japan).zip を採用
3. `index` : ファイル名の最初の1文字でインデックス化

**転送先 (Fire TV Stick):**
```
/storage/B42F-0FFA/RetroArch/ROM/nes/
├── 0-9/
│   └── 1942.zip
├── P/
│   └── Pac-Man (Japan).zip
└── S/
    └── Super Mario Bros. (Japan).zip
```

### 例2: `'psx' => 'rand:4g,chd,disks'`

**転送元 (PC):**
```
/mnt/d/files/roms/rebuild/psx/
├── Final Fantasy VII (Disk 1 of 3).7z        (各600MB程度)
├── Final Fantasy VII (Disk 2 of 3).7z
├── Final Fantasy VII (Disk 3 of 3).7z
├── Metal Gear Solid.7z                       (1.2GB、単一ディスク)
├── Resident Evil (Disk 1 of 3).7z            (各300MB程度)
├── Resident Evil (Disk 2 of 3).7z
├── Resident Evil (Disk 3 of 3).7z
└── Chrono Cross.7z                           (1.5GB、単一ディスク)
```

**処理:**
1. `rand:4g` : 合計容量4GB以内でランダムに選出
   - disks オプション有効時：複数ディスクで構成されるゲーム（ファイル名に「(Disk n of m)」パターンを持つ）は一括でカウント
   - Final Fantasy VII (3ファイル、1.8GB) 、Metal Gear Solid (1ファイル、1.2GB) 、Resident Evil (3ファイル、0.9GB) を選出 → 合計3.9GB
   - Chrono Cross (1ファイル、1.5GB) は容量超過のため除外
2. `chd` : 7zを展開してcue/binをCHD形式に変換（圧縮率向上）
3. `disks` : 複数ディスクで構成されるゲーム（ファイル名に「(Disk n of m)」パターンを持つ）のみ、ゲーム名ごとにm3uファイルを自動生成。単一ディスクゲームではm3uは生成しない

**転送先 (Fire TV Stick):**
```
/storage/B42F-0FFA/RetroArch/ROM/psx/
├── Final Fantasy VII (Disk 1 of 3)/
│   ├── Final Fantasy VII (Disk 1 of 3).chd
│   └── hash_XXXXXXXXXXXXXXXX                 (転送元ファイルのハッシュ値、再転送判定に使用)
├── Final Fantasy VII (Disk 2 of 3)/
│   ├── Final Fantasy VII (Disk 2 of 3).chd
│   └── hash_XXXXXXXXXXXXXXXX
├── Final Fantasy VII (Disk 3 of 3)/
│   ├── Final Fantasy VII (Disk 3 of 3).chd
│   └── hash_XXXXXXXXXXXXXXXX
├── Metal Gear Solid/
│   ├── Metal Gear Solid.chd
│   └── hash_XXXXXXXXXXXXXXXX
├── Resident Evil (Disk 1 of 3)/
│   ├── Resident Evil (Disk 1 of 3).chd
│   └── hash_XXXXXXXXXXXXXXXX
├── Resident Evil (Disk 2 of 3)/
│   ├── Resident Evil (Disk 2 of 3).chd
│   └── hash_XXXXXXXXXXXXXXXX
├── Resident Evil (Disk 3 of 3)/
│   ├── Resident Evil (Disk 3 of 3).chd
│   └── hash_XXXXXXXXXXXXXXXX
├── Final Fantasy VII.m3u                     (自動生成、複数ディスクを統合)
└── Resident Evil.m3u                         (自動生成、複数ディスクを統合)
```

### 例3: `'c64' => 'full:1g1r,excl(BIOS),rename(c64/games)'`

**転送元 (PC):**
```
/mnt/d/files/roms/rebuild/c64/
├── c64 BIOS (1982).zip
├── Boulder Dash (1984)(USA).zip
├── Boulder Dash (1984)(Japan).zip
├── Boulder Dash (1984)(Europe).zip
├── Pac-Man (1983)(USA).zip
├── Pac-Man (1983)(Japan).zip
└── Galaga (1983)(USA).zip
```

**処理:**
1. `excl(BIOS)` : c64 BIOS (1982).zip を除外
2. `1g1r` : 複数バージョンから1つを選出（日本版を優先）
   - Boulder Dash (1984)(Japan).zip を採用
   - Pac-Man (1983)(Japan).zip を採用
   - Galaga (1983)(USA).zip はバージョン1つ
3. `rename(c64/games)` : 転送先ディレクトリを c64/games に変更

**転送先 (Fire TV Stick):**
```
/storage/B42F-0FFA/RetroArch/ROM/c64/games/
├── Boulder Dash (1984)(Japan).zip
├── Pac-Man (1983)(Japan).zip
└── Galaga (1983)(USA).zip
```

## 設定

### `\Menrui\AdbSyncRetroArch` のメンバプロパティ

#### srcPath : string
転送元PC上のディレクトリ（必須）

#### dstPath : string
転送先Fire TV Stick上のディレクトリ（必須）

#### statesPaths : array
Fire TV Stick上のRetroArchのセーブファイル (state) 保存先ディレクトリ。セーブ済みゲームの削除ロック判定に使用します。

#### favoritesPaths : array
Fire TV Stick上のRetroArchのお気に入りファイル保存先。お気に入りに登録したゲームの削除ロック判定に使用します。

#### lockDays : int
セーブしたゲームをFire TV Stickから削除しない日数（デフォルト14）

#### retryCount : int
adbコマンド実行時の再試行回数。不安定なため再試行を実装しています（デフォルト5）

#### retrySleep : int
adbコマンド再試行前の待機時間 (秒)（デフォルト60）

### `syncGames()` メソッドの引数

連想配列のキーはsrcPathのサブディレクトリ、値は転送モードを指定します。値は以下のいずれかの形式で指定できます：

1. **文字列** 転送モードとオプションを指定
   ```php
   'mame' => 'rand:4g,lock'
   ```

2. **配列** 転送モードとオプションを配列で指定
   ```php
   'mame' => ['mode' => 'rand', 'size' => 4 * 1024 * 1024 * 1024, 'lock' => '*']
   ```

3. **コールバック関数** 転送対象のファイルを動的に決定
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
   - `AdbSyncRetroArch` オブジェクトを受け取ってオプションを動的に生成する関数
   - `parseOptions()` メソッドで文字列を配列に変換し、その配列を加工して返す関数

   また、MAMEのXMLファイルからゲーム情報を読み込む関数の例を以下に示します：
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

   `sanitizeAndroidFilename()` はAndroidのファイルシステムで有効なパスに変換する独自関数です。実装の詳細は省略しています。

#### 転送モード

##### full
PCのゲーム全てを転送するモード。PCに存在しないゲームはFire TV Stickから削除されます。

**指定可能なオプション:** ext, lock, zip, excl, incl, 1g1r, index, rename, official, list, disks, cmd, m3u, deps, clones, cso, chd

##### rand
PCのゲームをランダムに選出して転送するモード。選出されたゲーム以外はFire TV Stickから削除されます。

**指定可能なオプション:** (ゲーム数または容量), ext, lock, zip, excl, incl, 1g1r, index, rename, official, list, disks, cmd, m3u, deps, clones, cso, chd, 1f1z

#### 転送モードオプション

##### (ゲーム数) または (サイズ)
randモードで転送するゲーム数またはファイルの総容量を指定します。数字だけの場合はゲーム数、単位 (k, m, g) をつけた場合は容量として扱われます。

##### lock
通常、転送対象外のゲームはFire TV Stickから削除されますが、このオプションでセーブ済みゲームとお気に入りゲームを保護します。デフォルトでセーブから14日間保護されます。

##### 圧縮関連オプション
PC上の7z/zipファイルを展開してから転送します。圧縮関連オプションは以下の優先順位で適用されます：

###### distill
7z/zip内のファイルを展開してから、指定したファイルだけを再圧縮して転送します。ホワイトリストは連想配列で指定し、キーにファイル名 (拡張子なし)、値にホワイトリストファイルを指定します。このオプションは `syncGames()` では文字列として指定できません。主にMerged ROMセットからオリジナルROMセットのみを抽出する際に使用します。

###### zip
7zファイルをzipに再圧縮して転送します。

###### cso
7z/zip内のisoファイルをcso形式に圧縮して転送します。

###### chd
7z/zip内のcue/gid/isoファイルをchd形式に圧縮して転送します。ccdファイルはcueへの変換を試みてからchd化します。

###### ext
7z/zipファイルを展開して転送します。ファイルは拡張子を除いたディレクトリ名のフォルダ下に展開されます。

##### 1f1z
7z/zip内にファイルが1つだけの場合、指定した拡張子で再圧縮して転送します。圧縮形式は `1f1z(7z)` のように指定できます。デフォルトはzipです。

##### フィルターオプション
転送対象のファイルを制御するオプションです。

###### list
リストに含まれないファイルは転送されません。

###### incl
リストに含まれるファイルは優先して転送されます (randモードのみ適用)。

###### excl
リストに含まれるファイルは転送から除外されます。

###### official
ファイル名をチェックして公式版のみを転送します。unl、pirateなどのタグが付いているゲームは除外されます。

**フィルター優先順位:** list > official > excl > incl

##### ROMの依存関係とバリエーション
ROMの依存関係やバリエーションを管理するオプションです。

###### 1g1r
同じゲームの複数バージョンから1つだけを選出して転送します。優先順位は以下の通りです：

1. クラック版
2. 公式版
3. officialオプションで指定されたバージョン
4. その他のバージョン

ファイル名に含まれる国名、demo、betaなどの修飾子も考慮されます。国名では日本版が優先され、リビジョン番号 (rev 2など) は最新が優先されます。demo/betaなどが含まれるバージョンは優先度が低下します。

###### deps
依存するゲームを自動で転送します。フィルターオプションで除外されたゲームでも、依存ゲームは転送されます。このオプションは `syncGames()` では文字列として指定できません。配列で指定する場合は、以下の連想配列形式を使用します：
```php
$options['deps'] => [
    'child_game' => ['parent_game', 'bios'],
]
```
キーのゲームが依存するゲームを値に列挙します。

###### clones
Merged ROMセットでは、1つの圧縮ファイルにオリジナルとクローンが含まれます。クローンゲームを起動するには、同じディレクトリにクローンの空のzipファイルを配置します。このオプションは `syncGames()` では文字列として指定できません。配列で指定する場合は、以下の連想配列形式を使用します：
```php
$options['clones'] => [
    'merged_rom' => ['clone1', 'clone2'],
]
```
キーにMerged ROMセット名、値にクローン名の配列を指定します。

##### ディレクトリ管理
転送先のディレクトリ構造を制御するオプションです。

###### index
ゲームをアルファベット順にインデックス化して転送します。例: `nes/0-9/`、`nes/A/`、`nes/B/` など。インデックスの文字数は `index(2)` のように指定できます。未指定時は1文字です。

###### rename
転送先のディレクトリ名を変更します。括弧内に指定した文字列が転送先ディレクトリ名になります。

##### マルチディスク対応オプション
複数のディスクイメージを持つゲームのため、イメージファイルリストを生成します。

###### cmd
コマンドファイルを生成します。文字列で指定する場合は、括弧内にcmdファイルの先頭に記載するプログラム名を指定します。例：`cmd(np2kai)`。配列で指定する場合は、以下の連想配列形式を使用します：
```php
$options['cmd'] => [
    'exe'    => プログラム名,
    'title'  => cmdファイルのファイル名 (オプション),
    'disks'  => プログラムに渡すイメージファイルリスト (オプション),
]
```

###### m3u
m3uファイルを生成します。文字列で指定する場合、括弧内にオプションを指定する必要はありません。配列で指定する場合は、以下の連想配列形式を使用します：
```php
$options['m3u'] => [
    'title' => m3uファイルのファイル名 (オプション),
    'disks' => プログラムに渡すイメージファイルリスト (オプション),
]
```

###### disks
転送後、ファイル名からm3uを自動生成します。randモードでは、複数ディスクで構成されるゲームは一括転送されます。そのため、指定した容量やゲーム数を超える場合があります。  

## 免責事項

本ソフトウェアの使用に関連して生じたいかなる損害についても、開発者は一切の責任を負いません。

