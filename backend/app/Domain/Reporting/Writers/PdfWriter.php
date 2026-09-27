<?php

namespace App\Domain\Reporting\Writers;

use App\Domain\Reporting\ReportDocument;
use Barryvdh\DomPDF\Facade\Pdf;

/** Presentation-ready, branded per project. */
final class PdfWriter
{
    public function write(ReportDocument $document, string $absolutePath): void
    {
        $pdf = Pdf::loadView('reports.document', ['document' => $document])
            ->setPaper('a4', 'portrait')
            ->setOption('isRemoteEnabled', false)
            ->setOption('defaultFont', 'DejaVu Sans');

        file_put_contents($absolutePath, $pdf->output());
    }
}
