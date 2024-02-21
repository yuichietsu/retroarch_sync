<?php

namespace Menrui;

# [references]
# https://github.com/Meetsch/ccd2cue
# https://www.gnu.org/software/ccd2cue/manual/html_node/CCD-sheet-format.html
# https://www.gnu.org/software/ccd2cue/manual/html_node/CUE-sheet-format.html

class CCD2CUE
{
    public const IMAGE_EXTS = ['img', 'bin', 'iso'];

    public function convert(string $ccdPath, ?string $cuePath = null): void
    {
        if (!file_exists($ccdPath)) {
            throw new Exception("ccd file not found: $ccdPath");
        }

        $imgPath = null;
        $baseName = preg_replace('/\\.ccd$/', '', $ccdPath);
        foreach (self::IMAGE_EXTS as $ext) {
            if (file_exists("$baseName.$ext")) {
                $imgPath = "$baseName.$ext";
                break;
            }
        }
        if ($imgPath === null) {
            throw new Exception("image file not found: $baseName.(img|bin|iso)");
        }

        if ($cuePath === null) {
            $cuePath = "$baseName.cue";
        }

        $ccd = parse_ini_file($ccdPath, true);

        $zplba = false;
        $track = 1;
        $cue[] = sprintf("FILE \"%s\" BINARY\r\n", basename($imgPath));
        foreach ($ccd as $k => $v) {
            if (preg_match('/^Entry (\\d+)$/', $k)) {
                if (!$zplba && ($v['PLBA'] ?? false) !== '0') {
                    continue;
                }
                $zplba = true;

                $type  = ($v['Control'] ?? null) === '0x04' ? 'MODE1/2352' : 'AUDIO';
                $cue[] = sprintf("  TRACK %02d %s\r\n", $track, $type);

                $session = $v['Session'] ?? 1;

                $idx = [];
                foreach (['PMin', 'PSec', 'PFrame'] as $in) {
                    if (!array_key_exists($in, $v)) {
                        throw new Exception("[$k] $in not found");
                    }
                    $idx[$in] = (int)$v[$in];
                }
                if ($idx['PSec'] < 2) {
                    if ($idx['PMin'] === 0) {
                        throw new Exception("[$k] PSec must be greater than 2, if PMin is 0");
                    }
                    $idx['PMin']--;
                    $idx['PSec'] = 60;
                }
                $idx['PSec'] -= 2;
                $cue[] = sprintf(
                    "    INDEX %02d %02d:%02d:%02d\r\n",
                    $session,
                    $idx['PMin'],
                    $idx['PSec'],
                    $idx['PFrame']
                );
                $track++;
            }
        }
        file_put_contents($cuePath, implode($cue));
    }
}
