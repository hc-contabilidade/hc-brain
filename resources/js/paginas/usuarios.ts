/* =========================================================
   TELA USUÁRIOS
   A lista é a tabela `users` do Laravel — a mesma que autentica o
   login. Cadastrar aqui cria de fato um acesso ao HC Brain, e
   excluir tira o acesso de quem não trabalha mais com a base.
   ========================================================= */

import {
    cadastrarUsuario,
    carregarUsuarios,
    excluirUsuario,
    salvarUsuario,
    type Usuario,
} from "../dados/usuarios.ts";
import {
    carregarAgendas,
    desconectarAgenda,
    sincronizarAgendas,
    type AgendasGoogle,
} from "../dados/agendas.ts";
import { ErroDeApi } from "../comum/api.ts";
import { combina, termoDeBusca } from "../comum/busca.ts";
import { campo, dado, porId, selecao, todos } from "../comum/dom.ts";
import { atualizarSecao, observarEstado, obterSecao } from "../comum/estado.ts";
import { escapar, plural } from "../comum/formato.ts";
import { abrirFormulario, confirmarAcao } from "../comum/formulario.ts";
import { desenharIcones, icone } from "../comum/icones.ts";
import { showModal } from "../comum/modal.ts";
import { usuarioLogado } from "../comum/sessao.ts";
import { iniciarPagina } from "../comum/shell.ts";

iniciarPagina("usuarios");

const PERFIS = ["Administrador", "Gestor", "Colaborador"];
const AREAS = [
    "Gestão",
    "Comercial",
    "Operações",
    "Atendimento",
    "Financeiro",
    "Projetos",
];
const SITUACOES = ["Ativo", "Inativo"];

const QUANTIDADES = [10, 20, 50] as const;

const busca = campo("usuarioSearch");
const perfil = selecao("usuarioRoleFilter");
const status = selecao("usuarioStatusFilter");
const porPagina = selecao("usuarioPorPagina");
const corpo = porId("usuariosTableBody");
const paginacao = porId("usuariosPaginacao");
const paginaAnterior = porId<HTMLButtonElement>("usuariosPaginaAnterior");
const paginaProxima = porId<HTMLButtonElement>("usuariosPaginaProxima");

let usuarios: Usuario[] = await carregarUsuarios();

function quantidadeValida(valor: number): number {
    return QUANTIDADES.includes(valor as (typeof QUANTIDADES)[number]) ? valor : 10;
}

