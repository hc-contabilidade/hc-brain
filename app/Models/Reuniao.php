<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'empresa_id', 'titulo', 'tipo', 'data', 'horario', 'responsavel',
    'participantes', 'resumo', 'decisoes', 'proximos_passos', 'status',
    'conta_google_id', 'google_evento_id', 'link_meet', 'cor',
])]
class Reuniao extends Model
{
    protected $table = 'reunioes';

    public const TIPOS = ['Abertura', 'Transferência', 'Dúvidas', 'Comercial', 'Alinhamento', 'Financeira', self::INTERNA, self::OUTRA];

    /** Reunião só da equipe da HC: não é de cliente, então pode ficar sem empresa. */
    public const INTERNA = 'Interna';

    /** Veio do Google sem cor nem palavra de tipo no título — não inventar Alinhamento. */
    public const OUTRA = 'Outra';

    public const STATUS = ['Concluída', 'Agendada', 'Cancelada'];

    public const SEM_EMPRESA = 'Sem empresa';

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'participantes' => 'array',
            'decisoes' => 'array',
            'proximos_passos' => 'array',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function contaGoogle(): BelongsTo
    {
        return $this->belongsTo(ContaGoogle::class);
    }

    /**
     * Com empresa, o nome dela. Sem empresa, o título do evento no Google —
     * "Sem empresa" só quando não há nenhum dos dois.
     */
    public function nomeDaEmpresa(): string
    {
        if ($this->empresa !== null) {
            return $this->empresa->nome;
        }

        $titulo = trim((string) $this->titulo);

        return $titulo !== '' ? $titulo : self::SEM_EMPRESA;
    }
}
