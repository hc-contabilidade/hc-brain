<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'periodo', 'leads', 'qualified', 'meetings', 'meetings_done', 'proposals', 'closed',
    'abertura', 'transferencia', 'rebotes',
    'revenue', 'average_ticket', 'in_process', 'monthly', 'origins', 'sources',
    'funnel', 'losses', 'sales', 'team',
])]
class IndicadorComercial extends Model
{
    protected $table = 'indicadores_comerciais';

    protected $primaryKey = 'periodo';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'monthly' => 'array',
            'origins' => 'array',
            'sources' => 'array',
            'funnel' => 'array',
            'losses' => 'array',
            'sales' => 'array',
            'team' => 'array',
        ];
    }
}
