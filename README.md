# 🌿 Cultivo — Automação para Estufa/Grow Tent (ESP32-S3)

Sistema de automação para cultivo indoor baseado em **ESP32-S3**, com controle de iluminação por fotoperíodo, exaustão/ventilação, trava de acesso, monitoramento ambiental (interno e externo) e umidade do solo, display **OLED SSD1306** local e **dashboard web embutido** — sem depender de nenhum serviço de terceiros para o controle remoto.

> Este documento descreve a **versão atual (v1)** do projeto e resume as mudanças em relação à primeira versão (v0), publicada anteriormente como *GrowController ESP32*.

---

## 📋 O que mudou da v0 para a v1

A v1 é uma reformulação significativa da automação original. A mudança mais visível é a **saída do Blynk como camada de controle remoto**, substituído por um **dashboard web hospedado no próprio ESP32**. Além disso, o projeto passou a ser estruturado como um projeto **PlatformIO + Wokwi**, em vez de um sketch único `.ino`.

| Área | v0 (GrowController) | v1 (Cultivo) |
|---|---|---|
| Estrutura do projeto | Sketch único `.ino` (Arduino IDE) | Projeto **PlatformIO** (`platformio.ini`, `wokwi.toml`, `diagram.json`, `src/main.cpp`) |
| Controle remoto | App **Blynk** (nuvem de terceiros) | **Dashboard web local**, servido pelo próprio ESP32 (HTML/CSS/JS embutidos) |
| Autenticação remota | Token do Blynk | **HTTP Basic Auth** + bloqueio temporário após tentativas de login incorretas |
| Histórico de dados | Não existia | **Log local em CSV** (LittleFS, cache de 7 dias) + exportação e gráfico no dashboard |
| Backup remoto de dados | Não existia | Envio periódico de leituras e comandos para um **backend próprio (Hostinger)** |
| Atualização de firmware | Somente via cabo USB | **OTA** (rede WiFi) com senha própria e ambiente dedicado no `platformio.ini` |
| Acesso por nome | Não existia | **mDNS** (`http://cultivo.local/`) |
| Indicador de status (NeoPixel) | Cor por estado de conexão (WiFi/Blynk) | Cor pela **zona de VPD** (déficit de pressão de vapor) do ar interno |
| Cálculo de VPD | Não existia | Calculado a partir de temperatura/umidade internas e usado no LED e no dashboard |
| Override manual da luz | Não sobrevivia a reinícios | **Persistido na NVRAM do RTC**, com horário absoluto de expiração |
| Mapa de relés | 8 relés fixos (2 automáticos + 6 manuais) | Mapa revisado (ver tabela abaixo), com 1 posição reservada sem uso |
| Robustez do barramento I2C | Sem tratamento especial | **Rotina de recuperação do barramento I2C** antes da inicialização |
| Fonte de hora | RTC como prioridade, NTP como reforço | **NTP como prioridade** (uma vez sincronizado na sessão), RTC como reserva |

Essas mudanças tornam o sistema **independente de serviços externos para operar no dia a dia** (o dashboard funciona 100% na rede local, sem internet), ao mesmo tempo em que mantêm um backend remoto opcional para histórico estendido.

---

## Funcionalidades

- **Controle automático de iluminação** por fotoperíodo, com dois modos de cultivo (Vegetação e Floração)
- **Override manual da luz** (Automático / Ligada / Desligada) que não interrompe o cálculo do fotoperíodo e expira sozinho após 30 minutos — persistido entre reinícios
- **Monitoramento ambiental interno e externo**, com sensores independentes dentro e fora da estufa
- **Sensor de umidade do solo** com calibração por faixa de leitura bruta e média de múltiplas amostras
- **Cálculo de VPD (Déficit de Pressão de Vapor)**, refletido em tempo real na cor do LED NeoPixel e no dashboard
- **8 relés** para luz, exaustores, ventiladores, entradas de ar e trava de acesso
- **Trava eletrônica de acesso** com liberação temporizada, via botão físico ou dashboard
- **Display OLED SSD1306 (128x64)** com tela de descanso animada, tela de informações e menu de configuração navegável por botão físico
- **Dashboard web local** responsivo (mobile e desktop), protegido por autenticação, com controle de relés, modo de cultivo, luz manual, liberação de acesso, gráfico de histórico e exportação de CSV
- **Histórico local em LittleFS**, com rotação automática (cache de 7 dias) e envio periódico opcional para um backend remoto
- **Persistência entre quedas de energia**: relés manuais, modo de cultivo e override de luz (com prazo de expiração) gravados na NVRAM com bateria do RTC DS1307
- **Atualização de firmware por OTA** (rede WiFi), sem necessidade de cabo USB após a primeira gravação
- **Sincronização de hora via NTP** com múltiplos servidores de fallback e reserva no RTC
- **Reconexão automática** de WiFi com estratégia de backoff
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
| Botão de liberação de acesso | 19 | `INPUT_PULLUP` |
| LED NeoPixel | 4 (simulação) / 48 (hardware — LED embutido do ESP32-S3) | Indicador de zona de VPD |

