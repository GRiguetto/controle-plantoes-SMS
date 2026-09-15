/**
 * app.js
 * Helpers genéricos usados em todas as telas: chamadas à API,
 * exibição de mensagens, formatação e comportamento de UI (sidebar).
 */

 const API_BASE = 'api';

 /**
  * Executa uma requisição JSON contra os endpoints PHP.
  * @param {string} endpoint - ex: 'auth.php?acao=login'
  * @param {object|null} corpo - payload (será enviado como JSON no POST)
  * @param {string} metodo - 'GET' | 'POST'
  */
 async function apiFetch(endpoint, corpo = null, metodo = corpo ? 'POST' : 'GET') {
   const resposta = await fetch(`${API_BASE}/${endpoint}`, {
     method: metodo,
     headers: { 'Content-Type': 'application/json' },
     credentials: 'same-origin',
     body: corpo ? JSON.stringify(corpo) : undefined,
   });
 
   const json = await resposta.json().catch(() => ({
     sucesso: false,
     mensagem: 'Resposta inválida do servidor.',
   }));
 
   return json;
 }
 
 function exibirMensagem(elementoId, mensagem, tipo = 'erro') {
   const el = document.getElementById(elementoId);
   if (!el) return;
   el.textContent = mensagem;
   el.className = tipo === 'erro' ? 'erro' : 'sucesso';
   el.style.display = mensagem ? 'block' : 'none';
 }
 
 function badgeStatus(status) {
   const rotulos = { pendente: 'Pendente', aceito: 'Aprovado', negado: 'Negado' };
   return `<span class="badge badge-${status}">${rotulos[status] ?? status}</span>`;
 }
 
 function iniciaisNome(nomeCompleto) {
   if (!nomeCompleto) return '?';
   const partes = nomeCompleto.trim().split(/\s+/);
   const primeira = partes[0]?.[0] ?? '';
   const ultima = partes.length > 1 ? partes[partes.length - 1][0] : '';
   return (primeira + ultima).toUpperCase();
 }
 
 /**
  * Preenche o cabeçalho (topbar) com nome/perfil do usuário logado
  * e monta o avatar com iniciais.
  */
 function montarTopbar(nomeCompleto, rotuloPerfil) {
   const nomeEl = document.getElementById('topbar-user-nome');
   const perfilEl = document.getElementById('topbar-user-perfil');
   const avatarEl = document.getElementById('topbar-avatar');
   if (nomeEl) nomeEl.textContent = nomeCompleto;
   if (perfilEl && rotuloPerfil) perfilEl.textContent = rotuloPerfil;
   if (avatarEl) avatarEl.textContent = iniciaisNome(nomeCompleto);
 }
 
 /**
  * Liga o comportamento do menu mobile (abrir/fechar sidebar) presente
  * em todas as páginas internas do sistema.
  */
 function iniciarSidebarMobile() {
   const btnAbrir = document.getElementById('btn-menu-mobile');
   const sidebar = document.getElementById('sidebar');
   const overlay = document.getElementById('overlay-mobile');
   if (!btnAbrir || !sidebar || !overlay) return;
 
   const abrir = () => { sidebar.classList.add('aberto'); overlay.classList.add('ativo'); };
   const fechar = () => { sidebar.classList.remove('aberto'); overlay.classList.remove('ativo'); };
 
   btnAbrir.addEventListener('click', abrir);
   overlay.addEventListener('click', fechar);
   sidebar.querySelectorAll('a').forEach(a => a.addEventListener('click', fechar));
 }
 
 function ligarBotaoSair() {
   const btn = document.getElementById('btn-sair');
   if (!btn) return;
   btn.addEventListener('click', async () => {
     await apiFetch('auth.php?acao=logout', {});
     window.location.href = 'login.html';
   });
 }
 
 /* -------------------- Sidebar / topbar por perfil -------------------- */
 
 const ICONES = {
   visao: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
   registro: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>',
   pendentes: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>',
   historico: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/></svg>',
   usuarios: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
   perfil: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>',
   sair: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>',
   senha: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
   menu: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>',
 };
 
 const ROTAS_POR_PERFIL = {
   funcionario: [
     ['dashboard-funcionario.html', 'Meu Painel', 'visao'],
     ['historico.html', 'Histórico', 'historico'],
   ],
   gerente: [
     ['dashboard-gerente.html', 'Pedidos Pendentes', 'pendentes'],
     ['historico.html', 'Histórico', 'historico'],
   ],
   administrador: [
     ['dashboard-administrador.html', 'Visão Geral', 'visao'],
     ['historico.html', 'Histórico', 'historico'],
   ],
 };
 
 const ROTULO_PERFIL = {
   funcionario: 'Funcionário',
   gerente: 'Gerente',
   administrador: 'Administrador',
 };
 
 /**
  * Monta a sidebar e a topbar de uma página interna a partir do perfil do
  * usuário logado. paginaAtual deve ser o nome do arquivo (ex: 'historico.html').
  */
 function montarShell(perfil, paginaAtual, nomeCompleto) {
   const rotas = ROTAS_POR_PERFIL[perfil] || [];
 
   const sidebar = document.getElementById('sidebar');
   if (sidebar) {
     const nav = sidebar.querySelector('.sidebar-nav');
     if (nav) {
       nav.innerHTML = rotas.map(([href, label, icone]) => {
         const ativo = href === paginaAtual ? ' ativo' : '';
         return `<a href="${href}" class="${ativo.trim()}"><span class="icone">${ICONES[icone]}</span>${label}</a>`;
       }).join('');
     }
   }
 
   montarTopbar(nomeCompleto, ROTULO_PERFIL[perfil] || perfil);
 }
 
 document.addEventListener('DOMContentLoaded', () => {
   iniciarSidebarMobile();
   ligarBotaoSair();
 });