<?php

namespace App\Support;

/**
 * MAPA DAS TELAS
 * Fonte única para as rotas, o menu lateral, os atalhos do Início e
 * qualquer link entre páginas. O id de cada tela é o nome dos seus
 * arquivos: a tela "reunioes" vive em resources/views/telas/reunioes.blade.php,
 * resources/css/reunioes.css e resources/js/paginas/reunioes.ts.
 *
 * O mapa é serializado para o navegador pelo layout, então o TypeScript monta
 * a barra lateral a partir daqui em vez de manter uma segunda lista.
 */
final class Telas
{
    /** Telas com entrada no menu lateral, na ordem em que aparecem. */
    public const MENU = [
        ['id' => 'inicio', 'titulo' => 'Início', 'icone' => 'home', 'grupo' => 'Navegação', 'rota' => '/'],
        ['id' => 'pesquisa', 'titulo' => 'Pesquisa', 'icone' => 'search', 'grupo' => 'Navegação', 'rota' => '/pesquisa'],
        ['id' => 'documentos', 'titulo' => 'Documentos', 'icone' => 'folder', 'grupo' => 'Navegação', 'rota' => '/documentos'],
        ['id' => 'sofia-ia', 'titulo' => 'Sofia IA', 'icone' => 'sparkles', 'grupo' => 'Navegação', 'rota' => '/sofia-ia'],
        ['id' => 'treinamentos', 'titulo' => 'Treinamentos', 'icone' => 'graduation-cap', 'grupo' => 'Navegação', 'rota' => '/treinamentos'],
        ['id' => 'clientes', 'titulo' => 'Empresas & clientes', 'icone' => 'building-2', 'grupo' => 'Banco de dados', 'rota' => '/clientes'],
        ['id' => 'usuarios', 'titulo' => 'Usuários', 'icone' => 'users', 'grupo' => 'Banco de dados', 'rota' => '/usuarios'],
        ['id' => 'comercial', 'titulo' => 'Comercial', 'icone' => 'layout-grid', 'grupo' => 'Banco de dados', 'rota' => '/comercial'],
        ['id' => 'reunioes', 'titulo' => 'Reuniões', 'icone' => 'calendar', 'grupo' => 'Banco de dados', 'rota' => '/reunioes', 'contador' => 'reunioes'],
        ['id' => 'financeiro', 'titulo' => 'Financeiro geral', 'icone' => 'circle-dollar-sign', 'grupo' => 'Banco de dados', 'rota' => '/financeiro'],
        ['id' => 'projetos', 'titulo' => 'Projetos', 'icone' => 'rocket', 'grupo' => 'Banco de dados', 'rota' => '/projetos'],
        ['id' => 'processos', 'titulo' => 'Processos', 'icone' => 'settings', 'grupo' => 'Banco de dados', 'rota' => '/processos'],
    ];

    /** Telas que existem sem entrada no menu. */
    public const AUXILIARES = [
        ['id' => 'login', 'titulo' => 'Entrar', 'rota' => '/login'],
        // As duas telas de senha compartilham esta folha e este script; a de
        // nova senha só é alcançada pelo link do e-mail, com o token na URL.
        ['id' => 'senha', 'titulo' => 'Recuperar acesso', 'rota' => '/esqueci-a-senha'],
        // O detalhe de um cliente mantém "Empresas & clientes" ativo no menu.
        ['id' => 'cliente', 'titulo' => 'Detalhe do cliente', 'rota' => '/clientes/{id}', 'menu' => 'clientes'],
        // O perfil é de quem está logado, não um lugar para onde se navega:
        // ele é alcançado pelo bloco do usuário no rodapé da barra lateral.
        ['id' => 'perfil', 'titulo' => 'Meu perfil', 'rota' => '/perfil'],
    ];

    /** Folhas compartilhadas por toda tela que tem o shell (menu, topo e modais). */
    public const CSS_COMUM = ['base', 'layout', 'sidebar', 'componentes', 'modal', 'sofia-agente'];

    /**
     * Folhas e script que o Vite precisa entregar para uma tela, na ordem em
     * que o navegador deve recebê-los: primeiro o comum, depois o da tela.
     *
     * @param  array<int, string>  $comuns
     * @return array<int, string>
     */
    public static function assets(string $id, array $comuns = self::CSS_COMUM): array
    {
        return [
            ...array_map(fn (string $nome) => "resources/css/comum/{$nome}.css", $comuns),
            "resources/css/{$id}.css",
            "resources/js/paginas/{$id}.ts",
        ];
    }

    /** @return array<string, mixed>|null */
    public static function porId(string $id): ?array
    {
        foreach ([...self::MENU, ...self::AUXILIARES] as $tela) {
            if ($tela['id'] === $id) {
                return $tela;
            }
        }

        return null;
    }

    /** Caminho navegável de uma tela, com os parâmetros já aplicados. */
    public static function caminho(string $id, array $parametros = []): string
    {
        $tela = self::porId($id);
        if ($tela === null) {
            return '/';
        }

        $caminho = $tela['rota'];
        foreach ($parametros as $chave => $valor) {
            $caminho = str_replace('{'.$chave.'}', rawurlencode((string) $valor), $caminho);
        }

        return $caminho;
    }
}