---

## Dashboard Web Local

Substitui completamente a integração com o Blynk. Com o ESP32 conectado à rede WiFi, o painel fica disponível em:

```
http://cultivo.local/          (via mDNS, recomendado)
http://<IP-do-dispositivo>/    (alternativa, exibida no OLED e no Serial durante o boot)
```

O acesso exige **usuário e senha** (HTTP Basic Auth). As credenciais padrão (`admin` / `2009`) **devem ser alteradas** antes de qualquer uso real, assim como a senha de OTA — ambas ficam no topo do `main.cpp`. Após 5 tentativas de login incorretas, o dashboard fica temporariamente bloqueado por 5 minutos.

### O que o painel oferece

- Temperatura, umidade (interna e externa), umidade do solo e VPD em tempo real
- Controle do modo de cultivo (Vegetativo / Floração)
- Controle da luz (Automático / Ligar / Desligar)
- Toggles para os relés manuais (Desumidificador, Ventilador, Entradas de Ar, Saída de Ar)
- Liberação da trava de acesso por 3 segundos
- Gráfico das últimas 24h de temperatura, alimentado pelo histórico local
- Exportação do histórico completo em CSV

### Endpoints HTTP

Todas as rotas abaixo exigem autenticação:

| Rota | Método | Função |
|---|---|---|
| `/` | GET | Página do dashboard |
| `/api/status` | GET | Estado atual do sistema, em JSON |
| `/api/rele?id=&estado=` | GET | Liga/desliga um relé manual |
| `/api/modo_cultivo?valor=` | GET | Define o modo de cultivo (`vegetativo`/`floracao`) |
| `/api/luz_manual?modo=` | GET | Define o override de luz (`auto`/`ligada`/`desligada`) |
| `/api/acesso` | GET | Libera a trava de acesso por 3 segundos |
| `/api/historico/json?limite=` | GET | Últimas N leituras do histórico, em JSON (padrão 144, teto 300) |
| `/api/historico/csv` | GET | Download do histórico completo em CSV |

> ⚠️ HTTP Basic Auth **não é criptografado**. Para acesso fora da rede local, use um túnel/proxy com HTTPS (ex.: Cloudflare Tunnel) na frente — nunca exponha a porta 80 direto para a internet.

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

- **Local (LittleFS):** uma leitura completa é gravada em CSV a cada 10 minutos, com limite de 1008 linhas (~7 dias) — ao ultrapassar o limite, as linhas mais antigas são descartadas automaticamente.
- **Remoto (opcional):** leituras são enviadas a cada 1 minuto para um backend HTTP próprio (configurável via `HOSTINGER_BASE_URL`/`HOSTINGER_API_KEY`), e comandos (mudanças de relé, modo, acesso) são enfileirados e reenviados de forma resiliente em caso de falha de rede.

O backend remoto é totalmente opcional — o dashboard local continua funcionando de forma independente mesmo sem internet.

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
- `ESP32Async/AsyncTCP`
- `ESP32Async/ESPAsyncWebServer`

> O Blynk **não é mais uma dependência** do projeto.

---

## Configuração Antes de Compilar

```cpp
#define WOKWI_SIMULATION 1     // 0 = hardware físico | 1 = simulação Wokwi

const char* ssid = "SUA_REDE";
const char* password = "SUA_SENHA";

const char* WEB_AUTH_USER = "admin";   // TROCAR
const char* WEB_AUTH_PASS = "2009";    // TROCAR

const char* OTA_PASSWORD = "#########"; // TROCAR

const char* HOSTINGER_BASE_URL = "Link_do_site"; // opcional
const char* HOSTINGER_API_KEY  = "API Key";      // opcional
```

E calibre o sensor de solo com os valores reais do seu hardware:

```cpp
const int SOLO_ADC_SECO  = 4095;  // leitura bruta com o sensor seco
const int SOLO_ADC_UMIDO = 2240;  // leitura bruta com o sensor em água
```

---

## Licença

Uso pessoal/educacional. Adapte livremente para seu ambiente de cultivo.
