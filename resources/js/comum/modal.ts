/* =========================================================
   MODAIS COMPARTILHADOS
   O modal genérico e o de reunião são usados por quase todas as
   telas, então o markup é injetado pelo shell em cada página.
   ========================================================= */

import { tagDoStatus, tagDoTipoDeReuniao, type Reuniao } from "../dados/reunioes.ts";
import { dado, porId, todos } from "./dom.ts";
import { escapar } from "./formato.ts";
import { desenharIcones, icone } from "./icones.ts";

const MARKUP = `
  <div class="modal" id="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-box">
      <button class="icon-button close" id="closeModal" aria-label="Fechar">${icone("x")}</button>
      <h2 id="modalTitle"></h2>
      <p id="modalText"></p>
      <div class="modal-actions">
        <button class="primary" id="modalOk">Fechar</button>
      </div>
    </div>
  </div>

  <div class="modal" id="meetingModal" role="dialog" aria-modal="true" aria-labelledby="meetingModalTitle">
    <div class="modal-box wide">
      <button class="icon-button close" id="closeMeetingModal" aria-label="Fechar">${icone("x")}</button>
      <span class="page-overline" id="meetingModalEmpresa"></span>
      <h2 id="meetingModalTitle"></h2>

      <div class="modal-badges" id="meetingModalBadges"></div>

      <div class="modal-meta-grid">
        <div><span>Data</span><strong id="meetingModalData"></strong></div>
        <div><span>Responsável interno</span><strong id="meetingModalResponsavel"></strong></div>
        <div><span>Sócio da empresa</span><strong id="meetingModalSocio"></strong></div>
        <div><span>Participantes</span><strong id="meetingModalParticipantes"></strong></div>
      </div>

      <div class="modal-section">
        <h4>Resumo</h4>
        <p id="meetingModalResumo"></p>
      </div>

      <div class="modal-section">
        <h4>Decisões tomadas</h4>
        <ul id="meetingModalDecisoes"></ul>
      </div>

      <div class="modal-section">
        <h4>Próximos passos</h4>
        <ul id="meetingModalProximos"></ul>
      </div>

      <div class="modal-actions">
        <a class="secondary" id="meetingModalMeet" target="_blank" rel="noopener noreferrer" hidden>Entrar no Meet</a>
        <button class="primary" id="meetingModalOk">Fechar</button>
      </div>
    </div>
  </div>
`;

interface Modais {
    generico: HTMLElement;
    reuniao: HTMLElement;
}

let modais: Modais | null = null;

/** Elemento que tinha o foco antes de abrir, para devolvê-lo ao fechar. */
let focoAnterior: HTMLElement | null = null;

/** Injeta o markup na primeira chamada e devolve os dois modais da página. */
function garantirModais(): Modais {
    if (modais) return modais;

    const container = document.createElement("div");
    container.innerHTML = MARKUP;
    document.body.append(...container.children);

    modais = { generico: porId("modal"), reuniao: porId("meetingModal") };
    const { generico, reuniao } = modais;

    porId("closeModal").onclick = closeModal;
    porId("modalOk").onclick = closeModal;
    porId("closeMeetingModal").onclick = closeMeetingModal;
    porId("meetingModalOk").onclick = closeMeetingModal;

    generico.addEventListener("click", (evento) => {
        if (evento.target === generico) closeModal();
    });

    reuniao.addEventListener("click", (evento) => {
        if (evento.target === reuniao) closeMeetingModal();
    });

    document.addEventListener("keydown", (evento) => {
        if (evento.key === "Escape") {
            closeModal();
            closeMeetingModal();
        }
    });

    desenharIcones(container);
    return modais;
}

function abrir(modal: HTMLElement): void {
    focoAnterior = document.activeElement as HTMLElement | null;
    modal.classList.add("show");
    modal.querySelector<HTMLElement>(".primary")?.focus();
}

function fechar(modal: HTMLElement | undefined): void {
    if (!modal?.classList.contains("show")) return;
    modal.classList.remove("show");
    focoAnterior?.focus();
    focoAnterior = null;
}

export function montarModais(): void {
    garantirModais();
}

export function showModal(title: string, text?: string): void {
    const { generico } = garantirModais();
    porId("modalTitle").textContent = title;
    porId("modalText").textContent =
        text || "Não há detalhe registrado para este item na base.";
    abrir(generico);
}

export function closeModal(): void {
    fechar(modais?.generico);
}

/** O detalhe de uma reunião — só leitura; o cadastro vem das agendas Google. */
export function openMeetingModal(meeting: Reuniao): void {
    const { reuniao } = garantirModais();

    const meet = porId<HTMLAnchorElement>("meetingModalMeet");
    meet.hidden = meeting.linkMeet === null;
    meet.href = meeting.linkMeet ?? "";

    porId("meetingModalEmpresa").textContent = `Reunião • ${meeting.empresa}`;
    porId("meetingModalTitle").textContent = `Reunião de ${meeting.tipo}`;

    const agenda = meeting.agenda
        ? (() => {
              const rotulo =
                  meeting.agenda.nome.trim().split(/\s+/)[0] || meeting.agenda.email;
              let soma = 0;
              for (const caractere of meeting.agenda.email) {
                  soma += caractere.charCodeAt(0);
              }
              const tom = (["blue", "purple", "yellow"] as const)[soma % 3];
              return `<span class="tag ${tom}" title="${escapar(meeting.agenda.email)}">${escapar(rotulo)}</span>`;
          })()
        : "";

    porId("meetingModalBadges").innerHTML = `
    <span class="tag ${tagDoTipoDeReuniao[meeting.tipo] ?? ""}">${escapar(meeting.tipo)}</span>
    <span class="tag ${tagDoStatus[meeting.status] ?? ""}">${escapar(meeting.status)}</span>
    ${agenda}
  `;

    porId("meetingModalData").textContent = meeting.data;
    porId("meetingModalResponsavel").textContent = meeting.responsavel;
    porId("meetingModalSocio").textContent = meeting.socio ?? "—";
    porId("meetingModalParticipantes").textContent = meeting.participantes.join(", ");
    porId("meetingModalResumo").textContent = meeting.resumo;

    porId("meetingModalDecisoes").innerHTML = listaOuVazio(
        meeting.decisoes,
        "Nenhuma decisão registrada ainda.",
    );
    porId("meetingModalProximos").innerHTML = listaOuVazio(
        meeting.proximosPassos,
        "Nenhum próximo passo registrado.",
    );

    abrir(reuniao);
}

function listaOuVazio(itens: string[], vazio: string): string {
    return itens.length
        ? itens.map((item) => `<li>${escapar(item)}</li>`).join("")
        : `<li class="vazio">${vazio}</li>`;
}

export function closeMeetingModal(): void {
    fechar(modais?.reuniao);
}

/** Liga qualquer elemento com [data-info] ao modal genérico. */
export function ligarInfos(escopo: ParentNode = document): void {
    todos("[data-info]", escopo).forEach((elemento) => {
        elemento.addEventListener("click", () => showModal(dado(elemento, "info")));
    });
}
