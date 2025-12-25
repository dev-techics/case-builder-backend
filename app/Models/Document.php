<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'bundle_id',
        'parent_id',
        'name',
        'type',
        'mime_type',
        'storage_path',
        'order',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /* -----------------------------------------
     | Relationships
     |------------------------------------------*/

    // A document belongs to a bundle
    public function bundle()
    {
        return $this->belongsTo(Bundle::class);
    }

    // Parent folder (self-referencing)
    public function parent()
    {
        return $this->belongsTo(Document::class, 'parent_id');
    }

    // Children (files or folders inside this folder)
    public function children()
    {
        return $this->hasMany(Document::class, 'parent_id')
            ->orderBy('order');
    }

    /* -----------------------------------------
     | Scopes
     |------------------------------------------*/

    public function scopeFiles($query)
    {
        return $query->where('type', 'file');
    }

    public function scopeFolders($query)
    {
        return $query->where('type', 'folder');
    }

    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    /* -----------------------------------------
     | Helpers
     |------------------------------------------*/

    public function isFile(): bool
    {
        return $this->type === 'file';
    }

    public function isFolder(): bool
    {
        return $this->type === 'folder';
    }
}
