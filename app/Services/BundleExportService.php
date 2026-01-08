<?php

namespace App\Services;

use App\Models\Bundle;
use setasign\Fpdi\Tcpdf\Fpdi;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class BundleExportService
{
    public function __construct(
        private PdfModifierService $pdfModifier
    ) {}

    /**
     * Export all documents in a bundle as a single PDF
     */
    public function exportBundle(
        Bundle $bundle,
        bool $includeIndex = true,
        ?array $indexEntries = null,
        ?array $highlights = null
    ): string {
        Log::info('Starting bundle export', [
            'bundle_id' => $bundle->id,
            'include_index' => $includeIndex
        ]);

        try {
            $pdf = new Fpdi();
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetAutoPageBreak(false, 0);

            $globalPageNumber = 1;
            $filePageMapping = [];

            // Add index page if requested
            if ($includeIndex && !empty($indexEntries)) {
                $this->addIndexPage($pdf, $indexEntries);
                $globalPageNumber++;
            }

            // Get documents in order
            $documents = $bundle->documents()
                ->where('parent_id', null)
                ->orderBy('order')
                ->get();

            // Process each document
            foreach ($documents as $document) {
                $startPage = $globalPageNumber;
                
                $pageCount = $this->addDocumentPages(
                    $pdf, 
                    $document, 
                    $bundle,
                    $globalPageNumber
                );

                $filePageMapping[$document->id] = [
                    'start' => $startPage,
                    'end' => $startPage + $pageCount - 1
                ];

                $globalPageNumber += $pageCount;
            }

            // Add highlights if provided
            if (!empty($highlights)) {
                $this->addHighlights($pdf, $highlights, $filePageMapping);
            }

            // Generate and save PDF
            $filename = $this->generateFilename($bundle);
            $path = "exports/{$filename}";
            
            Storage::put($path, $pdf->Output('', 'S'));

            Log::info('Bundle export completed', [
                'bundle_id' => $bundle->id,
                'path' => $path,
                'pages' => $globalPageNumber - 1
            ]);

            return $path;
        } catch (\Exception $e) {
            Log::error('Bundle export failed', [
                'bundle_id' => $bundle->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function addDocumentPages(
        Fpdi $pdf,
        $document,
        Bundle $bundle,
        int &$globalPageNumber
    ): int {
        if (!Storage::exists($document->storage_path)) {
            Log::warning("Document file not found: {$document->storage_path}");
            return 0;
        }

        $sourcePath = Storage::path($document->storage_path);
        
        // Get modified PDF with headers/footers
        $metadata = $bundle->metadata ?? [];
        $headerFooter = [
            'headerLeft' => $metadata['header_left'] ?? '',
            'headerRight' => $metadata['header_right'] ?? '',
            'footer' => $metadata['footer'] ?? '',
        ];

        $cacheKey = md5($document->id . json_encode($headerFooter));
        $modifiedPath = $this->pdfModifier->generate(
            $sourcePath,
            $headerFooter,
            $cacheKey
        );

        // Import modified PDF
        $fullPath = Storage::path($modifiedPath);
        $pageCount = $pdf->setSourceFile($fullPath);

        for ($i = 1; $i <= $pageCount; $i++) {
            $tpl = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($tpl);

            $pdf->AddPage(
                $size['orientation'],
                [$size['width'], $size['height']]
            );

            $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);

            // Add page number overlay
            $totalPages = $this->calculateTotalPages($bundle);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->SetXY($size['width'] - 110, $size['height'] - 15);
            $pdf->Cell(100, 10, "Page {$globalPageNumber} of {$totalPages}", 0, 0, 'R');

            $globalPageNumber++;
        }

        return $pageCount;
    }

    private function addIndexPage(Fpdi $pdf, array $indexEntries): void
    {
        // Generate index page
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 20, 'Index', 0, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 10);
        foreach ($indexEntries as $entry) {
            $pdf->Cell(0, 8, $entry['title'] . ' - Page ' . $entry['page'], 0, 1);
        }
    }

    private function addHighlights(
        Fpdi $pdf,
        array $highlights,
        array $filePageMapping
    ): void {
        // Implement highlight overlay logic
        // This would require reopening the PDF and adding rectangles
        // Similar to the frontend logic but on backend
    }

    private function calculateTotalPages(Bundle $bundle): int
    {
        return $bundle->documents()
            ->where('parent_id', null)
            ->get()
            ->sum(function ($doc) {
                if (!Storage::exists($doc->storage_path)) {
                    return 0;
                }
                
                try {
                    $pdf = new Fpdi();
                    return $pdf->setSourceFile(Storage::path($doc->storage_path));
                } catch (\Exception $e) {
                    return 0;
                }
            });
    }

    private function generateFilename(Bundle $bundle): string
    {
        $name = $bundle->name ?? 'bundle';
        $timestamp = now()->format('Y-m-d_His');
        return "{$name}_{$timestamp}.pdf";
    }
}