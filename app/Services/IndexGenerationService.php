<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Document;
use setasign\Fpdi\Tcpdf\Fpdi;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class IndexGenerationService
{
    private const INDEX_CACHE_PATH = 'indexes';
    private const INDEX_FILE_PREFIX = 'index_';

    public function __construct(
        private DocumentTreeBuilder $treeBuilder
    ) {}

    /**
     * Generate or regenerate index PDF for a bundle
     */
    public function generateIndex(Bundle $bundle): ?string
    {
        try {
            Log::info('Generating index for bundle', ['bundle_id' => $bundle->id]);

            $documents = Document::where('bundle_id', $bundle->id)
                ->orderBy('order')
                ->get();

            if ($documents->isEmpty()) {
                Log::info('No documents found, skipping index generation');
                return null;
            }

            $tree = $this->treeBuilder->build($documents);

            // PASS 1: Create temporary index to determine page count
            $tempPdf = $this->createTemporaryIndexPdf($bundle, $tree);
            $indexPageCount = $tempPdf->getNumPages();

            Log::info('Index will occupy pages', ['pages' => $indexPageCount]);

            // PASS 2: Generate index entries with CORRECT page numbers
            $currentPage = $indexPageCount + 1;
            $indexEntries = $this->buildIndexEntries($tree, $bundle, $currentPage);

            if (empty($indexEntries)) {
                Log::info('No valid entries for index');
                return null;
            }

            // PASS 3: Create final PDF with positions tracked
            $result = $this->createIndexPdf($bundle, $indexEntries);
            $pdf = $result['pdf'];
            $linkPositions = $result['positions'];

            // Save to storage
            $filename = $this->getIndexFilename($bundle);
            $path = self::INDEX_CACHE_PATH . '/' . $filename;

            Storage::put($path, $pdf->Output('', 'S'));

            // Update bundle metadata with index info AND link positions
            $this->updateBundleIndexMetadata($bundle, $path, $indexEntries, $linkPositions);

            Log::info('Index generated successfully', [
                'bundle_id' => $bundle->id,
                'path' => $path,
                'index_pages' => $indexPageCount,
                'entries' => count($indexEntries),
                'link_positions' => count($linkPositions)
            ]);

            return $path;
        } catch (\Exception $e) {
            Log::error('Index generation failed', [
                'bundle_id' => $bundle->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Update bundle metadata with index information
     */
    private function updateBundleIndexMetadata(
        Bundle $bundle,
        string $path,
        array $entries,
        array $linkPositions
    ): void {
        $metadata = $bundle->metadata ?? [];
        $metadata['index_path'] = $path;
        $metadata['index_entries'] = $entries;
        $metadata['index_link_positions'] = $linkPositions; // ← NEW!
        $metadata['index_updated_at'] = now()->toISOString();

        $bundle->update(['metadata' => $metadata]);
    }
    
    /**
     * Render tree entries for temporary PDF (no page numbers, no links)
     */
    private function renderTreeEntries(Fpdi $pdf, array $tree, int $level): void
    {
        foreach ($tree as $item) {
            if ($item['type'] === 'folder') {
                $this->renderTempFolderEntry($pdf, $item, $level);

                if (!empty($item['children'])) {
                    $this->renderTreeEntries($pdf, $item['children'], $level + 1);
                }
            } else {
                $this->renderTempFileEntry($pdf, $item, $level);
            }
        }
    }

    /**
     * Render temporary folder entry (just for measuring)
     */
    private function renderTempFolderEntry(Fpdi $pdf, array $item, int $level): void
    {
        $rowHeight = 8;
        $this->checkPageBreak($pdf, $rowHeight);

        $indent = $level * 10;

        if ($pdf->GetY() > 50) {
            $pdf->Ln(2);
        }

        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetX(20 + $indent);
        $pdf->Cell(0, 8, strtoupper($item['name']), 0, 1);
    }

    /**
     * Render temporary file entry (just for measuring)
     */
    private function renderTempFileEntry(Fpdi $pdf, array $item, int $level): void
    {
        $rowHeight = 6;
        $this->checkPageBreak($pdf, $rowHeight);

        $indent = $level * 10;

        $pdf->SetFont('helvetica', '', 10.5);
        $pdf->SetX(20 + $indent);
        $pdf->Cell(0, 6, $item['name'], 0, 1);
    }

    /**
     * Check if a row will fit on current page, if not add page break
     */
    private function checkPageBreak(Fpdi $pdf, float $rowHeight): void
    {
        $bottomMargin = 20;
        $currentY = $pdf->GetY();
        $pageHeight = $pdf->getPageHeight();

        // If row would cross bottom margin, start new page
        if ($currentY + $rowHeight > ($pageHeight - $bottomMargin)) {
            $pdf->AddPage();
        }
    }

    /**
     * Build index entries from tree structure with page numbers and index numbers
     */
    private function buildIndexEntries(
        array $tree,
        Bundle $bundle,
        int &$currentPage,
        int $level = 0,
        array &$indexCounter = [0]
    ): array {
        $entries = [];

        foreach ($tree as $item) {
            if ($item['type'] === 'folder') {
                // Increment index counter for this level
                if (!isset($indexCounter[$level])) {
                    $indexCounter[$level] = 0;
                }
                $indexCounter[$level]++;

                // Reset counters for deeper levels
                for ($i = $level + 1; $i < count($indexCounter); $i++) {
                    $indexCounter[$i] = 0;
                }

                // Build index number
                $indexNumber = $this->buildIndexNumber($indexCounter, $level);

                // Calculate folder's first page
                $folderPage = $this->getFirstChildPage($item['children'] ?? [], $bundle, $currentPage);

                // Add folder as section heading
                $entries[] = [
                    'type' => 'folder',
                    'name' => $item['name'],
                    'level' => $level,
                    'page' => $folderPage,
                    'page_range' => null,
                    'document_id' => $item['id'],
                    'index_number' => $indexNumber
                ];

                // Process children
                if (!empty($item['children'])) {
                    $childEntries = $this->buildIndexEntries(
                        $item['children'],
                        $bundle,
                        $currentPage,
                        $level + 1,
                        $indexCounter
                    );
                    $entries = array_merge($entries, $childEntries);
                }
            } else {
                // Increment index counter for files
                if (!isset($indexCounter[$level])) {
                    $indexCounter[$level] = 0;
                }
                $indexCounter[$level]++;

                // Reset counters for deeper levels
                for ($i = $level + 1; $i < count($indexCounter); $i++) {
                    $indexCounter[$i] = 0;
                }

                // Build index number
                $indexNumber = $this->buildIndexNumber($indexCounter, $level);

                // Add file with page number and range
                $document = Document::find($item['id']);

                if ($document && Storage::exists($document->storage_path)) {
                    $pageCount = $this->getDocumentPageCount($document);
                    $startPage = $currentPage;
                    $endPage = $currentPage + $pageCount - 1;

                    $entries[] = [
                        'type' => 'file',
                        'name' => $item['name'],
                        'level' => $level,
                        'page' => $startPage,
                        'page_range' => $pageCount > 1 ? "$startPage-$endPage" : (string)$startPage,
                        'document_id' => $item['id'],
                        'page_count' => $pageCount,
                        'index_number' => $indexNumber
                    ];

                    $currentPage += $pageCount;
                }
            }
        }

        return $entries;
    }

    /**
     * Get the first page number from folder's children
     */
    private function getFirstChildPage(array $children, Bundle $bundle, int $currentPage): ?int
    {
        foreach ($children as $child) {
            if ($child['type'] === 'file') {
                return $currentPage;
            } elseif ($child['type'] === 'folder' && !empty($child['children'])) {
                $page = $this->getFirstChildPage($child['children'], $bundle, $currentPage);
                if ($page !== null) {
                    return $page;
                }
            }
        }
        return null;
    }

    /**
     * Build hierarchical index number (e.g., "1.2.3")
     */
    private function buildIndexNumber(array $indexCounter, int $level): string
    {
        $parts = [];
        for ($i = 0; $i <= $level; $i++) {
            if (isset($indexCounter[$i]) && $indexCounter[$i] > 0) {
                $parts[] = $indexCounter[$i];
            }
        }
        return implode('.', $parts);
    }

    /**
     * Create the index PDF with styling and TRACK POSITIONS
     */
    private function createIndexPdf(Bundle $bundle, array $entries): array
    {
        $pdf = new Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(20, 20, 20);
        $pdf->SetAutoPageBreak(true, 20);

        $pdf->AddPage();

        // Title
        $pdf->SetFont('helvetica', 'B', 30);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 15, 'TABLE OF CONTENTS', 0, 1, 'C');
        $pdf->Ln(10);

        // Track where each entry is rendered for link recreation
        $linkPositions = [];

        // Render entries and track positions
        foreach ($entries as $index => $entry) {
            $beforeY = $pdf->GetY();
            $beforePage = $pdf->getPage();

            if ($entry['type'] === 'folder') {
                $this->addFolderEntry($pdf, $entry, null);
                $rowHeight = 8;
                $indent = $entry['level'] * 10;
            } else {
                $this->addFileEntry($pdf, $entry, null);
                $rowHeight = 6;
                $indent = $entry['level'] * 10;
            }

            // Store the position where this entry was rendered
            $linkPositions[] = [
                'page' => $beforePage,
                'y' => $beforeY,
                'height' => $rowHeight,
                'indent' => $indent,
                'target_page' => $entry['page'],
                'entry_index' => $index
            ];
        }

        return ['pdf' => $pdf, 'positions' => $linkPositions];
    }
    /**
     * Add folder entry (section heading) - NO LINK
     */
    private function addFolderEntry(Fpdi $pdf, array $entry, ?int $link = null): void
    {
        $rowHeight = 8;
        $this->checkPageBreak($pdf, $rowHeight);

        $indent = $entry['level'] * 10;

        // Add spacing if not at top of page
        if ($pdf->GetY() > 50) {
            $pdf->Ln(2);
        }

        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetTextColor(40, 40, 40);
        $pdf->SetX(20 + $indent);

        // Format: "1.2 FOLDER NAME"
        $folderTitle = $entry['index_number'] . '. ' . strtoupper($entry['name']);

        // ❌ REMOVE link parameter from Cell()
        $pdf->Cell(0, 8, $folderTitle, 0, 1, 'L'); // ← No link!
    }

    /**
     * Add file entry - NO LINK
     */
    private function addFileEntry(Fpdi $pdf, array $entry, ?int $link = null): void
    {
        $rowHeight = 6;
        $this->checkPageBreak($pdf, $rowHeight);

        $indent = $entry['level'] * 10;

        $pdf->SetFont('helvetica', '', 10.5);
        $pdf->SetTextColor(60, 60, 60);

        // Calculate positions
        $x = 20 + $indent;
        $y = $pdf->GetY();
        $pageWidth = $pdf->getPageWidth() - 40;

        // File name with index number (NO hyperlink)
        $pdf->SetX($x);
        $nameWidth = $pageWidth - 50;

        // Format: "1.2.3 Document Name"
        $fileName = $entry['index_number'] . '. ' . $entry['name'];
        $truncatedName = $this->truncateFileName($fileName, $nameWidth);

        // ❌ REMOVE link parameter from Cell()
        $pdf->Cell($nameWidth, 6, $truncatedName, 0, 0, 'L'); // ← No link!

        // Page range (aligned right)
        $pdf->SetXY($pdf->getPageWidth() - 50, $y);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(30, 6, $entry['page_range'], 0, 1, 'R');
    }

    /**
     * Get page count for a document
     */
    private function getDocumentPageCount(Document $document): int
    {
        try {
            $pdf = new Fpdi();
            $path = Storage::path($document->storage_path);
            return $pdf->setSourceFile($path);
        } catch (\Exception $e) {
            Log::warning('Could not get page count', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Truncate file name if too long
     */
    private function truncateFileName(string $name, float $maxWidth): string
    {
        $maxLength = 70;
        if (strlen($name) > $maxLength) {
            return substr($name, 0, $maxLength - 3) . '...';
        }
        return $name;
    }

    /**
     * Get index filename for a bundle
     */
    private function getIndexFilename(Bundle $bundle): string
    {
        return self::INDEX_FILE_PREFIX . $bundle->id . '.pdf';
    }

    /**
     * Get index path for a bundle
     */
    public function getIndexPath(Bundle $bundle): ?string
    {
        $filename = $this->getIndexFilename($bundle);
        $path = self::INDEX_CACHE_PATH . '/' . $filename;

        return Storage::exists($path) ? $path : null;
    }

    /**
     * Delete index for a bundle
     */
    public function deleteIndex(Bundle $bundle): void
    {
        $path = $this->getIndexPath($bundle);

        if ($path && Storage::exists($path)) {
            Storage::delete($path);

            // Update metadata
            $metadata = $bundle->metadata ?? [];
            unset($metadata['index_path'], $metadata['index_entries'], $metadata['index_updated_at']);
            $bundle->update(['metadata' => $metadata]);
        }
    }

    /**
     * Check if index needs regeneration
     */
    public function needsRegeneration(Bundle $bundle): bool
    {
        $metadata = $bundle->metadata ?? [];

        if (!isset($metadata['index_path']) || !isset($metadata['index_updated_at'])) {
            return true;
        }

        $path = $metadata['index_path'];
        if (!Storage::exists($path)) {
            return true;
        }

        // Check if any document was updated after index
        $indexUpdatedAt = $metadata['index_updated_at'];
        $latestDocUpdate = Document::where('bundle_id', $bundle->id)
            ->max('updated_at');

        return $latestDocUpdate > $indexUpdatedAt;
    }

    /**
     * Get index entries for export
     */
    public function getIndexEntries(Bundle $bundle): array
    {
        $metadata = $bundle->metadata ?? [];
        return $metadata['index_entries'] ?? [];
    }

    /**
     * PASS 1: Create a temporary index PDF to calculate page count
     * - No links
     * - No page numbers
     * - Just layout
     */
    private function createTemporaryIndexPdf(Bundle $bundle, array $tree): Fpdi
    {
        $pdf = new Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(20, 20, 20);
        $pdf->SetAutoPageBreak(true, 20);

        $pdf->AddPage();

        // Title (must match final index exactly)
        $pdf->SetFont('helvetica', 'B', 30);
        $pdf->Cell(0, 15, 'TABLE OF CONTENTS', 0, 1, 'C');
        $pdf->Ln(10);

        // Render tree entries (folders + files)
        $this->renderTreeEntries($pdf, $tree, 0);

        return $pdf;
    }
}
