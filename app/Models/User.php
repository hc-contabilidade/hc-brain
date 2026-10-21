<?php

namespace App\Models;

use App\Notifications\RedefinirSenha;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * Equipe com acesso ao HC Brain. Os campos extras (perfil, área, status e
 * último acesso) são os mesmos exibidos na tela de Usuários.
 */
#[Fillable(['name', 'email', 'password', 'perfil', 'area', 'status', 'ultimo_acesso'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use Notifiable;

    public const PERFIS = ['Administrador', 'Gestor', 'Colaborador'];

    public const AREAS = ['Gestão', 'Comercial', 'Operações', 'Atendimento', 'Financeiro', 'Projetos'];

    public const STATUS = ['Ativo', 'Inativo'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'ultimo_acesso' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function ehAdministrador(): bool
    {
        return $this->perfil === 'Administrador';
    }

    /** O e-mail de recuperação sai em português e com a marca do HC Brain. */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new RedefinirSenha($token));
    }

    /**
     * Iniciais do avatar. É derivação do nome, não informação própria, então
     * sai do nome toda vez em vez de virar uma coluna que pode desencontrar.
     */
    protected function iniciais(): Attribute
    {
        return Attribute::get(fn (): string => self::iniciaisDe($this->name));
    }

    /** Até duas letras do nome, em caixa alta. */
    public static function iniciaisDe(string $nome): string
    {
        $iniciais = collect(preg_split('/\s+/u', trim($nome), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->take(2)
            ->map(fn (string $parte) => Str::upper(Str::substr($parte, 0, 1)))
            ->implode('');

        return $iniciais !== '' ? $iniciais : 'U';
    }
}
