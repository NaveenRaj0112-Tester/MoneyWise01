<?php
$logo = dirname(__DIR__, 2) . '/Mlogo/MoneywiseLOGO.png';
if (!file_exists($logo)) { echo "Logo not found\n"; exit(1); }

$sizes = [72, 96, 128, 144, 152, 192, 384, 512];
$outDir = __DIR__;

foreach ($sizes as $size) {
    $out = "$outDir/icon-{$size}.png";
    if (file_exists($out)) continue;

    $im = imagecreatetruecolor($size, $size);
    $bg = imagecolorallocate($im, 124, 58, 237);
    imagefilledrectangle($im, 0, 0, $size - 1, $size - 1, $bg);

    $src = imagecreatefrompng($logo);
    $sw = imagesx($src);
    $sh = imagesy($src);
    $pad = (int)($size * 0.15);
    $dw = $size - ($pad * 2);
    $dh = $size - ($pad * 2);
    imagecopyresampled($im, $src, $pad, $pad, 0, 0, $dw, $dh, $sw, $sh);

    imagepng($im, $out);
    imagedestroy($im);
    imagedestroy($src);
    echo "Created icon-{$size}.png\n";
}
echo "Done!\n";
