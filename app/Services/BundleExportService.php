<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Document;
use App\Models\Highlight;
use App\Models\Redaction;
use App\Models\CoverPage;
use setasign\Fpdi\Tcpdf\Fpdi;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class BundleExportService
{
    public function __construct(
        private PdfModifierService $pdfModifier,
        private IndexGenerationService $indexService,
        private CoverPageGeneratorService $coverPageGenerator
    ) {}

    /**
     * Export all documents in a bundle as a single PDF
     */
    public function exportBundle(
        Bundle $bundle,
        bool $includeIndex = true,
        bool $includeFrontCover = true,
        bool $includeBackCover = true
    ): string {
        Log::info('Starting bundle export', [
            'bundle_id' => $bundle->id,
            'include_index' => $includeIndex,
            'include_front_cover' => $includeFrontCover,
            'include_back_cover' => $includeBackCover,
        ]);

        try {
            $pdf = new Fpdi();
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetAutoPageBreak(false, 0);

            $globalPageNumber = 1;
            $filePageMapping = [];
            $metadata = $bundle->metadata ?? [];


            // STEP 1: Add front cover page if enabled
            $frontCoverPageCount = 0;
            if ($includeFrontCover && isset($metadata['front_cover_page_id'])) {
                $coverPageId = $metadata['front_cover_page_id'];
                $coverPage = CoverPage::find($coverPageId);

                if ($coverPage) {
                    $frontCoverPageCount = $this->addCoverPage($pdf, $coverPage, 'front');
                    $globalPageNumber += $frontCoverPageCount;

                    Log::info('Front cover page added', [
                        'cover_page_id' => $coverPageId,
                        'pages' => $frontCoverPageCount
                    ]);
                } else {
                    Log::warning('Front cover page not found', [
                        'cover_page_id' => $coverPageId,
                    ]);
                }
            }
            

            // STEP 2: Add index pages
            $index_path = $metadata['index_path'] ?? null;

            $indexPageCount = 0;
            $linkPositions = [];

            // Add index pages if requested and available
            if ($includeIndex && $index_path) {
                $indexPageCount = $this->addIndexPages($pdf, $bundle);
                $globalPageNumber += $indexPageCount;

                // Get link positions from metadata
                $linkPositions = $metadata['index_link_positions'] ?? [];
            }

            // STEP 3: Calculate total pages
            $totalPages = $this->calculateTotalPages($bundle);

            if ($includeIndex && $index_path) {
                $totalPages += $indexPageCount;
            }

            if ($includeFrontCover && $frontCoverPageCount > 0) {
                $totalPages += $frontCoverPageCount;
            }

            // Note: Back cover pages will be added to total at the end
            // since we need to know the count first

            // STEP 4: Add documents
            // Get root-level documents in order
            $documents = $bundle->documents()
                ->whereNull('parent_id')
                ->orderBy('order')
                ->get();

            // Get bundle metadata for headers/footers
            $headerFooter = [
                'headerLeft' => $metadata['header_left'] ?? '',
                'headerRight' => $metadata['header_right'] ?? '',
                'footer' => $metadata['footer'] ?? '',
            ];

            // Process each document recursively
            foreach ($documents as $document) {
                $pageCount = $this->processDocument(
                    $pdf,
                    $document,
                    $globalPageNumber,
                    $totalPages,
                    $filePageMapping,
                    $headerFooter
                );

                $globalPageNumber += $pageCount;
            }

            // STEP 5: Add index links with correct page offset
            // Recreate clickable links using STORED POSITIONS, offset by front cover page count
            if ($includeIndex && !empty($linkPositions)) {
                $this->recreateIndexLinks($pdf, $linkPositions, $frontCoverPageCount);
            }

            // STEP 6: Add highlights
            // Get highlights from database
            $highlights = $this->getHighlightsGrouped($bundle);

            // Add highlights if available, offset by front cover page count
            if (!empty($highlights)) {
                $this->addHighlights($pdf, $highlights, $filePageMapping, $frontCoverPageCount);
                
                // CRITICAL FIX: After using setPage() in highlights, TCPDF's internal state
                // needs to be reset so that AddPage() will add at the END, not at current position
                // This ensures the back cover is added as the last page
                $pdf->lastPage();
            }

            // STEP 6b: Add redactions (drawn after highlights)
            // Get redactions from database
            $redactions = $this->getRedactionsGrouped($bundle);

            // Add redactions if available, offset by front cover page count
            if (!empty($redactions)) {
                $this->addRedactions($pdf, $redactions, $filePageMapping, $frontCoverPageCount);

                // Reset page pointer after setPage() calls
                $pdf->lastPage();
            }

            // STEP 7: Add back cover page if enabled
            $backCoverPageCount = 0;
            if ($includeBackCover && isset($metadata['back_cover_page_id'])) {
                $coverPageId = $metadata['back_cover_page_id'];
                $coverPage = CoverPage::find($coverPageId);

                if ($coverPage) {
                    Log::info('About to add back cover page', [
                        'cover_page_id' => $coverPageId
                    ]);

                    $backCoverPageCount = $this->addCoverPage($pdf, $coverPage, 'back');

                    Log::info('Back cover page added', [
                        'cover_page_id' => $coverPageId,
                        'pages' => $backCoverPageCount
                    ]);
                } else {
                    Log::warning('Back cover page not found', [
                        'cover_page_id' => $coverPageId,
                    ]);
                }
            }

            // STEP 8: Save and return
            // Calculate total pages (front cover + index + documents + back cover)
            $finalTotalPages = $frontCoverPageCount + $indexPageCount + ($globalPageNumber - 1 - $frontCoverPageCount - $indexPageCount) + $backCoverPageCount;

            Log::info('About to output PDF', [
                'final_total_pages' => $finalTotalPages,
                'current_page' => $pdf->getPage()
            ]);

            // Generate and save PDF
            $filename = $this->generateFilename($bundle);
            $path = "exports/{$filename}";

            Storage::put($path, $pdf->Output('', 'S'));

            Log::info('Bundle export completed', [
                'bundle_id' => $bundle->id,
                'path' => $path,
                'total_pages' => $finalTotalPages,
                'front_cover_pages' => $frontCoverPageCount,
                'index_pages' => $indexPageCount,
                'document_pages' => $globalPageNumber - 1 - $frontCoverPageCount - $indexPageCount,
                'back_cover_pages' => $backCoverPageCount
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
     * Recreate clickable links using EXACT stored positions
     * FIXED: Offset both index page and target page by cover page count
     */
    private function recreateIndexLinks(Fpdi $pdf, array $linkPositions, int $coverPageOffset = 0): void
    {
        Log::info('Recreating index links', [
            'positions' => count($linkPositions),
            'cover_page_offset' => $coverPageOffset
        ]);

        foreach ($linkPositions as $position) {
            if (empty($position['target_page'])) {
                continue;
            }

            // Offset both the index page and target page by cover page count
            $indexPage  = (int) $position['page'] + $coverPageOffset;
            $targetPage = (int) $position['target_page'] + $coverPageOffset;

            if ($targetPage <= 0) {
                continue;
            }

            // Create a TCPDF internal link
            $linkId = $pdf->AddLink();

            // Associate link with target page (top of page)
            $pdf->SetLink($linkId, 0, $targetPage);

            // Switch to index page
            $pdf->setPage($indexPage);

            $x      = 20 + (float) $position['indent'];
            $y      = (float) $position['y'];
            $height = (float) $position['height'];
            $width  = $pdf->getPageWidth() - $x - 50;

            // Correct internal link
            $pdf->Link($x, $y, $width, $height, $linkId);

            Log::debug('Index link created', [
                'index_page'  => $indexPage,
                'target_page' => $targetPage,
                'link_id'     => $linkId,
                'cover_offset' => $coverPageOffset
            ]);
        }

        Log::info('Index links recreated successfully');
    }

    /**
     * Get highlights grouped by document and page
     */
    private function getHighlightsGrouped(Bundle $bundle): array
    {
        $highlights = Highlight::where('bundle_id', $bundle->id)->get();

        $grouped = [];

        foreach ($highlights as $highlight) {
            $documentId = $highlight->document_id;
            $pageNumber = $highlight->page_number;

            // Get coordinates directly from columns
            $x = $highlight->x ?? 0;
            $y = $highlight->y ?? 0;
            $width = $highlight->width ?? 0;
            $height = $highlight->height ?? 0;

            // Skip highlights with invalid coordinates
            if ($width <= 0 || $height <= 0) {
                Log::warning('Skipping highlight with invalid dimensions', [
                    'highlight_id' => $highlight->id,
                    'x' => $x,
                    'y' => $y,
                    'width' => $width,
                    'height' => $height
                ]);
                continue;
            }

            // Get color from color_hex column
            $colorValue = $highlight->color_hex ?? '#FFFF00';

            if (!isset($grouped[$documentId])) {
                $grouped[$documentId] = [];
            }

            if (!isset($grouped[$documentId][$pageNumber])) {
                $grouped[$documentId][$pageNumber] = [];
            }

            $grouped[$documentId][$pageNumber][] = [
                'x' => $x,
                'y' => $y,
                'width' => $width,
                'height' => $height,
                'color' => $colorValue
            ];
        }

        Log::info('Highlights grouped', [
            'total_highlights' => $highlights->count(),
            'valid_highlights' => array_sum(array_map(function ($doc) {
                return array_sum(array_map('count', $doc));
            }, $grouped))
        ]);

        return $grouped;
    }

    /**
     * Get redactions grouped by document and page
     */
    private function getRedactionsGrouped(Bundle $bundle): array
    {
        $redactions = Redaction::where('bundle_id', $bundle->id)->get();

        $grouped = [];

        foreach ($redactions as $redaction) {
            $documentId = $redaction->document_id;
            $pageNumber = $redaction->page_number;

            // Get coordinates directly from columns
            $x = $redaction->x ?? 0;
            $y = $redaction->y ?? 0;
            $width = $redaction->width ?? 0;
            $height = $redaction->height ?? 0;

            // Skip redactions with invalid coordinates
            if ($width <= 0 || $height <= 0) {
                Log::warning('Skipping redaction with invalid dimensions', [
                    'redaction_id' => $redaction->id,
                    'x' => $x,
                    'y' => $y,
                    'width' => $width,
                    'height' => $height
                ]);
                continue;
            }

            $fillHex = $redaction->fill_hex ?? '#000000';
            $borderHex = $redaction->border_hex ?? '#000000';
            $opacity = $redaction->opacity ?? 1;
            $borderWidth = $redaction->border_width ?? 0;

            if (!isset($grouped[$documentId])) {
                $grouped[$documentId] = [];
            }

            if (!isset($grouped[$documentId][$pageNumber])) {
                $grouped[$documentId][$pageNumber] = [];
            }

            $grouped[$documentId][$pageNumber][] = [
                'x' => $x,
                'y' => $y,
                'width' => $width,
                'height' => $height,
                'fill' => $fillHex,
                'opacity' => $opacity,
                'border' => $borderHex,
                'border_width' => $borderWidth,
            ];
        }

        Log::info('Redactions grouped', [
            'total_redactions' => $redactions->count(),
            'valid_redactions' => array_sum(array_map(function ($doc) {
                return array_sum(array_map('count', $doc));
            }, $grouped))
        ]);

        return $grouped;
    }

    /**
     * Process document recursively (handles folders and files)
     */
    private function processDocument(
        Fpdi $pdf,
        Document $document,
        int &$globalPageNumber,
        int $totalPages,
        array &$filePageMapping,
        array $headerFooter
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
                $this->processDocument(
                    $pdf,
                    $child,
                    $globalPageNumber,
                    $totalPages,
                    $filePageMapping,
                    $headerFooter
                );
            }

            // IMPORTANT: folders do NOT add pages
            return 0;
        }


        // It's a file - add its pages
        $startPage = $globalPageNumber;

        $pageCount = $this->addDocumentPages(
            $pdf,
            $document,
            $globalPageNumber,
            $totalPages,
            $headerFooter
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
     * Count index pages
     */
    private function countIndexPages(Bundle $bundle): int
    {
        $index_path = $bundle->metadata['index_path'] ?? null;
        if (!$index_path || !Storage::exists($index_path)) {
            return 0;
        }

        try {
            $pdf = new Fpdi();
            return $pdf->setSourceFile(Storage::path($index_path));
        } catch (\Exception $e) {
            Log::warning('Failed to count index pages', [
                'bundle_id' => $bundle->id,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Add index pages to PDF
     */
    private function addIndexPages(Fpdi $pdf, Bundle $bundle): int
    {
        $index_path = $bundle->metadata['index_path'] ?? null;

        try {
            if (!$index_path || !Storage::exists($index_path)) {
                Log::warning('No index available for export', [
                    'bundle_id' => $bundle->id
                ]);
                return 0;
            }

            // Import index pages
            $fullPath = Storage::path($index_path);
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
     * Add document pages with headers, footers, and page numbers
     * IMPROVED: Enhanced error handling with multiple conversion strategies
     */
    private function addDocumentPages(
        Fpdi $pdf,
        Document $document,
        int &$globalPageNumber,
        int $totalPages,
        array $headerFooter
    ): int {
        if (empty($document->storage_path)) {
            Log::warning('Document has no storage path', [
                'document_id' => $document->id
            ]);
            return 0;
        }

        if (!Storage::exists($document->storage_path)) {
            Log::warning('Document file not found', [
                'document_id' => $document->id,
                'path' => $document->storage_path
            ]);
            return 0;
        }

        $originalPath = Storage::path($document->storage_path);
        $sourcePath = $originalPath;
        $convertedPath = null;

        // Try to get page count and import
        try {
            $pageCount = $pdf->setSourceFile($sourcePath);
        } catch (\Throwable $e) {
            Log::warning('FPDI failed on original PDF, attempting conversion', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);

            // Strategy 1: Enhanced Ghostscript (most compatible)
            $convertedPath = $this->convertPdfWithEnhancedGhostscript($originalPath);

            if (!$convertedPath) {
                // Strategy 2: pdftocairo
                Log::info('Enhanced Ghostscript failed, trying pdftocairo', [
                    'document_id' => $document->id
                ]);
                $convertedPath = $this->convertPdfWithCairo($originalPath);
            }

            if (!$convertedPath) {
                // Strategy 3: Basic Ghostscript
                Log::info('pdftocairo failed, trying basic Ghostscript', [
                    'document_id' => $document->id
                ]);
                $convertedPath = $this->convertPdfWithGhostscript($originalPath);
            }

            if (!$convertedPath) {
                Log::error('All PDF conversion methods failed', [
                    'document_id' => $document->id
                ]);
                return 0;
            }

            try {
                $sourcePath = $convertedPath;
                $pageCount = $pdf->setSourceFile($sourcePath);

                Log::info('PDF conversion successful', [
                    'document_id' => $document->id,
                    'pages' => $pageCount
                ]);
            } catch (\Throwable $e) {
                Log::error('FPDI failed even after conversion', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage()
                ]);

                // Cleanup converted file
                if ($convertedPath && file_exists($convertedPath)) {
                    @unlink($convertedPath);
                }

                return 0;
            }
        }

        // Import pages
        for ($i = 1; $i <= $pageCount; $i++) {
            try {
                $tpl = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);

                $pdf->AddPage(
                    $size['orientation'],
                    [$size['width'], $size['height']]
                );

                $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);

                $this->addPageOverlays(
                    $pdf,
                    $size,
                    $globalPageNumber,
                    $totalPages,
                    $headerFooter
                );

                $globalPageNumber++;
            } catch (\Throwable $e) {
                Log::error('Failed to import page', [
                    'document_id' => $document->id,
                    'page' => $i,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Cleanup converted file if exists
        if ($convertedPath && file_exists($convertedPath)) {
            @unlink($convertedPath);
        }

        return $pageCount;
    }

    /**
     * Add headers, footers, and page numbers to a page
     */
    private function addPageOverlays(
        Fpdi $pdf,
        array $size,
        int $pageNumber,
        int $totalPages,
        array $headerFooter
    ): void {
        $width = $size['width'];
        $height = $size['height'];

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(100, 100, 100);

        // Header left
        if (!empty($headerFooter['headerLeft'])) {
            $pdf->SetXY(10, 10);
            $pdf->Cell(0, 10, $headerFooter['headerLeft'], 0, 0, 'L');
        }

        // Header right
        if (!empty($headerFooter['headerRight'])) {
            $pdf->SetXY($width - 130, 10);
            $pdf->Cell(120, 10, $headerFooter['headerRight'], 0, 0, 'R');
        }

        // Footer
        if (!empty($headerFooter['footer'])) {
            $pdf->SetXY(10, $height - 15);
            $pdf->Cell(0, 10, $headerFooter['footer'], 0, 0, 'L');
        }

        // Page number
        $pdf->SetXY($width - 130, $height - 15);
        $pdf->Cell(120, 10, "Page {$pageNumber} of {$totalPages}", 0, 0, 'R');
    }

    /**
     * Add highlights to PDF
     * FIXED: Offset page numbers by cover page count
     */
    private function addHighlights(
        Fpdi $pdf,
        array $highlights,
        array $filePageMapping,
        int $coverPageOffset = 0
    ): void {
        try {
            Log::info('Adding highlights to PDF', [
                'cover_offset' => $coverPageOffset
            ]);

            foreach ($highlights as $documentId => $documentHighlights) {

                if (!isset($filePageMapping[$documentId])) {
                    continue;
                }

                $mapping = $filePageMapping[$documentId];

                foreach ($documentHighlights as $pageNum => $pageHighlights) {

                    // Calculate global page number (merged PDF page index)
                    // This already includes cover page offset from addDocumentPages
                    $globalPage = $mapping['start'] + ($pageNum - 1);

                    if ($globalPage < 1 || $globalPage > $pdf->getNumPages()) {
                        continue;
                    }

                    // Move to correct page
                    $pdf->setPage($globalPage);

                    // Get page height (TCPDF coordinate space)
                    $pageHeight = $pdf->getPageHeight();

                    // Semi-transparent highlights
                    $pdf->SetAlpha(0.3);

                    foreach ($pageHighlights as $highlight) {

                        if (
                            !isset(
                                $highlight['x'],
                                $highlight['y'],
                                $highlight['width'],
                                $highlight['height']
                            )
                        ) {
                            continue;
                        }

                        // Parse highlight color
                        $color = $this->parseColor(
                            $highlight['color'] ?? '#FFFF00'
                        );

                        $pdf->SetFillColor($color['r'], $color['g'], $color['b']);

                        $pageHeightMm = $pdf->getPageHeight();

                        $x = $this->ptToMm($highlight['x']);
                        $y = $this->ptToMm($highlight['y']);
                        $w = $this->ptToMm($highlight['width']);
                        $h = $this->ptToMm($highlight['height']);

                        $convertedY = $pageHeightMm - $y - $h;

                        // Draw highlight rectangle
                        $pdf->Rect($x, $convertedY, $w, $h, 'F');
                    }

                    // Reset alpha
                    $pdf->SetAlpha(1);
                }
            }

            Log::info('Highlights added successfully');
        } catch (\Throwable $e) {
            Log::warning('Failed to add highlights', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Add redactions to PDF
     * FIXED: Offset page numbers by cover page count
     */
    private function addRedactions(
        Fpdi $pdf,
        array $redactions,
        array $filePageMapping,
        int $coverPageOffset = 0
    ): void {
        try {
            Log::info('Adding redactions to PDF', [
                'cover_offset' => $coverPageOffset
            ]);

            foreach ($redactions as $documentId => $documentRedactions) {

                if (!isset($filePageMapping[$documentId])) {
                    continue;
                }

                $mapping = $filePageMapping[$documentId];

                foreach ($documentRedactions as $pageNum => $pageRedactions) {

                    // Calculate global page number (merged PDF page index)
                    // This already includes cover page offset from addDocumentPages
                    $globalPage = $mapping['start'] + ($pageNum - 1);

                    if ($globalPage < 1 || $globalPage > $pdf->getNumPages()) {
                        continue;
                    }

                    // Move to correct page
                    $pdf->setPage($globalPage);

                    foreach ($pageRedactions as $redaction) {

                        if (
                            !isset(
                                $redaction['x'],
                                $redaction['y'],
                                $redaction['width'],
                                $redaction['height']
                            )
                        ) {
                            continue;
                        }

                        $opacity = $redaction['opacity'] ?? 1;
                        $borderWidth = $redaction['border_width'] ?? 0;

                        // Parse colors
                        $fillColor = $this->parseColor(
                            $redaction['fill'] ?? '#000000'
                        );
                        $borderColor = $this->parseColor(
                            $redaction['border'] ?? '#000000'
                        );

                        $pdf->SetFillColor($fillColor['r'], $fillColor['g'], $fillColor['b']);
                        $pdf->SetDrawColor($borderColor['r'], $borderColor['g'], $borderColor['b']);
                        $pdf->SetLineWidth($this->ptToMm((float) $borderWidth));

                        // Apply opacity for redaction block
                        $pdf->SetAlpha($opacity);

                        $x = $this->ptToMm($redaction['x']);
                        $y = $this->ptToMm($redaction['y']);
                        $w = $this->ptToMm($redaction['width']);
                        $h = $this->ptToMm($redaction['height']);

                        $pageHeightMm = $pdf->getPageHeight();
                        $convertedY = $pageHeightMm - $y - $h;

                        // Draw border at full opacity (if any)
                        if ($borderWidth > 0) {
                            $pdf->SetAlpha(1);
                            $pdf->Rect($x, $convertedY, $w, $h, 'D');
                        }

                        // Draw fill with configured opacity
                        if ($opacity > 0) {
                            $pdf->SetAlpha($opacity);
                            $pdf->Rect($x, $convertedY, $w, $h, 'F');
                        }
                    }

                    // Reset alpha
                    $pdf->SetAlpha(1);
                }
            }

            Log::info('Redactions added successfully');
        } catch (\Throwable $e) {
            Log::warning('Failed to add redactions', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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

        $path = Storage::path($document->storage_path);

        try {
            $pdf = new Fpdi();
            return $pdf->setSourceFile($path);
        } catch (\Throwable $e) {
            Log::warning('Could not get page count with FPDI, falling back to pdfinfo', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
        }

        // Fallback: pdfinfo
        return $this->getPageCountWithPdfInfo($path);
    }

    /**
     * Parse color string to RGB
     */
    private function parseColor(string $color): array
    {
        // Default yellow
        $default = ['r' => 255, 'g' => 255, 'b' => 0];

        // RGB format: rgb(255, 255, 0)
        if (preg_match('/rgb\((\d+),\s*(\d+),\s*(\d+)\)/', $color, $matches)) {
            return [
                'r' => (int)$matches[1],
                'g' => (int)$matches[2],
                'b' => (int)$matches[3]
            ];
        }

        // Hex format: #FFFF00
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

    /**
     * Convert PDF points (dots) to millimeters
     */
    private function ptToMm(float $pt): float
    {
        return $pt * 25.4 / 72;
    }

    /**
     * Convert PDF using ENHANCED Ghostscript with maximum compatibility
     * This is the MOST COMPATIBLE method for FPDI
     */
    private function convertPdfWithEnhancedGhostscript(string $sourcePath): ?string
    {
        if (!function_exists('shell_exec')) {
            Log::warning('shell_exec not available');
            return null;
        }

        // Check if ghostscript is available
        $checkCommand = "which gs 2>/dev/null";
        if (empty(shell_exec($checkCommand))) {
            Log::warning('Ghostscript not available');
            return null;
        }

        $outputPath = storage_path('app/tmp/gs_enhanced_' . md5($sourcePath . time()) . '.pdf');

        // Ensure temp directory exists
        $tmpDir = dirname($outputPath);
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $source = escapeshellarg($sourcePath);
        $output = escapeshellarg($outputPath);

        // ENHANCED Ghostscript command with FPDI-optimized parameters
        $command = "gs -sDEVICE=pdfwrite " .
            "-dCompatibilityLevel=1.4 " .
            "-dPDFSETTINGS=/prepress " .
            "-dNOPAUSE -dQUIET -dBATCH " .
            "-dCompressPages=false " .
            "-dUseFlateCompression=false " .
            "-dAutoFilterColorImages=false " .
            "-dAutoFilterGrayImages=false " .
            "-dColorImageFilter=/FlateEncode " .
            "-dGrayImageFilter=/FlateEncode " .
            "-dEmbedAllFonts=true " .
            "-sOutputFile=$output $source 2>&1";

        $result = shell_exec($command);

        if (file_exists($outputPath) && filesize($outputPath) > 0) {
            Log::info('Enhanced Ghostscript conversion successful', [
                'output' => $outputPath,
                'size' => filesize($outputPath)
            ]);
            return $outputPath;
        }

        Log::warning('Enhanced Ghostscript conversion failed', [
            'command' => $command,
            'result' => $result
        ]);

        return null;
    }

    /**
     * Convert PDF using pdftocairo (Poppler - good quality)
     */
    private function convertPdfWithCairo(string $sourcePath): ?string
    {
        if (!function_exists('shell_exec')) {
            return null;
        }

        // Check if pdftocairo is available
        $checkCommand = "which pdftocairo 2>/dev/null";
        if (empty(shell_exec($checkCommand))) {
            Log::warning('pdftocairo not available');
            return null;
        }

        $outputPath = storage_path('app/tmp/cairo_' . md5($sourcePath . time()) . '.pdf');

        // Ensure temp directory exists
        $tmpDir = dirname($outputPath);
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $source = escapeshellarg($sourcePath);
        $output = escapeshellarg($outputPath);

        $command = "pdftocairo -pdf $source $output 2>&1";
        $result = shell_exec($command);

        if (file_exists($outputPath) && filesize($outputPath) > 0) {
            Log::info('pdftocairo conversion successful', [
                'output' => $outputPath,
                'size' => filesize($outputPath)
            ]);
            return $outputPath;
        }

        Log::warning('pdftocairo conversion failed', [
            'command' => $command,
            'result' => $result
        ]);

        return null;
    }

    /**
     * Convert PDF using Ghostscript (basic fallback)
     */
    private function convertPdfWithGhostscript(string $sourcePath): ?string
    {
        if (!function_exists('shell_exec')) {
            return null;
        }

        // Check if ghostscript is available
        $checkCommand = "which gs 2>/dev/null";
        if (empty(shell_exec($checkCommand))) {
            Log::warning('Ghostscript not available');
            return null;
        }

        $outputPath = storage_path('app/tmp/gs_basic_' . md5($sourcePath . time()) . '.pdf');

        // Ensure temp directory exists
        $tmpDir = dirname($outputPath);
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $source = escapeshellarg($sourcePath);
        $output = escapeshellarg($outputPath);

        $command = "gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dNOPAUSE -dQUIET -dBATCH -sOutputFile=$output $source 2>&1";
        $result = shell_exec($command);

        if (file_exists($outputPath) && filesize($outputPath) > 0) {
            Log::info('Basic Ghostscript conversion successful', [
                'output' => $outputPath,
                'size' => filesize($outputPath)
            ]);
            return $outputPath;
        }

        Log::warning('Basic Ghostscript conversion failed', [
            'command' => $command,
            'result' => $result
        ]);

        return null;
    }

    /**
     * Get page count using pdfinfo (Poppler)
     */
    private function getPageCountWithPdfInfo(string $path): int
    {
        try {
            $escapedPath = escapeshellarg($path);
            $output = shell_exec("pdfinfo $escapedPath 2>/dev/null");

            if ($output && preg_match('/Pages:\s+(\d+)/', $output, $matches)) {
                return (int) $matches[1];
            }
        } catch (\Throwable $e) {
            Log::warning('pdfinfo failed', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
        }

        return 0;
    }

    /**
     * Add cover page to PDF
     * 
     * @param Fpdi $pdf The PDF instance
     * @param CoverPage $coverPage The cover page model
     * @param string $type 'front' or 'back'
     * @return int Number of pages added
     */
    private function addCoverPage(Fpdi $pdf, CoverPage $coverPage, string $type = 'front'): int
    {
        try {
            $html = $coverPage->html ?? '';

            Log::info('Generating cover page PDF', [
                'cover_page_id' => $coverPage->id,
                'template_key' => $coverPage->template_key,
                'type' => $type,
                'html_length' => is_string($html) ? strlen($html) : 0
            ]);

            $coverPdfString = $this->coverPageGenerator->generateCoverPage($html);

            if ($coverPdfString === '') {
                Log::warning('Cover page PDF generation returned empty output', [
                    'cover_page_id' => $coverPage->id,
                    'type' => $type
                ]);
                return 0;
            }

            // Save to temporary file
            $tempPath = storage_path('app/tmp/cover_' . $type . '_' . uniqid() . '.pdf');
            
            // Ensure temp directory exists
            $tmpDir = dirname($tempPath);
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0755, true);
            }
            
            file_put_contents($tempPath, $coverPdfString);

            Log::info('Cover page PDF saved to temp file', [
                'path' => $tempPath,
                'size' => filesize($tempPath),
                'type' => $type
            ]);

            // Import cover page
            $pageCount = $pdf->setSourceFile($tempPath);
            
            for ($i = 1; $i <= $pageCount; $i++) {
                $tpl = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);

                // For back cover, ensure we're adding at the end by calling AddPage with explicit parameters
                // This is important because highlights might have moved the page pointer
                $pdf->AddPage(
                    $size['orientation'],
                    [$size['width'], $size['height']],
                    false, // keepmargins
                    false  // tocpage
                );

                $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);
                
                Log::debug('Cover page template applied', [
                    'type' => $type,
                    'page_num' => $i,
                    'orientation' => $size['orientation']
                ]);
            }

            // Clean up temp file
            @unlink($tempPath);

            Log::info('Cover page added successfully', [
                'type' => $type,
                'pages' => $pageCount
            ]);

            return $pageCount;
        } catch (\Exception $e) {
            Log::error('Failed to add cover page', [
                'cover_page_id' => $coverPage->id,
                'type' => $type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 0;
        }
    }
}
