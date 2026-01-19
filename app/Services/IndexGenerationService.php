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

            // Get all documents with their hierarchy
            $documents = Document::where('bundle_id', $bundle->id)
                ->orderBy('order')
                ->get();

            if ($documents->isEmpty()) {
                Log::info('No documents found, skipping index generation');
                return null;
            }

            // Build tree structure
            $tree = $this->treeBuilder->build($documents);

            // Generate index entries with page numbers
            $indexEntries = $this->buildIndexEntries($tree, $bundle);

            if (empty($indexEntries)) {
                Log::info('No valid entries for index');
                return null;
            }

            // Create PDF
            $pdf = $this->createIndexPdf($bundle, $indexEntries);

            // Save to storage
            $filename = $this->getIndexFilename($bundle);
            $path = self::INDEX_CACHE_PATH . '/' . $filename;

            Storage::put($path, $pdf->Output('', 'S'));

            // Update bundle metadata with index info
            $this->updateBundleIndexMetadata($bundle, $path, $indexEntries);

            Log::info('Index generated successfully', [
                'bundle_id' => $bundle->id,
                'path' => $path,
                'entries' => count($indexEntries)
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
     * Build index entries from tree structure with page numbers and index numbers
     */
    private function buildIndexEntries(
        array $tree, 
        Bundle $bundle, 
        int &$currentPage = 2, 
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

                // Build index number (e.g., "1", "1.1", "1.1.2")
                $indexNumber = $this->buildIndexNumber($indexCounter, $level);

                // Add folder as section heading
                $entries[] = [
                    'type' => 'folder',
                    'name' => $item['name'],
                    'level' => $level,
                    'page' => null,
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
     * Create the index PDF with styling and hyperlinks
     */
    private function createIndexPdf(Bundle $bundle, array $entries): Fpdi
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

        // Entries
        foreach ($entries as $entry) {
            if ($entry['type'] === 'folder') {
                $this->addFolderEntry($pdf, $entry);
            } else {
                $this->addFileEntry($pdf, $entry);
            }
        }

        return $pdf;
    }

    /**
     * Add folder entry (section heading) with index number
     */
    private function addFolderEntry(Fpdi $pdf, array $entry): void
    {
        $indent = $entry['level'] * 10;
        
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetTextColor(40, 40, 40);
        $pdf->SetX(20 + $indent);
        
        // Format: "1.2 FOLDER NAME"
        $folderTitle = $entry['index_number'] . '. ' . strtoupper($entry['name']);
        $pdf->Cell(0, 10, $folderTitle, 0, 1);
        $pdf->Ln(2);
    }

    /**
     * Add file entry with index number, page range and hyperlink
     */
    private function addFileEntry(Fpdi $pdf, array $entry): void
    {
        $indent = $entry['level'] * 10;
        
        $pdf->SetFont('helvetica', '', 10.5);
        $pdf->SetTextColor(60, 60, 60);
        
        // Calculate positions
        $x = 20 + $indent;
        $y = $pdf->GetY();
        $pageWidth = $pdf->getPageWidth() - 40;
        
        // File name with index number (with hyperlink)
        $pdf->SetX($x);
        $nameWidth = $pageWidth - 50; // More space for page range
        
        // Format: "1.2.3 Document Name"
        $fileName = $entry['index_number'] . '. ' . $entry['name'];
        
        // Add clickable link to first page of document
        $pdf->Write(
            6,
            $this->truncateFileName($fileName, $nameWidth),
            '',
            false,
            'L',
            true,
            0,
            false,
            false,
            0,
            $entry['page']
        );
        
        // Page range (aligned right)
        $pdf->SetXY($pdf->getPageWidth() - 50, $y);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(30, 6, $entry['page_range'], 0, 1, 'R');
        
        $pdf->Ln(1);
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
        // Simple truncation - could be improved with actual text width calculation
        $maxLength = 70; // Reduced to account for index numbers
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
     * Update bundle metadata with index information
     */
    private function updateBundleIndexMetadata(Bundle $bundle, string $path, array $entries): void
    {
        $metadata = $bundle->metadata ?? [];
        $metadata['index_path'] = $path;
        $metadata['index_entries'] = $entries;
        $metadata['index_updated_at'] = now()->toISOString();
        
        $bundle->update(['metadata' => $metadata]);
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
}