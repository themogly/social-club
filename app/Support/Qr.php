<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/** QR PNG generation (via gd — no imagick). Used for member cards (embedded in email/PWA). */
class Qr
{
    /** Raw PNG bytes for the given payload. */
    public static function png(string $data, int $scale = 6): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => false,
            'scale' => $scale,
            'eccLevel' => EccLevel::M,
        ]);

        return (new QRCode($options))->render($data);
    }
}
