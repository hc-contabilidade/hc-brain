<?php

namespace Database\Seeders;

use App\Models\Empresa;
use Illuminate\Database\Seeder;

/**
 * Empresas de demonstração (Empresa X, Y…). Sócio e fontes ficam para as
 * outras telas; o financeiro real vem do automacao/Omie, não do seeder.
 */
class EmpresaSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->empresas() as $dados) {
            $empresa = Empresa::updateOrCreate(['id' => $dados['id']], [
                'nome' => $dados['nome'],
                'setor' => $dados['setor'],
                'status' => $dados['status'],
                'status_tag' => $dados['status_tag'],
            ]);

            $empresa->socio()->delete();
            $empresa->socio()->create($dados['socio']);

            $empresa->fontes()->delete();
            foreach (array_values($dados['fontes']) as $ordem => $fonte) {
                $empresa->fontes()->create([...$fonte, 'ordem' => $ordem]);
            }

            // Limpa qualquer financeiro antigo dessas empresas fictícias.
            $empresa->transacoes()->delete();
            $empresa->receitasMensais()->delete();
            $empresa->financeiro()->delete();
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function empresas(): array
    {
        return [
            [
                'id' => 'empresaX',
                'nome' => 'Empresa X',
                'setor' => 'Tecnologia',
                'status' => 'Em negociação',
                'status_tag' => 'green',
                'socio' => [
                    'nome' => 'Flávia Silva',
                    'cargo' => 'Gerente Comercial',
                    'email' => 'flavia.silva@empresax.com.br',
                    'telefone' => '(11) 99887-2201',
                    'participacao' => '35%',
                    'desde' => 'Cliente desde jan/2026',
                ],
                'fontes' => [
                    ['tipo' => 'PDF', 'nome' => 'Proposta comercial.pdf', 'info' => 'Atualizado ontem'],
                    ['tipo' => 'PDF', 'nome' => 'Cadastro de clientes.pdf', 'info' => 'Atualizado hoje'],
                    ['tipo' => 'DOC', 'nome' => 'Fluxos e procedimentos.docx', 'info' => '01/09/2026'],
                    ['tipo' => 'XLS', 'nome' => 'Relatório mensal.xlsx', 'info' => '02/09/2026'],
                ],
                'financeiro' => [
                    'regime' => 'Simples Nacional', 'desde' => '2025-01-15',
                    'primeiro' => 4500, 'atual' => 8700, 'total' => 142600,
                    'saldo' => 18450, 'receber' => 8700, 'despesas' => 3150, 'margem' => 63,
                ],
                'receita_mensal' => [
                    ['Abr/26', 7200], ['Mai/26', 7600], ['Jun/26', 8100], ['Jul/26', 8400], ['Ago/26', 8700],
                ],
                'transacoes' => [
                    ['data' => '08/09/2026', 'descricao' => 'Honorário mensal', 'categoria' => 'Honorários', 'tipo' => 'Entrada', 'valor' => 8700, 'status' => 'Recebido'],
                    ['data' => '05/09/2026', 'descricao' => 'Serviço de implantação', 'categoria' => 'Projeto', 'tipo' => 'Entrada', 'valor' => 2400, 'status' => 'Pendente'],
                    ['data' => '02/09/2026', 'descricao' => 'Custos operacionais', 'categoria' => 'Despesas', 'tipo' => 'Saída', 'valor' => 1200, 'status' => 'Pago'],
                    ['data' => '28/08/2026', 'descricao' => 'Honorário mensal', 'categoria' => 'Honorários', 'tipo' => 'Entrada', 'valor' => 8400, 'status' => 'Recebido'],
                ],
            ],
            [
                'id' => 'empresaY',
                'nome' => 'Empresa Y',
                'setor' => 'Logística',
                'status' => 'Cliente ativo',
                'status_tag' => 'blue',
                'socio' => [
                    'nome' => 'João Pereira',
                    'cargo' => 'Diretor de Operações',
                    'email' => 'joao.pereira@empresay.com.br',
                    'telefone' => '(21) 98211-4470',
                    'participacao' => '50%',
                    'desde' => 'Cliente desde jun/2026',
                ],
                'fontes' => [
                    ['tipo' => 'PDF', 'nome' => 'Contrato de prestação de serviço.pdf', 'info' => '15/06/2026'],
                    ['tipo' => 'XLS', 'nome' => 'Plano de transferência.xlsx', 'info' => '20/08/2026'],
                    ['tipo' => 'DOC', 'nome' => 'Checklist de onboarding.docx', 'info' => '16/06/2026'],
                ],
                'financeiro' => [
                    'regime' => 'Lucro Presumido', 'desde' => '2024-06-10',
                    'primeiro' => 3200, 'atual' => 6800, 'total' => 168900,
                    'saldo' => 22100, 'receber' => 6800, 'despesas' => 2750, 'margem' => 60,
                ],
                'receita_mensal' => [
                    ['Abr/26', 5900], ['Mai/26', 6100], ['Jun/26', 6400], ['Jul/26', 6600], ['Ago/26', 6800],
                ],
                'transacoes' => [
                    ['data' => '08/09/2026', 'descricao' => 'Honorário mensal', 'categoria' => 'Honorários', 'tipo' => 'Entrada', 'valor' => 6800, 'status' => 'Recebido'],
                    ['data' => '04/09/2026', 'descricao' => 'Parcela contratual', 'categoria' => 'Contrato', 'tipo' => 'Entrada', 'valor' => 3200, 'status' => 'Pendente'],
                    ['data' => '01/09/2026', 'descricao' => 'Despesas administrativas', 'categoria' => 'Despesas', 'tipo' => 'Saída', 'valor' => 980, 'status' => 'Pago'],
                    ['data' => '28/08/2026', 'descricao' => 'Honorário mensal', 'categoria' => 'Honorários', 'tipo' => 'Entrada', 'valor' => 6600, 'status' => 'Recebido'],
                ],
            ],
            [
                'id' => 'empresaZ',
                'nome' => 'Empresa Z',
                'setor' => 'Varejo',
                'status' => 'Acompanhar',
                'status_tag' => 'yellow',
                'socio' => [
                    'nome' => 'Mariana Costa',
                    'cargo' => 'Coordenadora de Projetos',
                    'email' => 'mariana.costa@empresaz.com.br',
                    'telefone' => '(31) 97744-9012',
                    'participacao' => '20%',
                    'desde' => 'Cliente desde mar/2026',
                ],
                'fontes' => [
                    ['tipo' => 'PDF', 'nome' => 'Ata reunião Empresa X.pdf', 'info' => '28/08/2026'],
                    ['tipo' => 'DOC', 'nome' => 'Escopo do projeto.docx', 'info' => '30/08/2026'],
                ],
                'financeiro' => [
                    'regime' => 'Simples Nacional', 'desde' => '2025-03-20',
                    'primeiro' => 2800, 'atual' => 5200, 'total' => 96400,
                    'saldo' => 12600, 'receber' => 5200, 'despesas' => 1900, 'margem' => 63,
                ],
                'receita_mensal' => [
                    ['Abr/26', 4200], ['Mai/26', 4500], ['Jun/26', 4700], ['Jul/26', 5000], ['Ago/26', 5200],
                ],
                'transacoes' => [
                    ['data' => '08/09/2026', 'descricao' => 'Honorário mensal', 'categoria' => 'Honorários', 'tipo' => 'Entrada', 'valor' => 5200, 'status' => 'Recebido'],
                    ['data' => '03/09/2026', 'descricao' => 'Serviço adicional', 'categoria' => 'Serviços', 'tipo' => 'Entrada', 'valor' => 1500, 'status' => 'Pendente'],
                    ['data' => '30/08/2026', 'descricao' => 'Despesas administrativas', 'categoria' => 'Despesas', 'tipo' => 'Saída', 'valor' => 700, 'status' => 'Pago'],
                    ['data' => '05/08/2026', 'descricao' => 'Honorário mensal', 'categoria' => 'Honorários', 'tipo' => 'Entrada', 'valor' => 5000, 'status' => 'Recebido'],
                ],
            ],
            [
                'id' => 'empresaW',
                'nome' => 'Empresa W',
                'setor' => 'Saúde',
                'status' => 'Novo cliente',
                'status_tag' => 'purple',
                'socio' => [
                    'nome' => 'Rafael Nogueira',
                    'cargo' => 'Sócio-fundador',
                    'email' => 'rafael@empresaw.com.br',
                    'telefone' => '(41) 99456-1123',
                    'participacao' => '60%',
                    'desde' => 'Cliente desde set/2026',
                ],
                'fontes' => [
                    ['tipo' => 'PDF', 'nome' => 'Contrato inicial.pdf', 'info' => '01/09/2026'],
                ],
                'financeiro' => [
                    'regime' => 'Simples Nacional', 'desde' => '2026-09-01',
                    'primeiro' => 3500, 'atual' => 3500, 'total' => 12800,
                    'saldo' => 3500, 'receber' => 3500, 'despesas' => 450, 'margem' => 87,
                ],
                'receita_mensal' => [
                    ['Abr/26', 0], ['Mai/26', 0], ['Jun/26', 0], ['Jul/26', 0], ['Ago/26', 3500],
                ],
                'transacoes' => [
                    ['data' => '08/09/2026', 'descricao' => 'Honorário inicial', 'categoria' => 'Honorários', 'tipo' => 'Entrada', 'valor' => 3500, 'status' => 'Recebido'],
                    ['data' => '02/09/2026', 'descricao' => 'Custos de onboarding', 'categoria' => 'Despesas', 'tipo' => 'Saída', 'valor' => 450, 'status' => 'Pago'],
                ],
            ],
            [
                'id' => 'empresaK',
                'nome' => 'Empresa K',
                'setor' => 'Educação',
                'status' => 'Cliente ativo',
                'status_tag' => 'blue',
                'socio' => [
                    'nome' => 'Luciana Almeida',
                    'cargo' => 'Diretora Financeira',
                    'email' => 'luciana.almeida@empresak.com.br',
                    'telefone' => '(51) 98123-7765',
                    'participacao' => '40%',
                    'desde' => 'Cliente desde fev/2026',
                ],
                'fontes' => [
                    ['tipo' => 'XLS', 'nome' => 'Fechamento financeiro.xlsx', 'info' => '12/08/2026'],
                    ['tipo' => 'PDF', 'nome' => 'Relatório de indicadores.pdf', 'info' => '20/08/2026'],
                ],
                'financeiro' => [
                    'regime' => 'Lucro Real', 'desde' => '2023-07-05',
                    'primeiro' => 5000, 'atual' => 9200, 'total' => 285600,
                    'saldo' => 31750, 'receber' => 9200, 'despesas' => 4100, 'margem' => 56,
                ],
                'receita_mensal' => [
                    ['Abr/26', 8400], ['Mai/26', 8700], ['Jun/26', 8900], ['Jul/26', 9100], ['Ago/26', 9200],
                ],
                'transacoes' => [
                    ['data' => '08/09/2026', 'descricao' => 'Honorário mensal', 'categoria' => 'Honorários', 'tipo' => 'Entrada', 'valor' => 9200, 'status' => 'Recebido'],
                    ['data' => '06/09/2026', 'descricao' => 'Projeto complementar', 'categoria' => 'Projeto', 'tipo' => 'Entrada', 'valor' => 3800, 'status' => 'Pendente'],
                    ['data' => '01/09/2026', 'descricao' => 'Custos operacionais', 'categoria' => 'Despesas', 'tipo' => 'Saída', 'valor' => 1600, 'status' => 'Pago'],
                    ['data' => '05/08/2026', 'descricao' => 'Honorário mensal', 'categoria' => 'Honorários', 'tipo' => 'Entrada', 'valor' => 9100, 'status' => 'Recebido'],
                ],
            ],
        ];
    }
}
