<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Conta Google com permissão de leitura do YouTube Analytics (Insights do canal).
 */
#[Fillable(['email', 'nome', 'channel_id', 'refresh_token', 'sincronizada_em', 'erro'])]
#[Hidden(['refresh_token'])]
class ContaYoutubeAnalytics extends Model
{
    protected $table = 'contas_youtube_analytics';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'sincronizada_em' => 'datetime',
        ];
    }
}
