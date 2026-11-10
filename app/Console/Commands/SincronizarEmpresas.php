<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Models\Socio;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Traz as empresas do automacao-2-hc para a carteira do HC Brain.
 *
 * O automacao-2-hc é a fonte da verdade: nome, setor, status e sócio são
 * regravados a cada execução, então editar por aqui uma empresa que veio de lá
 * só dura até a próxima sincronização. Reuniões, fontes e financeiro são do
 * HC Brain e ficam como estão. É seguro repetir: a empresa é reconhecida pelo
 * `automacao_id`, e não pelo nome, então nunca é duplicada.
 */
class SincronizarEmpresas extends Command
{
    protected $signature = 'hc:sincronizar-empresas';

    protected $description = 'Traz as empresas do automacao-2-hc para a carteira do HC Brain';

    /** @var array<string, array{0: string, 1: string}> status no automacao-2-hc => [rótulo, cor da etiqueta] */
    private const STATUS = [
        'ACTIVE' => ['Ativo', 'green'],
        'IN_OPENING' => ['Em abertura', 'blue'],
        'IN_TRANSFER' => ['Em transferência', 'purple'],
        'IN_DISCONNECTION' => ['Em desligamento', 'yellow'],
        'BLOCKED' => ['Bloqueado', 'red'],
        'INACTIVE' => ['Inativo', 'gray'],
    ];

    private const NAO_INFORMADO = '—';

    public function handle(): int
    {
        try {
            $origem = DB::connection('automacao');
            $companies = $this->companies($origem);
            $socios = $this->sociosPorCompany($origem);
        } catch (QueryException $erro) {
            $this->components->error('Não foi possível ler o banco do automacao-2-hc: '.$erro->getMessage());

            return self::FAILURE;
        }

        $existentes = Empresa::query()->get(['id', 'nome', 'automacao_id']);
        $idPorCompany = $existentes->whereNotNull('automacao_id')->pluck('id', 'automacao_id')->all();
        $idsOcupados = $existentes->pluck('id')->flip()->all();
        $donoDoNome = $existentes->pluck('id', 'nome')->all();

        $empresas = [];
        $linhasSocio = [];
        $novas = 0;

        foreach ($companies as $company) {
            $id = $idPorCompany[$company->id] ?? null;

            if ($id === null) {
                $id = $this->chaveLivre($this->nomeDe($company), $idsOcupados);
                $idsOcupados[$id] = true;
                $novas++;
            }

            $nome = $this->nomeUnico($company, $id, $donoDoNome);
            $donoDoNome[$nome] = $id;
            [$status, $statusTag] = $this->status($company);

            $empresas[] = [
                'id' => $id,
                'automacao_id' => $company->id,
                'codigo' => $this->codigoDe($company),
                'documento' => $this->documentoDe($company),
                'documento_tipo' => $this->documentoTipoDe($company),
                'nome' => $nome,
                'setor' => $this->setor($company),
                'status' => $status,
                'status_tag' => $statusTag,
            ];

            $linhasSocio[] = ['empresa_id' => $id, ...$this->socio($company, $socios[$company->id] ?? null)];
        }

        DB::transaction(function () use ($empresas, $linhasSocio) {
            foreach (array_chunk($empresas, 500) as $lote) {
                Empresa::upsert($lote, ['id'], [
                    'automacao_id', 'codigo', 'documento', 'documento_tipo',
                    'nome', 'setor', 'status', 'status_tag',
                ]);
            }

            foreach (array_chunk($linhasSocio, 500) as $lote) {
                Socio::upsert($lote, ['empresa_id'], ['nome', 'cargo', 'email', 'telefone', 'participacao', 'desde']);
            }
        });

        $atualizadas = count($empresas) - $novas;
        $this->components->info(count($empresas)." empresas sincronizadas: {$novas} novas e {$atualizadas} atualizadas.");

        return self::SUCCESS;
    }

    /**
     * Rascunho fica de fora porque ainda não é cliente. Empresa excluída no
     * automacao-2-hc continua vindo, com o status "Excluída", para não levar
     * junto as reuniões registradas aqui.
     *
     * @return Collection<int, object>
     */
    private function companies(ConnectionInterface $origem): Collection
    {
        $colunas = [
            'id', 'code', 'name', 'trade_name', 'status', 'metier',
            'segment_type', 'contract_start_date', 'deleted_at',
        ];

        try {
            return $origem->table('companies')
                ->where('draft', false)
                ->orderBy('id')
                ->get([...$colunas, 'document_number', 'document_type']);
        } catch (QueryException) {
            // O usuário de leitura pode não ter estas colunas. A lista segue
            // sem CNPJ, e o status continua sendo atualizado.
            return $origem->table('companies')
                ->where('draft', false)
                ->orderBy('id')
                ->get($colunas)
                ->each(function (stdClass $company): void {
                    $company->document_number = null;
                    $company->document_type = null;
                });
        }
    }