function renderUsuarios(): void {
    const estado = obterSecao("usuarios");
    const palavras = termoDeBusca(estado.busca);
    const tamanho = quantidadeValida(Number(estado.porPagina));

    const filtrados = usuarios.filter((usuario) => {
        const texto = `${usuario.nome} ${usuario.email} ${usuario.area} ${usuario.perfil}`;
        return (
            combina(texto, palavras) &&
            (estado.perfil === "Todos" || usuario.perfil === estado.perfil) &&
            (estado.status === "Todos" || usuario.status === estado.status)
        );
    });

    const totalPaginas = Math.max(1, Math.ceil(filtrados.length / tamanho));
    const pagina = Math.min(Math.max(1, Number(estado.pagina) || 1), totalPaginas);

    if (pagina !== estado.pagina || tamanho !== estado.porPagina) {
        atualizarSecao("usuarios", { pagina, porPagina: tamanho });
        return;
    }

    const inicio = (pagina - 1) * tamanho;
    const visiveis = filtrados.slice(inicio, inicio + tamanho);
    const fim = filtrados.length === 0 ? 0 : inicio + visiveis.length;

    porId("usuariosTotal").textContent = String(filtrados.length);
    porId("usuariosAtivos").textContent = String(
        filtrados.filter((usuario) => usuario.status === "Ativo").length,
    );
    porId("usuariosAdmins").textContent = String(
        filtrados.filter((usuario) => usuario.perfil === "Administrador").length,
    );
    porId("usuariosUltimo").textContent = filtrados[0]?.ultimoAcesso || "—";
    porId("usuariosCount").textContent =
        filtrados.length <= tamanho
            ? plural(filtrados.length, "usuário", "usuários")
            : `${inicio + (filtrados.length ? 1 : 0)}–${fim} de ${plural(filtrados.length, "usuário", "usuários")}`;

    corpo.innerHTML = visiveis.length
        ? visiveis
              .map(
                  (usuario) => `
            <tr>
              <td>
                <div class="usuario-person">
                  <span class="avatar">${escapar(usuario.iniciais)}</span>
                  <div>
                    <strong>${escapar(usuario.nome)}</strong>
                    <small>${escapar(usuario.email)}</small>
                  </div>
                </div>
              </td>
              <td><span class="tag gray">${escapar(usuario.perfil)}</span></td>
              <td>${escapar(usuario.area)}</td>
              <td><span class="tag ${usuario.status === "Ativo" ? "green" : "red"}">${escapar(usuario.status)}</span></td>
              <td class="usuario-access">${escapar(usuario.ultimoAcesso)}</td>
              <td class="usuario-acoes">
                <button type="button" class="icon-button" data-editar="${usuario.id}" aria-label="Editar ${escapar(usuario.nome)}">
                  ${icone("settings")}
                </button>
              </td>
            </tr>
          `,
              )
              .join("")
        : `<tr><td colspan="6"><div class="empty-state">Nenhum usuário encontrado.</div></td></tr>`;

    todos("[data-editar]", corpo).forEach((botao) => {
        botao.addEventListener("click", () => {
            const id = Number(dado(botao, "editar"));
            const usuario = usuarios.find((registro) => registro.id === id);
            if (usuario) abrirFormularioDe(usuario);
        });
    });

    desenharIcones(corpo);

    const precisaPaginar = filtrados.length > tamanho;
    paginacao.hidden = !precisaPaginar;
    if (precisaPaginar) {
        porId("usuariosPaginacaoInfo").textContent = `Página ${pagina} de ${totalPaginas}`;
        paginaAnterior.disabled = pagina <= 1;
        paginaProxima.disabled = pagina >= totalPaginas;
    }

    if (busca.value !== estado.busca) busca.value = estado.busca;
    perfil.value = estado.perfil;
    status.value = estado.status;
    porPagina.value = String(tamanho);
}

async function recarregar(): Promise<void> {
    usuarios = await carregarUsuarios();
    renderUsuarios();
}

/**
 * O mesmo formulário cadastra e edita. Na edição a senha é opcional: trocar
 * a área de alguém não deveria obrigar quem edita a inventar uma senha nova.
 */
function abrirFormularioDe(usuario?: Usuario): void {
    const opcoes = (valores: string[]) =>
        valores.map((valor) => ({ valor, rotulo: valor }));

    void abrirFormulario({
        titulo: usuario ? usuario.nome : "Cadastrar usuário",
        descricao: usuario
            ? "Alterar o acesso desta pessoa ao HC Brain."
            : "Adicione uma pessoa ao ambiente do HC Brain e defina o perfil e a área de acesso.",
        campos: [
            {
                nome: "nome",
                rotulo: "Nome completo",
                valor: usuario?.nome,
                obrigatorio: true,
            },
            {
                nome: "email",
                rotulo: "E-mail",
                tipo: "email",
                valor: usuario?.email,
                obrigatorio: true,
            },
            {
                nome: "perfil",
                rotulo: "Perfil",
                tipo: "selecao",
                valor: usuario?.perfil ?? "Colaborador",
                opcoes: opcoes(PERFIS),
            },
            {
                nome: "area",
                rotulo: "Área",
                tipo: "selecao",
                valor: usuario?.area ?? AREAS[0],
                opcoes: opcoes(AREAS),
            },
            {
                nome: "status",
                rotulo: "Status",
                tipo: "selecao",
                valor: usuario?.status ?? "Ativo",
                opcoes: opcoes(SITUACOES),
            },
            {
                nome: "senha",
                rotulo: usuario ? "Nova senha" : "Senha inicial",
                tipo: "senha",
                obrigatorio: usuario === undefined,
                dica: usuario
                    ? "Em branco, a senha atual continua valendo."
                    : "Mínimo de 8 caracteres.",
            },
        ],
        confirmar: usuario ? "Salvar usuário" : "Cadastrar usuário",
        excluir: usuario
            ? {
                  rotulo: "Excluir acesso",
                  confirmacao: `${usuario.nome} perde o acesso ao HC Brain. Não há como desfazer.`,
                  aoExcluir: async () => {
                      await excluirUsuario(usuario.id);
                      await recarregar();
                  },
              }
            : undefined,
        aoSalvar: async (valores) => {
            const dados = {
                nome: valores.texto("nome"),
                email: valores.texto("email"),
                perfil: valores.texto("perfil"),
                area: valores.texto("area"),
                status: valores.texto("status"),
                senha: valores.texto("senha"),
            };

            if (usuario) {
                await salvarUsuario(usuario.id, dados);
            } else {
                await cadastrarUsuario(dados);
            }

            await recarregar();
        },
    });
}

