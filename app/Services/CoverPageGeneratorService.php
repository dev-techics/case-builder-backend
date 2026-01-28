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

        $template = $this->getTemplate($templateKey);

        if (!$template) {
            throw new \Exception("Template not found: {$templateKey}");
        }

        Log::info('Generating cover page', [
            'template' => $templateKey,
            'fields' => count($values)
        ]);

        $pdf = new Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set up page based on template config
        $pageConfig = $template['page'];
        $orientation = $pageConfig['orientation'] === 'landscape' ? 'L' : 'P';
        $format = $pageConfig['size'] === 'Letter' ? 'LETTER' : 'A4';
        
        $pdf->AddPage($orientation, $format);
        $pdf->SetMargins(
            $pageConfig['margin'],
            $pageConfig['margin'],
            $pageConfig['margin']
        );

        // Render each field
        foreach ($template['fields'] as $field) {
            $fieldName = $field['name'];
            $value = $values[$fieldName] ?? '';

            if (empty($value)) {
                continue; // Skip empty fields
            }

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

        // Handle multi-line text
        $maxWidth = $field['maxWidth'] ?? 0;
        
        if ($maxWidth > 0) {
            // Use MultiCell for text wrapping
            $pdf->SetXY($x, $y);
            $pdf->MultiCell($maxWidth, 0, $value, 0, $align, false, 1);
        } else {
            // Single line
            $pdf->SetXY($x, $y);
            $pdf->Cell(0, 0, $value, 0, 0, $align);
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

    /**
     * Get template definition
     */
    private function getTemplate(string $key): ?array
    {
        $templates = [
            'legal_cover_v1' => [
                'key' => 'legal_cover_v1',
                'name' => 'Legal Cover – Default',
                'page' => [
                    'size' => 'A4',
                    'margin' => 20,
                    'orientation' => 'portrait',
                ],
                'fields' => [
                    [
                        'name' => 'title',
                        'x' => 105,
                        'y' => 60,
                        'font' => 'times-bold',
                        'size' => 24,
                        'align' => 'center',
                    ],
                    [
                        'name' => 'case_number',
                        'x' => 105,
                        'y' => 85,
                        'font' => 'times',
                        'size' => 14,
                        'align' => 'center',
                    ],
                    [
                        'name' => 'court_name',
                        'x' => 105,
                        'y' => 105,
                        'font' => 'times',
                        'size' => 12,
                        'align' => 'center',
                    ],
                    [
                        'name' => 'prepared_for',
                        'x' => 30,
                        'y' => 140,
                        'font' => 'times-bold',
                        'size' => 11,
                        'align' => 'left',
                    ],
                    [
                        'name' => 'prepared_for_value',
                        'x' => 30,
                        'y' => 150,
                        'font' => 'times',
                        'size' => 11,
                        'align' => 'left',
                    ],
                    [
                        'name' => 'prepared_by',
                        'x' => 30,
                        'y' => 170,
                        'font' => 'times-bold',
                        'size' => 11,
                        'align' => 'left',
                    ],
                    [
                        'name' => 'prepared_by_value',
                        'x' => 30,
                        'y' => 180,
                        'font' => 'times',
                        'size' => 11,
                        'align' => 'left',
                    ],
                    [
                        'name' => 'date',
                        'x' => 30,
                        'y' => 200,
                        'font' => 'times-bold',
                        'size' => 11,
                        'align' => 'left',
                    ],
                    [
                        'name' => 'date_value',
                        'x' => 30,
                        'y' => 210,
                        'font' => 'times',
                        'size' => 11,
                        'align' => 'left',
                    ],
                ],
            ],
            'simple_cover_v1' => [
                'key' => 'simple_cover_v1',
                'name' => 'Simple Cover',
                'page' => [
                    'size' => 'A4',
                    'margin' => 20,
                    'orientation' => 'portrait',
                ],
                'fields' => [
                    [
                        'name' => 'title',
                        'x' => 105,
                        'y' => 100,
                        'font' => 'helvetica-bold',
                        'size' => 28,
                        'align' => 'center',
                    ],
                    [
                        'name' => 'subtitle',
                        'x' => 105,
                        'y' => 130,
                        'font' => 'helvetica',
                        'size' => 14,
                        'align' => 'center',
                    ],
                    [
                        'name' => 'date_value',
                        'x' => 105,
                        'y' => 260,
                        'font' => 'helvetica',
                        'size' => 12,
                        'align' => 'center',
                    ],
                ],
            ],
            'court_submission_v1' => [
                'key' => 'court_submission_v1',
                'name' => 'Court Submission',
                'page' => [
                    'size' => 'A4',
                    'margin' => 20,
                    'orientation' => 'portrait',
                ],
                'fields' => [
                    [
                        'name' => 'court_header',
                        'x' => 105,
                        'y' => 40,
                        'font' => 'times-bold',
                        'size' => 16,
                        'align' => 'center',
                    ],
                    [
                        'name' => 'case_number',
                        'x' => 105,
                        'y' => 60,
                        'font' => 'times',
                        'size' => 14,
                        'align' => 'center',
                    ],
                    [
                        'name' => 'case_title',
                        'x' => 105,
                        'y' => 90,
                        'font' => 'times-bold',
                        'size' => 18,
                        'align' => 'center',
                    ],
                    [
                        'name' => 'document_type',
                        'x' => 105,
                        'y' => 130,
                        'font' => 'times',
                        'size' => 14,
                        'align' => 'center',
                    ],
                    [
                        'name' => 'filed_by',
                        'x' => 30,
                        'y' => 170,
                        'font' => 'times',
                        'size' => 11,
                        'align' => 'left',
                    ],
                    [
                        'name' => 'counsel',
                        'x' => 30,
                        'y' => 190,
                        'font' => 'times',
                        'size' => 11,
                        'align' => 'left',
                    ],
                    [
                        'name' => 'date_value',
                        'x' => 105,
                        'y' => 260,
                        'font' => 'times',
                        'size' => 11,
                        'align' => 'center',
                    ],
                ],
            ],
        ];

        return $templates[$key] ?? null;
    }
}