# 🌿 Cultivo — Automação para Estufa/Grow Tent (ESP32-S3)

Sistema de automação para cultivo indoor baseado em **ESP32-S3**, com controle de iluminação por fotoperíodo, exaustão/ventilação, trava de acesso, monitoramento ambiental (interno e externo) e umidade do solo, display **OLED SSD1306** local e **dashboard web remoto** (hospedado na Hostinger, protegido por login) — o ESP32 não sobe mais nenhum servidor HTTP próprio.

> Este documento descreve a **versão atual (v2)** do projeto. A v1 substituiu o Blynk por um dashboard web embutido no próprio ESP32; a v2 removeu esse servidor local (instável sob uso real — reiniciava o dispositivo ao ser acessado) e migrou todo o controle para o dashboard da Hostinger, com login e fila de comandos.

---

## 📋 O que mudou da v1 para a v2

A v2 reformula o **canal de controle remoto**: o dashboard web que rodava embutido no próprio ESP32 (introduzido na v1) foi **removido** depois de se mostrar instável em uso real, e todo o controle passou a ser feito por um backend próprio hospedado na Hostinger, com login e fila de comandos.

| Área | v1 (Cultivo) | v2 (Cultivo) |
|---|---|---|
| Controle remoto | **Dashboard web local**, servido pelo próprio ESP32 (`ESPAsyncWebServer`/`AsyncTCP`, HTML/CSS/JS embutidos) | **Dashboard web na Hostinger** (PHP + MySQL), fora do ESP32 — pasta `hostinger/` |
| Modelo de comunicação | **Push**: o navegador falava direto com o ESP32, via rotas próprias (`/api/rele`, `/api/modo_cultivo`, `/api/luz_manual`, `/api/acesso`) | **Pull**: o ESP32 consulta (poll) comandos pendentes na Hostinger a cada ~4s; o navegador nunca fala com o ESP32 |
| Estabilidade | `ESPAsyncWebServer`/`AsyncTCP` **instável sob uso real** — o dispositivo reiniciava sozinho ao acessar o dashboard | Servidor HTTP local **removido**; o ESP32 só faz requisições HTTPS de saída |
| Acesso fora da rede WiFi local | Exigia VPN/túnel ou porta aberta no roteador | **Nativo** — a Hostinger é acessível de qualquer lugar com internet, sem abrir porta nenhuma no ESP32/roteador |
| Autenticação do painel | **HTTP Basic Auth** | **Login com sessão** (cookie `secure`/`httponly`), senha com hash bcrypt, bloqueio de IP persistido no banco após tentativas incorretas |
| Backend Hostinger | **Opcional** — só recebia backup/histórico de leituras e comandos | **Obrigatório** — é o único canal de controle remoto e a fonte de dados do dashboard |
| Acesso por nome (mDNS) | `cultivo.local` apontava para o dashboard embutido no ESP32 | mDNS mantido **só para anunciar o serviço de OTA** — não há mais dashboard local para apontar |
| Dependências do firmware | Incluía `AsyncTCP`/`ESPAsyncWebServer` | Essas libs **saíram** do `platformio.ini` — não sobra nenhum servidor HTTP no ESP32 |

Vale notar o trade-off: os controles **locais** (menu no OLED, botão físico de acesso) continuam funcionando **normalmente sem internet** em qualquer versão — eles agem direto no relé. O que passou a depender de internet foi só o **controle remoto**: sem WiFi/Hostinger, dá pra operar o sistema fisicamente na frente do equipamento, mas não pelo dashboard.

---

## Funcionalidades

- **Controle automático de iluminação** por fotoperíodo, com dois modos de cultivo fixos no firmware:
  - **Vegetação**: luz ligada das 16h às 10h (18h de luz / 6h de escuro)
  - **Floração**: luz ligada das 19h às 7h (12h de luz / 12h de escuro)
