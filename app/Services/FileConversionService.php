<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Tcpdf\Fpdi;

class FileConversionService
{
    private const TEMP_PATH = 'temp/conversions';
    
    /**
     * Supported file types for conversion
     */
    private const SUPPORTED_TYPES = [
        // Images
        'image/jpeg' => 'convertImageToPdf',
        'image/jpg' => 'convertImageToPdf',
        'image/png' => 'convertImageToPdf',
        'image/gif' => 'convertImageToPdf',
        'image/bmp' => 'convertImageToPdf',
        'image/webp' => 'convertImageToPdf',
        'image/tiff' => 'convertImageToPdf',
        
        // Documents
        'application/msword' => 'convertDocumentToPdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'convertDocumentToPdf',
        'text/plain' => 'convertTextToPdf',
        'application/rtf' => 'convertDocumentToPdf',
        'application/vnd.oasis.opendocument.text' => 'convertDocumentToPdf',
        
        // Presentations
        'application/vnd.ms-powerpoint' => 'convertPresentationToPdf',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'convertPresentationToPdf',
        'application/vnd.oasis.opendocument.presentation' => 'convertPresentationToPdf',
        
        // Spreadsheets
        'application/vnd.ms-excel' => 'convertSpreadsheetToPdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'convertSpreadsheetToPdf',
        'application/vnd.oasis.opendocument.spreadsheet' => 'convertSpreadsheetToPdf',
    ];

    /**
     * Check if a file type is supported for conversion
     */
    public function isSupported(string $mimeType): bool
    {
        return isset(self::SUPPORTED_TYPES[$mimeType]);
    }

