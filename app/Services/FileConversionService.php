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
        /*--------------------------
                    Images
         ---------------------------*/
        'image/jpeg' => 'convertImageToPdf',
        'image/jpg' => 'convertImageToPdf',
        'image/png' => 'convertImageToPdf',
        'image/gif' => 'convertImageToPdf',
        'image/bmp' => 'convertImageToPdf',
        'image/webp' => 'convertImageToPdf',
        'image/tiff' => 'convertImageToPdf',
        'image/heic' => 'convertImageToPdf',

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

        $processedImage = $this->preprocessImage($imagePath, $originalName);
        $processedImagePath = $processedImage['path'];

        try {
            $dimensions = $this->getImageDimensions($processedImagePath);

            if (!$dimensions) {
                throw new \Exception("Unable to read image dimensions for: {$originalName}");
            }

            list($width, $height) = $dimensions;

            Log::info("Image width & height: ", ["width" => $width, "height" => $height]);

            // A4 dimensions in mm
            $a4Width = 210;
            $a4Height = 297;

            // Determine orientation based on image aspect ratio
            $imageRatio = $width / $height;
            $a4Ratio = $a4Width / $a4Height;

            // Choose A4 orientation that best fits the image
            if ($imageRatio > 1) {
                // Image is landscape - use landscape A4
                $pageWidth = $a4Height;  // 297mm
                $pageHeight = $a4Width;  // 210mm
                $orientation = 'L';
            } else {
                // Image is portrait or square - use portrait A4
                $pageWidth = $a4Width;   // 210mm
                $pageHeight = $a4Height; // 297mm
                $orientation = 'P';
            }

            // Add A4 page
            $pdf->AddPage($orientation);

            // Calculate image dimensions to fit within A4 while maintaining aspect ratio
            $imageWidthMm = ($width / 96) * 25.4;  // Convert pixels to mm (assuming 96 DPI)
            $imageHeightMm = ($height / 96) * 25.4;

            // Scale down if image is larger than page
            if ($imageWidthMm > $pageWidth || $imageHeightMm > $pageHeight) {
                $scale = min($pageWidth / $imageWidthMm, $pageHeight / $imageHeightMm);
                $imageWidthMm *= $scale;
                $imageHeightMm *= $scale;
            }

            // Center the image on the page
            $x = ($pageWidth - $imageWidthMm) / 2;
            $y = ($pageHeight - $imageHeightMm) / 2;

            // Add image centered on page
            $pdf->Image(
                $processedImagePath,
                $x,                 // x position (centered)
                $y,                 // y position (centered)
                $imageWidthMm,      // width
                $imageHeightMm,     // height
                '',                 // type (auto-detect)
                '',                 // link
                '',                 // align
                false,              // resize
                300,                // dpi for quality
                '',                 // palign
                false,              // ismask
                false,              // imgmask
                0,                  // border
                false,              // fitbox
                false,              // hidden
                true                // fitonpage
            );

            // Save to temp file
            $tempPath = $this->getTempPath($originalName);
            Storage::put($tempPath, $pdf->Output('', 'S'));

            Log::info('Image converted to PDF', [
                'temp_path' => $tempPath,
                'original_dimensions' => "{$width}x{$height}px",
                'pdf_dimensions' => "{$imageWidthMm}x{$imageHeightMm}mm",
                'page_size' => "A4 {$orientation}",
                'centered_at' => "x:{$x}mm, y:{$y}mm"
            ]);

            return $tempPath;
        } finally {
            if (!empty($processedImage['cleanup'])) {
                Storage::delete($processedImage['cleanup']);
            }
        }
    }

    /**
     * Get image dimensions
     */
    private function getImageDimensions(string $imagePath): ?array
    {
        $dimensions = @getimagesize($imagePath);

        if ($dimensions !== false && $dimensions[0] > 0 && $dimensions[1] > 0) {
            return [$dimensions[0], $dimensions[1]];
        }

        return null;
    }

    /**
     * Check if image is HEIC format
     */
    private function isHeicImage(string $imagePath): bool
    {
        $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        return in_array($extension, ['heic', 'heif']);
    }

    /**
     * Convert HEIC to a supported format using CLI tools if needed.
     *
     * @return array{path:string,cleanup:?string}
     */
    private function preprocessImage(string $imagePath, string $originalName): array
    {
        $originalExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $isHeic = in_array($originalExtension, ['heic', 'heif'], true) || $this->isHeicImage($imagePath);

        if (!$isHeic) {
            return [
                'path' => $imagePath,
                'cleanup' => null,
            ];
        }

        $converted = $this->convertHeicUsingCli($imagePath);
        if ($converted) {
            return $converted;
        }

        throw new \Exception(
            "Unable to process HEIC image: {$originalName}. Install heif-convert or ffmpeg, or pre-convert to JPEG/PNG."
        );
    }

    /**
     * Try converting HEIC to JPEG using available CLI tools.
     *
     * @return array{path:string,cleanup:string}|null
     */
    private function convertHeicUsingCli(string $imagePath): ?array
    {
        $heifConvertPath = $this->getHeifConvertPath();
        $ffmpegPath = $this->getFfmpegPath();

        if (!$heifConvertPath && !$ffmpegPath) {
            return null;
        }

        Storage::makeDirectory(self::TEMP_PATH);
        $tempRelativePath = self::TEMP_PATH . '/' . Str::uuid() . '.jpg';
        $tempFullPath = Storage::path($tempRelativePath);

        if ($heifConvertPath) {
            $command = sprintf(
                '%s %s %s 2>/dev/null',
                escapeshellarg($heifConvertPath),
                escapeshellarg($imagePath),
                escapeshellarg($tempFullPath)
            );

            Log::info('Attempting HEIC conversion using heif-convert', ['command' => $command]);

            exec($command, $output, $returnCode);
            if ($returnCode === 0 && file_exists($tempFullPath) && filesize($tempFullPath) > 0) {
                return [
                    'path' => $tempFullPath,
                    'cleanup' => $tempRelativePath,
                ];
            }

            Log::warning('heif-convert failed', [
                'return_code' => $returnCode,
                'output' => $output,
            ]);
        }

        if ($ffmpegPath) {
            $command = sprintf(
                '%s -y -i %s -frames:v 1 %s 2>/dev/null',
                escapeshellarg($ffmpegPath),
                escapeshellarg($imagePath),
                escapeshellarg($tempFullPath)
            );

            Log::info('Attempting HEIC conversion using ffmpeg', ['command' => $command]);

            exec($command, $output, $returnCode);
            if ($returnCode === 0 && file_exists($tempFullPath) && filesize($tempFullPath) > 0) {
                return [
                    'path' => $tempFullPath,
                    'cleanup' => $tempRelativePath,
                ];
            }

            Log::warning('ffmpeg conversion failed', [
                'return_code' => $returnCode,
                'output' => $output,
            ]);
        }

        if (file_exists($tempFullPath)) {
            Storage::delete($tempRelativePath);
        }

        return null;
    }

    /**
     * Get heif-convert executable path
     */
    private function getHeifConvertPath(): ?string
    {
        $possiblePaths = [
            '/usr/bin/heif-convert',
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        exec('which heif-convert 2>/dev/null', $output);
        if (!empty($output[0])) {
            return $output[0];
        }

        return null;
    }

    /**
     * Get ffmpeg executable path
     */
    private function getFfmpegPath(): ?string
    {
        $possiblePaths = [
            '/usr/bin/ffmpeg',
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        exec('which ffmpeg 2>/dev/null', $output);
        if (!empty($output[0])) {
            return $output[0];
        }

        return null;
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
            'image/heic' => 'HEIC Image',
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