- **Override manual da luz** (Automático / Ligada / Desligada) que não interrompe o cálculo do fotoperíodo e expira sozinho após 30 minutos — persistido entre reinícios
- **Monitoramento ambiental interno e externo**, com sensores independentes dentro e fora da estufa
- **Sensor de umidade do solo** com calibração por faixa de leitura bruta e média de múltiplas amostras
- **Cálculo de VPD (Déficit de Pressão de Vapor)**, refletido em tempo real na cor do LED NeoPixel e no dashboard
- **8 relés** para luz, exaustores, ventiladores, entradas de ar e trava de acesso
- **Trava eletrônica de acesso** com liberação temporizada de 3 segundos, acionável tanto pelo botão físico dedicado quanto pelo dashboard — os dois caminhos chamam a mesma rotina e geram o mesmo evento no histórico
- **Display OLED SSD1306 (128x64)** com tela de descanso animada, tela de informações e menu de configuração navegável por botão físico
- **Dashboard web remoto** (Hostinger — marca "Smart Demetra"), responsivo (mobile e desktop), protegido por login, organizado em 3 abas:
  - **Início**: leituras atuais (temperatura/umidade interna e externa, solo), contagem regressiva até a próxima troca de luz, barra de VPD com zona destacada e barra de umidade do solo com faixa ideal (50%–60%)
  - **Comandos**: modo de cultivo, luz manual, liberação de acesso e toggles dos relés manuais — cada ação vai para uma fila que o ESP32 consulta por polling
  - **Histórico**: gráficos de temperatura e umidade (interna x externa) com seletor de período (24h / 7 dias / 30 dias — os períodos de 7 e 30 dias vêm agregados por hora no banco), roda de eventos das últimas 24h e lista dos eventos mais recentes
- **Atualização automática do dashboard** a cada 1 minuto (mesmo ritmo do envio de leituras do ESP32); a contagem regressiva da luz é recalculada localmente a cada 1 segundo
- **Histórico local em LittleFS** (cache interno de 7 dias) + histórico completo e fila de comandos na Hostinger
- **Persistência entre quedas de energia**: relés manuais, modo de cultivo e override de luz (com prazo de expiração) gravados na NVRAM com bateria do RTC DS1307
- **Atualização de firmware por OTA** (rede WiFi), sem necessidade de cabo USB após a primeira gravação
- **Sincronização de hora via NTP** com múltiplos servidores de fallback e reserva no RTC
- **Reconexão automática** de WiFi com estratégia de backoff
- **Entrega de comandos sem duplicidade**: `comandos_pendentes.php` usa transação com `FOR UPDATE` ao marcar comandos como entregues, evitando que o mesmo comando seja aplicado duas vezes se o ESP32 fizer poll duas vezes quase ao mesmo tempo (ex.: reinício no meio de um ciclo)
- **Modo de simulação Wokwi** para testes completos sem hardware físico

---

## Hardware e Pinagem (ESP32-S3)

### Barramento I2C — compartilhado entre OLED, RTC e sensor interno

| Sinal | Pino | Conectar em |
|---|---|---|
| SDA | 8 | OLED + RTC DS1307 + sensor interno (SHT40) |
| SCL | 9 | OLED + RTC DS1307 + sensor interno (SHT40) |
| 3.3V | 3V3 | VCC de todos os dispositivos I2C |
| GND | GND | GND de todos os dispositivos I2C |

> Antes de inicializar o barramento, o firmware executa uma rotina de **recuperação de I2C** (pulsos manuais de clock + condição de STOP), evitando que um dispositivo travado no barramento (ex.: RTC desligado) impeça o OLED de iniciar.

### Sensores

| Sensor | Modo | Pino | Observação |
|---|---|---|---|
| Temperatura/umidade interna | Hardware real | I2C (SDA/SCL) | SHT40 |
| Temperatura/umidade interna | Simulação Wokwi | GPIO 15 | DHT22 |
| Temperatura/umidade externa | Ambos os modos | GPIO 16 | DHT11 no hardware físico / DHT22 na simulação — sempre presente |
| Umidade do solo | Ambos os modos | GPIO 6 (ADC) | Calibrar `SOLO_ADC_SECO`/`SOLO_ADC_UMIDO` |

### Relés (mapa atualizado — índice no array `reles[8]`)

