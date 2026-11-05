<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Credenciais de um app Omie. O secret fica criptografado na base.
 */
#[Fillable(['automacao_id', 'nome', 'app_key', 'app_secret', 'ativo'])]
#[Hidden(['app_secret'])]
class OmieApp extends Model
{
    protected $table = 'omie_apps';

    protected function casts(): array
    {
        return [
            'app_secret' => 'encrypted',
            'ativo' => 'boolean',
        ];
    }

    public function empresas(): HasMany
    {
        return $this->hasMany(Empresa::class);
    }

    public function estaPronta(): bool
    {
        return $this->ativo
            && filled($this->app_key)
            && filled($this->app_secret);
    }
}
