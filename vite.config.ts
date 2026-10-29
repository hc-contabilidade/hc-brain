import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";

// Cada tela tem a sua folha e o seu script, com o mesmo nome nos dois lugares:
// a tela "reunioes" é resources/css/reunioes.css e resources/js/paginas/reunioes.ts.
// O Blade de cada tela pede esse par pelo @vite, então todos entram como entrada.
const TELAS = [
  "login",
  "senha",
  "inicio",
  "pesquisa",
  "documentos",
  "sofia-ia",
  "treinamentos",
  "clientes",
  "cliente",
  "usuarios",
  "comercial",
  "reunioes",
  "financeiro",
  "projetos",
  "processos",
  "perfil",
];

const COMUNS = ["base", "layout", "sidebar", "componentes", "modal", "sofia-agente"];

// As páginas de erro não são telas do app: não têm script nem menu, só a
// base e a própria folha.
const AVULSAS = ["resources/css/erro.css"];

export default defineConfig({
  plugins: [
    laravel({
      input: [
        ...COMUNS.map((nome) => `resources/css/comum/${nome}.css`),
        ...TELAS.map((tela) => `resources/css/${tela}.css`),
        ...TELAS.map((tela) => `resources/js/paginas/${tela}.ts`),
        ...AVULSAS,
      ],
      refresh: true,
    }),
  ],
  server: {
    watch: {
      ignored: ["**/storage/framework/views/**"],
    },
  },
});