| Índice | Pino GPIO | Função | Controle |
|---|---|---|---|
| 0 | 42 | Luz de Cultivo | Automático (fotoperíodo) + override manual |
| 1 | 41 | Desumidificador | Manual |
| 2 | 40 | Ventilador | Manual |
| 3 | 39 | *(não utilizado)* | — não conectado no hardware, oculto do menu e do dashboard |
| 4 | 38 | Entrada de Ar 1 | Manual |
| 5 | 37 | Entrada de Ar 2 | Manual |
| 6 | 36 | Trava de Acesso | Automático (pulso temporizado) |
| 7 | 35 | Saída de Ar | Manual |

Todos os relés são **ativos em nível baixo** por padrão (`RELE_ATIVO_EM_BAIXO`).

> ⚠️ **ESP32-S3 com PSRAM Octal:** módulos "N16R8" reservam GPIO35/36/37 para a PSRAM. Nesta pinagem esses três pinos são usados (Entrada de Ar 2, Trava de Acesso e Saída de Ar) — confirme a variante da sua placa antes de montar o circuito físico.

### Demais periféricos

| Componente | Pino | Observação |
|---|---|---|
| Buzzer | 17 | Passivo, acionamento não bloqueante |
| Botão de menu OLED | 5 | `INPUT_PULLUP` |
| Botão de liberação de acesso | 19 | `INPUT_PULLUP` — libera a trava por 3 segundos ao pressionar |
| LED NeoPixel | 4 (simulação) / 48 (hardware — LED embutido do ESP32-S3) | Indicador de zona de VPD |

---

## Dashboard Web (Hostinger)

Desde a v2, **não existe mais servidor HTTP no ESP32**. Todo o controle e monitoramento remoto é feito pelo dashboard hospedado na Hostinger (pasta `hostinger/`), acessível de qualquer lugar com internet:

```
https://SEU-DOMINIO/index.php
```

O acesso exige **login** (usuário e senha, com hash bcrypt — configurado em `hostinger/config.php`, ver `WEB_LOGIN_USUARIO`/`WEB_LOGIN_SENHA_HASH`). Após 5 tentativas incorretas, o IP fica bloqueado por **2 minutos** (`LOGIN_MAX_TENTATIVAS`/`LOGIN_BLOQUEIO_MINUTOS` em `config.php`) — bloqueio persistido no banco (tabela `login_tentativas`), não expira ao trocar de sessão/cookie.

### Como o controle chega até o ESP32

Como o dispositivo não tem mais servidor próprio, os botões do dashboard **não falam direto com o ESP32** — eles gravam um comando numa fila (`comandos_pendentes`, no banco da Hostinger), e o ESP32 **consulta essa fila periodicamente** (a cada ~4 segundos, via HTTPS, autenticado por API key) e aplica o que encontrar. Essa troca de modelo (empurrar → puxar) é o que elimina a necessidade de abrir qualquer porta no ESP32 ou na rede local, e funciona de qualquer lugar com internet, não só na rede WiFi da estufa.

### O que o painel oferece

O dashboard (marca "Smart Demetra") é dividido em 3 abas, navegáveis pela barra lateral (menu hambúrguer no mobile):

**Início** — visão geral em tempo real:
- Cards de temperatura/umidade interna e externa, umidade do solo e contagem regressiva até a próxima troca de luz ("Luz do cultivo")
- Barra de VPD com marcador de zona atual (mesmas faixas/cores do LED NeoPixel do firmware)
- Barra de umidade do solo com indicação da faixa ideal (50%–60%)

**Comandos** — controle remoto (grava na fila que o ESP32 consulta):
- Modo de cultivo (Vegetativo / Floração), com indicação do horário de cada um
- Luz (Automático / Ligar / Desligar)
- Liberação da trava de acesso (pulso de 3 segundos, igual ao botão físico)
- Toggles para os relés manuais (Desumidificador, Ventilador, Entradas de Ar, Saída de Ar)

**Histórico** — dados acumulados:
- Seletor de período: **24 horas** (leituras cruas, 1/min), **1 semana** e **1 mês** (agregadas por hora no banco, para não sobrecarregar o navegador)
- Gráficos separados de temperatura e de umidade, cada um comparando interna x externa
- **Relógio de eventos**: roda de 24h sincronizada com o modo de cultivo ativo, mostrando acionamentos da porta, do ventilador e da saída de ar, e há quanto tempo cada um está no estado atual
- Lista dos eventos das últimas 24h