busca.addEventListener("input", () =>
    atualizarSecao("usuarios", { busca: busca.value, pagina: 1 }),
);
perfil.addEventListener("change", () =>
    atualizarSecao("usuarios", { perfil: perfil.value, pagina: 1 }),
);
status.addEventListener("change", () =>
    atualizarSecao("usuarios", { status: status.value, pagina: 1 }),
);
porPagina.addEventListener("change", () =>
    atualizarSecao("usuarios", {
        porPagina: quantidadeValida(Number(porPagina.value)),
        pagina: 1,
    }),
);
paginaAnterior.addEventListener("click", () =>
    atualizarSecao("usuarios", (atual) => ({ pagina: Math.max(1, atual.pagina - 1) })),
);
paginaProxima.addEventListener("click", () =>
    atualizarSecao("usuarios", (atual) => ({ pagina: atual.pagina + 1 })),
);

porId("novoUsuarioBtn").addEventListener("click", () => abrirFormularioDe());

observarEstado(["usuarios"], renderUsuarios);
renderUsuarios();

/* ---------------------------------------------------------
   ÚLTIMO ACESSO EM TEMPO REAL
   A lista consulta de novo a API enquanto a aba está aberta,
   para o "Hoje, 14:15" aparecer sem precisar dar F5.
   --------------------------------------------------------- */
const INTERVALO_DOS_ACESSOS_MS = 5_000;

async function atualizarAcessosEmTempoReal(): Promise<void> {
    try {
        const atualizados = await carregarUsuarios();
        const mudou =
            atualizados.length !== usuarios.length ||
            atualizados.some((usuario) => {
                const atual = usuarios.find((registro) => registro.id === usuario.id);
                return (
                    atual === undefined ||
                    atual.ultimoAcesso !== usuario.ultimoAcesso ||
                    atual.status !== usuario.status ||
                    atual.perfil !== usuario.perfil ||
                    atual.nome !== usuario.nome
                );
            });

        if (!mudou) {
            return;
        }

        usuarios = atualizados;
        renderUsuarios();
    } catch {
        // Sem rede ou sessão: a próxima rodada tenta de novo.
    }
}

window.setInterval(() => {
    if (document.hidden) {
        return;
    }

    void atualizarAcessosEmTempoReal();
}, INTERVALO_DOS_ACESSOS_MS);

document.addEventListener("visibilitychange", () => {
    if (!document.hidden) {
        void atualizarAcessosEmTempoReal();
    }
});

/* ---------------------------------------------------------
   AGENDAS DO GOOGLE
   Só quem administra conecta e desconecta; o servidor também
   recusa os demais, isto aqui só evita mostrar o que não funciona.
   --------------------------------------------------------- */
