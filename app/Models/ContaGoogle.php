<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma conta Google conectada por um administrador. Os eventos com Meet da
 * agenda principal dela viram reuniões, com o nome da conta como responsável.
 */
#[Fillable(['email', 'nome', 'refresh_token', 'sincronizada_em', 'erro'])]
#[Hidden(['refresh_token'])]
class ContaGoogle extends Model
{
    protected $table = 'contas_google';

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'sincronizada_em' => 'datetime',
        ];
    }

    public function reunioes(): HasMany
    {
        return $this->hasMany(Reuniao::class);
    }

    /**
     * Nome curto na agenda e em "Realiza": a conta comercial no Google
     * vem como "HealthCare", que fica vago ao lado da tag Comercial.
     */
    public function rotuloNaAgenda(): string
    {
        $nome = trim($this->nome);
        $email = strtolower(trim($this->email));

        if (
            ($nome !== '' && preg_match('/^healthcare\b/iu', $nome) === 1)
            || str_contains($email, 'healthcare')
        ) {
            return 'Equipe HC';
        }

        $primeiro = explode(' ', $nome)[0] ?? '';

        return $primeiro !== '' ? $primeiro : $this->email;
    }
}
