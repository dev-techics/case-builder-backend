<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Document;
use setasign\Fpdi\Tcpdf\Fpdi;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class BundleExportService
{
    public function __construct(
        private PdfModifierService $pdfModifier,
        private IndexGenerationService $indexService
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

            // Add index pages if requested
            if ($includeIndex) {
                $indexPageCount = $this->addIndexPages($pdf, $bundle, $indexEntries);
                $globalPageNumber += $indexPageCount;
            }

            // Get total pages for footer
            $totalPages = $this->calculateTotalPages($bundle);

            // Get root-level documents in order
            $documents = $bundle->documents()
                ->whereNull('parent_id')
                ->orderBy('order')
                ->get();

            // Process each document recursively
            foreach ($documents as $document) {
                $pageCount = $this->processDocument(
                    $pdf,
                    $document,
                    $bundle,
                    $globalPageNumber,
                    $totalPages,
                    $filePageMapping
                );

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
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Process document recursively (handles folders and files)
     */
    private function processDocument(
        Fpdi $pdf,
        Document $document,
        Bundle $bundle,
        int &$globalPageNumber,
        int $totalPages,
        array &$filePageMapping
    ): int {
        $totalPageCount = 0;

        // If it's a folder, process children recursively
        if ($document->type === 'folder') {
            Log::info('Processing folder', [
                'folder_id' => $document->id,
                'folder_name' => $document->name
            ]);

            $children = $document->children()
                ->orderBy('order')
                ->get();

            foreach ($children as $child) {
                $pageCount = $this->processDocument(
                    $pdf,
                    $child,
                    $bundle,
                    $globalPageNumber,
                    $totalPages,
                    $filePageMapping
                );

                $totalPageCount += $pageCount;
            }

            return $totalPageCount;
        }

        // It's a file - add its pages
        $startPage = $globalPageNumber;
        
        $pageCount = $this->addDocumentPages(
            $pdf,
            $document,
            $bundle,
            $globalPageNumber,
            $totalPages
        );

        if ($pageCount > 0) {
            $filePageMapping[$document->id] = [
                'start' => $startPage,
                'end' => $startPage + $pageCount - 1,
                'count' => $pageCount
            ];

            Log::info('Document added to export', [
                'document_id' => $document->id,
                'name' => $document->name,
                'pages' => $pageCount,
                'start_page' => $startPage
            ]);
        }

        return $pageCount;
    }

    /**
     * Add index pages to PDF
     */
    private function addIndexPages(
        Fpdi $pdf,
        Bundle $bundle,
        ?array $indexEntries
    ): int {
        try {
            // Generate or get existing index
            $indexPath = null;
            
            if ($indexEntries) {
                // Use provided entries
                $indexPath = $this->indexService->getIndexPath($bundle);
            }
            
            if (!$indexPath) {
                // Generate new index
                $indexPath = $this->indexService->generateIndex($bundle);
            }

            if (!$indexPath || !Storage::exists($indexPath)) {
                Log::warning('No index available for export', [
                    'bundle_id' => $bundle->id
                ]);
                return 0;
            }

            // Import index pages
            $fullPath = Storage::path($indexPath);
            $pageCount = $pdf->setSourceFile($fullPath);

            for ($i = 1; $i <= $pageCount; $i++) {
                $tpl = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);

                $pdf->AddPage(
                    $size['orientation'],
                    [$size['width'], $size['height']]
                );

                $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);
            }

            Log::info('Index pages added', [
                'bundle_id' => $bundle->id,
                'pages' => $pageCount
            ]);

            return $pageCount;

        } catch (\Exception $e) {
            Log::error('Failed to add index pages', [
                'bundle_id' => $bundle->id,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Add document pages with proper null checks
     */
    private function addDocumentPages(
        Fpdi $pdf,
        Document $document,
        Bundle $bundle,
        int &$globalPageNumber,
        int $totalPages
    ): int {
        // **NULL SAFETY CHECKS**
        if (empty($document->storage_path)) {
            Log::warning('Document has no storage path, skipping', [
                'document_id' => $document->id,
                'name' => $document->name
            ]);
            return 0;
        }

        if (!Storage::exists($document->storage_path)) {
            Log::warning('Document file not found, skipping', [
                'document_id' => $document->id,
                'path' => $document->storage_path
            ]);
            return 0;
        }

        try {
            $sourcePath = Storage::path($document->storage_path);

            // Verify physical file
            if (!file_exists($sourcePath)) {
                Log::warning('Physical file does not exist', [
                    'document_id' => $document->id,
                    'path' => $sourcePath
                ]);
                return 0;
            }

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
                $pdf->SetFont('helvetica', '', 9);
                $pdf->SetTextColor(100, 100, 100);
                $pdf->SetXY($size['width'] - 110, $size['height'] - 15);
                $pdf->Cell(
                    100,
                    10,
                    "Page {$globalPageNumber} of {$totalPages}",
                    0,
                    0,
                    'R'
                );

                $globalPageNumber++;
            }

            return $pageCount;

        } catch (\Exception $e) {
            Log::error('Failed to add document pages', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Add highlights to PDF
     */
    private function addHighlights(
        Fpdi $pdf,
        array $highlights,
        array $filePageMapping
    ): void {
        try {
            // Note: Adding highlights requires re-processing the PDF
            // This is a simplified version - you may need a more sophisticated approach
            
            foreach ($highlights as $documentId => $documentHighlights) {
                if (!isset($filePageMapping[$documentId])) {
                    continue;
                }

                $mapping = $filePageMapping[$documentId];
                
                foreach ($documentHighlights as $pageNum => $pageHighlights) {
                    // Calculate global page number
                    $globalPage = $mapping['start'] + ($pageNum - 1);
                    
                    // Set page
                    $pdf->setPage($globalPage);
                    
                    // Add highlight rectangles
                    $pdf->SetAlpha(0.3);
                    
                    foreach ($pageHighlights as $highlight) {
                        $color = $this->parseColor($highlight['color'] ?? '#FFFF00');
                        
                        $pdf->SetFillColor($color['r'], $color['g'], $color['b']);
                        $pdf->Rect(
                            $highlight['x'],
                            $highlight['y'],
                            $highlight['width'],
                            $highlight['height'],
                            'F'
                        );
                    }
                    
                    $pdf->SetAlpha(1);
                }
            }

            Log::info('Highlights added successfully');

        } catch (\Exception $e) {
            Log::warning('Failed to add highlights', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Calculate total pages recursively
     */
    private function calculateTotalPages(Bundle $bundle): int
    {
        $documents = $bundle->documents()
            ->whereNull('parent_id')
            ->orderBy('order')
            ->get();

        $total = 0;

        foreach ($documents as $document) {
            $total += $this->countDocumentPages($document);
        }

        return $total;
    }

    /**
     * Count pages for a document (recursive for folders)
     */
    private function countDocumentPages(Document $document): int
    {
        if ($document->type === 'folder') {
            $children = $document->children()->get();
            $total = 0;
            
            foreach ($children as $child) {
                $total += $this->countDocumentPages($child);
            }
            
            return $total;
        }

        // It's a file
        if (empty($document->storage_path) || !Storage::exists($document->storage_path)) {
            return 0;
        }

        try {
            $pdf = new Fpdi();
            return $pdf->setSourceFile(Storage::path($document->storage_path));
        } catch (\Exception $e) {
            Log::warning('Failed to count pages', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Parse color string to RGB
     */
    private function parseColor(string $color): array
    {
        // Default yellow
        $default = ['r' => 255, 'g' => 255, 'b' => 0];

        // RGB format
        if (preg_match('/rgb\((\d+),\s*(\d+),\s*(\d+)\)/', $color, $matches)) {
            return [
                'r' => (int)$matches[1],
                'g' => (int)$matches[2],
                'b' => (int)$matches[3]
            ];
        }

        // Hex format
        if (preg_match('/#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})/i', $color, $matches)) {
            return [
                'r' => hexdec($matches[1]),
                'g' => hexdec($matches[2]),
                'b' => hexdec($matches[3])
            ];
        }

        return $default;
    }

    /**
     * Generate safe filename
     */
    private function generateFilename(Bundle $bundle): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $bundle->name ?? 'bundle');
        $timestamp = now()->format('Y-m-d_His');
        return "{$name}_{$timestamp}.pdf";
    }
}