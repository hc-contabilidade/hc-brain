<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'periodo', 'plataforma', 'views', 'likes', 'comentarios',
    'compartilhamentos', 'inscritos_ou_alcance', 'seguidores',
])]
class MarketingMetrica extends Model
{
    protected $table = 'marketing_metricas';

    /** @var list<string> */
    public const PLATAFORMAS = ['youtube', 'instagram'];
}
