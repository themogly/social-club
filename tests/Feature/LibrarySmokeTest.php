<?php

namespace Tests\Feature;

use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Proves each domain library — and its underlying binary/extension — actually
 * produces output on THIS machine, so a broken/missing dependency surfaces on
 * day one instead of on prompt 16. (PDF, XLSX, QR-PNG, image/WebP.)
 */
class LibrarySmokeTest extends TestCase
{
    public function test_dompdf_renders_a_pdf(): void
    {
        $pdf = Pdf::loadHTML('<h1>Prueba de documento</h1>')->output();

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_openspout_writes_an_xlsx(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'smoke_').'.xlsx';

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Socio', 'Aportación (€)']));
        $writer->addRow(Row::fromValues(['María García', '12,50']));
        $writer->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        $this->assertStringStartsWith('PK', $bytes); // XLSX is a zip container
    }

    public function test_chillerlan_renders_a_png_qr_via_gd(): void
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => false,
        ]);

        $png = (new QRCode($options))->render('csc://member/01J000000000000000000000');

        $this->assertStringStartsWith("\x89PNG", $png);
    }

    public function test_intervention_crops_and_encodes_webp_via_gd(): void
    {
        $manager = new ImageManager(new Driver);

        $webp = $manager->decodePath(resource_path('mail/logo.png'))
            ->cover(48, 48)
            ->encode(new WebpEncoder)
            ->toString();

        $this->assertStringStartsWith('RIFF', $webp);
        $this->assertSame('WEBP', substr($webp, 8, 4));
    }
}