const RETORNOS_DO_GOOGLE: Record<string, [string, string]> = {
    conectada: [
        "Agenda conectada",
        "Os eventos com Meet desta agenda entram em Reuniões em até 5 minutos.",
    ],
    recusada: [
        "Conexão cancelada",
        "A permissão não foi dada na tela do Google, então nenhuma agenda foi conectada.",
    ],
    sessao: [
        "Sessão expirou no meio do login",
        "Abra Usuários de novo, clique em Conectar conta Google e aceite na mesma aba, sem demorar. Não precisa de outro Client ID.",
    ],
    oauth: [
        "Google recusou o cliente OAuth",
        "No Cloud, o redirect tem que ser exatamente o GOOGLE_REDIRECT_URI do Render (…/google/retorno), e o Client ID/Secret têm que ser do mesmo cliente HC Brain.",
    ],
    sem_refresh: [
        "Google não deu acesso contínuo",
        "Na tela do Google, aceite todas as permissões (incluindo agenda). Se a conta já tinha autorizado antes, revogue o acesso em myaccount.google.com/permissions e conecte de novo.",
    ],
    erro: [
        "Não foi possível conectar",
        "O Google não confirmou a conexão. Tente de novo; se repetir, confira o cliente OAuth no ambiente.",
    ],
};

function renderAgendas({ configurada, contas }: AgendasGoogle): void {
    const lista = porId("agendasGoogleLista");
    porId("conectarAgenda").hidden = !configurada;
    porId("sincronizarAgendas").hidden = !configurada || contas.length === 0;

    if (!configurada) {
        lista.innerHTML = `<div class="empty-state">Defina GOOGLE_CLIENT_ID e GOOGLE_CLIENT_SECRET no ambiente para conectar agendas.</div>`;
        return;
    }

    lista.innerHTML = contas.length
        ? `<div class="tabela-wrap"><table class="tabela">
            <thead>
              <tr>
                <th>Conta</th>
                <th>Última sincronização</th>
                <th><span class="sr-only">Ações</span></th>
              </tr>
            </thead>
            <tbody>${contas
                .map(
                    (conta) => `
              <tr>
                <td>
                  <div class="usuario-person">
                    <div>
                      <strong>${escapar(conta.nome)}</strong>
                      <small>${escapar(conta.email)}</small>
                    </div>
                  </div>
                </td>
                <td class="usuario-access">${
                    conta.erro
                        ? `<span class="tag red">${escapar(conta.erro)}</span>`
                        : escapar(conta.sincronizadaEm ?? "Aguardando a primeira leitura")
                }</td>
                <td class="usuario-acoes">
                  <button type="button" class="secondary" data-desconectar="${conta.id}">Desconectar</button>
                </td>
              </tr>`,
                )
                .join("")}</tbody>
          </table></div>`
        : `<div class="empty-state">Nenhuma agenda conectada ainda.</div>`;

    todos("[data-desconectar]", lista).forEach((botao) => {
        botao.addEventListener("click", async () => {
            const conta = contas.find(
                ({ id }) => id === Number(dado(botao, "desconectar")),
            );
            if (!conta) return;

            const confirmou = await confirmarAcao(
                `Desconectar ${conta.email}?`,
                "Novos eventos desta agenda deixam de entrar. As reuniões que já vieram dela continuam em Reuniões.",
                "Desconectar",
            );
            if (!confirmou) return;

            await desconectarAgenda(conta.id);
            renderAgendas(await carregarAgendas());
        });
    });
}

if (usuarioLogado().perfil === "Administrador") {
    porId("agendasGoogle").hidden = false;
    renderAgendas(await carregarAgendas());

    const sincronizar = porId<HTMLButtonElement>("sincronizarAgendas");
    sincronizar.addEventListener("click", async () => {
        sincronizar.disabled = true;
        sincronizar.textContent = "Sincronizando…";

        try {
            const leitura = await sincronizarAgendas();
            renderAgendas(leitura);
            showModal("Leitura das agendas", leitura.relatorio.join(" "));
        } catch (erro) {
            showModal(
                "Não foi possível sincronizar",
                erro instanceof ErroDeApi
                    ? erro.message
                    : "Tente de novo em alguns minutos.",
            );
        } finally {
            sincronizar.disabled = false;
            sincronizar.textContent = "Sincronizar agora";
        }
    });

    const retorno = new URLSearchParams(window.location.search).get("google");
    const aviso = retorno ? RETORNOS_DO_GOOGLE[retorno] : undefined;
    if (aviso) {
        history.replaceState(null, "", window.location.pathname);
        showModal(...aviso);
    }
}
