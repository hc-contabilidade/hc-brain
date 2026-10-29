<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Context;

/**
 * Quem está perguntando, em qual modo e em qual tela do HC Brain.
 * Vive no Context do request para o prompt e as ferramentas lerem
 * sem receber o usuário de mão em mão.
 */
final class SofiaContexto
{
    public const MODOS = ['search', 'base'];

    public static function definir(User $usuario, string $modo, ?string $tela = null): void
    {
        Context::add('sofia.nome', $usuario->name);
        Context::add('sofia.perfil', (string) $usuario->perfil);
        Context::add('sofia.area', (string) $usuario->area);
        Context::add('sofia.modo', in_array($modo, self::MODOS, true) ? $modo : 'search');
        Context::add('sofia.tela', self::telaValida($tela) ?? '');
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

    /** Id da tela atual (ex.: reunioes), ou vazio se veio da Sofia IA. */
    public static function tela(): string
    {
        return (string) Context::get('sofia.tela', '');
    }

    public static function telaValida(?string $tela): ?string
    {
        if ($tela === null || $tela === '') {
            return null;
        }

        return Telas::porId($tela) !== null ? $tela : null;
    }

    public static function tituloDaTela(): ?string
    {
        $id = self::tela();
        if ($id === '') {
            return null;
        }

        $tela = Telas::porId($id);

        return is_array($tela) ? (string) ($tela['titulo'] ?? $id) : null;
    }
}
