<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'titulo', 'descricao', 'plataforma', 'status', 'responsavel', 'prioridade', 'ordem',
])]
class MarketingIdeia extends Model
{
    protected $table = 'marketing_ideias';

    /** @var list<string> */
    public const PLATAFORMAS = ['youtube', 'instagram', 'ambos'];

    /** @var list<string> */
    public const STATUS = ['rascunho', 'aprovada', 'publicada', 'arquivada'];

    /** @var list<string> */
    public const PRIORIDADES = ['alta', 'media', 'baixa'];
}
