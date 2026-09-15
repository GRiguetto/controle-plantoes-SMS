# Sistema de Controle de Plantões

Aplicação web para gestão do ciclo de vida de plantões (registro de jornada,
apuração de horas, avaliação de produtividade e relatórios), com 3 perfis de
acesso: **Funcionário**, **Gerente** e **Administrador**.

Stack: PHP (back-end) · MySQL/phpMyAdmin (banco) · HTML5/CSS3/JS (front-end).

## Estrutura de pastas

```
controle-plantoes/
├── api/                    # Endpoints PHP (back-end)
│   ├── bootstrap.php       # Sessão, headers, helpers de RBAC
│   ├── db.php              # Conexão PDO com o MySQL
│   ├── auth.php            # Login, cadastro, logout
│   ├── pedidos.php         # Registro e avaliação de plantões
│   ├── usuarios.php        # Painel administrativo de usuários
│   ├── unidades.php        # Listagem de unidades/setores
│   └── relatorios.php      # Exportação de relatórios (CSV/XLSX/PDF)
├── css/
│   └── style.css           # Design tokens e componentes (ver seção 7 da spec)
├── img/                    # Imagens e ícones estáticos
├── js/
│   └── app.js               # Helpers de front-end (fetch da API, etc.)
├── sql/
│   └── schema.sql          # Schema completo do banco de dados
├── index.html                    # Ponto de entrada (verifica sessão via auth.php?acao=me e redireciona por perfil)
├── login.html                    # Login + Cadastro (split-screen com tabs)
├── historico.html                # Histórico de plantões com filtros (shell — falta plugar consulta/exportação)
├── dashboard-funcionario.html    # KPIs de horas + registro de plantão (com múltiplas pausas)
├── dashboard-gerente.html        # Avaliação de pedidos pendentes (aprovar/negar) da unidade do gerente
├── dashboard-administrador.html  # Visão global read-only + gestão de usuários (busca/senha/desativação)
├── meu-perfil.html               # Edição de dados cadastrais + troca de senha (com validação da senha atual)
└── README.md
```

## Banco de dados

Importe `sql/schema.sql` via phpMyAdmin ou linha de comando:

```bash
mysql -u root -p < sql/schema.sql
```

Tabelas principais: `usuarios`, `unidades`, `usuario_unidade` (N:N), `setores`,
`plantoes`, `pausas`, `logs`.

## Configuração

Editar credenciais de conexão em `api/db.php` (`$host`, `$banco`, `$usuario`, `$senha`).

## Pendências conhecidas

- **Cálculo de horas diurnas/noturnas**: regra de negócio a definir (horário de corte diurno/noturno) e endpoint de KPIs (`pedidos.php?acao=kpis`) referenciado em `dashboard-funcionario.html`
- **Exportação XLSX/PDF** em `relatorios.php` depende de biblioteca externa via Composer (sugestão: PhpSpreadsheet para XLSX, DOMPDF/TCPDF para PDF) — CSV já funcional
- **Recuperação de senha**: endpoint `auth.php?acao=recuperar_senha` (fluxo "Esqueci minha senha" em `login.html`) ainda não implementado
- **Módulo de onboarding guiado** (seção 8 da especificação): tooltip guiado sequencial com spotlight, ainda não iniciado
- **`historico.html`**: shell criado, mas a busca de dados (`pedidos.php?acao=historico`), filtros dinâmicos por perfil e os links de exportação ainda precisam ser conectados via JS
- **Validação cruzada gerente↔unidade** em `pedidos.php?acao=aprovar/negar`: recomenda-se checar explicitamente que o plantão pertence à unidade do gerente antes do UPDATE (comentado no código)
- **Atributos de acessibilidade** (WCAG 2.1 AA, `aria-label`, `aria-describedby`) ainda não aplicados nos formulários
