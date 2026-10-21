# HC Brain — Laravel + telas em TypeScript

O "cérebro da empresa" da Health Care. É uma aplicação Laravel de verdade: os dados estão no
banco, o login é sessão do Laravel, cada tela tem a sua URL e o que se cadastra, edita ou
exclui na tela é gravado. **As telas são em TypeScript** — o Blade entrega só o esqueleto da
página, e o script daquela tela busca os dados na API e preenche o conteúdo.

A divisão é essa:

- **PHP manda nos dados e nas rotas.** Migrations, models Eloquent, seeders, validação,
  autenticação e os endpoints JSON.
- **TypeScript manda na tela.** Montagem do menu, renderização das listas, gráficos,
  filtros, modais e o estado de interface.

## Rodando localmente

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate
php artisan migrate --seed

npm run dev          # Vite, em outro terminal
php artisan serve
```

O banco é SQLite (`database/database.sqlite`) e `migrate --seed` já popula tudo. A primeira
tela é o login, e a equipe semeada entra com `123456` — a senha que o rodapé da própria tela
anuncia, porque a base é uma demonstração. Para mudá-la, defina `HC_SENHA_SEMEADA` no `.env`.

Entre com qualquer e-mail da equipe (`beatriz@healthcare.com.br`, por exemplo) e essa senha.
Se ela se perder, a tela de recuperação em `/senha` envia o link; em desenvolvimento
`MAIL_MAILER=log` guarda o e-mail em `storage/logs/laravel.log`.

Para criar um acesso com a senha que você escolher — ou trocar a de alguém —, use:

```bash
php artisan hc:acesso
```

Ele pergunta nome, e-mail, perfil e área, pede a senha duas vezes escondida e grava. Rodar de
novo para o mesmo e-mail atualiza o acesso em vez de duplicá-lo. Com `--senha-aleatoria` ele
gera a senha e a mostra uma única vez.

As decisões que mudam de um ambiente para o outro ficam em `config/hc.php`: a senha semeada,
o disco onde os documentos enviados são guardados, o tamanho máximo e as extensões aceitas.

Outros comandos: `npm run build` (confere os tipos e gera `public/build/`), `npm run
typecheck` (só a conferência de tipos), `npm run format` (Prettier no CSS e no TypeScript) e
`php vendor/bin/phpunit` (a suíte de testes).

## As telas

| Tela                | Rota               | View Blade          | Estilo                  | Script                        |
| ------------------- | ------------------ | ------------------- | ----------------------- | ----------------------------- |
| Login               | `/login`           | `telas/login`       | `css/login.css`         | `js/paginas/login.ts`         |
| Recuperar acesso    | `/esqueci-a-senha` | `telas/senha`       | `css/senha.css`         | `js/paginas/senha.ts`         |
| Nova senha          | `/redefinir-senha/{token}` | `telas/nova-senha` | `css/senha.css`  | `js/paginas/senha.ts`         |
| Início              | `/`                | `telas/inicio`      | `css/inicio.css`        | `js/paginas/inicio.ts`        |
| Pesquisa            | `/pesquisa`        | `telas/pesquisa`    | `css/pesquisa.css`      | `js/paginas/pesquisa.ts`      |
| Documentos          | `/documentos`      | `telas/documentos`  | `css/documentos.css`    | `js/paginas/documentos.ts`    |
| Sofia IA            | `/sofia-ia`        | `telas/sofia-ia`    | `css/sofia-ia.css`      | `js/paginas/sofia-ia.ts`      |
| Treinamentos        | `/treinamentos`    | `telas/treinamentos`| `css/treinamentos.css`  | `js/paginas/treinamentos.ts`  |
| Empresas & clientes | `/clientes`        | `telas/clientes`    | `css/clientes.css`      | `js/paginas/clientes.ts`      |
| Detalhe do cliente  | `/clientes/{id}`   | `telas/cliente`     | `css/cliente.css`       | `js/paginas/cliente.ts`       |
| Usuários            | `/usuarios`        | `telas/usuarios`    | `css/usuarios.css`      | `js/paginas/usuarios.ts`      |
| Comercial           | `/comercial`       | `telas/comercial`   | `css/comercial.css`     | `js/paginas/comercial.ts`     |
| Reuniões            | `/reunioes`        | `telas/reunioes`    | `css/reunioes.css`      | `js/paginas/reunioes.ts`      |
| Financeiro geral    | `/financeiro`      | `telas/financeiro`  | `css/financeiro.css`    | `js/paginas/financeiro.ts`    |
| Projetos            | `/projetos`        | `telas/projetos`    | `css/projetos.css`      | `js/paginas/projetos.ts`      |
| Processos           | `/processos`       | `telas/processos`   | `css/processos.css`     | `js/paginas/processos.ts`     |

O nome da tela é o mesmo nos quatro lugares: a tela `reunioes` é `telas/reunioes.blade.php`,
`resources/css/reunioes.css` e `resources/js/paginas/reunioes.ts`.

O detalhe do cliente pode ser compartilhado por link (`/clientes/empresaX`). Quando o id não
existe, a página mostra o estado de erro com o caminho de volta — e responde 404.

## O mapa das telas

`app/Support/Telas.php` é a fonte única das rotas, do menu lateral e de qualquer link entre
páginas. Trocar um ícone, renomear um item ou reordenar o menu é editar esse mapa.

Ele não é copiado para o TypeScript: o layout serializa o mapa (junto com o usuário da sessão
e os contadores) em `window.HC_BRAIN`, e `resources/js/comum/paginas.ts` lê dali. Assim o menu
existe em um lugar só, mesmo sendo montado no navegador.

`Telas::assets()` devolve, para cada tela, as folhas comuns, a folha própria e o script — na
ordem em que o navegador deve recebê-los. O layout passa isso direto para `@vite`, então uma
tela nova não precisa mexer no `vite.config.ts` além de entrar na lista de entradas.

## O sistema visual

`resources/css/comum/base.css` é a fonte de todo valor visual: cor, tipografia, espaçamento,
raio, sombra e o anel de foco. Uma tela não inventa um `#1a2733` nem um `font-size: 9px` —
ela usa `var(--surface)` e `var(--texto-sm)`. Se um valor não existe lá, ou ele entra na
lista ou o caso não é de fato diferente.

