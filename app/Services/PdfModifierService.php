<?php

namespace App\Services;

use setasign\Fpdi\Tcpdf\Fpdi;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class PdfModifierService
{
    public function generate(
        string $sourcePath,
        array $headerFooter,
        string $cacheKey
    ): string {
        $cachedPath = "pdf-cache/{$cacheKey}.pdf";

        // ✅ Return cached PDF if exists
        if (Storage::exists($cachedPath)) {
            Log::info('Using cached PDF', ['cache_key' => $cacheKey]);
            return $cachedPath;
        }

        Log::info('Generating new PDF with headers/footers', [
            'source' => $sourcePath,
            'headers_footers' => $headerFooter,
            'cache_key' => $cacheKey
        ]);

        try {
            // Create new PDF instance
            $pdf = new Fpdi();
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetAutoPageBreak(false, 0);

            // Set source file
            $pageCount = $pdf->setSourceFile($sourcePath);

            Log::info("Processing {$pageCount} pages");

            for ($i = 1; $i <= $pageCount; $i++) {
                // Import page
                $tpl = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);

                // Add page with same dimensions as original
                $pdf->AddPage(
                    $size['orientation'],
                    [$size['width'], $size['height']]
                );

                // Use the imported page as template
                $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);

                // Set font for headers/footers
                $pdf->SetFont('helvetica', '', 10);
                $pdf->SetTextColor(100, 100, 100); // Gray color

                // Header left
                if (!empty($headerFooter['headerLeft'])) {
                    $pdf->SetXY(10, 5);
                    $pdf->Cell(0, 10, $headerFooter['headerLeft'], 0, 0, 'L');
                    Log::info("Added header left: {$headerFooter['headerLeft']}");
                }

                // Header right
                if (!empty($headerFooter['headerRight'])) {
                    $pdf->SetXY($size['width'] - 110, 5);
                    $pdf->Cell(100, 10, $headerFooter['headerRight'], 0, 0, 'R');
                    Log::info("Added header right: {$headerFooter['headerRight']}");
                }

                // Footer left
                if (!empty($headerFooter['footer'])) {
                    $pdf->SetXY(10, $size['height'] - 15);
                    $pdf->Cell(0, 10, $headerFooter['footer'], 0, 0, 'L');
                    Log::info("Added footer: {$headerFooter['footer']}");
                }

                // if ($includePageNumbers) {
                //     $pdf->SetXY($size['width'] - 110, $size['height'] - 15);
                //     $pdf->Cell(100, 10, "Page {$i} of {$pageCount}", 0, 0, 'R');
                // }
            }

            // Generate PDF content
            $pdfContent = $pdf->Output('', 'S');

            // Ensure cache directory exists
            Storage::makeDirectory('pdf-cache');

            // Save to storage
            Storage::put($cachedPath, $pdfContent);

            Log::info('PDF generated and cached successfully', [
                'cache_path' => $cachedPath,
                'size' => strlen($pdfContent)
            ]);

            return $cachedPath;
        } catch (\Exception $e) {
            Log::error('PDF generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
