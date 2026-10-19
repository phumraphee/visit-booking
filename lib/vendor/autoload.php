<?php
// PSR-4 autoload ขนาดเล็กสำหรับ chillerlan/php-qrcode 5.0.5 + php-settings-container 3.3.0 
spl_autoload_register(function (string $class): void {
    foreach (['chillerlan\\QRCode\\' => 'php-qrcode/src/', 'chillerlan\\Settings\\' => 'php-settings-container/src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__ . '/chillerlan/' . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