A escala tipográfica tem sete degraus e o menor é 11px, porque abaixo disso nada é legível em
tela de verdade. O corpo padrão é 14px.

`comum/componentes.css` tem as peças usadas por mais de uma tela: painel, cartão, tabela,
etiqueta, botão, campo, barra de progresso e os estados de vazio, carregando e erro. O que é
exclusivo de uma tela mora em `css/<tela>.css`.

A fonte (Inter) e os ícones (Lucide) entram no bundle pelo npm — nada é buscado em CDN, então
a tela não depende de rede de terceiros para renderizar certo.

## Os ícones

`resources/js/comum/icones.ts` é o registro único. Uma tela não escreve um emoji nem um
caractere solto: ela pede `icone("arrow-right")`, e o TypeScript recusa um nome que não esteja
registrado — o ícone quebrado aparece na compilação, não na tela de quem usa.

Ícone que é parte fixa da página fica no Blade como `<i data-lucide="search">`; ícone dentro
de HTML que o TypeScript monta usa `icone()`. Depois de injetar HTML novo, a tela chama
`desenharIcones()` para trocar os marcadores pelos SVGs.

Só entram no bundle os ícones importados no registro: cada importação é um SVG a mais no
pacote.

## Onde ficam os dados

Tudo o que é registro da HC está no banco:

```
empresas, socios, fontes            empresa_financeiros, receitas_mensais, transacoes
reunioes                            pastas, documentos
treinamentos, treinamento_historico indicadores_comerciais
projetos, processos                 resultados_pesquisa, sugestoes_sofia, users
```

Os seeders em `database/seeders/` trazem a base da HC: 5 empresas com sócio, fontes e
financeiro, 10 reuniões, 5 documentos em 5 pastas, 19 conteúdos de capacitação, 6 projetos, 6
processos, 3 períodos comerciais, 16 resultados de pesquisa e a equipe de 7 pessoas.

Documento é arquivo de verdade: `documentos` guarda a pasta, o tamanho, o mime e o caminho
dentro do disco configurado, e o `DocumentoSeeder` grava os arquivos nesse disco, então
baixar, renomear e excluir se comportam em desenvolvimento como vão se comportar no ar. O
download passa pelo Laravel, e não por URL pública do disco, porque documento da HC não deve
ficar acessível a quem não está na sessão.

Nada que é aparência mora no banco. O ícone de um treinamento sai do tipo dele
(`dados/treinamentos.ts`), não de uma coluna; o último acesso de um usuário é um `timestamp`,
e "Hoje, 10:42" é montado na hora de exibir (`UsuarioResource`).

Os endpoints ficam em `routes/web.php`, sob o prefixo `/api` e o middleware `auth`. Eles vivem
ali, e não em `routes/api.php`, justamente para compartilharem a sessão e o CSRF do navegador —
é o mesmo usuário logado, então não há segundo mecanismo de autenticação para manter.

