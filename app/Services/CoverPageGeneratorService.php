<?php

namespace App\Services;

use setasign\Fpdi\Tcpdf\Fpdi;
use Illuminate\Support\Facades\Log;

class CoverPageGeneratorService
{
    /**
     * Generate cover page PDF from template and data
     */
    public function generateCoverPage(array $coverPageData): string
    {
        $templateKey = $coverPageData['template_key'] ?? 'legal_cover_v1';
        $values = $coverPageData['values'] ?? [];

        Log::info('Generating cover page', [
            'template' => $templateKey,
            'values_structure' => $values
        ]);

        // Extract fields from the values structure
        // Values come as: { page: {...}, fields: [...] }
        $fields = $values['fields'] ?? [];
        $pageConfig = $values['page'] ?? [
            'size' => 'A4',
            'margin' => 20,
            'orientation' => 'portrait'
        ];

        $pdf = new Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set up page based on template config
        $orientation = $pageConfig['orientation'] === 'landscape' ? 'L' : 'P';
        $format = $pageConfig['size'] === 'Letter' ? 'LETTER' : 'A4';
        
        $pdf->AddPage($orientation, $format);
        $pdf->SetMargins(
            $pageConfig['margin'],
            $pageConfig['margin'],
            $pageConfig['margin']
        );

        Log::info('Cover page fields', [
            'field_count' => count($fields),
            'fields' => $fields
        ]);

        // Render each field
        foreach ($fields as $field) {
            $value = $field['value'] ?? '';

            if (empty($value)) {
                Log::debug('Skipping empty field', ['field' => $field['name']]);
                continue; // Skip empty fields
            }

            Log::debug('Rendering field', [
                'name' => $field['name'],
                'value' => $value,
                'x' => $field['x'],
                'y' => $field['y']
            ]);

            $this->renderField($pdf, $field, $value);
        }

        // Return PDF as string
        return $pdf->Output('', 'S');
    }

  /**
     * Render a single field on the PDF
     */
    private function renderField(Fpdi $pdf, array $field, string $value): void
    {
        // Parse font
        $fontParts = explode('-', $field['font']);
        $fontFamily = strtolower($fontParts[0]);
        $fontStyle = isset($fontParts[1]) && strtolower($fontParts[1]) === 'bold' ? 'B' : '';

        // Handle bold flag if present
        if (isset($field['bold']) && $field['bold'] === true) {
            $fontStyle = 'B';
        }

        // Set font
        $pdf->SetFont($fontFamily, $fontStyle, $field['size']);

        // Set color (default black)
        $color = $field['color'] ?? '#000000';
        list($r, $g, $b) = $this->hexToRgb($color);
        $pdf->SetTextColor($r, $g, $b);

        // Position
        $x = $field['x'];
        $y = $field['y'];

        // Alignment
        $align = strtoupper(substr($field['align'], 0, 1)); // L, C, R

        // Get page width for center/right alignment
        $pageWidth = $pdf->getPageWidth();
        $margins = $pdf->getMargins();

        // Handle multi-line text
        $maxWidth = $field['maxWidth'] ?? 0;
        
        if ($maxWidth > 0) {
            // Use MultiCell for text wrapping
            $pdf->SetXY($x, $y);
            $pdf->MultiCell($maxWidth, 0, $value, 0, $align, false, 1);
        } else {
            // Single line - handle alignment properly
            if ($align === 'C') {
                // For center alignment, position is the center point
                // Calculate the text width and adjust x position
                $textWidth = $pdf->GetStringWidth($value);
                $actualX = $x - ($textWidth / 2);
                $pdf->SetXY($actualX, $y);
                $pdf->Cell($textWidth, 0, $value, 0, 0, 'L');
            } elseif ($align === 'R') {
                // For right alignment, position is the right edge
                $textWidth = $pdf->GetStringWidth($value);
                $actualX = $x - $textWidth;
                $pdf->SetXY($actualX, $y);
                $pdf->Cell($textWidth, 0, $value, 0, 0, 'L');
            } else {
                // Left alignment - use position as-is
                $pdf->SetXY($x, $y);
                $pdf->Cell(0, 0, $value, 0, 0, 'L');
            }
        }
    }
    /**
     * Convert hex color to RGB
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2))
        ];
    }
}