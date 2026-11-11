<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Entidade principal do banco: cada empresa tem um sócio responsável, fontes
 * vinculadas diretamente a ela e as reuniões do relacionamento.
 */
#[Fillable([
    'id', 'automacao_id', 'codigo', 'documento', 'documento_tipo',
    'omie_codigo_cliente', 'omie_app_id',
    'nome', 'setor', 'status', 'status_tag',
])]
class Empresa extends Model
{
    protected $table = 'empresas';

    /** Cores de etiqueta que a interface sabe desenhar. */
    public const TAGS = ['green', 'blue', 'yellow', 'purple', 'gray', 'red'];

    public $incrementing = false;

    protected $keyType = 'string';

    /** CNPJ 00.000.000/0000-00, CPF 000.000.000-00, ou traço quando ainda não há documento. */
    public function documentoFormatado(): string
    {
        $digitos = preg_replace('/\D+/', '', (string) $this->documento) ?? '';

        return match (strlen($digitos)) {
            14 => preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digitos) ?: '—',
            11 => preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digitos) ?: '—',
            default => $digitos !== '' ? $digitos : '—',
        };
    }

    public function omieApp(): BelongsTo
    {
        return $this->belongsTo(OmieApp::class);
    }

    public function socio(): HasOne
    {
        return $this->hasOne(Socio::class);
    }

    public function fontes(): HasMany
    {
        return $this->hasMany(Fonte::class)->orderBy('ordem');
    }

    public function reunioes(): HasMany
    {
        return $this->hasMany(Reuniao::class)->orderByDesc('data');
    }

    /**
     * Só a reunião mais recente, para a lista de clientes: carregar todas as
     * reuniões de toda a carteira para mostrar uma por empresa pesa à toa.
     */
    public function ultimaReuniao(): HasOne
    {
        return $this->hasOne(Reuniao::class)->ofMany(['data' => 'max', 'id' => 'max']);
    }

    public function financeiro(): HasOne
    {
        return $this->hasOne(EmpresaFinanceiro::class);
    }

    public function receitasMensais(): HasMany
    {
        return $this->hasMany(ReceitaMensal::class)->orderBy('ordem');
    }

    public function transacoes(): HasMany
    {
        return $this->hasMany(Transacao::class)->orderBy('ordem');
    }

    public function treinamentos(): BelongsToMany
    {
        return $this->belongsToMany(Treinamento::class, 'empresa_treinamento')
            ->withPivot('ordem')
            ->orderBy('empresa_treinamento.ordem');
    }
}