| Endpoint                  | Serve                                              |
| ------------------------- | -------------------------------------------------- |
| `GET /api/contadores`     | os números do menu lateral                          |
| `GET /api/inicio`         | os números e a atividade recente do painel inicial  |
| `GET /api/empresas`       | a lista de Empresas & clientes                      |
| `GET /api/empresas/{id}`  | o detalhe do cliente inteiro, em uma resposta só    |
| `GET /api/reunioes`       | reuniões e as empresas para o filtro                |
| `GET /api/documentos`     | pastas e documentos                                 |
| `GET /api/documentos/{id}/arquivo` | o arquivo, servido dentro da sessão        |
| `GET /api/treinamentos`   | conteúdos e histórico                               |
| `GET /api/projetos`       | a carteira de projetos, com prazo e progresso       |
| `GET /api/processos`      | os fluxos internos e suas etapas                    |
| `GET /api/comercial`      | os meses fechados, para o filtro                    |
| `GET /api/comercial/{periodo}` | os indicadores daquele mês                     |
| `GET /api/financeiro`     | a carteira consolidada                              |
| `GET /api/pesquisa`       | o índice de busca                                   |
| `GET /api/usuarios`       | a equipe                                            |
| `GET /api/sofia/sugestoes`, `POST /api/sofia/perguntar` | a Sofia          |

Cada entidade tem o seu lado de escrita: `POST`, `PUT` e `DELETE` em `/api/empresas`,
`/api/reunioes`, `/api/projetos`, `/api/processos`, `/api/treinamentos`, `/api/usuarios`,
`/api/pastas` e `/api/documentos` — este último recebe o arquivo em `multipart/form-data`.
As rotas de escrita são declaradas uma a uma, e não por `apiResource`, porque o parâmetro é
em português: o singular que o Laravel deduz de "reunioes" não é "reuniao".

`app/Http/Requests/` valida o que chega e `app/Http/Resources/` garante que uma empresa, uma
reunião ou um usuário cheguem sempre com a mesma forma, independente de qual tela pediu. Os
erros de validação voltam como 422 e `resources/js/comum/formulario.ts` os coloca embaixo do
campo que os causou, sem fechar a caixa.

## O que ainda vive no navegador

`resources/js/comum/estado.ts` continua guardando no `localStorage` só o que é escolha de quem
está usando: termo e filtros de pesquisa, pasta aberta em Documentos, filtros e mês do
calendário em Reuniões, período do Comercial, conversa com a Sofia. Mudanças são avisadas por
`BroadcastChannel` e pelo evento `storage`, então duas abas abertas se atualizam na hora.

O que saiu de lá foi tudo o que virou registro: cadastrar um usuário agora grava no banco, e o
contador do menu muda para todo mundo — não só para a aba que cadastrou.

Alguns caminhos aproveitam esse estado para atravessar páginas:

- pesquisar no Início leva o termo já aplicado para a tela de Pesquisa;
- "Ver na página de reuniões", no detalhe da empresa, abre Reuniões filtrada por aquele cliente.

## A Sofia

A Sofia responde com Chat Completions (`gpt-4o-mini`) e consulta a mesma base das outras
telas por ferramentas (`app/Support/Sofia.php`). A tela envia a pergunta e o histórico da
conversa; o servidor devolve o texto. Sem `OPENAI_API_KEY` a API avisa — não há casamento
de palavras escondido.

## A barra lateral

A barra lateral é a mesma em todas as telas porque existe em um só lugar: o layout tem apenas
`<aside class="sidebar" data-sidebar></aside>`, e `resources/js/comum/shell.ts` preenche esse
espaço a partir do mapa serializado em `window.HC_BRAIN`.

Ela funciona como uma gaveta: fica fechada e, ao clicar no botão de menu da barra superior,
desliza por cima da página. Enquanto está aberta, um overlay escurece o conteúdo, a rolagem
fica travada e a página recebe `inert`. Fecha pelo X, pelo overlay ou pelo Esc, e o foco volta
para o botão que a abriu. `prefers-reduced-motion` desliga a animação.

## Acesso e segurança

O login vai por POST com o token da sessão e é limitado a 10 tentativas por minuto
(`throttle:10,1`) — sem isso, a tela de entrada fica aberta a força bruta. A sessão é
regenerada no login e invalidada no logout.

A recuperação de senha é a do Laravel: um token de validade curta chega por e-mail e só ele
autoriza a troca. O pedido é limitado a 5 por minuto e a resposta é sempre a mesma, exista ou
não a conta, para que a tela não vire uma lista de quem tem acesso ao HC Brain. Ela vive em
`/senha`; a tela de login não leva até lá, onde o botão "Esqueci minha senha" é o aviso
demonstrativo do protótipo.

