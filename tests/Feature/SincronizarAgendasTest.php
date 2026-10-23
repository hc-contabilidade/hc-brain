<?php

namespace Tests\Feature;

use App\Models\ContaGoogle;
use App\Models\Empresa;
use App\Models\Reuniao;
use App\Models\User;
use Database\Seeders\UsuarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O Google é falso aqui: o endpoint de token devolve sempre um token de
 * acesso e o de eventos devolve o que cada teste monta.
 */
class SincronizarAgendasTest extends TestCase
{
    use RefreshDatabase;

    private const EVENTOS = 'www.googleapis.com/calendar/v3/calendars/primary/events*';

    private const TOKEN = 'oauth2.googleapis.com/token';

    private const AGENDA = 'www.googleapis.com/calendar/v3/users/me/calendarList/primary';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'cliente', 'services.google.client_secret' => 'segredo']);
        Http::fake([self::AGENDA => Http::response(['backgroundColor' => '#9FE1E7'])]);
    }

    private function conta(string $email = 'ana@hc.com.br', string $nome = 'Ana HC'): ContaGoogle
    {
        return ContaGoogle::create(['email' => $email, 'nome' => $nome, 'refresh_token' => "refresh-{$email}"]);
    }

    private function empresa(string $id, string $nome, string $emailDoSocio = '—'): Empresa
    {
        $empresa = Empresa::create(['id' => $id, 'nome' => $nome, 'setor' => 'Saúde', 'status' => 'Ativo', 'status_tag' => 'green']);
        $empresa->socio()->create([
            'nome' => 'Sócia', 'cargo' => 'Sócia', 'email' => $emailDoSocio,
            'telefone' => '—', 'participacao' => '—', 'desde' => '—',
        ]);

        return $empresa;
    }

    /**
     * @param  array<string, mixed>  $trocas
     * @return array<string, mixed>
     */
    private function evento(string $id, array $trocas = []): array
    {
        return [
            'id' => $id,
            'status' => 'confirmed',
            'summary' => 'Conversa',
            'start' => ['dateTime' => '2026-10-20T17:00:00Z'],
            'hangoutLink' => "https://meet.google.com/{$id}",
            ...$trocas,
        ];
    }

    public function test_evento_com_meet_vira_reuniao_da_empresa_do_socio_convidado(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->empresa('clinica-vida', 'CLINICA VIDA LTDA', 'Helena@Vida.com.br');
        $this->conta();
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => Http::response(['access_token' => 'acesso']),
            self::EVENTOS => Http::response(['items' => [
                $this->evento('abc-defg-hij', [
                    'summary' => 'Alinhamento mensal',
                    'attendees' => [
                        ['email' => 'ana@hc.com.br', 'self' => true],
                        ['email' => 'helena@vida.com.br', 'displayName' => 'Helena Dias'],
                        ['email' => 'sala@hc.com.br', 'resource' => true],
                    ],
                ]),
                $this->evento('sem-meet', ['hangoutLink' => null, 'summary' => 'Dentista']),
            ]]),
        ]);

        $this->artisan('hc:sincronizar-agendas')->assertSuccessful();

        $reuniao = Reuniao::sole();
        $this->assertSame('clinica-vida', $reuniao->empresa_id);
        $this->assertSame('Alinhamento', $reuniao->tipo);
        $this->assertSame('2026-10-20', $reuniao->data->toDateString());
        $this->assertSame('14:00', $reuniao->horario);
        $this->assertSame('Ana HC', $reuniao->responsavel);
        $this->assertSame(['Helena Dias'], $reuniao->participantes);
        $this->assertSame('Agendada', $reuniao->status);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $reuniao->link_meet);
    }

    public function test_colega_do_socio_liga_pela_empresa_do_dominio_e_a_cor_vem_do_google(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->empresa('clinica-vida', 'Clínica Vida', 'helena@vida.com.br');
        // Um sócio com e-mail no domínio da HC não faz toda reunião da equipe ser dele.
        $this->empresa('outra', 'Outra Empresa', 'contato@hc.com.br');
        $this->conta();
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => Http::response(['access_token' => 'acesso']),
            self::EVENTOS => Http::response(['items' => [
                $this->evento('cliente', [
                    'colorId' => '11',
                    'attendees' => [['email' => 'ana@hc.com.br', 'self' => true], ['email' => 'Joao@Vida.com.br']],
                ]),
                $this->evento('interna', [
                    'attendees' => [['email' => 'ana@hc.com.br', 'self' => true], ['email' => 'pedro@hc.com.br']],
                ]),
            ]]),
        ]);

        $this->artisan('hc:sincronizar-agendas')->assertSuccessful();

        $cliente = Reuniao::firstWhere('google_evento_id', 'cliente');
        $this->assertSame('clinica-vida', $cliente->empresa_id);
        $this->assertSame('#d50000', $cliente->cor);

        $interna = Reuniao::firstWhere('google_evento_id', 'interna');
        $this->assertNull($interna->empresa_id);
        $this->assertSame('#9fe1e7', $interna->cor);
    }

    public function test_banana_e_reuniao_interna_sem_empresa_e_salvia_e_abertura(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->empresa('clinica-vida', 'Clínica Vida', 'helena@vida.com.br');
        $this->conta();
        $socioConvidado = ['attendees' => [['email' => 'helena@vida.com.br']]];
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => Http::response(['access_token' => 'acesso']),
            self::EVENTOS => Http::sequence()
                ->push(['items' => [
                    $this->evento('banana', ['colorId' => '5', ...$socioConvidado]),
                    $this->evento('salvia', ['colorId' => '2', 'summary' => 'Comercial com a Clínica Vida']),
                    $this->evento('recolorida', $socioConvidado),
                ]])
                ->push(['items' => [$this->evento('recolorida', ['colorId' => '2', ...$socioConvidado])]]),
        ]);

        $this->artisan('hc:sincronizar-agendas')->assertSuccessful();

        $banana = Reuniao::firstWhere('google_evento_id', 'banana');
        $this->assertSame('Interna', $banana->tipo);
        $this->assertNull($banana->empresa_id);

        $salvia = Reuniao::firstWhere('google_evento_id', 'salvia');
        $this->assertSame('Abertura', $salvia->tipo);
        $this->assertSame('clinica-vida', $salvia->empresa_id);

        $this->assertSame('Outra', Reuniao::firstWhere('google_evento_id', 'recolorida')->tipo);

        // Pintar o evento de Sálvia no Google depois de importado também muda o tipo.
        $this->travel(5)->minutes();
        $this->artisan('hc:sincronizar-agendas')->assertSuccessful();

        $this->assertSame('Abertura', Reuniao::firstWhere('google_evento_id', 'recolorida')->tipo);
    }

    public function test_sem_socio_convidado_a_empresa_vem_do_titulo_e_sem_pista_fica_sem_empresa(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->seed(UsuarioSeeder::class);
        $this->empresa('clinica-vida', 'CLINICA VIDA LTDA');
        $this->conta();
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => Http::response(['access_token' => 'acesso']),
            self::EVENTOS => Http::response(['items' => [
                $this->evento('com-nome', ['summary' => 'Comercial — Clínica Vida']),
                $this->evento('sem-pista', ['summary' => 'Papo com prospect', 'start' => ['dateTime' => '2026-10-01T13:00:00Z']]),
            ]]),
        ]);

        $this->artisan('hc:sincronizar-agendas')->assertSuccessful();

        $comNome = Reuniao::firstWhere('google_evento_id', 'com-nome');
        $this->assertSame('clinica-vida', $comNome->empresa_id);
        $this->assertSame('Comercial', $comNome->tipo);

        $semPista = Reuniao::firstWhere('google_evento_id', 'sem-pista');
        $this->assertNull($semPista->empresa_id);
        $this->assertSame('Concluída', $semPista->status);
        $this->assertSame('Outra', $semPista->tipo);
        $this->assertSame('Papo com prospect', $semPista->titulo);

        // A agenda e o modal leem `empresa` de toda reunião, inclusive das sem empresa.
        $this->actingAs(User::firstOrFail())
            ->getJson('/api/reunioes')
            ->assertOk()
            ->assertJsonFragment([
                'empresa' => 'Papo com prospect',
                'empresaId' => null,
                'titulo' => 'Papo com prospect',
                'tipo' => 'Outra',
                'linkMeet' => 'https://meet.google.com/sem-pista',
                'agenda' => ['nome' => 'Ana', 'email' => 'ana@hc.com.br'],
            ]);
    }

    public function test_sinonimo_no_titulo_define_o_tipo_e_alinhamento_antigo_vira_outra(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->empresa('clinica-vida', 'Clínica Vida', 'helena@vida.com.br');
        $this->conta();
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => Http::response(['access_token' => 'acesso']),
            self::EVENTOS => Http::sequence()
                ->push(['items' => [
                    $this->evento('kick', [
                        'summary' => 'Kickoff com a Clínica Vida',
                        'attendees' => [['email' => 'helena@vida.com.br']],
                    ]),
                    $this->evento('velha', [
                        'summary' => 'Papo sem tipo',
                        'attendees' => [['email' => 'helena@vida.com.br']],
                    ]),
                ]])
                ->push(['items' => [
                    $this->evento('kick', [
                        'summary' => 'Kickoff com a Clínica Vida',
                        'attendees' => [['email' => 'helena@vida.com.br']],
                    ]),
                    $this->evento('velha', [
                        'summary' => 'Papo sem tipo',
                        'attendees' => [['email' => 'helena@vida.com.br']],
                    ]),
                ]]),
        ]);

        $this->artisan('hc:sincronizar-agendas')->assertSuccessful();

        $this->assertSame('Abertura', Reuniao::firstWhere('google_evento_id', 'kick')->tipo);
        $this->assertSame('Outra', Reuniao::firstWhere('google_evento_id', 'velha')->tipo);

        Reuniao::firstWhere('google_evento_id', 'velha')->update(['tipo' => 'Alinhamento']);

        $this->travel(5)->minutes();
        $this->artisan('hc:sincronizar-agendas')->assertSuccessful();

        $this->assertSame('Outra', Reuniao::firstWhere('google_evento_id', 'velha')->tipo);
    }

    public function test_cancelada_sem_pista_de_tipo_nao_fica_como_alinhamento(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->conta();
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => Http::response(['access_token' => 'acesso']),
            self::EVENTOS => Http::sequence()
                ->push(['items' => [
                    $this->evento('sumiu', ['summary' => 'Call com fornecedor']),
                ]])
                ->push(['items' => [
                    ['id' => 'sumiu', 'status' => 'cancelled', 'summary' => 'Call com fornecedor'],
                ]]),
        ]);

        $this->artisan('hc:sincronizar-agendas')->assertSuccessful();
        Reuniao::sole()->update(['tipo' => 'Alinhamento']);

        $this->travel(5)->minutes();
        $this->artisan('hc:sincronizar-agendas')->assertSuccessful();

        $reuniao = Reuniao::sole();
        $this->assertSame('Cancelada', $reuniao->status);
        $this->assertSame('Outra', $reuniao->tipo);
        $this->assertSame('Call com fornecedor', $reuniao->titulo);
    }

    public function test_conta_healthcare_aparece_como_equipe_hc_na_agenda(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->seed(UsuarioSeeder::class);
        $this->empresa('clinica-vida', 'CLINICA VIDA LTDA');
        $this->conta('comercial@hc.com.br', 'HealthCare Contabilidade');
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => Http::response(['access_token' => 'acesso']),
            self::EVENTOS => Http::response(['items' => [
                $this->evento('comercial-hc', ['summary' => 'Comercial — Clínica Vida']),
            ]]),
        ]);

        $this->artisan('hc:sincronizar-agendas')->assertSuccessful();

        $this->actingAs(User::firstOrFail())
            ->getJson('/api/reunioes')
            ->assertOk()
            ->assertJsonFragment([
                'agenda' => ['nome' => 'Equipe HC', 'email' => 'comercial@hc.com.br'],
            ]);
    }

    public function test_rodar_de_novo_segue_o_google_sem_apagar_o_que_a_equipe_registrou(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->conta();
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => Http::response(['access_token' => 'acesso']),
            self::EVENTOS => Http::sequence()
                ->push(['items' => [$this->evento('abc')]])
                ->push(['items' => [$this->evento('abc', ['start' => ['dateTime' => '2026-10-22T18:30:00Z']])]])
                ->push(['items' => [['id' => 'abc', 'status' => 'cancelled']]]),
        ]);

        $this->artisan('hc:sincronizar-agendas')->assertSuccessful();
        Reuniao::sole()->update(['resumo' => 'Ata escrita pela equipe', 'decisoes' => ['Renovar contrato']]);

        $this->travel(5)->minutes();
        $this->artisan('hc:sincronizar-agendas')->assertSuccessful();

        $reuniao = Reuniao::sole();
        $this->assertSame('2026-10-22', $reuniao->data->toDateString());
        $this->assertSame('15:30', $reuniao->horario);
        $this->assertSame('Ata escrita pela equipe', $reuniao->resumo);
        $this->assertSame(['Renovar contrato'], $reuniao->decisoes);

        $this->travel(5)->minutes();
        $this->artisan('hc:sincronizar-agendas')->assertSuccessful();

        $this->assertSame('Cancelada', Reuniao::sole()->status);

        // Da segunda leitura em diante só vem o que mudou desde a anterior, com folga.
        Http::assertSent(fn (Request $pedido) => str_contains($pedido->url(), 'calendar/v3')
            && str_contains(urldecode($pedido->url()), 'updatedMin=2026-10-13T11:55:00'));
    }

    public function test_conta_com_acesso_revogado_fica_marcada_sem_parar_as_outras(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $revogada = $this->conta('revogada@gmail.com', 'Revogada');
        $ativa = $this->conta('ativa@gmail.com', 'Ativa');
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => Http::sequence()
                ->push(['error' => 'invalid_grant'], 400)
                ->push(['access_token' => 'acesso']),
            self::EVENTOS => Http::response(['items' => [$this->evento('abc')]]),
        ]);

        $this->artisan('hc:sincronizar-agendas')->assertFailed();

        $this->assertSame('O Google recusou o acesso desta conta: conecte-a de novo.', $revogada->fresh()->erro);
        $this->assertNull($revogada->fresh()->sincronizada_em);
        $this->assertNull($ativa->fresh()->erro);
        $this->assertSame(1, $ativa->reunioes()->count());
    }

    public function test_falha_inesperada_ao_gravar_fica_registrada_na_conta_e_nada_e_gravado_pela_metade(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $conta = $this->conta();
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => Http::response(['access_token' => 'acesso']),
            self::EVENTOS => Http::response(['items' => [
                $this->evento('boa'),
                $this->evento('quebrada', ['start' => ['dateTime' => 'data-que-nao-existe']]),
            ]]),
        ]);

        $this->artisan('hc:sincronizar-agendas')->assertFailed();

        $this->assertStringStartsWith('A leitura da agenda falhou:', $conta->fresh()->erro);
        $this->assertNull($conta->fresh()->sincronizada_em);
        $this->assertSame(0, Reuniao::count());
    }

    public function test_sem_credenciais_no_servidor_as_contas_mostram_o_motivo(): void
    {
        config(['services.google.client_id' => null]);
        $conta = $this->conta();
        Http::preventStrayRequests();

        $this->artisan('hc:sincronizar-agendas')->assertFailed();

        $this->assertSame('GOOGLE_CLIENT_ID e GOOGLE_CLIENT_SECRET não estão definidos no servidor.', $conta->fresh()->erro);
    }
}