    /**
     * Um sócio por empresa: o administrador quando há, senão o vínculo mais
     * antigo ainda ativo, com o primeiro e-mail e telefone preenchidos dele.
     *
     * @return array<int, array{nome: string, administrador: bool, email: ?string, telefone: ?string}>
     */
    private function sociosPorCompany(ConnectionInterface $origem): array
    {
        $vinculos = $origem->table('company_partner')
            ->join('partners', 'partners.id', '=', 'company_partner.partner_id')
            ->whereNull('company_partner.deleted_at')
            ->whereNull('partners.deleted_at')
            ->where(fn ($query) => $query->whereNull('company_partner.status')->orWhere('company_partner.status', '!=', 'INACTIVE'))
            ->orderByDesc('company_partner.is_administrator')
            ->orderBy('company_partner.id')
            ->get(['company_partner.company_id', 'company_partner.partner_id', 'company_partner.is_administrator', 'partners.name'])
            ->unique('company_id');

        $contatos = $origem->table('partner_contact')
            ->join('contacts', 'contacts.id', '=', 'partner_contact.contact_id')
            ->whereNull('partner_contact.deleted_at')
            ->whereNull('contacts.deleted_at')
            ->orderByDesc('partner_contact.is_primary_contact')
            ->orderBy('partner_contact.id')
            ->get(['partner_contact.partner_id', 'contacts.email', 'contacts.phone_number'])
            ->groupBy('partner_id');

        return $vinculos->mapWithKeys(function (object $vinculo) use ($contatos) {
            $doSocio = $contatos->get($vinculo->partner_id, collect());

            return [$vinculo->company_id => [
                'nome' => $vinculo->name,
                'administrador' => (bool) $vinculo->is_administrator,
                'email' => $doSocio->pluck('email')->first(fn (?string $email) => filled($email)),
                'telefone' => $doSocio->pluck('phone_number')->first(fn (?string $telefone) => filled($telefone)),
            ]];
        })->all();
    }

    private function nomeDe(object $company): string
    {
        return trim((string) $company->trade_name) ?: trim((string) $company->name);
    }

    private function codigoDe(object $company): ?string
    {
        $codigo = trim((string) $company->code);

        return $codigo === '' ? null : $codigo;
    }

    private function documentoDe(object $company): ?string
    {
        $documento = preg_replace('/\D+/', '', (string) $company->document_number) ?? '';

        return $documento === '' ? null : $documento;
    }

    /** @return 'CNPJ'|'CPF'|null */
    private function documentoTipoDe(object $company): ?string
    {
        $tipo = strtoupper(trim((string) $company->document_type));

        return in_array($tipo, ['CNPJ', 'CPF'], true) ? $tipo : null;
    }

    /**
     * O nome é único na carteira. Duas empresas com o mesmo nome fantasia
     * (filiais, por exemplo) se diferenciam pelo código interno da HC.
     *
     * @param  array<string, string>  $donoDoNome
     */
    private function nomeUnico(object $company, string $id, array $donoDoNome): string
    {
        foreach ([$this->nomeDe($company), "{$this->nomeDe($company)} ({$company->code})"] as $nome) {
            if (($donoDoNome[$nome] ?? $id) === $id) {
                return $nome;
            }
        }

        return "{$this->nomeDe($company)} (#{$company->id})";
    }

    /**
     * Mesma regra do `Chave::apartirDe`, mas contra os ids já reservados nesta
     * execução, porque as empresas novas só são gravadas no fim, todas juntas.
     *
     * @param  array<string, bool>  $idsOcupados
     */
    private function chaveLivre(string $nome, array $idsOcupados): string
    {
        $base = Str::slug($nome) ?: 'empresa';
        $chave = $base;
        $sufixo = 2;

        while (isset($idsOcupados[$chave])) {
            $chave = "{$base}-{$sufixo}";
            $sufixo++;
        }

        return $chave;
    }

    /** O ramo de atuação quando preenchido; senão, o primeiro segmento da empresa. */
    private function setor(object $company): string
    {
        if (filled($company->metier)) {
            return trim($company->metier);
        }

        $segmentos = json_decode((string) $company->segment_type, true);
        $segmento = is_array($segmentos) ? ($segmentos[0] ?? null) : $company->segment_type;

        return filled($segmento) ? (string) $segmento : 'Não informado';
    }

    /** @return array{0: string, 1: string} */
    private function status(object $company): array
    {
        if ($company->deleted_at !== null) {
            return ['Excluída', 'gray'];
        }

        return self::STATUS[$company->status] ?? [Str::headline((string) $company->status), 'gray'];
    }

    /**
     * @param  array{nome: string, administrador: bool, email: ?string, telefone: ?string}|null  $socio
     * @return array{nome: string, cargo: string, email: string, telefone: string, participacao: string, desde: string}
     */
    private function socio(object $company, ?array $socio): array
    {
        $desde = $company->contract_start_date === null
            ? self::NAO_INFORMADO
            : 'Cliente desde '.Carbon::parse($company->contract_start_date)->locale('pt_BR')->translatedFormat('M/Y');

        return [
            'nome' => $socio['nome'] ?? 'Sócio não cadastrado',
            'cargo' => match ($socio['administrador'] ?? null) {
                true => 'Sócio administrador',
                false => 'Sócio',
                null => self::NAO_INFORMADO,
            },
            'email' => $socio['email'] ?? self::NAO_INFORMADO,
            'telefone' => $socio['telefone'] ?? self::NAO_INFORMADO,
            'participacao' => self::NAO_INFORMADO,
            'desde' => $desde,
        ];
    }
}
