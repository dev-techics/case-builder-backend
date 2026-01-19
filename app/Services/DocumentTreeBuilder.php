<?php
// Document Tree Builder Service
namespace App\Services;

use Illuminate\Support\Collection;

class DocumentTreeBuilder
{
    /**
     * Build a nested tree structure from flat documents
     */
    public function build(Collection $documents, $parentId = null): array
    {
        return $documents
            ->where('parent_id', $parentId)
            ->sortBy('order')
            ->map(function ($doc) use ($documents) {
                return [
                    'id' => (string) $doc->id,
                    'parent_id' => $doc->parent_id,
                    'name' => $doc->name,
                    'type' => $doc->type,
                    'url' => $doc->type === 'file'
                        ? route('documents.stream', $doc->id)
                        : null,
                    'children' => $doc->type === 'folder'
                        ? $this->build($documents, $doc->id)
                        : null,
                ];
            })
            ->values()
            ->toArray();
    }
}
