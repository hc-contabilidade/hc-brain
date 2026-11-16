<?php

namespace Tests\Feature;

use App\Models\Documento;
use App\Models\IndicadorComercial;
use App\Models\Pasta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O que as telas gravam. A API de leitura já tinha testes; aqui está o outro
 * lado, o que faz do HC Brain um sistema e não uma vitrine: cadastrar, editar
 * e excluir, com as regras que impedem a base de ficar incoerente.
 */
class EscritaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    private function autenticado(): static
    {
        return $this->actingAs(User::firstOrFail());
    }

    /** @return array<string, mixed> */
    private function empresaValida(array $trocas = []): array
    {
        return [
            'nome' => 'Clínica Bonsucesso',
            'setor' => 'Saúde',
            'status' => 'Cliente ativo',
            'statusTag' => 'green',
            'socio' => [
                'nome' => 'Helena Dias',
                'cargo' => 'Diretora clínica',
                'email' => 'helena@bonsucesso.com.br',
                'telefone' => '(11) 98888-1122',
                'participacao' => '70%',
                'desde' => 'Março de 2025',
            ],
            ...$trocas,
        ];
    }

    public function test_escrita_exige_sessao(): void
    {
        $this->postJson('/api/empresas', $this->empresaValida())->assertUnauthorized();
    }

    public function test_empresa_nao_pode_ser_criada_pela_api(): void
    {
        $this->autenticado()
            ->postJson('/api/empresas', $this->empresaValida())
            ->assertForbidden();
    }

    public function test_empresa_nao_pode_ser_editada_pela_api(): void
    {
        $this->autenticado()
            ->putJson('/api/empresas/empresaX', $this->empresaValida([
                'nome' => 'Empresa X',
                'status' => 'Em renovação',
                'statusTag' => 'yellow',
            ]))
            ->assertForbidden();
    }

    public function test_empresa_nao_pode_ser_excluida_pela_api(): void
    {
        $this->autenticado()
            ->deleteJson('/api/empresas/empresaX')
            ->assertForbidden();

        $this->assertDatabaseHas('empresas', ['id' => 'empresaX']);
    }

    public function test_reuniao_entra_na_base_com_as_listas(): void
    {
        $this->autenticado()
            ->postJson('/api/reunioes', [
                'empresaId' => 'empresaX',
                'tipo' => 'Alinhamento',
                'data' => '2026-10-02',
                'horario' => '14:30',
                'responsavel' => 'Matheus',
                'status' => 'Agendada',
                'resumo' => 'Alinhamento do trimestre com a diretoria.',
                'participantes' => ['Matheus', 'Flávia Silva'],
                'decisoes' => [],
                'proximosPassos' => ['Enviar a ata'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.tipo', 'Alinhamento')
            ->assertJsonPath('data.participantes.1', 'Flávia Silva');
    }

    public function test_reuniao_exige_empresa_da_carteira(): void
    {
        $this->autenticado()
            ->postJson('/api/reunioes', [
                'empresaId' => 'empresa-que-nao-existe',
                'tipo' => 'Alinhamento',
                'data' => '2026-10-02',
                'responsavel' => 'Matheus',
                'status' => 'Agendada',
                'resumo' => 'Resumo.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('empresaId');
    }

    public function test_so_reuniao_interna_fica_sem_empresa(): void
    {
        $semEmpresa = [
            'empresaId' => '',
            'data' => '2026-10-02',
            'responsavel' => 'Matheus',
            'status' => 'Agendada',
            'resumo' => 'Planejamento da equipe.',
        ];

        $this->autenticado()
            ->postJson('/api/reunioes', [...$semEmpresa, 'tipo' => 'Alinhamento'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('empresaId');

        $this->autenticado()
            ->postJson('/api/reunioes', [...$semEmpresa, 'tipo' => 'Interna'])
            ->assertCreated()
            ->assertJsonPath('data.empresaId', null)
            ->assertJsonPath('data.empresa', 'Sem empresa');
    }

    public function test_projeto_e_cadastrado_e_excluido(): void
    {
        $id = $this->autenticado()
            ->postJson('/api/projetos', [
                'nome' => 'Portal do paciente',
                'descricao' => 'Área logada para acompanhar exames.',
                'status' => 'Planejado',
                'prioridade' => 'Alta',
                'responsavel' => 'Ana Souza',
                'area' => 'Projetos',
                'progresso' => 0,
                'inicio' => '2026-10-01',
                'prazo' => '2027-02-01',
                'empresaId' => 'empresaX',
            ])
            ->assertCreated()
            ->assertJsonPath('data.empresa', 'Empresa X')
            ->json('data.id');

        $this->autenticado()->deleteJson("/api/projetos/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('projetos', ['id' => $id]);
    }

    public function test_projeto_recusa_prazo_anterior_ao_inicio(): void
    {
        $this->autenticado()
            ->postJson('/api/projetos', [
                'nome' => 'Projeto impossível',
                'descricao' => 'Entrega antes de começar.',
                'status' => 'Planejado',
                'prioridade' => 'Baixa',
                'responsavel' => 'Ana Souza',
                'area' => 'Projetos',
                'progresso' => 0,
                'inicio' => '2026-10-01',
                'prazo' => '2026-09-01',
                'empresaId' => null,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prazo');
    }

    public function test_processo_precisa_de_pelo_menos_uma_etapa(): void
    {
        $this->autenticado()
            ->postJson('/api/processos', [
                'nome' => 'Processo vazio',
                'descricao' => 'Sem etapa nenhuma.',
                'area' => 'Operações',
                'responsavel' => 'Carlos Mendes',
                'frequencia' => 'Sob demanda',
                'etapas' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('etapas');
    }

    public function test_processo_guarda_as_etapas_na_ordem_enviada(): void
    {
        $this->autenticado()
            ->postJson('/api/processos', [
                'nome' => 'Onboarding de colaborador',
                'descricao' => 'Primeira semana de quem entra na HC.',
                'area' => 'Gestão',
                'responsavel' => 'Beatriz',
                'frequencia' => 'A cada contratação',
                'etapas' => ['Criar os acessos', 'Apresentar a equipe', 'Definir o plano de 30 dias'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.etapas.2', 'Definir o plano de 30 dias');
    }

    public function test_treinamento_entra_na_base_de_conteudos(): void
    {
        $this->autenticado()
            ->postJson('/api/treinamentos', [
                'titulo' => 'LGPD na prática',
                'tipo' => 'Curso',
                'categoria' => 'Compliance',
                'nivel' => 'Iniciante',
                'descricao' => 'O que muda no dia a dia de quem lida com dados de paciente.',
                'trilha' => null,
                'cursos' => null,
                'processo' => null,
                'link' => 'https://capacitacao.example/lgpd',
            ])
            ->assertCreated()
            ->assertJsonPath('data.titulo', 'LGPD na prática')
            ->assertJsonPath('data.link', 'https://capacitacao.example/lgpd');

        $this->autenticado()
            ->getJson('/api/treinamentos')
            ->assertJsonCount(20, 'conteudos');
    }

    public function test_upload_guarda_o_arquivo_e_deriva_o_tipo(): void
    {
        $pasta = Pasta::firstOrFail();

        // Upload é multipart, não JSON; o Accept é o que a tela manda, e é
        // ele que faz o erro de validação voltar como 422 em vez de redirect.
        $resposta = $this->autenticado()
            ->post('/api/documentos', [
                'pastaId' => $pasta->id,
                'nome' => 'Contrato social atualizado',
                'arquivo' => UploadedFile::fake()->create('contrato.pdf', 120, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.tipo', 'pdf')
            ->assertJsonPath('data.nome', 'Contrato social atualizado');

        $documento = Documento::findOrFail($resposta->json('data.id'));
        Storage::disk(config('hc.documentos.disco'))->assertExists($documento->arquivo);
    }

    public function test_upload_recusa_extensao_fora_da_lista(): void
    {
        $this->autenticado()
            ->post('/api/documentos', [
                'pastaId' => Pasta::firstOrFail()->id,
                'arquivo' => UploadedFile::fake()->create('script.exe', 10),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('arquivo');
    }

    public function test_download_sai_pelo_laravel_e_exige_sessao(): void
    {
        $documento = Documento::whereNotNull('arquivo')->firstOrFail();

        $this->get("/api/documentos/{$documento->id}/arquivo")->assertRedirect('/login');

        $this->autenticado()
            ->get("/api/documentos/{$documento->id}/arquivo")
            ->assertOk()
            ->assertDownload($documento->nomeDoArquivo());
    }

    public function test_download_funciona_quando_o_disco_sumiu_mas_a_base_tem_o_conteudo(): void
    {
        Storage::fake(config('hc.documentos.disco'));

        $pasta = Pasta::firstOrFail();
        $caminho = 'documentos/'.$pasta->id.'/arquivo-perdido.pdf';
        $bytes = "%PDF-1.4\nlista de usuarios\n%%EOF\n";

        $documento = Documento::create([
            'pasta_id' => $pasta->id,
            'nome' => 'Lista de Usuários',
            'extensao' => 'pdf',
            'arquivo' => $caminho,
            'conteudo' => $bytes,
            'tamanho' => strlen($bytes),
            'mime' => 'application/pdf',
            'enviado_por' => User::firstOrFail()->id,
        ]);

        Storage::disk(config('hc.documentos.disco'))->assertMissing($caminho);

        $resposta = $this->autenticado()
            ->get("/api/documentos/{$documento->id}/arquivo")
            ->assertOk();

        $this->assertStringContainsString('attachment', (string) $resposta->headers->get('content-disposition'));
        $this->assertSame($bytes, $resposta->streamedContent());
        Storage::disk(config('hc.documentos.disco'))->assertExists($caminho);
    }

    public function test_download_inline_abre_pdf_no_navegador(): void
    {
        Storage::fake(config('hc.documentos.disco'));

        $pasta = Pasta::firstOrFail();
        $bytes = "%PDF-1.4\npreview\n%%EOF\n";
        $documento = Documento::create([
            'pasta_id' => $pasta->id,
            'nome' => 'Previa Inline',
            'extensao' => 'pdf',
            'arquivo' => 'documentos/'.$pasta->id.'/previa.pdf',
            'conteudo' => $bytes,
            'tamanho' => strlen($bytes),
            'mime' => 'application/pdf',
            'enviado_por' => User::firstOrFail()->id,
        ]);

        $this->autenticado()
            ->get("/api/documentos/{$documento->id}/arquivo?inline=1")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename="Previa Inline.pdf"');
    }

    public function test_download_aceita_conteudo_bytea_como_stream_do_postgres(): void
    {
        Storage::fake(config('hc.documentos.disco'));

        $pasta = Pasta::firstOrFail();
        $caminho = 'documentos/'.$pasta->id.'/stream-postgres.pdf';
        $bytes = "%PDF-1.4\nstream postgres\n%%EOF\n";

        $documento = Documento::create([
            'pasta_id' => $pasta->id,
            'nome' => 'Stream Postgres',
            'extensao' => 'pdf',
            'arquivo' => $caminho,
            'conteudo' => $bytes,
            'tamanho' => strlen($bytes),
            'mime' => 'application/pdf',
            'enviado_por' => User::firstOrFail()->id,
        ]);

        Storage::disk(config('hc.documentos.disco'))->delete($caminho);

        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, $bytes);
        rewind($stream);

        $documento->setRawAttributes([
            ...$documento->getAttributes(),
            'conteudo' => $stream,
        ], true);

        $this->assertSame($bytes, Documento::decodificarConteudo($stream));

        $this->autenticado()
            ->get("/api/documentos/{$documento->id}/arquivo")
            ->assertOk()
            ->assertDownload($documento->nomeDoArquivo());
    }

    public function test_download_regenera_lista_de_usuarios_quando_o_blob_sumiu(): void
    {
        Storage::fake(config('hc.documentos.disco'));

        $pasta = Pasta::firstOrFail();
        $caminho = 'documentos/'.$pasta->id.'/lista-perdida.pdf';

        $documento = Documento::create([
            'pasta_id' => $pasta->id,
            'nome' => 'Informações sobre Usuários Cadastrados',
            'extensao' => 'pdf',
            'arquivo' => $caminho,
            'conteudo' => null,
            'tamanho' => 961,
            'mime' => 'application/pdf',
            'enviado_por' => User::firstOrFail()->id,
        ]);

        Storage::disk(config('hc.documentos.disco'))->assertMissing($caminho);

        $resposta = $this->autenticado()
            ->get("/api/documentos/{$documento->id}/arquivo")
            ->assertOk();

        $this->assertStringContainsString('attachment', (string) $resposta->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $resposta->streamedContent());
        $this->assertNotNull($documento->fresh()->lerBytes());
    }

    public function test_download_regenera_txt_de_lista_de_usuarios_quando_o_blob_sumiu(): void
    {
        Storage::fake(config('hc.documentos.disco'));

        $pasta = Pasta::firstOrFail();
        $caminho = 'documentos/'.$pasta->id.'/lista-perdida.txt';

        $documento = Documento::create([
            'pasta_id' => $pasta->id,
            'nome' => 'Lista de Usuários Cadastrados',
            'extensao' => 'txt',
            'arquivo' => $caminho,
            'conteudo' => null,
            'tamanho' => 168,
            'mime' => 'text/plain',
            'enviado_por' => User::firstOrFail()->id,
        ]);

        Storage::disk(config('hc.documentos.disco'))->assertMissing($caminho);

        $resposta = $this->autenticado()
            ->get("/api/documentos/{$documento->id}/arquivo")
            ->assertOk();

        $corpo = $resposta->streamedContent();
        $this->assertStringContainsString('Lista de Usuários Cadastrados', $corpo);
        $this->assertStringContainsString('Total:', $corpo);
        $this->assertNotNull($documento->fresh()->lerBytes());
    }

    public function test_excluir_documento_apaga_o_arquivo_do_disco(): void
    {
        $documento = Documento::whereNotNull('arquivo')->firstOrFail();
        $caminho = $documento->arquivo;

        $this->autenticado()
            ->deleteJson("/api/documentos/{$documento->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('documentos', ['id' => $documento->id]);
        Storage::disk(config('hc.documentos.disco'))->assertMissing($caminho);
    }

    public function test_pasta_nova_aparece_com_total_zero(): void
    {
        $this->autenticado()
            ->postJson('/api/pastas', ['nome' => 'Auditorias'])
            ->assertCreated();

        $pastas = $this->autenticado()->getJson('/api/documentos')->json('pastas');
        $auditorias = collect($pastas)->firstWhere('nome', 'Auditorias');

        $this->assertSame(0, $auditorias['total']);
    }

    public function test_excluir_pasta_leva_os_documentos_dela(): void
    {
        $pasta = Pasta::has('documentos')->firstOrFail();

        $this->autenticado()->deleteJson("/api/pastas/{$pasta->id}")->assertNoContent();

        $this->assertDatabaseMissing('documentos', ['pasta_id' => $pasta->id]);
    }

    public function test_ninguem_exclui_o_proprio_acesso(): void
    {
        $eu = User::firstOrFail();

        $this->actingAs($eu)
            ->deleteJson("/api/usuarios/{$eu->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('users', ['id' => $eu->id]);
    }

    /** Sem administrador ativo não há quem governe o sistema. */
    public function test_ultimo_administrador_nao_pode_ser_rebaixado_nem_excluido(): void
    {
        $administrador = User::where('perfil', 'Administrador')->where('status', 'Ativo')->firstOrFail();
        $outro = User::where('perfil', '!=', 'Administrador')->firstOrFail();

        $this->actingAs($outro)
            ->deleteJson("/api/usuarios/{$administrador->id}")
            ->assertUnprocessable();

        $this->actingAs($outro)
            ->putJson("/api/usuarios/{$administrador->id}", [
                'nome' => $administrador->name,
                'email' => $administrador->email,
                'perfil' => 'Colaborador',
                'area' => $administrador->area,
                'status' => 'Ativo',
            ])
            ->assertUnprocessable();
    }

    /** Senha em branco na edição quer dizer "não mexer", não "apagar". */
    public function test_editar_usuario_sem_senha_mantem_a_atual(): void
    {
        $usuario = User::where('email', 'ana.souza@healthcare.com.br')->firstOrFail();
        $hashAntigo = $usuario->password;

        $this->autenticado()
            ->putJson("/api/usuarios/{$usuario->id}", [
                'nome' => 'Ana Souza Lima',
                'email' => $usuario->email,
                'perfil' => 'Colaborador',
                'area' => 'Projetos',
                'status' => 'Ativo',
                'senha' => '',
            ])
            ->assertOk()
            ->assertJsonPath('data.nome', 'Ana Souza Lima');

        $this->assertSame($hashAntigo, $usuario->fresh()->password);
    }

    public function test_periodos_comerciais_saem_da_base(): void
    {
        IndicadorComercial::query()->updateOrCreate(['periodo' => '2026-10'], [
            'leads' => 11,
            'qualified' => 7,
            'meetings' => 64,
            'meetings_done' => 3,
            'proposals' => 0,
            'closed' => 4,
            'abertura' => 3,
            'transferencia' => 1,
            'rebotes' => 4,
            'revenue' => 0,
            'average_ticket' => 0,
            'in_process' => 255,
            'monthly' => [['Out', 11]],
            'origins' => [['Orgânico', 11, '#6178ef']],
            'sources' => [['Indicação', 11, '#6178ef']],
            'funnel' => [['Fechado', 4]],
            'losses' => [],
            'sales' => [],
            'team' => [],
        ]);

        $this->autenticado()
            ->getJson('/api/comercial')
            ->assertOk()
            ->assertJsonPath('0.id', '2026-10')
            ->assertJsonPath('0.rotulo', 'Outubro 2026');
    }
}
