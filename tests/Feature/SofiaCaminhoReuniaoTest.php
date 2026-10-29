<?php

namespace Tests\Feature;

use App\Models\Reuniao;
use App\Support\SofiaTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O caminho que a Sofia devolve para uma reunião precisa abrir essa
 * reunião no HC Brain (/reunioes?reuniao=id), e não a empresa ou a lista.
 */
class SofiaCaminhoReuniaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_caminho_da_reuniao_abre_o_detalhe_na_agenda(): void
    {
        $this->seed();

        $reuniao = Reuniao::create([
            'empresa_id' => 'empresaX',
            'tipo' => 'Alinhamento',
            'data' => '2026-10-22',
            'horario' => '09:00',
            'responsavel' => 'Ana',
            'participantes' => [],
            'resumo' => 'Pauta',
            'decisoes' => [],
            'proximos_passos' => [],
            'status' => 'Agendada',
            'link_meet' => 'https://meet.google.com/abc-defg-hij',
        ]);

        $bruto = SofiaTools::executar('listar_reunioes', [
            'data' => '2026-10-22',
            'status' => 'Agendada',
        ]);
        $corpo = json_decode($bruto, true);
        $item = collect($corpo['reunioes'] ?? [])->firstWhere('id', $reuniao->id);

        $this->assertIsArray($item);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $item['link_meet']);
        $this->assertSame('/reunioes?reuniao='.$reuniao->id, $item['caminho']);
    }
}
