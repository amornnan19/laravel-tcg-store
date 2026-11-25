<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Renders a payload as a QR code: inline SVG for the page, PNG for the download.
 */
final class QrImage
{
    public static function svg(string $payload): string
    {
        return (string) (new QRCode(self::options(QROutputInterface::MARKUP_SVG)))->render($payload);
    }

    public static function png(string $payload): string
    {
        return (string) (new QRCode(self::options(QROutputInterface::GDIMAGE_PNG, scale: 10, quietZone: 4)))->render($payload);
    }

    private static function options(string $outputType, int $scale = 5, int $quietZone = 2): QROptions
    {
        return new QROptions([
            'outputType' => $outputType,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'scale' => $scale,
            'quietzoneSize' => $quietZone,
            'drawLightModules' => false,
            'addQuietzone' => true,
        ]);
    }
}
