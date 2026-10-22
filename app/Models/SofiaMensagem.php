<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversa_id', 'autor', 'texto', 'voz'])]
class SofiaMensagem extends Model
{
    protected $table = 'sofia_mensagens';

    protected function casts(): array
    {
        return [
            'voz' => 'boolean',
        ];
    }

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(SofiaConversa::class, 'conversa_id');
    }
}
