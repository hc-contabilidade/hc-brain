<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Catálogo dos modelos que a Sofia pode usar. Cada id da tela aponta para
 * um provedor (ChatGPT/OpenAI ou Gemini) e o nome que a API espera.
 */
final class SofiaModelos
{
    /**
     * @return array<string, array{rotulo: string, subtitulo: string, provedor: string, api: string}>
     */
    public static function catalogo(): array
    {
        /** @var array<string, array{rotulo: string, subtitulo: string, provedor: string, api: string}> */
        return config('hc.sofia.modelos', []);
    }

    /**
     * Modelos cuja chave do provedor está definida no ambiente.
     *
     * @return array<string, array{rotulo: string, subtitulo: string, provedor: string, api: string}>
     */
    public static function disponiveis(): array
    {
        return array_filter(
            self::catalogo(),
            fn (array $modelo): bool => self::provedorConfigurado((string) $modelo['provedor']),
        );
    }

    /**
     * @return list<string>
     */
    public static function idsDisponiveis(): array
    {
        return array_keys(self::disponiveis());
    }

    public static function algumDisponivel(): bool
    {
        return self::disponiveis() !== [];
    }

    /** Modelo padrão da config, ou o primeiro disponível. */
    public static function padrao(): ?string
    {
        $preferido = (string) config('hc.sofia.chat.modelo', '');
        $disponiveis = self::idsDisponiveis();

        if ($preferido !== '' && in_array($preferido, $disponiveis, true)) {
            return $preferido;
        }

        return $disponiveis[0] ?? null;
    }

    /**
     * Lista pronta para a tela: só o que o ambiente consegue chamar.
     *
     * @return list<array{id: string, rotulo: string, subtitulo: string, provedor: string}>
     */
    public static function paraTela(): array
    {
        $lista = [];

        foreach (self::disponiveis() as $id => $modelo) {
            $lista[] = [
                'id' => $id,
                'rotulo' => $modelo['rotulo'],
                'subtitulo' => $modelo['subtitulo'],
                'provedor' => $modelo['provedor'],
            ];
        }

        return $lista;
    }

    /**
     * Resolve id da tela → dados do provedor + nome da API.
     *
     * @return array{id: string, rotulo: string, subtitulo: string, provedor: string, api: string, chave: string, url: string}
     */
    public static function resolver(?string $id): array
    {
        $escolhido = $id !== null && $id !== '' ? $id : self::padrao();
        $disponiveis = self::disponiveis();

        if ($escolhido === null || ! isset($disponiveis[$escolhido])) {
            throw new InvalidArgumentException('Modelo da Sofia indisponível.');
        }

        $modelo = $disponiveis[$escolhido];
        $provedor = self::provedor((string) $modelo['provedor']);

        return [
            'id' => $escolhido,
            'rotulo' => $modelo['rotulo'],
            'subtitulo' => $modelo['subtitulo'],
            'provedor' => $modelo['provedor'],
            'api' => $modelo['api'],
            'chave' => $provedor['chave'],
            'url' => $provedor['url'],
        ];
    }

    public static function provedorConfigurado(string $provedor): bool
    {
        try {
            return filled(self::provedor($provedor)['chave']);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @return array{chave: string, url: string}
     */
    public static function provedor(string $id): array
    {
        $provedores = config('hc.sofia.provedores', []);
        $provedor = is_array($provedores) ? ($provedores[$id] ?? null) : null;

        if (! is_array($provedor)) {
            throw new InvalidArgumentException("Provedor da Sofia desconhecido: {$id}.");
        }

        $chave = (string) ($provedor['chave'] ?? '');
        if ($chave === '' && $id === 'openai') {
            $chave = (string) config('hc.sofia.chave', '');
        }

        return [
            'chave' => $chave,
            'url' => (string) ($provedor['url'] ?? ''),
        ];
    }
}
