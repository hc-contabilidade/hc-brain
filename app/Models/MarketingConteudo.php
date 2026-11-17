<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'titulo', 'plataforma', 'periodo', 'publicado_em', 'views', 'likes',
    'comentarios', 'ganho_inscritos', 'ganho_seguidores', 'destaque', 'url',
])]
class MarketingConteudo extends Model
{
    protected $table = 'marketing_conteudos';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'publicado_em' => 'date',
            'destaque' => 'boolean',
        ];
    }
}
