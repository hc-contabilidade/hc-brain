<?php

namespace App\Support;

/**
 * Encaixe para amanhã: YouTube Data API + Instagram Graph API.
 *
 * Nesta fase só confirma o que já está em marketing_metricas (seed/manual).
 * Quando as credenciais existirem, gravar por período/plataforma com
 * updateOrCreate — o contrato da tela /api/marketing não muda.
 */
final class MarketingMetricasSync
{
    /**
     * @return array{periodos: int, aviso: string|null}
     */
    public static function sincronizar(): array
    {
        // Stub: sem OAuth/API ainda. A tela já lê marketing_metricas.
        return [
            'periodos' => 0,
            'aviso' => 'Integração YouTube/Instagram ainda não configurada; use o seeder ou cadastro manual.',
        ];
    }
}
