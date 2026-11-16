<?php

namespace Database\Seeders;

use App\Models\MarketingIdeia;
use App\Models\MarketingMetrica;
use Illuminate\Database\Seeder;

/**
 * Dados fictícios para o rascunho da tela Marketing.
 * Métricas reais virão do hc:sincronizar-marketing.
 */
class MarketingSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->metricas() as $linha) {
            MarketingMetrica::query()->updateOrCreate(
                ['periodo' => $linha['periodo'], 'plataforma' => $linha['plataforma']],
                $linha,
            );
        }

        MarketingIdeia::query()->delete();

        foreach ($this->ideias() as $ordem => $ideia) {
            MarketingIdeia::query()->create([...$ideia, 'ordem' => $ordem + 1]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function metricas(): array
    {
        return [
            ['periodo' => '2026-06', 'plataforma' => 'youtube', 'views' => 2800, 'likes' => 190, 'comentarios' => 28, 'compartilhamentos' => 12, 'inscritos_ou_alcance' => 120],
            ['periodo' => '2026-06', 'plataforma' => 'instagram', 'views' => 6400, 'likes' => 820, 'comentarios' => 55, 'compartilhamentos' => 90, 'inscritos_ou_alcance' => 4100],
            ['periodo' => '2026-07', 'plataforma' => 'youtube', 'views' => 3200, 'likes' => 230, 'comentarios' => 35, 'compartilhamentos' => 15, 'inscritos_ou_alcance' => 140],
            ['periodo' => '2026-07', 'plataforma' => 'instagram', 'views' => 7100, 'likes' => 910, 'comentarios' => 62, 'compartilhamentos' => 105, 'inscritos_ou_alcance' => 4800],
            ['periodo' => '2026-08', 'plataforma' => 'youtube', 'views' => 3600, 'likes' => 260, 'comentarios' => 41, 'compartilhamentos' => 18, 'inscritos_ou_alcance' => 155],
            ['periodo' => '2026-08', 'plataforma' => 'instagram', 'views' => 8300, 'likes' => 1050, 'comentarios' => 74, 'compartilhamentos' => 120, 'inscritos_ou_alcance' => 5400],
            ['periodo' => '2026-09', 'plataforma' => 'youtube', 'views' => 4200, 'likes' => 310, 'comentarios' => 48, 'compartilhamentos' => 22, 'inscritos_ou_alcance' => 180],
            ['periodo' => '2026-09', 'plataforma' => 'instagram', 'views' => 9100, 'likes' => 1200, 'comentarios' => 95, 'compartilhamentos' => 140, 'inscritos_ou_alcance' => 6400],
            ['periodo' => '2026-10', 'plataforma' => 'youtube', 'views' => 5100, 'likes' => 390, 'comentarios' => 61, 'compartilhamentos' => 28, 'inscritos_ou_alcance' => 210],
            ['periodo' => '2026-10', 'plataforma' => 'instagram', 'views' => 11200, 'likes' => 1480, 'comentarios' => 110, 'compartilhamentos' => 175, 'inscritos_ou_alcance' => 7200],
            ['periodo' => '2026-11', 'plataforma' => 'youtube', 'views' => 3800, 'likes' => 270, 'comentarios' => 40, 'compartilhamentos' => 18, 'inscritos_ou_alcance' => 95],
            ['periodo' => '2026-11', 'plataforma' => 'instagram', 'views' => 8700, 'likes' => 980, 'comentarios' => 78, 'compartilhamentos' => 120, 'inscritos_ou_alcance' => 5900],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function ideias(): array
    {
        return [
            [
                'titulo' => 'Bastidores da abertura de empresa',
                'descricao' => 'Vídeo curto explicando as 3 primeiras etapas do onboarding contábil.',
                'plataforma' => 'youtube',
                'status' => 'aprovada',
                'responsavel' => 'Marketing HC',
                'prioridade' => 'alta',
            ],
            [
                'titulo' => 'Reels: mitos do Simples Nacional',
                'descricao' => '3 mitos em 30 segundos, com CTA para o comercial.',
                'plataforma' => 'instagram',
                'status' => 'rascunho',
                'responsavel' => 'Marketing HC',
                'prioridade' => 'media',
            ],
            [
                'titulo' => 'Depoimento de cliente — Clínica Vida',
                'descricao' => 'Entrevista rápida para YouTube + corte para Instagram.',
                'plataforma' => 'ambos',
                'status' => 'rascunho',
                'responsavel' => 'Beatriz',
                'prioridade' => 'alta',
            ],
            [
                'titulo' => 'Checklist MEI em 60 segundos',
                'descricao' => 'Lista visual do que o MEI precisa enviar todo mês.',
                'plataforma' => 'instagram',
                'status' => 'aprovada',
                'responsavel' => 'Marketing HC',
                'prioridade' => 'media',
            ],
            [
                'titulo' => 'Como ler o DAS sem medo',
                'descricao' => 'Explicação didática com tela compartilhadas e exemplos reais.',
                'plataforma' => 'youtube',
                'status' => 'rascunho',
                'responsavel' => 'Beatriz',
                'prioridade' => 'baixa',
            ],
        ];
    }
}
