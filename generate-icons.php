<?php
/**
 * Generador de iconos PNG para PWA
 * Ejecutar UNA vez: php generate-icons.php
 * Requiere: extensión GD de PHP
 */

$sizes = [192, 512];
$outputDir = __DIR__ . '/assets/icons';

foreach ($sizes as $size) {
    $img = imagecreatetruecolor($size, $size);

    // Antialiasing
    imagealphablending($img, true);
    imagesavealpha($img, true);

    // Colors
    $blue = imagecolorallocate($img, 37, 99, 235);    // #2563eb
    $white = imagecolorallocate($img, 255, 255, 255);
    $lightBlue = imagecolorallocate($img, 96, 165, 250); // decorative

    // Background
    imagefilledrectangle($img, 0, 0, $size, $size, $blue);

    // Rounded corners simulation - fill corners with transparency
    $radius = (int)($size * 0.19); // ~96/512
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);

    // Draw rounded rectangle by filling corners
    imagefilledellipse($img, $radius, $radius, $radius * 2, $radius * 2, $blue);
    imagefilledellipse($img, $size - $radius, $radius, $radius * 2, $radius * 2, $blue);
    imagefilledellipse($img, $radius, $size - $radius, $radius * 2, $radius * 2, $blue);
    imagefilledellipse($img, $size - $radius, $size - $radius, $radius * 2, $radius * 2, $blue);

    // Text "QM"
    $fontSize = (int)($size * 0.35);
    $font = null;

    // Try to find a system font
    $fontPaths = [
        'C:/Windows/Fonts/arialbd.ttf',
        'C:/Windows/Fonts/arial.ttf',
        'C:/Windows/Fonts/segoeui.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/data/data/com.termux/files/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
    ];

    foreach ($fontPaths as $path) {
        if (file_exists($path)) {
            $font = $path;
            break;
        }
    }

    if ($font) {
        // Use TrueType font
        $bbox = imagettfbbox($fontSize, 0, $font, 'QM');
        $textWidth = $bbox[2] - $bbox[0];
        $textHeight = $bbox[1] - $bbox[7];
        $x = (int)(($size - $textWidth) / 2) - $bbox[0];
        $y = (int)(($size - $textHeight) / 2) + $textHeight - (int)($size * 0.05);
        imagettftext($img, $fontSize, 0, $x, $y, $white, $font, 'QM');
    } else {
        // Fallback: built-in font (smaller but works everywhere)
        $builtinFont = 5; // largest built-in
        $textWidth = imagefontwidth($builtinFont) * 2;
        $textHeight = imagefontheight($builtinFont);
        $x = (int)(($size - $textWidth) / 2);
        $y = (int)(($size - $textHeight) / 2);
        imagestring($img, $builtinFont, $x, $y, 'QM', $white);
    }

    // Decorative lines below text
    $lineY = (int)($size * 0.67);
    $lineLeft = (int)($size * 0.16);
    $lineRight = (int)($size * 0.84);
    imagefilledrectangle($img, $lineLeft, $lineY, $lineRight, $lineY + (int)($size * 0.012), $lightBlue);

    // Small dots
    $dotY1 = (int)($size * 0.74);
    $dotY2 = (int)($size * 0.82);
    $dotR = (int)($size * 0.03);
    imagefilledellipse($img, (int)($size * 0.27), $dotY1, $dotR * 2, $dotR * 2, $lightBlue);
    imagefilledrectangle($img, (int)($size * 0.33), $dotY1 - $dotR, (int)($size * 0.72), $dotY1 + $dotR, $lightBlue);
    imagefilledellipse($img, (int)($size * 0.27), $dotY2, $dotR * 2, $dotR * 2, $lightBlue);
    imagefilledrectangle($img, (int)($size * 0.33), $dotY2 - $dotR, (int)($size * 0.64), $dotY2 + $dotR, $lightBlue);

    // Save
    $filename = "{$outputDir}/icon-{$size}.png";
    imagepng($img, $filename);
    imagedestroy($img);
    echo "Generado: {$filename}\n";
}

echo "\nIconos generados exitosamente.\n";
echo "Puedes eliminar este archivo (generate-icons.php) despues de ejecutarlo.\n";
