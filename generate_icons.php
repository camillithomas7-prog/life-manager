<?php
// Genera icone PWA stile gradient indigo
function makeIcon($size, $maskable = false) {
  $img = imagecreatetruecolor($size, $size);
  imagesavealpha($img, true);

  $padding = $maskable ? round($size * 0.1) : 0;
  $inner = $size - $padding * 2;

  // Sfondo: gradient simulato con strisce
  for ($y = 0; $y < $size; $y++) {
    $t = $y / $size;
    $r = (int)(79 + (124 - 79) * $t);
    $g = (int)(70 + (58 - 70) * $t);
    $b = (int)(229 + (237 - 229) * $t);
    $col = imagecolorallocate($img, $r, $g, $b);
    imageline($img, 0, $y, $size, $y, $col);
  }

  // Pallino bianco al centro (logo "●" stilizzato)
  $white = imagecolorallocatealpha($img, 255, 255, 255, 0);
  $cx = $size / 2;
  $cy = $size / 2;
  $d = $inner * 0.55;
  imagefilledellipse($img, $cx, $cy, $d, $d, $white);

  // Cerchio interno indigo
  $indigo = imagecolorallocate($img, 79, 70, 229);
  imagefilledellipse($img, $cx, $cy, $d * 0.55, $d * 0.55, $indigo);

  return $img;
}

foreach ([192, 512] as $size) {
  $img = makeIcon($size);
  imagepng($img, __DIR__ . "/assets/icon-{$size}.png");
  imagedestroy($img);
  echo "+ icon-{$size}.png\n";
}

// Maskable (con safe area extra)
$img = makeIcon(512, true);
imagepng($img, __DIR__ . "/assets/icon-maskable-512.png");
imagedestroy($img);
echo "+ icon-maskable-512.png\n";

// Apple touch icon (no transparency)
$img = makeIcon(180);
imagepng($img, __DIR__ . "/assets/apple-touch-icon.png");
imagedestroy($img);
echo "+ apple-touch-icon.png\n";

// Favicon ICO surrogate (PNG)
$img = makeIcon(64);
imagepng($img, __DIR__ . "/assets/favicon.png");
imagedestroy($img);
echo "+ favicon.png\n";

echo "\n✓ Icone generate\n";