O painel inteiro se atualiza sozinho a cada 1 minuto (mesmo ritmo do envio de leituras do firmware); a contagem regressiva da luz é recalculada localmente a cada 1 segundo, sem precisar de nova consulta ao servidor.

### Endpoints HTTP (hostinger/)

| Rota | Método | Autenticação | Função |
|---|---|---|---|
| `index.php` | GET | Login (sessão) | Página do dashboard |
| `login.php` / `logout.php` | GET/POST | — | Entrar/sair |
| `dados.php` | GET | Pública (leitura) | Leituras de ambiente (histórico). Aceita `?periodo=24h\|7d\|30d` (padrão `24h`) |
| `comandos.php` | GET | Pública (leitura) | Eventos executados + modo de cultivo atual. Aceita `?limite=N` (1–500, padrão 100) |
| `enviar_comando.php` | POST | Login (sessão) | Grava um comando na fila (botões do dashboard) |
| `comandos_pendentes.php` | GET | API key (dispositivo) | ESP32 busca e "retira" (até 20 por vez, com trava transacional `FOR UPDATE` contra entrega duplicada) comandos pendentes da fila |
| `registrar_leitura.php` | POST | API key (dispositivo) | ESP32 envia uma leitura de sensores |
| `registrar_comando.php` | POST | API key (dispositivo) | ESP32 confirma um comando já executado (para o histórico/roda de eventos) |

> ⚠️ O login usa cookie de sessão com a flag `secure` — a Hostinger precisa estar servindo por **HTTPS** (padrão na maioria dos planos).
> ⚠️ `dados.php` e `comandos.php` são endpoints de leitura pública (sem API key nem login) — pensados para alimentar o dashboard sem fricção, mas isso significa que qualquer pessoa com a URL consegue ler leituras e eventos. Se isso for uma preocupação, considere adicionar autenticação a esses dois endpoints também.

---

## Monitoramento de VPD

O firmware calcula o **Déficit de Pressão de Vapor** (fórmula de Tetens/Magnus) a partir da temperatura e umidade internas, e usa o resultado tanto no dashboard quanto na cor do LED de status:

| Faixa de VPD | Zona | Cor do LED |
|---|---|---|
| < 0,4 kPa | Ar úmido demais (risco de fungo/mofo) | Rosa fraco |
| 0,4 – 0,8 kPa | Propagação / início de vegetativo | Rosa forte |
| 0,8 – 1,2 kPa | Fim de vegetativo / início de floração | Verde |
| 1,2 – 1,6 kPa | Floração média/tardia | Laranja |
| > 1,6 kPa | Ar seco demais (estresse hídrico) | Rosa fraco |

O LED mantém um leve efeito de "respiração" no brilho, sem nunca apagar por completo.

---

## Histórico de Dados

- **Local (LittleFS):** uma leitura completa é gravada em CSV a cada 10 minutos, com limite de 1008 linhas (~7 dias) — ao ultrapassar o limite, as linhas mais antigas são descartadas automaticamente. Serve como registro interno; sem servidor local, não há mais como baixar esse CSV diretamente do dispositivo.
- **Remoto (Hostinger):** leituras são enviadas a cada 1 minuto para o backend próprio (`HOSTINGER_BASE_URL`/`HOSTINGER_API_KEY`), e comandos executados são enfileirados e reenviados de forma resiliente em caso de falha de rede. Desde a v2, o backend remoto **deixou de ser opcional**: é ele quem alimenta o dashboard (único ponto de controle/monitoramento remoto) e a fila de comandos pendentes que o ESP32 consulta. A aba Histórico do dashboard consulta esses dados via `dados.php`, que serve leituras cruas (1/min) para o período de 24h e leituras agregadas por hora para 7 e 30 dias.

---

## Persistência entre Quedas de Energia

O RTC DS1307 possui 56 bytes de RAM com bateria de backup. Os 8 primeiros são usados para:

