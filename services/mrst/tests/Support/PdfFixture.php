<?php

namespace Tests\Support;

use FPDF;

class PdfFixture
{
    /**
     * @param  list<string>  $lines
     */
    public static function withText(array $lines): string
    {
        require_once __DIR__.'/../../vendor/setasign/fpdf/fpdf.php';

        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 14);
        foreach ($lines as $line) {
            $pdf->Cell(0, 10, $line);
            $pdf->Ln(10);
        }

        return $pdf->Output('S');
    }

    public static function withoutText(): string
    {
        require_once __DIR__.'/../../vendor/setasign/fpdf/fpdf.php';

        $pdf = new FPDF;
        $pdf->AddPage();

        return $pdf->Output('S');
    }
}
