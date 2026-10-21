# Hostinger — dashboard e API do Cultivo

Conteúdo a ser enviado para o `public_html` do domínio
(`https://powderblue-rhinoceros-609254.hostingersite.com`). A descrição completa
do projeto está no `README.md` da raiz.

## Arquivos

| Arquivo | Acesso | Função |
|---|---|---|
| `index.php` | Sessão | Dashboard (Início, Relógio de eventos, Automação, Comandos, Histórico, Dispositivo, Configurações) |
| `login.php` / `logout.php` | — | Login, cadastro (com código de convite), esqueci a senha, sair |
| `auth.php` | — | Contas, "Lembrar de mim", código de convite e CSRF |
| `usuarios.php` | Sessão | Alterar senha; admin: contas, redefinir senha, convite |
| `config.example.php` | — | Modelo do `config.php` (credenciais do banco, API key e login) |
| `migracoes.php` | — | Cria a tabela `configuracoes` e a coluna `vpd` automaticamente |
| `configuracoes.php` | Sessão | Preferências do painel e status do fotoperíodo confirmado pelo ESP32 |
| `enviar_comando.php` | Sessão | Grava comandos do dashboard na fila `comandos_pendentes` |
| `exportar_csv.php` | Sessão | Histórico em CSV (`?periodo=24h\|7d\|30d`; `;` e vírgula decimal) |
| `dados.php` | Pública | Leituras para os gráficos (`?periodo=24h\|7d\|30d`) |
| `comandos.php` | Pública | Eventos executados + modo de cultivo atual |
| `comandos_pendentes.php` | `X-API-Key` (token `sd_hw_` ou API_KEY) | ESP32 busca os comandos da fila |
| `registrar_leitura.php` | `X-API-Key` (token `sd_hw_` ou API_KEY) | ESP32 envia leituras (o VPD é calculado aqui) |
| `registrar_comando.php` | `X-API-Key` (token `sd_hw_` ou API_KEY) | ESP32 envia eventos executados |
| `status.php` | Sessão | Online/offline e dados do ESP32 (firmware, sinal, tempo ligado) |
| `dispositivo.php` | Sessão (alterações: admin) | Token do hardware, token de vinculação e atividade recente |
| `vincular.php` | Pública (código de uso único) | ESP32 troca o token de vinculação pelo token definitivo |
| `automacao.php` | Sessão | Troca de fase: ativar agora, programar para uma data, cancelar |
| `cron.php` | Só linha de comando | Trocas de fase programadas e avisos do Telegram (cron a cada minuto) |
| `notificacoes.php` | Sessão | Vínculo do Telegram, mensagem de teste e interruptores dos avisos |
| `telegram.php` | — | Envio pelo bot, limite de 1 aviso a cada 30 min e "normalizado" |
| `sql/schema.sql` | — | Estrutura do banco (instalação nova) |
| `sql/migracoes/` | — | Mudanças de banco numeradas: `.up.sql` aplica sozinho, `.down.sql` desfaz (rodar no phpMyAdmin) |
| `partials/` | — | Cabeçalho e rodapé comuns do painel |

## Instalação

1. No hPanel, crie um banco MySQL e importe `sql/schema.sql` pelo phpMyAdmin.
2. Copie `config.example.php` para `config.php` e preencha `API_KEY`, `DB_*`,
   `WEB_LOGIN_USUARIO` e `WEB_LOGIN_SENHA_HASH`. A `API_KEY` deve ser a mesma de
   `SECRET_HOSTINGER_API_KEY` no `secrets.h` do firmware.
3. Envie o conteúdo desta pasta para o `public_html`, **incluindo a pasta `sql/`** (o site precisa de HTTPS).
4. Entre com o admin do `config.php`. Para liberar o cadastro de outras contas,
   gere o código de convite em Configurações → Usuários.
5. No hPanel → **Avançado → Cron Jobs**, crie um cron **a cada minuto** com o comando
   `php /home/SEU_USUARIO/domains/SEU-DOMINIO/public_html/cron.php`.
   Ele executa as trocas de fase programadas na Automação. Sem o cron, só a troca
   programada deixa de funcionar ("Ativar agora" funciona normalmente).
6. (Opcional) Telegram: crie um bot no **@BotFather**, coloque o token no `config.php`
   (`const TELEGRAM_BOT_TOKEN = '...';`) e, no painel, vá em Configurações →
   Notificações → **Conectar**. Abra o link no celular e toque em Iniciar. O offline,
   o timer sem confirmação e o resumo das 08:00 dependem do cron do passo 5.
O banco: numa instalação nova, o `schema.sql` já cria tudo. Num banco de versão
anterior, basta abrir o painel uma vez: `migracoes.php` cria a tabela
`configuracoes` e a coluna `vpd`, e `auth.php` cria `usuarios` e `lembrar_tokens`.

O admin do `config.php` vira uma conta no banco no primeiro acesso (Etapa 6). Para
recuperar o acesso, gere um hash novo e troque `WEB_LOGIN_SENHA_HASH`: ele passa a
valer no próximo login e encerra as sessões abertas. A tabela antiga
`login_tentativas` deixou de ser usada e pode ser apagada.

Token do ESP32 (Etapa 7): enquanto nenhum token for gerado, vale a `API_KEY` do
`config.php` (firmware antigo). Ao vincular o dispositivo ou gerar um token em
**Dispositivo**, só o token `sd_hw_` passa a ser aceito; "Revogar" bloqueia os dois.

Não apague `migracoes.php` nem `auth.php`: além das migrações, eles têm funções
usadas pelas outras páginas.

O `config.php` fica fora do git. O histórico completo de leituras e eventos fica
no MySQL; o ESP32 guarda localmente só os últimos 7 dias.
