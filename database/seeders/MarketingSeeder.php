<?php

namespace Database\Seeders;

use App\Models\MarketingConteudo;
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

        foreach ($this->conteudos() as $linha) {
            MarketingConteudo::query()->updateOrCreate(
                [
                    'titulo' => $linha['titulo'],
                    'plataforma' => $linha['plataforma'],
                    'periodo' => $linha['periodo'],
                ],
                $linha,
            );
        }

        if (MarketingIdeia::query()->count() > 0) {
            return;
        }

        foreach ($this->ideias() as $ordem => $ideia) {
            MarketingIdeia::query()->create([...$ideia, 'ordem' => $ordem + 1]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function metricas(): array
    {
        // YouTube: inscritos_ou_alcance = novos no mês; seguidores = total do canal.
        // Instagram: inscritos_ou_alcance = alcance; seguidores = base de seguidores.
        return [
            ['periodo' => '2026-06', 'plataforma' => 'youtube', 'views' => 2800, 'likes' => 190, 'comentarios' => 28, 'compartilhamentos' => 12, 'inscritos_ou_alcance' => 28, 'seguidores' => 7820],
            ['periodo' => '2026-06', 'plataforma' => 'instagram', 'views' => 6400, 'likes' => 820, 'comentarios' => 55, 'compartilhamentos' => 90, 'inscritos_ou_alcance' => 4100, 'seguidores' => 3180],
            ['periodo' => '2026-07', 'plataforma' => 'youtube', 'views' => 3200, 'likes' => 230, 'comentarios' => 35, 'compartilhamentos' => 15, 'inscritos_ou_alcance' => 32, 'seguidores' => 7852],
            ['periodo' => '2026-07', 'plataforma' => 'instagram', 'views' => 7100, 'likes' => 910, 'comentarios' => 62, 'compartilhamentos' => 105, 'inscritos_ou_alcance' => 4800, 'seguidores' => 3265],
            ['periodo' => '2026-08', 'plataforma' => 'youtube', 'views' => 3600, 'likes' => 260, 'comentarios' => 41, 'compartilhamentos' => 18, 'inscritos_ou_alcance' => 35, 'seguidores' => 7887],
            ['periodo' => '2026-08', 'plataforma' => 'instagram', 'views' => 8300, 'likes' => 1050, 'comentarios' => 74, 'compartilhamentos' => 120, 'inscritos_ou_alcance' => 5400, 'seguidores' => 3370],
            ['periodo' => '2026-09', 'plataforma' => 'youtube', 'views' => 4200, 'likes' => 310, 'comentarios' => 48, 'compartilhamentos' => 22, 'inscritos_ou_alcance' => 45, 'seguidores' => 7932],
            ['periodo' => '2026-09', 'plataforma' => 'instagram', 'views' => 9100, 'likes' => 1200, 'comentarios' => 95, 'compartilhamentos' => 140, 'inscritos_ou_alcance' => 6400, 'seguidores' => 3495],
            // Outubro: salto de inscritos após o vídeo destaque (+100 vs setembro).
            ['periodo' => '2026-10', 'plataforma' => 'youtube', 'views' => 9100, 'likes' => 720, 'comentarios' => 112, 'compartilhamentos' => 64, 'inscritos_ou_alcance' => 145, 'seguidores' => 8077],
            ['periodo' => '2026-10', 'plataforma' => 'instagram', 'views' => 12800, 'likes' => 1680, 'comentarios' => 140, 'compartilhamentos' => 210, 'inscritos_ou_alcance' => 8200, 'seguidores' => 3680],
            ['periodo' => '2026-11', 'plataforma' => 'youtube', 'views' => 4800, 'likes' => 340, 'comentarios' => 52, 'compartilhamentos' => 24, 'inscritos_ou_alcance' => 52, 'seguidores' => 8129],
            ['periodo' => '2026-11', 'plataforma' => 'instagram', 'views' => 9700, 'likes' => 1180, 'comentarios' => 88, 'compartilhamentos' => 150, 'inscritos_ou_alcance' => 6900, 'seguidores' => 3765],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function conteudos(): array
    {
        return [
            [
                'titulo' => 'Como ler o DAS sem medo',
                'plataforma' => 'youtube',
                'periodo' => '2026-10',
                'publicado_em' => '2026-10-08',
                'views' => 6800,
                'likes' => 540,
                'comentarios' => 86,
                'ganho_inscritos' => 118,
                'ganho_seguidores' => 0,
                'destaque' => true,
                'url' => 'https://youtube.com/@healthcarecontabilidade/videos',
            ],
            [
                'titulo' => 'Bastidores da abertura de empresa',
                'plataforma' => 'youtube',
                'periodo' => '2026-10',
                'publicado_em' => '2026-10-22',
                'views' => 1600,
                'likes' => 120,
                'comentarios' => 18,
                'ganho_inscritos' => 19,
                'ganho_seguidores' => 0,
                'destaque' => false,
                'url' => 'https://youtube.com/@healthcarecontabilidade/videos',
            ],
            [
                'titulo' => 'Checklist MEI em 60 segundos',
                'plataforma' => 'instagram',
                'periodo' => '2026-10',
                'publicado_em' => '2026-10-14',
                'views' => 5400,
                'likes' => 780,
                'comentarios' => 64,
                'ganho_inscritos' => 0,
                'ganho_seguidores' => 92,
                'destaque' => true,
                'url' => 'https://instagram.com/healthcarecontabilidade/',
            ],
            [
                'titulo' => 'Reels: mitos do Simples Nacional',
                'plataforma' => 'instagram',
                'periodo' => '2026-09',
                'publicado_em' => '2026-09-18',
                'views' => 3900,
                'likes' => 510,
                'comentarios' => 41,
                'ganho_inscritos' => 0,
                'ganho_seguidores' => 48,
                'destaque' => false,
                'url' => 'https://instagram.com/healthcarecontabilidade/',
            ],
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
