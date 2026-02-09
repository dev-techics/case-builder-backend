<?php

namespace App\Observers;

use App\Models\Document;
Use App\Services\IndexGenerationService;
use Illuminate\Support\Facades\Log;

class DocumentObserver
{
   protected IndexGenerationService $indexGenerator;

    public function __construct(IndexGenerationService $indexGenerator)
    {
        $this->indexGenerator = $indexGenerator;
    }

    /**
     * Handle the Document "created" event.
     */
    public function created(Document $document): void
    {
        $this->regenerateIndex($document);
    }

    /**
     * Handle the Document "updated" event.
     */
    public function updated(Document $document): void
    {
        // Only regenerate if name or order changed
        if ($document->isDirty(['name', 'order', 'parent_id'])) {
            $this->regenerateIndex($document);
        }
        Log::info("Index regenerated for reorder/rename");
    }

    /**
     * Handle the Document "deleted" event.
     */
    public function deleted(Document $document): void
    {
        $this->regenerateIndex($document);
    }

    /**
     * Regenerate index for the document's bundle
     */
    private function regenerateIndex(Document $document): void
    {
        try {
            if ($document->bundle) {
                Log::info('Regenerating index due to document change', [
                    'document_id' => $document->id,
                    'bundle_id' => $document->bundle_id,
                    'action' => debug_backtrace()[1]['function'] ?? 'unknown'
                ]);

                $this->indexGenerator->generateIndex($document->bundle);
            }
        } catch (\Exception $e) {
            Log::error('Failed to regenerate index in observer', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            // Don't throw - let the main operation succeed even if index fails
        }
    }
}