| Byte(s) | Conteúdo |
|---|---|
| 0 | Marcador de validade |
| 1 | Bitmask dos relés manuais |
| 2 | Modo de cultivo |
| 3 | Modo do override de luz (Auto/Ligada/Desligada) |
| 4–7 | Horário absoluto (unixtime) em que o override de luz expira |

Diferente da v0, o **override manual da luz agora sobrevive a um reinício** (desde que ainda não tenha expirado, calculado em horário absoluto e não em `millis()`). A luz em si e a trava de acesso **nunca são restauradas diretamente**, por segurança — a luz é sempre recalculada pelo fotoperíodo/override, e a trava nunca volta ligada.

---

## Atualização por OTA

Após a primeira gravação via USB, novas versões do firmware podem ser enviadas pela rede WiFi:

1. Troque o ambiente ativo no PlatformIO de `esp32-s3-devkitc-1` para `esp32-s3-devkitc-1-ota`
2. Garanta que a senha em `upload_flags` do `platformio.ini` seja igual à `OTA_PASSWORD` no `main.cpp`
3. Faça o upload normalmente

O OLED exibe o progresso da atualização e o sistema reinicia sozinho ao concluir.

---

## Sincronização de Hora

Ordem de prioridade (invertida em relação à v0):

1. **Relógio interno do ESP32**, uma vez sincronizado via NTP nesta sessão — continua contando mesmo se o WiFi cair depois
2. **RTC DS1307** físico, usado enquanto o NTP ainda não sincronizou nesta sessão
3. **Data fixa de fallback**, usada apenas se nenhuma das duas fontes acima estiver disponível

Múltiplos servidores NTP (`pool.ntp.org`, `time.google.com`, `a.st1.ntp.br`, `time.windows.com`) são tentados em rotação a cada falha.

---

## Simulação Wokwi

O projeto roda no [Wokwi](https://wokwi.com) via extensão do VS Code, usando os arquivos `diagram.json` e `wokwi.toml` já incluídos. Veja `README.md` da estrutura do projeto para o passo a passo de build e simulação com PlatformIO.

---

## Dependências

Gerenciadas via `platformio.ini`:

- `Adafruit GFX Library`
- `Adafruit SSD1306`
- `RTClib`
- `DHT sensor library`
- `Adafruit NeoPixel`
- `Adafruit SHT4x Library` (hardware físico)
- `bblanchon/ArduinoJson` (interpreta a resposta da fila de comandos pendentes da Hostinger)

> O Blynk **não é mais uma dependência** do projeto. Desde a v2, `AsyncTCP`/`ESPAsyncWebServer` também saíram — o ESP32 não sobe mais servidor HTTP próprio.

---

## Configuração Antes de Compilar

```cpp
#define WOKWI_SIMULATION 1     // 0 = hardware físico | 1 = simulação Wokwi

const char* ssid = "SUA_REDE";
const char* password = "SUA_SENHA";

const char* OTA_PASSWORD = "#########"; // TROCAR

const char* HOSTINGER_BASE_URL = "Link_do_site"; // necessário — é o único canal de controle remoto
const char* HOSTINGER_API_KEY  = "API Key";      // necessário
```

E no lado da Hostinger, em `hostinger/config.php`:

```php
const API_KEY = '############';                 // mesma API Key do firmware acima
const WEB_LOGIN_USUARIO = 'admin';               // TROCAR
const WEB_LOGIN_SENHA_HASH = '$2y$10$...';       // TROCAR — gere com:
// php -r "echo password_hash('SUA_SENHA_AQUI', PASSWORD_DEFAULT), PHP_EOL;"
```

Antes do primeiro uso, rode `hostinger/sql/schema.sql` no banco (cria as tabelas de leituras, comandos, fila de comandos pendentes e tentativas de login).

E calibre o sensor de solo com os valores reais do seu hardware:

```cpp
const int SOLO_ADC_SECO  = 4095;  // leitura bruta com o sensor seco
const int SOLO_ADC_UMIDO = 2240;  // leitura bruta com o sensor em água
```

---

## Licença

Uso pessoal/educacional. Adapte livremente para seu ambiente de cultivo.