<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Read-only: approved documents (from the books start) with no journal entry and no exclusion (v_posting_backlog). */
class PostingBacklog extends Model
{
    protected $table = 'v_posting_backlog';

    public $timestamps = false;

    public $incrementing = false;

    protected function casts(): array
    {
        return ['doc_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function documentUrl(): ?string
    {
        return JournalEntry::documentUrl($this->source_type, $this->source_id);
    }
}
