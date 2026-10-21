<?php

namespace Tests\Feature;

use App\Models\ContaGoogle;
use App\Models\Empresa;
use App\Models\Reuniao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgendasGoogleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'cliente', 'services.google.client_secret' => 'segredo']);
    }

    private function usuario(string $perfil): User
    {
        return User::create([
            'name' => "Pessoa {$perfil}", 'email' => strtolower($perfil).'@hc.com.br', 'password' => 'senha-de-teste',
            'perfil' => $perfil, 'area' => 'Gestão', 'status' => 'Ativo',
        ]);
    }

    public function test_so_administrador_conecta_e_desconecta_agenda(): void
    {
        $conta = ContaGoogle::create(['email' => 'ana@gmail.com', 'nome' => 'Ana', 'refresh_token' => 'refresh']);
        $colaborador = $this->usuario('Colaborador');

        $this->actingAs($colaborador)->get('/google/conectar')->assertForbidden();
        $this->actingAs($colaborador)->deleteJson("/api/agendas-google/{$conta->id}")->assertForbidden();

        $this->assertModelExists($conta);
    }

    public function test_conectar_passa_pelo_google_e_grava_a_conta_com_o_token_cifrado(): void
    {
        $administrador = $this->usuario('Administrador');
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'acesso', 'refresh_token' => 'refresh-da-ana']),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'ana@gmail.com', 'name' => 'Ana Souza']),
        ]);

        $ida = $this->actingAs($administrador)->get('/google/conectar');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $ida->headers->get('Location'));

        $this->actingAs($administrador)
            ->get('/google/retorno?'.http_build_query(['state' => session('google_estado'), 'code' => 'codigo']))
            ->assertRedirect('/usuarios?google=conectada');

        $conta = ContaGoogle::sole();
        $this->assertSame('ana@gmail.com', $conta->email);
        $this->assertSame('Ana Souza', $conta->nome);
        $this->assertSame('refresh-da-ana', $conta->refresh_token);
        $this->assertNotSame('refresh-da-ana', DB::table('contas_google')->value('refresh_token'));
    }

    public function test_retorno_que_nao_foi_pedido_nesta_sessao_e_recusado(): void
    {
        Http::preventStrayRequests();

        $this->actingAs($this->usuario('Administrador'))
            ->withSession(['google_estado' => 'pedido-desta-sessao'])
            ->get('/google/retorno?state=forjado&code=codigo')
            ->assertRedirect('/usuarios?google=sessao');

        $this->assertDatabaseCount('contas_google', 0);
    }

    public function test_sincronizar_agora_rele_a_agenda_inteira_e_conta_o_que_trouxe_so_para_administrador(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        ContaGoogle::create(['email' => 'ana@gmail.com', 'nome' => 'Ana', 'refresh_token' => 'refresh', 'sincronizada_em' => '2026-10-13 11:00:00']);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'acesso']),
            'www.googleapis.com/calendar/v3/*' => Http::response(['items' => [
                ['id' => 'com-meet', 'status' => 'confirmed', 'summary' => 'Conversa', 'start' => ['dateTime' => '2026-10-20T17:00:00Z'], 'hangoutLink' => 'https://meet.google.com/abc'],
                ['id' => 'sem-meet', 'status' => 'confirmed', 'summary' => 'Dentista', 'start' => ['dateTime' => '2026-10-21T17:00:00Z']],
            ]]),
        ]);

        $this->actingAs($this->usuario('Colaborador'))
            ->postJson('/api/agendas-google/sincronizar')
            ->assertForbidden();
        Http::assertNothingSent();

        $this->actingAs($this->usuario('Administrador'))
            ->postJson('/api/agendas-google/sincronizar')
            ->assertOk()
            ->assertJsonPath('contas.0.sincronizadaEm', '13/10/2026 09:00')
            ->assertJsonPath('contas.0.erro', null)
            ->assertJsonPath('relatorio.0', 'ana@gmail.com: 2 eventos lidos, 1 com Meet — 1 novas e 0 atualizadas.');

        Http::assertNotSent(fn (Request $pedido) => str_contains($pedido->url(), 'updatedMin'));
        $this->assertSame(1, Reuniao::count());
    }

    public function test_desconectar_mantem_as_reunioes_que_vieram_da_agenda(): void
    {
        $conta = ContaGoogle::create(['email' => 'ana@gmail.com', 'nome' => 'Ana', 'refresh_token' => 'refresh']);
        Empresa::create(['id' => 'clinica-vida', 'nome' => 'Clínica Vida', 'setor' => 'Saúde', 'status' => 'Ativo', 'status_tag' => 'green']);
        $reuniao = Reuniao::create([
            'empresa_id' => 'clinica-vida', 'conta_google_id' => $conta->id, 'google_evento_id' => 'abc',
            'tipo' => 'Alinhamento', 'data' => '2026-10-20', 'responsavel' => 'Ana', 'participantes' => [],
            'resumo' => 'Conversa', 'decisoes' => [], 'proximos_passos' => [], 'status' => 'Agendada',
        ]);

        $this->actingAs($this->usuario('Administrador'))
            ->deleteJson("/api/agendas-google/{$conta->id}")
            ->assertNoContent();

        $this->assertModelMissing($conta);
        $this->assertNull($reuniao->fresh()->conta_google_id);
    }
}
