/* =========================================================
   ÍCONES
   Um registro só para o app inteiro. Nenhuma tela desenha um
   emoji ou um caractere solto: ela pede um ícone pelo nome, e o
   TypeScript recusa um nome que não esteja registrado aqui — o
   ícone quebrado aparece na compilação, não na tela do usuário.

   A Lucide vem do bundle, não de CDN, e só entram os ícones em
   uso: cada importação abaixo é um SVG a mais no pacote.
   ========================================================= */

import {
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    AudioLines,
    Banknote,
    BookOpen,
    Building2,
    Calendar,
    CalendarDays,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    CircleDollarSign,
    CircleHelp,
    ClipboardList,
    Database,
    Download,
    EllipsisVertical,
    File,
    FileSpreadsheet,
    FileText,
    Folder,
    FolderOpen,
    FolderPlus,
    GraduationCap,
    Home,
    Image,
    Layers,
    LayoutGrid,
    List,
    LogOut,
    Menu,
    Mic,
    Moon,
    Paperclip,
    Plus,
    Rocket,
    Route,
    Search,
    Settings,
    ShieldCheck,
    Sparkles,
    Square,
    Sun,
    Trash2,
    TrendingUp,
    Upload,
    UserPlus,
    Users,
    Video,
    Workflow,
    X,
    createIcons,
} from "lucide";

/** Registro completo: a chave é o nome usado em `data-lucide`. */
const REGISTRO = {
    "arrow-left": ArrowLeft,
    "arrow-right": ArrowRight,
    "arrow-up": ArrowUp,
    "audio-lines": AudioLines,
    banknote: Banknote,
    "book-open": BookOpen,
    "building-2": Building2,
    calendar: Calendar,
    "calendar-days": CalendarDays,
    check: Check,
    "chevron-down": ChevronDown,
    "chevron-left": ChevronLeft,
    "chevron-right": ChevronRight,
    "circle-alert": CircleAlert,
    "circle-dollar-sign": CircleDollarSign,
    "circle-help": CircleHelp,
    "clipboard-list": ClipboardList,
    database: Database,
    download: Download,
    "ellipsis-vertical": EllipsisVertical,
    file: File,
    "file-spreadsheet": FileSpreadsheet,
    "file-text": FileText,
    folder: Folder,
    "folder-open": FolderOpen,
    "folder-plus": FolderPlus,
    "graduation-cap": GraduationCap,
    home: Home,
    image: Image,
    layers: Layers,
    "layout-grid": LayoutGrid,
    list: List,
    "log-out": LogOut,
    menu: Menu,
    mic: Mic,
    moon: Moon,
    paperclip: Paperclip,
    plus: Plus,
    rocket: Rocket,
    route: Route,
    search: Search,
    settings: Settings,
    "shield-check": ShieldCheck,
    sparkles: Sparkles,
    square: Square,
    sun: Sun,
    "trash-2": Trash2,
    "trending-up": TrendingUp,
    upload: Upload,
    "user-plus": UserPlus,
    users: Users,
    video: Video,
    workflow: Workflow,
    x: X,
} as const;

export type NomeDeIcone = keyof typeof REGISTRO;

/**
 * A Lucide procura o ícone pelo nome em PascalCase ("arrow-left" → ArrowLeft),
 * mas o registro acima usa a forma que aparece no atributo `data-lucide`, que
 * é a que se lê no HTML. A conversão acontece uma vez, aqui.
 */
const REGISTRO_LUCIDE = Object.fromEntries(
    Object.entries(REGISTRO).map(([nome, componente]) => [
        nome.replace(/(^|-)(\w)/g, (_, __, letra: string) => letra.toUpperCase()),
        componente,
    ]),
);

/**
 * Marcador para um ícone dentro de um template de HTML gerado aqui. Ícone que
 * é parte fixa da página fica no Blade como `<i data-lucide="...">`; esta
 * função é para o HTML que o TypeScript monta.
 *
 * O marcador vira SVG quando `desenharIcones()` roda — sempre depois de o HTML
 * entrar no documento.
 */
export function icone(nome: NomeDeIcone): string {
    return `<i data-lucide="${nome}" aria-hidden="true"></i>`;
}

/** Troca por SVG todo marcador `[data-lucide]` que ainda estiver na página. */
export function desenharIcones(raiz: Document | Element = document): void {
    createIcons({
        icons: REGISTRO_LUCIDE,
        root: raiz as Document,
        attrs: { focusable: "false" },
    });
}

/**
 * Ícone cujo nome veio do servidor (mapa de telas, sugestões da Sofia). Como
 * é texto solto, não dá para o TypeScript garantir nada: se o nome não existir
 * no registro, a tela mostra um ícone neutro em vez de um buraco.
 */
export function iconeSeguro(nome: string): string {
    return nome in REGISTRO ? icone(nome as NomeDeIcone) : icone("circle-alert");
}