    /**
     * Convert a file to PDF
     * 
     * @param string $sourcePath Path to the source file
     * @param string $mimeType MIME type of the source file
     * @param string $originalName Original filename
     * @return string Path to the converted PDF
     * @throws \Exception
     */
    public function convertToPdf(string $sourcePath, string $mimeType, string $originalName): string
    {
        if (!$this->isSupported($mimeType)) {
            throw new \Exception("Unsupported file type: {$mimeType}");
        }

        $method = self::SUPPORTED_TYPES[$mimeType];
        
        Log::info('Converting file to PDF', [
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'method' => $method
        ]);

        try {
            return $this->$method($sourcePath, $originalName);
        } catch (\Exception $e) {
            Log::error('Conversion failed', [
                'original_name' => $originalName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Convert image to PDF
     */
    private function convertImageToPdf(string $imagePath, string $originalName): string
    {
        $pdf = new Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Get image dimensions
        list($width, $height) = getimagesize($imagePath);
        
        // Calculate PDF page size (in mm)
        // Assuming 96 DPI: 1 inch = 25.4 mm, 96 pixels = 1 inch
        $pageWidth = ($width / 96) * 25.4;
        $pageHeight = ($height / 96) * 25.4;
        
        // Limit maximum dimensions to A4 equivalent
        $maxWidth = 210; // A4 width in mm
        $maxHeight = 297; // A4 height in mm
        
        if ($pageWidth > $maxWidth || $pageHeight > $maxHeight) {
            $ratio = min($maxWidth / $pageWidth, $maxHeight / $pageHeight);
            $pageWidth *= $ratio;
            $pageHeight *= $ratio;
        }
        
        // Add page with custom size
        $pdf->AddPage('P', [$pageWidth, $pageHeight]);
        
        // Add image to fill the entire page
        $pdf->Image($imagePath, 0, 0, $pageWidth, $pageHeight, '', '', '', false, 300, '', false, false, 0);
        
        // Save to temp file
        $tempPath = $this->getTempPath($originalName);
        Storage::put($tempPath, $pdf->Output('', 'S'));
        
        Log::info('Image converted to PDF', ['temp_path' => $tempPath]);
        
        return $tempPath;
    }

    /**
     * Convert text file to PDF
     */
    private function convertTextToPdf(string $textPath, string $originalName): string
    {
        $pdf = new Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();
        
        // Read text content
        $content = file_get_contents($textPath);
        
        // Set font
        $pdf->SetFont('courier', '', 10);
        
        // Add content
        $pdf->MultiCell(0, 5, $content, 0, 'L');
        
        // Save to temp file
        $tempPath = $this->getTempPath($originalName);
        Storage::put($tempPath, $pdf->Output('', 'S'));
        
        Log::info('Text converted to PDF', ['temp_path' => $tempPath]);
        
        return $tempPath;
    }

    /**
     * Convert Word/RTF documents to PDF using LibreOffice
     */
    private function convertDocumentToPdf(string $sourcePath, string $originalName): string
    {
        return $this->convertUsingLibreOffice($sourcePath, $originalName);
    }

    /**
     * Convert PowerPoint presentations to PDF using LibreOffice
     */
    private function convertPresentationToPdf(string $sourcePath, string $originalName): string
    {
        return $this->convertUsingLibreOffice($sourcePath, $originalName);
    }

    /**
     * Convert Excel spreadsheets to PDF using LibreOffice
     */
    private function convertSpreadsheetToPdf(string $sourcePath, string $originalName): string
    {
        return $this->convertUsingLibreOffice($sourcePath, $originalName);
    }

    /**
     * Convert files using LibreOffice command line
     * 
     * Requires LibreOffice to be installed:
     * - Ubuntu/Debian: sudo apt-get install libreoffice
     * - macOS: brew install --cask libreoffice
     * - Windows: Download from libreoffice.org
     */
    private function convertUsingLibreOffice(string $sourcePath, string $originalName): string
    {
        // Check if LibreOffice is installed
        $libreOfficePath = $this->getLibreOfficePath();
        
        if (!$libreOfficePath) {
            throw new \Exception('LibreOffice is not installed. Please install LibreOffice for document conversion.');
        }

        // Create temp directory for conversion
        $tempDir = storage_path('app/' . self::TEMP_PATH);
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Copy source file to temp directory with original name
        $tempSourcePath = $tempDir . '/' . Str::uuid() . '_' . $originalName;
        copy($sourcePath, $tempSourcePath);

        try {
            // Run LibreOffice conversion
            $command = sprintf(
                '%s --headless --convert-to pdf --outdir %s %s 2>&1',
                escapeshellarg($libreOfficePath),
                escapeshellarg($tempDir),
                escapeshellarg($tempSourcePath)
            );

            Log::info('Running LibreOffice conversion', ['command' => $command]);

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                Log::error('LibreOffice conversion failed', [
                    'return_code' => $returnCode,
                    'output' => $output
                ]);
                throw new \Exception('Document conversion failed: ' . implode("\n", $output));
            }

            // Find the generated PDF
            $pdfName = pathinfo($tempSourcePath, PATHINFO_FILENAME) . '.pdf';
            $generatedPdfPath = $tempDir . '/' . $pdfName;

            if (!file_exists($generatedPdfPath)) {
                throw new \Exception('Converted PDF not found');
            }

            // Move to storage
            $storagePath = self::TEMP_PATH . '/' . Str::uuid() . '.pdf';
            Storage::put($storagePath, file_get_contents($generatedPdfPath));

            // Clean up temp files
            @unlink($tempSourcePath);
            @unlink($generatedPdfPath);

            Log::info('Document converted to PDF using LibreOffice', ['storage_path' => $storagePath]);

            return $storagePath;

        } catch (\Exception $e) {
            // Clean up on failure
            @unlink($tempSourcePath);
            throw $e;
        }
    }

    /**
     * Get LibreOffice executable path
     */
    private function getLibreOfficePath(): ?string
    {
        $possiblePaths = [
            '/usr/bin/libreoffice',           // Linux
            '/usr/bin/soffice',                // Linux alternative
            '/Applications/LibreOffice.app/Contents/MacOS/soffice', // macOS
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe', // Windows
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe', // Windows 32-bit
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // Try to find in PATH
        exec('which libreoffice 2>/dev/null', $output);
        if (!empty($output[0])) {
            return $output[0];
        }

        exec('which soffice 2>/dev/null', $output);
        if (!empty($output[0])) {
            return $output[0];
        }

        return null;
    }

    /**
     * Generate temporary storage path
     */
    private function getTempPath(string $originalName): string
    {
        $filename = Str::uuid() . '.pdf';
        return self::TEMP_PATH . '/' . $filename;
    }

    /**
     * Clean up temporary conversion files
     */
    public function cleanupTempFiles(int $olderThanHours = 24): void
    {
        $files = Storage::files(self::TEMP_PATH);
        $cutoffTime = now()->subHours($olderThanHours);

        foreach ($files as $file) {
            if (Storage::lastModified($file) < $cutoffTime->timestamp) {
                Storage::delete($file);
                Log::info('Deleted old temp conversion file', ['file' => $file]);
            }
        }
    }

    /**
     * Get human-readable file type name
     */
    public function getFileTypeName(string $mimeType): string
    {
        $typeMap = [
            'image/jpeg' => 'JPEG Image',
            'image/png' => 'PNG Image',
            'image/gif' => 'GIF Image',
            'image/bmp' => 'BMP Image',
            'image/webp' => 'WebP Image',
            'image/tiff' => 'TIFF Image',
            'application/msword' => 'Word Document',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word Document',
            'text/plain' => 'Text File',
            'application/rtf' => 'RTF Document',
            'application/vnd.ms-powerpoint' => 'PowerPoint Presentation',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'PowerPoint Presentation',
            'application/vnd.ms-excel' => 'Excel Spreadsheet',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel Spreadsheet',
        ];

        return $typeMap[$mimeType] ?? 'Unknown';
    }
}