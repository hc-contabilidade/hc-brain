<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Context;

/**
 * Quem está perguntando e em qual modo a Sofia deve consultar. Vive no
 * Context do request para o prompt e as ferramentas lerem sem receber
 * o usuário de mão em mão.
 */
final class SofiaContexto
{
    public const MODOS = ['search', 'base'];

    public static function definir(User $usuario, string $modo): void
    {
        Context::add('sofia.nome', $usuario->name);
        Context::add('sofia.perfil', (string) $usuario->perfil);
        Context::add('sofia.area', (string) $usuario->area);
        Context::add('sofia.modo', in_array($modo, self::MODOS, true) ? $modo : 'search');
    }

    public static function nome(): string
    {
        return (string) Context::get('sofia.nome', '');
    }

    public static function perfil(): string
    {
        return (string) Context::get('sofia.perfil', '');
    }

    public static function area(): string
    {
        return (string) Context::get('sofia.area', '');
    }

    public static function modo(): string
    {
        $modo = (string) Context::get('sofia.modo', 'search');

        return in_array($modo, self::MODOS, true) ? $modo : 'search';
    }

    public static function ehPesquisa(): bool
    {
        return self::modo() === 'search';
    }
}
