<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class CoverPageGeneratorService
{
    /**
     * Generate cover page PDF from stored HTML
     */
    public function generateCoverPage(string $html, array $options = []): string
    {
        $html = $html ?? '';
        if (trim($html) === '') {
            Log::warning('Cover page HTML is empty, skipping render');
            return '';
        }

        $config = array_merge([
            'format' => 'A4',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
        ], $options);

        $mpdf = new Mpdf($config);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