Na tela de Usuários, duas coisas não são permitidas, porque nenhuma delas tem volta pela
interface: excluir o próprio acesso e remover ou rebaixar a última pessoa com perfil de
Administrador ativo.

A equipe semeada é de demonstração e entra com uma senha conhecida, anunciada na própria
tela de login. Num ambiente que deixe de ser demonstração isso precisa cair: defina
`HC_SENHA_SEMEADA`, ou crie o acesso de verdade com `php artisan hc:acesso` — ele pede a
senha no terminal de quem está publicando, sem escrevê-la em arquivo nenhum — e apague a
equipe semeada pela tela de Usuários.

Onde não há terminal — é o caso do Render — esse acesso vem de `HC_ACESSO_EMAIL` e
`HC_ACESSO_SENHA`, que o `hc:preparar` lê a cada inicialização. Enquanto as variáveis
existirem, elas mandam na senha desse acesso: trocá-las no painel é como se recupera a
entrada quando ninguém mais consegue entrar. Nome, perfil e área só são gravados na criação,
então ajustes feitos pela tela de Usuários sobrevivem ao próximo restart.

Erros têm página própria e com a marca do HC Brain (`resources/views/errors/`): 403, 404, 419,
429 e 500. Ninguém cai na tela crua do Laravel.

## Estrutura

```
app/Models/              as entidades do banco
app/Support/             mapa das telas, contadores, bootstrap do navegador e a Sofia
app/Http/Controllers/    uma ação por tela; Api/ para os endpoints JSON
app/Http/Resources/      a forma de cada entidade no JSON
database/migrations/     o esquema
database/seeders/        a base da HC
lang/pt_BR/              as mensagens de validação
routes/web.php           as telas e a API, todas dentro da sessão
resources/views/layouts/ o layout com shell (app) e o do login (auth)
resources/views/telas/   o esqueleto de cada tela
resources/views/errors/  as páginas de erro
resources/css/<tela>.css o estilo de cada tela
resources/css/comum/     base (tokens), layout, barra lateral, componentes e modal
resources/js/paginas/    o script de cada tela
resources/js/comum/      shell, ícones, mapa de telas, estado, API, modal, formulário, DOM e formatação
resources/js/dados/      os tipos das entidades e as funções que chamam a API
tests/Feature/           autenticação, telas e API
```

`resources/js/comum/api.ts` concentra as chamadas: `obter`, `enviar`, `atualizar`, `remover` e
`enviarArquivo` já mandam o CSRF, tratam erro de validação e devolvem para o login quando a
sessão cai. `resources/js/comum/formulario.ts` concentra o cadastrar-editar-excluir: uma só
descrição de campos vira a caixa, o erro de cada campo e a confirmação de exclusão, então as
telas não repetem esse comportamento cada uma do seu jeito. `resources/js/comum/dom.ts`
concentra o acesso aos elementos: `porId`, `campo` e `selecao` devolvem o elemento já tipado e
falham na hora se o id sair do Blade. `resources/js/comum/formato.ts` concentra como número,
dinheiro e nome viram texto, então "R$ 8.700" tem a mesma cara em toda tela.

## Testes

```bash
php vendor/bin/phpunit
```

`tests/Feature/` cobre o login e a sessão (incluindo o limite de tentativas e a recuperação
de senha), o acesso a cada tela do menu (com e sem sessão), o 404 do cliente inexistente, a
leitura de todos os endpoints e, em `EscritaTest`, o outro lado: cadastrar, editar e excluir
cada entidade, o upload e o download de documento, e as regras que impedem a base de ficar
incoerente. As telas do menu são percorridas a partir de `Telas::MENU`, então um item
apontando para uma rota que não existe quebra o teste.

A base vem dos seeders e serve de ponto de partida; não há integração externa.

## Publicando

O `Dockerfile` monta a imagem (PHP, Apache, `composer install --no-dev` e o build do Vite) e
`docker/entrypoint.sh` prepara o contêiner a cada inicialização:

```bash
php artisan migrate --force
php artisan hc:preparar
```

`migrate`, não `migrate:fresh`: o servidor reinicia sozinho, e recriar a base a cada boot
levaria junto tudo o que foi cadastrado pelo site. O `hc:preparar` semeia apenas quando a
base está vazia e garante o acesso de `HC_ACESSO_EMAIL` e `HC_ACESSO_SENHA` — as duas
operações são seguras de repetir.

Falta uma peça para os dados durarem de verdade: o SQLite mora no sistema de arquivos do
contêiner, que é descartado a cada deploy. Em produção ele precisa de um disco persistente
montado numa pasta própria, com `DB_DATABASE` apontando para o arquivo lá dentro.
