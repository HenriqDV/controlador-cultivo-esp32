// =============================================================================
//  PROJETO: CULTIVO — Automação de estufa/grow tent (ESP32-S3)
// =============================================================================
//
//  VISÃO GERAL
//  -----------
//  Controla luz (fotoperíodo automático por horário + override manual),
//  exaustores/ventiladores, trava de acesso (porta) e monitora temperatura,
//  umidade do ar e umidade do solo. Interface local via display OLED SSD1306
//  (128x64, com faixa amarela de 16px no topo) + botão físico; interface
//  remota via um dashboard web embutido (servidor HTTP próprio, sem depender
//  de internet — só da rede WiFi local. Ver seção "WEBSERVER LOCAL
//  (dashboard)" mais abaixo no arquivo). O estado dos relés e o modo de
//  cultivo sobrevivem a quedas de energia, gravados na NVRAM com bateria do
//  RTC DS1307.
//
//  BIBLIOTECAS NECESSÁRIAS (Library Manager da Arduino IDE)
//  -----------------------------------------------------------
//    Adafruit GFX Library, Adafruit SSD1306, RTClib, DHT sensor library,
//    Adafruit NeoPixel, e o dashboard web local usa também:
//    ESPAsyncWebServer-esphome (ou "ESP Async WebServer" do ESP32Async) e
//    AsyncTCP. O ESPmDNS (usado pra http://cultivo.local/) já vem embutido
//    no core do ESP32 — não precisa instalar nada à parte pra ele.
//
//  ACESSANDO O DASHBOARD WEB
//  -----------------------------------------------------------
//  Com o ESP32 conectado ao WiFi, abra http://cultivo.local/ num navegador
//  na MESMA rede WiFi (celular, PC, etc.) — não precisa de internet, só de
//  estar na mesma rede local. Isso funciona via mDNS (ver MDNS_HOSTNAME e
//  iniciarMDNS()); Android costuma NÃO resolver nomes ".local" no navegador
//  (funciona nativamente no iPhone) — nesse caso use o IP numérico, que
//  aparece no Serial Monitor e por alguns segundos no próprio OLED, logo
//  após o boot.
//
//  O dashboard exige usuário/senha (HTTP Basic Auth — ver WEB_AUTH_USER/
//  WEB_AUTH_PASS, TROQUE os valores padrão antes de usar) e bloqueia
//  tentativas repetidas erradas (ver autenticado()).
//
//  ACESSO PELA INTERNET (fora da rede local)
//  -----------------------------------------------------------
//  Isso NÃO é feito só no firmware — o ESP32 sozinho não consegue "aparecer"
//  na internet sem alguma ponte de rede. As opções são: (1) abrir a porta
//  80 no roteador (port forward — exige acesso ao painel do roteador), ou
//  (2) rodar um túnel que só faz conexão de SAÍDA a partir da sua rede (não
//  precisa mexer no roteador), como o Cloudflare Tunnel rodando num PC/
//  Raspberry Pi apontando pro IP local do ESP32 — essa opção também dá
//  HTTPS de graça, o que o ESP32 sozinho não tem aqui (HTTP Basic Auth sem
//  HTTPS não é criptografado). NUNCA exponha a porta 80 direto pra internet
//  sem um HTTPS na frente.
//
//  MODOS DE COMPILAÇÃO — flag WOKWI_SIMULATION (logo abaixo)
//  -----------------------------------------------------------
//    1 -> Simulador Wokwi: sensor DHT22 simulado, rede "Wokwi-GUEST" (aberta,
//         com internet real dentro do simulador).
//    0 -> Hardware físico real: sensor SHT40 (I2C), exige trocar ssid/password
//         pela rede WiFi real antes de gravar.
//  Ao alternar entre os dois, revise sempre: WOKWI_SIMULATION, ssid/password.
//
//  MAPA DE PINOS (ESP32-S3)
//  -------------------------
//    I2C (OLED + RTC DS1307 + SHT40, todos no mesmo barramento):
//      SDA = 8   SCL = 9
//    Sensores:
//      DHTPIN = 15 (só simulação)   |   SOIL_PIN = 6 (ADC, sensor de solo)
//      DHT_EXT_PIN = 16 (sensor externo, fora da estufa — DHT11 no hardware
//      físico / DHT22 na simulação Wokwi, sempre presente nos dois modos)
//    Atuadores:
//      BUZZER = 17   |   LED_PIN = 4 na simulação Wokwi / 48 no hardware físico
//      (NeoPixel de status — pino muda sozinho com WOKWI_SIMULATION, pois no
//      hardware real é o LED RGB embutido da placa ESP32-S3, no pino 48)
//    Botões:
//      BOTAO_OLED_PIN = 5 (navegação do menu)  |  BOTAO_TRV_PIN = 19 (abre acesso)
//    Relés (array "reles", índice = função — ver seção RELÉS UTILIZADOS):
//      {42, 41, 40, 39, 38, 37, 36, 35}  — 8 posições (0-7)
//      0=Luz  1=Desumidificador  2=Ventilador  3=NÃO UTILIZADO
//      4=Entrada de Ar 1  5=Entrada de Ar 2  6=Trava (porta)  7=Saida de Ar
//      ATENÇÃO: em módulos ESP32-S3 com PSRAM Octal, GPIO35/36/37 podem estar
//      reservados — ver aviso ao lado da declaração do array mais abaixo.
//
//  PERSISTÊNCIA ENTRE REINÍCIOS
//  ------------------------------
//  O DS1307 tem 56 bytes de RAM com bateria de backup. Usamos os 8 primeiros
//  para gravar o estado dos relés manuais, o modo de cultivo, e o override
//  manual da luz — incluindo até quando ele vale (ver seção "PERSISTÊNCIA
//  (NVRAM DO RTC DS1307)"). O estado LIGADO/DESLIGADO da luz em si nunca é
//  restaurado diretamente (é sempre recalculado: pelo horário quando
//  automática, ou pelo override restaurado quando ainda válido), e a trava
//  de acesso nunca volta ligada, por segurança.
//
//  INTEGRAÇÃO REMOTA — ver tabela dos endpoints HTTP na seção "WEBSERVER
//  LOCAL (dashboard)" mais abaixo.
//
//  CALIBRAÇÃO NECESSÁRIA NO HARDWARE FÍSICO
//  -------------------------------------------
//    - SOLO_ADC_SECO / SOLO_ADC_UMIDO (seção CALIBRAÇÃO DO SENSOR DE SOLO)
//    - RELE_ATIVO_EM_BAIXO (se os relés vierem invertidos ao ligar o sistema)
//    - ssid / password (rede WiFi real)
//
// =============================================================================

// Necessário porque este arquivo agora é compilado como .cpp puro (não mais
// .ino) — sem isso, símbolos básicos do Arduino (String, millis(), HIGH/LOW,
// pinMode etc.) não estariam disponíveis. Quando era .ino, o próprio
// framework injetava isso automaticamente antes de compilar.
#include <Arduino.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <RTClib.h>
#define WOKWI_SIMULATION 1
#if !WOKWI_SIMULATION
#include <Adafruit_SHT4x.h>
#endif
#include <DHT.h>
#include <Adafruit_NeoPixel.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <ESPmDNS.h>
#include <ArduinoOTA.h>
#include <LittleFS.h>
#include <AsyncTCP.h>
#include <ESPAsyncWebServer.h>
#include <time.h>
#include <math.h>

// ---------------- WIFI ----------------
// "Wokwi-GUEST" é a rede aberta com acesso à internet disponível dentro do
// simulador Wokwi — use pra testar o dashboard web e a sincronização NTP.
// Antes de gravar no hardware físico, troque pelo SSID e senha da sua rede
// WiFi real.
const char* ssid = "Wokwi-GUEST";
const char* password = "";

// Nome de acesso via mDNS: com isso funcionando, o dashboard fica acessível
// em http://cultivo.local/ (na mesma rede WiFi), sem precisar saber o IP.
// Funciona bem no Chrome/Safari/celulares em geral; alguns Android mais
// antigos podem precisar de um app tipo "Bonjour"/"NsdManager" pra resolver
// nomes .local, mas a maioria hoje suporta nativamente.
const char* MDNS_HOSTNAME = "cultivo";

// ---------------- SEGURANÇA DO DASHBOARD WEB ----------------
// Usuário/senha de HTTP Basic Auth — protege TODAS as rotas (página e
// endpoints de ação), inclusive a que libera a porta. Isso é OBRIGATÓRIO
// trocar antes de expor esse dispositivo além da sua rede local (ex: atrás
// de um túnel/proxy pra acesso pela internet) — os valores abaixo são só
// um exemplo e NÃO devem ir pra produção assim.
//
// TROQUE ISTO:
const char* WEB_AUTH_USER = "admin";
const char* WEB_AUTH_PASS = "2009";

// Proteção simples contra tentativa repetida de senha (força bruta): depois
// de MAX_FALHAS_AUTH tentativas erradas seguidas, bloqueia QUALQUER
// requisição (mesmo com senha certa) por BLOQUEIO_AUTH_MS — dificulta um
// script tentando milhares de senhas por segundo. Não é páreo pra um
// ataque sério (HTTP Basic Auth não é criptografado — combine com HTTPS via
// um túnel/proxy reverso se for expor na internet), mas já eleva bastante a
// barreira pra alguém só "passando pela rede".
const int MAX_FALHAS_AUTH = 5;
const unsigned long BLOQUEIO_AUTH_MS = 5UL * 60UL * 1000UL; // 5 minutos
int webFalhasAutenticacao = 0;
unsigned long webBloqueadoAte = 0;

// ---------------- OTA (atualização de firmware pela rede) ----------------
// Senha separada da do dashboard web de propósito — são protocolos/
// superfícies de ataque diferentes, então não faz sentido reaproveitar o
// mesmo segredo (se um vazar, o outro continua protegido).
// TROQUE ISTO também:
const char* OTA_PASSWORD = "#########";
bool otaEmAndamento = false; // true durante uma atualização — ver iniciarOTA()

// ---------------- HISTÓRICO (LittleFS) ----------------
// Registro periódico de todas as leituras/estados num arquivo CSV na flash
// interna do ESP32 (LittleFS — não precisa de cartão SD nem de nenhum
// hardware extra). Ver iniciarHistorico()/registrarHistorico() mais abaixo.
const char* HISTORICO_ARQUIVO = "/historico.csv";
const char* HISTORICO_CABECALHO =
  "timestamp,temp_interna,umid_interna,temp_externa,umid_externa,solo_percentual,"
  "luz,modo_cultivo,ventilador,entrada_ar_1,entrada_ar_2,saida_ar,acesso";
const unsigned long INTERVALO_HISTORICO_MS = 10UL * 60UL * 1000UL; // registra localmente a cada 10 min
// Limite de linhas guardadas — 7 dias de histórico local a cada 10 min
// (7 * 24h * 6 amostras/hora = 1008). Quando passa disso, as linhas mais
// antigas são descartadas (ver rotacionarHistoricoSeNecessario()), pra nunca
// lotar a flash nem desgastá-la com um arquivo que só cresce. O histórico
// completo/mais longo mora na Hostinger (ver INTERVALO_ENVIO_LEITURA_HOSTINGER_MS
// abaixo), então o cache local não precisa guardar mais do que isso.
const int HISTORICO_MAX_LINHAS = 1008;
unsigned long ultimoRegistroHistoricoEm = 0;
int historicoTotalLinhas = 0;   // contagem de linhas de dados (sem contar o cabeçalho)
bool historicoDisponivel = false; // LittleFS montado com sucesso

// Envio da leitura atual pra Hostinger — dissociado do registro local
// (que continua a cada 10 min, ver INTERVALO_HISTORICO_MS): a Hostinger
// recebe uma leitura por minuto, pro dashboard web remoto ficar com dados
// bem mais granulares que o cache local do ESP32.
const unsigned long INTERVALO_ENVIO_LEITURA_HOSTINGER_MS = 60UL * 1000UL; // 1 minuto
unsigned long ultimoEnvioLeituraHostingerEm = 0;

const long GMT_OFFSET_SEC = -3 * 3600;
const int DAYLIGHT_OFFSET_SEC = 0;
const unsigned long WIFI_TIMEOUT_MS = 15000;
const unsigned long RETENTATIVA_WIFI_INICIAL_MS = 5000;
const unsigned long RETENTATIVA_WIFI_POSTERIOR_MS = 30000;
const unsigned long JANELA_WIFI_INICIAL_MS = 60000;
const unsigned long NTP_TIMEOUT_MS = 10000;
const unsigned long RETENTATIVA_NTP_MS = 60000;

// ---------------- HOSTINGER (histórico remoto) ----------------
// O dashboard local continua rodando no ESP32. A Hostinger recebe uma cópia
// persistente das leituras e dos comandos, usada pelo dashboard detalhado.
// ATENÇÃO: o botão "Site remoto" do cabeçalho de PAGINA_HTML (dashboard
// local, mais abaixo no arquivo) tem essa mesma URL escrita à mão em HTML
// puro (a página é uma string estática, não pode referenciar esta
// constante em tempo de execução) — se mudar aqui, troque lá também.
const char* HOSTINGER_BASE_URL = "Link_do_site";
const char* HOSTINGER_API_KEY = "API Key";
const unsigned long HOSTINGER_HTTP_TIMEOUT_MS = 7000;
const unsigned long INTERVALO_ENVIO_COMANDOS_HOSTINGER_MS = 1000;
const int HOSTINGER_FILA_COMANDOS_MAX = 20;
String filaComandosHostinger[HOSTINGER_FILA_COMANDOS_MAX];
int filaComandosInicio = 0;
int filaComandosFim = 0;
int filaComandosTotal = 0;
unsigned long ultimoEnvioComandoHostingerEm = 0;

// ---------------- PINOS ----------------
#define SDA_PIN 8
#define SCL_PIN 9

#if WOKWI_SIMULATION
#define DHTPIN 15
#define DHTTYPE DHT22
#endif

// Sensor externo (fora da estufa) — sempre presente, nos dois modos.
// Hardware físico usa DHT11; a simulação Wokwi usa DHT22 (mesmo sensor do
// modelo interno, já que o Wokwi não tem um componente DHT11 dedicado).
#define DHT_EXT_PIN 16
#if WOKWI_SIMULATION
#define DHT_EXT_TYPE DHT22
#else
#define DHT_EXT_TYPE DHT11
#endif

#define SOIL_PIN 6

// ---------------- CALIBRAÇÃO DO SENSOR DE SOLO ----------------
// Sensores capacitivos/resistivos reais fazem o OPOSTO do que a simulação
// costuma sugerir: solo SECO gera tensão/ADC ALTO, solo ÚMIDO gera ADC BAIXO.
// Além disso, na prática o sensor nunca varre 0-4095 inteiro entre seco e
// molhado — o intervalo real é bem mais estreito e varia de sensor pra
// sensor. Calibre assim:
//   1) Sensor completamente seco (no ar) -> anote o valor de "soloBruto"
//      mostrado na tela/serial e coloque em SOLO_ADC_SECO.
//   2) Sensor mergulhado em água (até a linha indicada, sem molhar a
//      eletrônica) -> anote o valor e coloque em SOLO_ADC_UMIDO.
const int SOLO_ADC_SECO = 4095;   // calibrado: leitura bruta com o sensor seco
const int SOLO_ADC_UMIDO = 2240;  // calibrado: leitura bruta com o sensor em água
const int SOLO_AMOSTRAS = 8;      // nº de leituras médias por ciclo (reduz ruído do ADC)

#define BOTAO_OLED_PIN 5
#define BOTAO_TRV_PIN 19

#define BUZZER 17

// LED de status (NeoPixel): no simulador Wokwi é um LED externo ligado no
// pino 4; no hardware físico usa o LED RGB embutido da placa ESP32-S3
// (WS2812 onboard), que fica no pino 48. Alternando WOKWI_SIMULATION,
// o pino correto é escolhido automaticamente.
#if WOKWI_SIMULATION
#define LED_PIN 4
#else
#define LED_PIN 48
#endif

// Nível elétrico que ATIVA o relé no seu módulo físico.
// A maioria dos módulos de relé (com optoacoplador) é ativa em LOW.
// Se ao ligar o sistema os relés vierem invertidos (ligam quando deveriam
// estar desligados), troque para "false" — é a única linha que precisa mudar.
#define RELE_ATIVO_EM_BAIXO true

int reles[8] = {42, 41, 40, 39, 38, 37, 36, 35};
// ATENÇÃO (ESP32-S3): em módulos com PSRAM Octal (ex.: variantes "N16R8"),
// os pinos GPIO35, GPIO36 e GPIO37 são usados internamente para a PSRAM e
// NÃO podem ser usados como GPIO comum. Nesta pinagem os três estão
// funcionais (índices 5, 6 e 7 — Entrada de Ar 2, Trava e Saida de Ar) —
// remapeie-os para outros GPIOs livres da sua placa se for o seu caso.

// ---------------- RELÉS UTILIZADOS ----------------
// Índices dentro do array "reles[8]" (definido acima) e seus papéis fixos.
// O índice 0 é especial (luz, controlada por lógica própria); o índice 3
// não tem nada conectado no hardware físico (pino ainda configurado como
// saída e mantido desligado no boot, mas nunca aparece no menu OLED nem no
// dashboard web); os demais são "manuais" ou a trava de acesso (pulso).
const int TOTAL_RELES = 8;           // tamanho de reles[]/estadoReles[] (índices 0-7)
const int RELE_LUZ = 0;              // controlado por controlarLuz() (fotoperíodo + override manual)
const int RELE_DESUMIDIFICADOR = 1;  // "Desumidificador"
const int RELE_VENTILADOR = 2;       // "Ventilador"
const int RELE_VAZIO_2 = 3;          // NÃO UTILIZADO — nada conectado no hardware físico
const int RELE_ENTRADA_AR_1 = 4;     // "Entrada de Ar 1"
const int RELE_ENTRADA_AR_2 = 5;     // "Entrada de Ar 2"
const int RELE_ACESSO = 6;           // trava/fechadura da porta, pulso temporário via liberarAcessoPor()
const int RELE_SAIDA_AR = 7;         // "Saida de Ar" (exaustor de saída)
const int PRIMEIRO_RELE_MANUAL = 1;  // menor índice de relé "manual" (usado só como limite de segurança)
const int CONTROLE_MODO_CULTIVO = -1; // sentinela: item de menu "Modo Cultivo" (não é relé de verdade)
const int CONTROLE_LUZ_MANUAL = -2;   // sentinela: item de menu "Luz Manual" (override, não é relé de verdade)

// Lista dos relés "manuais" que de fato aparecem no menu OLED e no dashboard
// web, na ordem de navegação. O índice 3 (RELE_VAZIO_2) fica de fora de
// propósito — é isso que o "pula" na prática em toda a interface.
const int RELES_MANUAIS[] = {RELE_DESUMIDIFICADOR, RELE_VENTILADOR, RELE_ENTRADA_AR_1, RELE_ENTRADA_AR_2, RELE_SAIDA_AR};
const int TOTAL_RELES_MANUAIS = sizeof(RELES_MANUAIS) / sizeof(RELES_MANUAIS[0]);

// ---------------- DISPLAY OLED ----------------
#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64
#define OLED_RESET -1
#define SCREEN_ADDRESS 0x3C
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, OLED_RESET);

// O painel físico tem uma faixa amarela de 16px no topo e o restante azul.
// Esse limite garante que nada fique cortado na costura entre as cores.
// (O antigo rodapé padrão, que reservava uma faixa extra embaixo, não
// existe mais em nenhuma tela do design atual — foi removido.)
const int OLED_ALTURA_CABECALHO = 16;                       // faixa amarela (linhas 0-15)
const int OLED_CONTEUDO_TOPO = OLED_ALTURA_CABECALHO + 2;    // margem de 2px após a costura

// ---------------- OBJETOS ----------------
RTC_DS1307 rtc;
#if WOKWI_SIMULATION
DHT dht(DHTPIN, DHTTYPE);
#else
Adafruit_SHT4x sht4 = Adafruit_SHT4x();
#endif
// Sensor externo (fora da estufa) — sempre existe, independente do modo de
// compilação (só o tipo de sensor muda, via DHT_EXT_TYPE acima).
DHT dhtExterno(DHT_EXT_PIN, DHT_EXT_TYPE);
Adafruit_NeoPixel pixel(1, LED_PIN, NEO_GRB + NEO_KHZ800);

// ---------------- WEBSERVER LOCAL (dashboard) ----------------
// Servidor HTTP embutido — dá pra abrir http://<ip-do-esp32>/ em qualquer
// navegador na mesma rede WiFi e ver/controlar tudo. É a ÚNICA interface
// remota do sistema (não depende de nenhum serviço de nuvem de terceiros).
// Não-bloqueante (ESPAsyncWebServer roda por trás via AsyncTCP), então não
// precisa de nenhuma chamada tipo "server.handleClient()" dentro do loop().
// Rotas registradas em configurarServidorWeb() (chamada uma vez no setup()).
AsyncWebServer server(80);

// ---------------- LED (indicador de VPD) ----------------
// O NeoPixel deixou de mostrar status de conexão (WiFi/porta/confirmação) e
// passou a mostrar o ZONEAMENTO DO VPD (Déficit de Pressão de Vapor) do ar
// dentro da estufa, calculado a partir da temperatura e umidade internas —
// ver calcularVPD() e corParaVPD() mais abaixo. Mantém um leve efeito de
// "respiração" (brilho pulsando) só pra ficar vivo visualmente; a COR é que
// muda conforme a zona do VPD.
unsigned long tempoLED = 0; // controla a taxa de atualização do efeito (a cada 20ms)
int brilho = 30;
int direcao = 5; // sentido do "respirar" (+ = aumentando, - = diminuindo)
const int LED_BRILHO_MIN = 30;  // nunca apaga de vez — sempre dá pra ver a cor
const int LED_BRILHO_MAX = 150;

// ---------------- CULTIVO ----------------
enum ModoCultivo { VEGETACAO, FLORACAO };
ModoCultivo modoAtual = VEGETACAO;

// Controle manual da luz: sobrepõe o resultado do fotoperíodo por um tempo
// limitado, sem parar o cálculo automático do timer (que continua rodando
// em segundo plano e retoma sozinho quando o override expira).
enum ModoControleLuz { LUZ_AUTOMATICA, LUZ_MANUAL_LIGADA, LUZ_MANUAL_DESLIGADA };
ModoControleLuz modoControleLuz = LUZ_AUTOMATICA;
unsigned long overrideLuzAte = 0;
const unsigned long DURACAO_OVERRIDE_LUZ_MS = 30UL * 60UL * 1000UL; // 30 minutos

// ---------------- OLED / BOTAO ----------------
// MÁQUINA DE ESTADOS DA INTERFACE LOCAL
// ---------------------------------------
// TelaOLED  = "qual conteúdo" mostrar dentro da área navegável (cabeçalho +
//             rodapé + corpo). A tela de descanso NÃO faz parte deste enum:
//             ela é um EstadoOLED à parte (ver abaixo) e ocupa a tela toda.
// EstadoOLED = "modo de exibição" atual do display, ortogonal ao TelaOLED.
//
// Navegação por um único botão físico (BOTAO_OLED_PIN):
//   clique curto -> avança para a próxima tela / avança item selecionado
//   clique longo  -> confirma entrada no menu interativo / alterna item
enum TelaOLED {
  TELA_PRINCIPAL,      // temperatura/umidade interna e externa
  TELA_CONFIGURACAO,    // menu com abas "Dispositivos" (relés) e "Status"
  TOTAL_TELAS_OLED
};

enum EstadoOLED {
  OLED_DESCANSO,   // tela de descanso: sol ou lua em tela cheia (estado ocioso padrão)
  OLED_MENU,       // aguardando confirmação (pressão longa) para abrir a tela interativa
  OLED_EXIBINDO,   // exibindo uma das telas navegáveis (TelaOLED acima)
  OLED_MENSAGEM    // mensagem temporária centralizada (ex: "Modo: Floracao")
};

EstadoOLED estadoOLED = OLED_DESCANSO;
TelaOLED telaSelecionada = TELA_PRINCIPAL;
bool mensagemTemporariaAtiva = false;
unsigned long mensagemAte = 0;      // timestamp em que a mensagem temporária deve sumir
String linha1Mensagem = "";
bool atualizarOLED = true;          // "dirty flag": true = precisa redesenhar no próximo loop()
unsigned long oledVoltaDescansoEm = 0; // timestamp em que volta sozinho para OLED_DESCANSO (0 = desativado)
unsigned long ultimoRefreshPeriodicoOLED = 0; // ver INTERVALO_REFRESH_PERIODICO_MS, no loop()

// --- Debounce e detecção de clique curto/longo do botão físico ---
bool ultimoEstadoBotao = HIGH;
bool estadoEstavelBotaoOLED = HIGH;
bool botaoPressionado = false;
unsigned long botaoMudouEm = 0;
unsigned long botaoPressionadoEm = 0;
const unsigned long DEBOUNCE_BOTAO_MS = 50;    // ignora oscilações do contato mecânico abaixo disso
const unsigned long PRESSAO_LONGA_MS = 1200;   // acima disso, conta como "clique longo"
const unsigned long TEMPO_EXIBICAO_OLED_MS = 15000; // tempo de inatividade até voltar pra tela de descanso
const unsigned long INTERVALO_REFRESH_PERIODICO_MS = 1000; // força redesenho p/ manter relógio/contadores atuais
const unsigned long INTERVALO_ANIMACAO_DESCANSO_MS = 60;   // ~16 fps para a animação sol/lua
unsigned long ultimoFrameAnimacaoDescanso = 0;
int releSelecionadoControle = CONTROLE_MODO_CULTIVO; // item atualmente destacado no menu de configuração

// ---------------- DHT / SHT40 (sensor de temperatura e umidade do ar) ----------------
// Qual sensor físico é lido depende de WOKWI_SIMULATION (ver topo do arquivo).
// tempAtual/umidAtual começam como NAN (valor "desconhecido") até a primeira
// leitura válida, e a tela mostra "--" enquanto isso.
float tempAtual = NAN;
float umidAtual = NAN;
unsigned long ultimoDHT = 0;
const unsigned long INTERVALO_DHT_MS = 2000; // intervalo mínimo recomendado p/ DHT22; SHT40 aceita mais rápido
bool pulsoSensorAtivo = false;      // indicador visual de "sensor vivo" (bolinha ao lado de "Interna", na TELA_PRINCIPAL)
unsigned long pulsoSensorAte = 0;
const unsigned long DURACAO_PULSO_SENSOR_MS = 300; // por quanto tempo o pulso fica visível a cada leitura

// ---------------- DHT EXTERNO (temperatura/umidade fora da estufa) ----------------
// Sensor independente, no GPIO16 (DHT_EXT_PIN). Mesma lógica de "só aceita
// se mudou o suficiente" do sensor interno, e tem seu próprio indicador de
// "sensor vivo" (bolinha ao lado de "Externa", na TELA_PRINCIPAL).
float tempExterna = NAN;
float umidExterna = NAN;
unsigned long ultimoDHTExterno = 0;
const unsigned long INTERVALO_DHT_EXTERNO_MS = 2000; // seguro tanto para DHT11 (>=1s) quanto DHT22 (>=2s)
bool pulsoSensorExternoAtivo = false;
unsigned long pulsoSensorExternoAte = 0;

// ---------------- SOLO (sensor de umidade do solo) ----------------
// Ver constantes de calibração (SOLO_ADC_SECO/SOLO_ADC_UMIDO) lá em cima,
// perto da definição de SOIL_PIN.
int soloBruto = 0;       // última leitura crua do ADC (média de SOLO_AMOSTRAS), útil para recalibrar
int soloPercentual = 0;  // 0 = seco, 100 = encharcado, já calibrado
unsigned long ultimoSolo = 0;
const unsigned long INTERVALO_SOLO_MS = 1000;
bool pulsoSensorSoloAtivo = false;  // indicador visual de "sensor vivo" (bolinha ao lado de "Solo", na TELA_PRINCIPAL)
unsigned long pulsoSensorSoloAte = 0;

// ---------------- BUZZER ----------------
// Beep não-bloqueante: beep() liga o buzzer e agenda o desligamento; quem
// efetivamente desliga é atualizarBuzzer(), chamado a cada loop().
bool buzzerLigado = false;
unsigned long buzzerDesligaEm = 0;

// ---------------- ACESSO (trava/fechadura) ----------------
// Mesmo padrão do buzzer: liberarAcessoPor() liga o relé e agenda o
// desligamento; atualizarAcesso() fecha de novo quando o tempo expira.
bool acessoAtivo = false;
unsigned long acessoDesligaEm = 0;

// ---------------- STATUS (conectividade e sincronismo) ----------------
bool rtcDisponivel = false;             // RTC DS1307 detectado no I2C
#if !WOKWI_SIMULATION
bool sht40Disponivel = false;           // SHT40 detectado no I2C (só existe fora do modo simulação)
#endif
bool wifiConectado = false;
bool horaSincronizada = false;          // já conseguiu sincronizar via NTP alguma vez desde o boot
unsigned long ultimaTentativaWiFi = 0;
unsigned long wifiDesconectadoDesde = 0;
unsigned long ultimaTentativaNTP = 0;
unsigned long ultimaInteracaoMenuEm = 0;      // marca a última interação local com o menu do OLED

// ---------------- ESTADO DOS RELÉS ----------------
// Espelho em RAM do estado real de cada relé (índices 0-7, ver seção RELÉS
// UTILIZADOS — o índice 3 existe no array mas nunca é acionado, pois não há
// hardware físico conectado a ele). É a "fonte da verdade" consultada por
// toda a interface (OLED, dashboard web); a escrita física no pino acontece
// só em acionarRele().
bool estadoReles[TOTAL_RELES] = {false, false, false, false, false, false, false, false};

// ---------------- PROTOTIPOS ----------------
// Declarações antecipadas — necessárias porque várias funções referenciam
// outras que só são definidas mais adiante no arquivo.
void beep(unsigned long tempo = 200);
void avancarReleControle();
void alternarReleSelecionado();
void alternarModoCultivo();
void alternarModoControleLuz();
bool telaInterativa(TelaOLED tela);
TelaOLED proximaTelaOLED(TelaOLED telaAtualBase);
void abrirTelaRapidaOLED();
void avancarNavegacaoOLED();
const char* nomeReleControle(int indiceRele);
bool conectarWiFi();
void atualizarConexaoWiFi();
String obterTimestampAtual();
void registrarEventoModo(const char* estadoModo, const char* origem = "sistema");
void registrarEventoAcesso(const char* origem, const char* resultado);
void registrarEventoRele(int indiceRele, bool ligado, const char* origem);
bool enviarJsonHostinger(const char* endpoint, const String& payload);
void enviarLeituraHostinger();
void atualizarEnvioLeituraHostinger();
void enfileirarComandoHostinger(const char* tipo, const char* alvo, const String& valor, const char* origem);
void atualizarFilaComandosHostinger();
String valorJsonFloat(float valor, int casas);
String escaparJson(const String& valor);
float calcularVPD(float tempC, float umidPercent);
uint32_t corParaVPD(float vpd);
void atualizarLEDVPD();
void aplicarComandoRele(int indiceRele, int valor);
void acionarRele(int numero, bool ligado);
void definirModoCultivoRemoto(ModoCultivo novoModo);
void definirModoControleLuzRemoto(ModoControleLuz novoModo);
void liberarAcessoRemoto();
DateTime obterAgora();
String tempoRestante(const DateTime& now);
String tempoRestanteOverrideLuz();
void salvarEstadoNaMemoria();
void restaurarEstadoDaMemoria();
void iniciarMDNS();
void iniciarOTA();
void mensagemInicializacao(const String& linha1, const String& linha2 = "");
void iniciarHistorico();
void registrarHistorico();
void rotacionarHistoricoSeNecessario();
void atualizarHistorico();
String construirHistoricoJson(int limite);

// ---------------- LED (indicador de VPD) ----------------
// Calcula o VPD (Déficit de Pressão de Vapor, em kPa) a partir da
// temperatura (°C) e umidade relativa (%) — fórmula padrão (Tetens/Magnus):
//   SVP = 0.6108 * e^(17.27*T / (T+237.3))   [pressão de saturação, kPa]
//   VPD = SVP * (1 - UR/100)
// Quanto maior o VPD, mais "sedento" o ar está (puxa mais água da planta);
// quanto menor, mais próximo da saturação (risco de fungo/mofo).
float calcularVPD(float tempC, float umidPercent) {
  float svp = 0.6108 * exp((17.27 * tempC) / (tempC + 237.3));
  return svp * (1.0 - umidPercent / 100.0);
}

// Traduz o VPD pra uma cor, seguindo as zonas da tabela de referência
// "Room VPD" (cultivo indoor). Paleta definida pelo usuário — as duas zonas
// de "perigo" (muito úmido/muito seco) reaproveitam a mesma cor (rosa
// fraco), igual a tabela de referência original fazia:
//   < 0.4 kPa        -> ar úmido demais (risco de fungo/mofo)     -> rosa fraco  #AB0025
//   0.4 - 0.8 kPa     -> Propagação / Início de Vegetativo         -> rosa forte  #FE69FF
//   0.8 - 1.2 kPa     -> Fim de Vegetativo / Início de Floração    -> verde       #0EAD31
//   1.2 - 1.6 kPa     -> Floração média/tardia                     -> laranja     #DBCB0F
//   > 1.6 kPa        -> ar seco demais (estresse hídrico)         -> rosa fraco  #AB0025
uint32_t corParaVPD(float vpd) {
  if (vpd < 0.4) {
    return pixel.Color(171, 0, 37);     // rosa fraco #AB0025 — muito úmido
  } else if (vpd < 0.8) {
    return pixel.Color(254, 105, 255);  // rosa forte #FE69FF — propagação/veg inicial
  } else if (vpd < 1.2) {
    return pixel.Color(14, 173, 49);    // verde #0EAD31 — fim de veg / início de flora
  } else if (vpd < 1.6) {
    return pixel.Color(219, 203, 15);   // laranja #DBCB0F — flora média/tardia
  } else {
    return pixel.Color(171, 0, 37);     // rosa fraco #AB0025 — muito seco
  }
}

// Roda a cada loop(); internamente se auto-limita a ~50Hz (20ms) para não
// gastar tempo de CPU redesenhando o NeoPixel sem necessidade. A cor vem da
// zona de VPD atual (sensor interno); o brilho só "respira" suavemente pra
// dar sinal de vida, sem nunca apagar de vez (LED_BRILHO_MIN).
void atualizarLEDVPD() {
  if (millis() - tempoLED < 20) return;
  tempoLED = millis();

  brilho += direcao;
  if (brilho >= LED_BRILHO_MAX) {
    brilho = LED_BRILHO_MAX;
    direcao = -5;
  }
  if (brilho <= LED_BRILHO_MIN) {
    brilho = LED_BRILHO_MIN;
    direcao = 5;
  }

  if (isnan(tempAtual) || isnan(umidAtual)) {
    // Ainda sem leitura válida do sensor interno — branco fraco, "aguardando".
    uint8_t nivel = brilho / 3;
    pixel.setPixelColor(0, pixel.Color(nivel, nivel, nivel));
  } else {
    uint32_t corBase = corParaVPD(calcularVPD(tempAtual, umidAtual));
    uint8_t rBase = (corBase >> 16) & 0xFF;
    uint8_t gBase = (corBase >> 8) & 0xFF;
    uint8_t bBase = corBase & 0xFF;
    uint8_t r = (uint16_t)rBase * brilho / LED_BRILHO_MAX;
    uint8_t g = (uint16_t)gBase * brilho / LED_BRILHO_MAX;
    uint8_t b = (uint16_t)bBase * brilho / LED_BRILHO_MAX;
    pixel.setPixelColor(0, pixel.Color(r, g, b));
  }

  pixel.show();
}

// Aplica um comando de relé manual vindo do dashboard web (ver
// configurarServidorWeb()). Só age se o valor for diferente do atual (evita
// gravar na NVRAM à toa em cada chamada repetida).
void aplicarComandoRele(int indiceRele, int valor) {
  bool novoEstado = valor == 1;
  if (estadoReles[indiceRele] == novoEstado) return;

  acionarRele(indiceRele, novoEstado);
  registrarEventoRele(indiceRele, novoEstado, "web");
  salvarEstadoNaMemoria();
  atualizarOLED = true;
}

// =========================================================================
//                              OLED - SSD1306
// =========================================================================

// Exibe uma mensagem centralizada por "duracaoMs" (ex: "Modo: Floracao").
// Interrompe qualquer tela/menu que estivesse aberto; ao expirar, o sistema
// volta direto pra tela de descanso (ver atualizarTimeoutOLED()).
void mostrarMensagemTemporaria(const String& linha1, unsigned long duracaoMs) {
  estadoOLED = OLED_MENSAGEM;
  linha1Mensagem = linha1;
  mensagemTemporariaAtiva = true;
  mensagemAte = millis() + duracaoMs;
  oledVoltaDescansoEm = mensagemAte;
  atualizarOLED = true;
}

// Fecha a mensagem temporária quando o tempo expira (chamada a cada loop()).
void atualizarMensagemTemporaria() {
  if (mensagemTemporariaAtiva && millis() >= mensagemAte) {
    mensagemTemporariaAtiva = false;
    atualizarOLED = true;
  }
}

// Só a TELA_CONFIGURACAO exige confirmação (pressão longa) antes de abrir,
// pois é a única onde o clique curto tem outro significado (navegar itens).
bool telaInterativa(TelaOLED tela) {
  return tela == TELA_CONFIGURACAO;
}

// Avança para a próxima tela do ciclo de navegação, voltando para a primeira
// (TELA_PRINCIPAL) depois da última.
TelaOLED proximaTelaOLED(TelaOLED telaAtualBase) {
  int proximaTela = static_cast<int>(telaAtualBase) + 1;
  if (proximaTela >= TOTAL_TELAS_OLED) {
    proximaTela = 0;
  }
  return static_cast<TelaOLED>(proximaTela);
}

// Sai da tela de descanso direto para a TELA_PRINCIPAL (é o que acontece no
// primeiro clique curto, quando o display estava "dormindo").
void abrirTelaRapidaOLED() {
  telaSelecionada = TELA_PRINCIPAL;
  estadoOLED = OLED_EXIBINDO;
  oledVoltaDescansoEm = millis() + TEMPO_EXIBICAO_OLED_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarOLED = true;
}

// Confirma a entrada na tela atualmente destacada no OLED_MENU (pressão
// longa). Sempre abre a lista de Dispositivos direto no primeiro item
// ("Modo Cultivo") — a seta já nasce ali, sem etapa intermediária.
void selecionarTelaOLED() {
  estadoOLED = OLED_EXIBINDO;
  if (telaSelecionada == TELA_CONFIGURACAO) {
    releSelecionadoControle = CONTROLE_MODO_CULTIVO;
  }
  oledVoltaDescansoEm = millis() + TEMPO_EXIBICAO_OLED_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarOLED = true;
}

// Chamada a cada loop(): se o tempo de exibição expirar sem interação,
// volta sozinho para a tela de descanso (economiza atenção do usuário e
// evita ficar preso numa tela de configuração esquecida).
void atualizarTimeoutOLED() {
  if (estadoOLED != OLED_DESCANSO && oledVoltaDescansoEm > 0 && millis() >= oledVoltaDescansoEm) {
    estadoOLED = OLED_DESCANSO;
    mensagemTemporariaAtiva = false;
    oledVoltaDescansoEm = 0;
    atualizarOLED = true;
  }
}

// Avança pro próximo item do ciclo de telas. Se o destino for uma tela
// "interativa" (só TELA_CONFIGURACAO), para em OLED_MENU pedindo confirmação
// por pressão longa, em vez de entrar direto.
void avancarNavegacaoOLED() {
  telaSelecionada = proximaTelaOLED(telaSelecionada);
  estadoOLED = telaInterativa(telaSelecionada) ? OLED_MENU : OLED_EXIBINDO;
  oledVoltaDescansoEm = millis() + TEMPO_EXIBICAO_OLED_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarOLED = true;
}

// Roteia o que um "clique curto" deve fazer, dependendo do estado atual:
//   - Tela de descanso     -> acorda o display (abrirTelaRapidaOLED)
//   - Informações          -> avança para Configurações (confirmação)
//   - Configurações (menu) -> avança de volta para Informações (ciclo)
//   - Lista de Dispositivos (já aberta) -> avança item selecionado no menu
// Ciclo completo por clique curto: Descanso -> Informações -> Configurações
// -> Informações -> Configurações -> ... Pressão longa em Configurações é
// que abre a lista de dispositivos (ver atualizarBotaoOLED).
void tratarCliqueCurtoBotaoOLED() {
  if (estadoOLED == OLED_DESCANSO) {
    abrirTelaRapidaOLED();
    return;
  }

  if (estadoOLED == OLED_EXIBINDO && telaSelecionada == TELA_CONFIGURACAO) {
    avancarReleControle();
    return;
  }

  if (estadoOLED == OLED_EXIBINDO || estadoOLED == OLED_MENU) {
    avancarNavegacaoOLED();
  }
}

// Lê o botão físico com debounce e diferencia clique curto de clique longo.
// Chamada a cada loop(). Fluxo: debounce -> detecta borda estável -> ao
// soltar (borda de subida), mede quanto tempo ficou pressionado e decide
// qual ação disparar, dependendo também do estado atual do OLED.
void atualizarBotaoOLED() {
  bool leitura = digitalRead(BOTAO_OLED_PIN);

  if (leitura != ultimoEstadoBotao) {
    botaoMudouEm = millis();
    ultimoEstadoBotao = leitura;
  }

  if (millis() - botaoMudouEm < DEBOUNCE_BOTAO_MS) {
    return;
  }

  if (leitura == estadoEstavelBotaoOLED) {
    return;
  }

  estadoEstavelBotaoOLED = leitura;
  ultimaInteracaoMenuEm = millis();

  if (estadoEstavelBotaoOLED == LOW) {
    botaoPressionado = true;
    botaoPressionadoEm = millis();
    return;
  }

  if (botaoPressionado) {
    unsigned long tempoPressionado = millis() - botaoPressionadoEm;
    botaoPressionado = false;

    if (tempoPressionado >= PRESSAO_LONGA_MS && estadoOLED == OLED_MENU) {
      beep(80);
      selecionarTelaOLED();
    } else if (tempoPressionado >= PRESSAO_LONGA_MS &&
               estadoOLED == OLED_EXIBINDO &&
               telaSelecionada == TELA_CONFIGURACAO) {
      beep(80);
      alternarReleSelecionado();
    } else if (tempoPressionado >= DEBOUNCE_BOTAO_MS) {
      beep(80);
      tratarCliqueCurtoBotaoOLED();
    }
  }
}

// ---------- desenho: primitivas auxiliares ----------

// Ícone de WiFi no cabeçalho: um "leque" de arcos crescendo a partir de um
// pontinho — o símbolo clássico de sinal WiFi. Só desenha alguma coisa
// quando conectado; sem WiFi, fica em branco (nada é desenhado ali) — de
// propósito, pra ficar óbvio à primeira vista que não há conexão.
//
// Pixels colocados um a um (em vez de drawCircleHelper) porque o algoritmo
// de círculo do Adafruit_GFX não desenha o pixel bem no topo de cada arco
// (deixava um furo no meio, os dois arcos pareciam "abertos") e as pontas
// laterais ficavam compridas demais — aqui cada arco já nasce fechado no
// centro, com 1px cortado de cada lado do arco interno e 2px de cada lado
// do arco externo.
void desenharIconeConexao(int x, int y) {
  if (!wifiConectado) return;

  int cx = x + 4; // centro horizontal do ícone
  int cy = y + 7; // "base" do leque (pontinho fica aqui)

  // Pontinho central
  display.drawPixel(cx, cy, SSD1306_WHITE);

  // Arco interno — fechado no topo (cx,cy-3), sem as pontas laterais
  display.drawPixel(cx - 2, cy - 2, SSD1306_WHITE);
  display.drawPixel(cx - 1, cy - 3, SSD1306_WHITE);
  display.drawPixel(cx,     cy - 3, SSD1306_WHITE);
  display.drawPixel(cx + 1, cy - 3, SSD1306_WHITE);
  display.drawPixel(cx + 2, cy - 2, SSD1306_WHITE);

  // Arco externo — fechado no topo (cx,cy-6), sem as 2 pontas laterais
  display.drawPixel(cx - 5, cy - 3, SSD1306_WHITE);
  display.drawPixel(cx - 4, cy - 4, SSD1306_WHITE);
  display.drawPixel(cx - 3, cy - 5, SSD1306_WHITE);
  display.drawPixel(cx - 2, cy - 6, SSD1306_WHITE);
  display.drawPixel(cx - 1, cy - 6, SSD1306_WHITE);
  display.drawPixel(cx,     cy - 6, SSD1306_WHITE);
  display.drawPixel(cx + 1, cy - 6, SSD1306_WHITE);
  display.drawPixel(cx + 2, cy - 6, SSD1306_WHITE);
  display.drawPixel(cx + 3, cy - 5, SSD1306_WHITE);
  display.drawPixel(cx + 4, cy - 4, SSD1306_WHITE);
  display.drawPixel(cx + 5, cy - 3, SSD1306_WHITE);
}

// Cabeçalho comum a TODAS as telas, inclusive a de descanso (que passou a
// usá-lo também — ver desenharTelaDescanso). Ocupa toda a faixa amarela do
// painel físico (16px) e termina com uma linha separadora exatamente na
// costura entre as duas cores.
// Cabeçalho comum a TODAS as telas, inclusive a de descanso — o texto do
// título é parametrizado porque cada tela pode ter o seu próprio (ex:
// "Cultivo" na tela de descanso, "Informacoes" na tela principal). Ocupa
// toda a faixa amarela do painel físico (16px) e termina com uma linha
// separadora exatamente na costura entre as duas cores.
void desenharCabecalho(const DateTime& now, const char* titulo) {
  display.setTextSize(1);
  display.setTextColor(SSD1306_WHITE);

  // Texto e ícone centralizados verticalmente dentro da faixa amarela (0-15)
  const int y = 4;

  display.setCursor(0, y);
  display.print(titulo);

  char buf[12];
  snprintf(buf, sizeof(buf), "%02d:%02d", now.hour(), now.minute());
  display.setCursor(SCREEN_WIDTH - 57, y);
  display.print(buf);

  // Modo de cultivo, abreviado numa letra só (V=Vegetativo, F=Floração) —
  // o nome por extenso saiu do cabeçalho e da tela de descanso (que agora
  // mostra o VPD no lugar); pra ver o modo por extenso, use o menu de
  // configuração (item "Modo Cultivo").
  display.setCursor(SCREEN_WIDTH - 24, y);
  display.print(modoAtual == VEGETACAO ? "V" : "F");

  // O indicador de "sensor vivo" saiu daqui — agora fica em duas bolinhas
  // dedicadas (uma por sensor) na TELA_PRINCIPAL, ao lado de "Interna" e
  // "Externa" — ver desenharConteudoPrincipal().
  desenharIconeConexao(SCREEN_WIDTH - 13, y);

  // Separador exatamente na costura entre a faixa amarela e a azul
  display.drawFastHLine(0, OLED_ALTURA_CABECALHO - 1, SCREEN_WIDTH, SSD1306_WHITE);
}

// Formata a temperatura no padrão brasileiro (vírgula em vez de ponto).
// Retorna "--,-" quando o valor ainda é desconhecido (NAN).
String formatarTemperatura(float valor) {
  if (isnan(valor)) return "--,-";

  char buffer[10];
  dtostrf(valor, 4, 1, buffer);
  String texto = String(buffer);
  texto.trim();
  texto.replace(".", ",");
  return texto;
}

// Formata o VPD no padrão brasileiro (vírgula, 2 casas). Retorna "--"
// quando o valor é NAN (ex: sensor interno ainda sem leitura válida — ver
// calcularVPD(), que já propaga NAN nesse caso).
String formatarVPD(float valor) {
  if (isnan(valor)) return "--";

  char buffer[10];
  dtostrf(valor, 4, 2, buffer);
  String texto = String(buffer);
  texto.trim();
  texto.replace(".", ",");
  return texto;
}

// Classificação simples exibida no rodapé — ver limiar em soloPercentual.
const char* estadoSolo() {
  if (soloPercentual >= 50) return "Umido";
  return "Seco";
}

// ---------- tela principal: temperatura / umidade (interna + externa) ----------
// Duas colunas simétricas lado a lado — Interna (esquerda) e Externa
// (direita, sensor do GPIO16) — cada uma com seu indicador de "sensor vivo"
// (bolinha) ao lado do nome, e uma linha final "Solo:" (sem divisória, ocupa
// a largura toda) com o status do solo e o indicador de "sensor vivo" do
// sensor de umidade do solo à esquerda.
//
// NOTA: os rótulos usam "T:"/"U:" (não "Temp:"/"Umid:") para caber dentro
// da largura de cada coluna (~62px) sem estourar a linha divisória.
void desenharConteudoPrincipal() {
  const int xDivisor = 64;              // linha vertical central
  const int xColunaExterna = xDivisor + 4;
  // Linhas de 10px cada (label, temp, umidade) a partir de OLED_CONTEUDO_TOPO;
  // o separador fica 1px depois do fim da linha de umidade, sem sobrepor texto.
  const int yDivisorSala = OLED_CONTEUDO_TOPO + 29;

  display.setTextSize(1);

  // --- Linha 1: nome de cada sensor + indicador de "sensor vivo" ---
  int yLabel = OLED_CONTEUDO_TOPO;
  int dotInternaX = 3;
  int dotInternaY = yLabel + 3;
  if (pulsoSensorAtivo) {
    display.fillCircle(dotInternaX, dotInternaY, 3, SSD1306_WHITE);
  } else {
    display.drawCircle(dotInternaX, dotInternaY, 3, SSD1306_WHITE);
  }
  display.setCursor(dotInternaX + 6, yLabel);
  display.print("Interna");

  int dotExternaX = xColunaExterna + 3;
  int dotExternaY = yLabel + 3;
  if (pulsoSensorExternoAtivo) {
    display.fillCircle(dotExternaX, dotExternaY, 3, SSD1306_WHITE);
  } else {
    display.drawCircle(dotExternaX, dotExternaY, 3, SSD1306_WHITE);
  }
  display.setCursor(dotExternaX + 6, yLabel);
  display.print("Externa");

  // --- Linha 2: temperatura ---
  int yTemp = yLabel + 10;
  display.setCursor(0, yTemp);
  display.print("T: ");
  display.print(formatarTemperatura(tempAtual));
  display.write(248); // símbolo de grau
  display.print("C");

  display.setCursor(xColunaExterna, yTemp);
  display.print("T: ");
  display.print(formatarTemperatura(tempExterna));
  display.write(248);
  display.print("C");

  // --- Linha 3: umidade ---
  int yUmid = yTemp + 10;
  display.setCursor(0, yUmid);
  display.print("U: ");
  if (isnan(umidAtual)) {
    display.print("--");
  } else {
    display.print(umidAtual, 0);
  }
  display.print("%");

  display.setCursor(xColunaExterna, yUmid);
  display.print("U: ");
  if (isnan(umidExterna)) {
    display.print("--");
  } else {
    display.print(umidExterna, 0);
  }
  display.print("%");

  // --- Separador horizontal antes da linha final ---
  display.drawFastHLine(0, yDivisorSala, SCREEN_WIDTH, SSD1306_WHITE);

  // --- Última linha: status do solo, com o indicador de "sensor vivo" do
  // sensor de umidade do solo à esquerda (sem divisória — ocupa a linha toda) ---
  int ySala = yDivisorSala + 3;
  int dotSoloX = 3;
  int dotSoloY = ySala + 3;
  if (pulsoSensorSoloAtivo) {
    display.fillCircle(dotSoloX, dotSoloY, 3, SSD1306_WHITE);
  } else {
    display.drawCircle(dotSoloX, dotSoloY, 3, SSD1306_WHITE);
  }
  display.setCursor(dotSoloX + 6, ySala);
  display.print("Solo: ");
  display.print(estadoSolo());

  // --- Separador vertical entre as colunas: só até a linha de umidade,
  // não desce até a linha do solo (que não é dividida) ---
  display.drawFastVLine(xDivisor, OLED_CONTEUDO_TOPO - 1, yDivisorSala - (OLED_CONTEUDO_TOPO - 1), SSD1306_WHITE);
}

// ---------- tela de configuração: menu interativo dos relés ----------
// Traduz um índice sequencial de item de menu (0..TOTAL_ITENS_MENU-1) para o
// "código" real selecionável em releSelecionadoControle (que mistura
// sentinelas negativas com índices de relé — ver constantes CONTROLE_* lá
// em cima). Itens 2 em diante vêm de RELES_MANUAIS — é essa lista que já
// "pula" o índice 5 (não utilizado) e não tem mais o antigo índice 7.
int itemIndexParaRele(int indiceItem) {
  if (indiceItem == 0) return CONTROLE_MODO_CULTIVO;
  if (indiceItem == 1) return CONTROLE_LUZ_MANUAL;
  int posManual = indiceItem - 2;
  if (posManual < 0 || posManual >= TOTAL_RELES_MANUAIS) return CONTROLE_MODO_CULTIVO; // segurança
  return RELES_MANUAIS[posManual];
}

// Caminho inverso: dado um código de relé manual (2, 3, 4 ou 6), devolve a
// posição correspondente dele na lista de itens do menu (0..TOTAL_ITENS_MENU-1).
// Usado para destacar a seta ">" no item certo em desenharConteudoConfiguracao().
int releParaItemIndex(int releCodigo) {
  for (int i = 0; i < TOTAL_RELES_MANUAIS; i++) {
    if (RELES_MANUAIS[i] == releCodigo) {
      return i + 2;
    }
  }
  return 0; // não deveria acontecer; cai no primeiro item por segurança
}

// Texto curto do estado do override de luz, usado no menu de configuração.
const char* textoModoControleLuz() {
  switch (modoControleLuz) {
    case LUZ_MANUAL_LIGADA: return "ON";
    case LUZ_MANUAL_DESLIGADA: return "OFF";
    case LUZ_AUTOMATICA:
    default: return "Auto";
  }
}

// Cabeçalho de tabela: "Dispositivos" (nomes, coluna da esquerda) e
// "Status" (valor/estado atual de cada um, coluna da direita). Não são
// abas/telas diferentes — é só a legenda das duas colunas da lista abaixo.
// Reaproveitado no menu de confirmação (com a seta) e no topo da lista de
// dispositivos (sem seta, já que ela desceu pra dentro da lista).
// Sem linha horizontal no topo (ficaria colada na linha do cabeçalho
// principal, logo acima) — só a linha horizontal de baixo (de ponta a
// ponta da tela) e a divisória central entre as duas colunas.
const int OLED_CABECALHO_TABELA_ALTURA = 10;
const int OLED_CABECALHO_TABELA_DIVISOR_X = 82;

void desenharCabecalhoTabela(bool mostrarSeta) {
  const int yTopo = OLED_CONTEUDO_TOPO;
  const int yBase = yTopo + OLED_CABECALHO_TABELA_ALTURA;

  display.drawFastHLine(0, yBase, SCREEN_WIDTH, SSD1306_WHITE);
  display.drawFastVLine(OLED_CABECALHO_TABELA_DIVISOR_X, yTopo, yBase - yTopo, SSD1306_WHITE);

  display.setCursor(2, yTopo + 1);
  display.print(mostrarSeta ? ">Dispositivos" : " Dispositivos");

  display.setCursor(OLED_CABECALHO_TABELA_DIVISOR_X + 3, yTopo + 1);
  display.print(" Status");
}

// Lista rolável com "totalItens" no total (Modo Cultivo, Luz Manual, e os
// relés manuais de RELES_MANUAIS — hoje 5: Desumidificador, Ventilador,
// Entrada de Ar 1, Entrada de Ar 2, Saida de Ar), mostrando só "visiveis"
// por vez, com a janela sempre centralizada no item selecionado
// (releSelecionadoControle). A seta já chega nesta tela posicionada no
// primeiro item (ver
// selecionarTelaOLED). "Dispositivos" e "Status" no topo são só os
// cabeçalhos das duas colunas (nome do dispositivo / valor atual dele) —
// não são telas diferentes.
void desenharConteudoConfiguracao() {
  desenharCabecalhoTabela(false);

  const int totalItens = 2 + TOTAL_RELES_MANUAIS; // modo de cultivo + luz manual + relés manuais
  const int visiveis = 4;
  const int yListaTopo = OLED_CONTEUDO_TOPO + OLED_CABECALHO_TABELA_ALTURA + 2;

  int indiceAtual;
  if (releSelecionadoControle == CONTROLE_MODO_CULTIVO) {
    indiceAtual = 0;
  } else if (releSelecionadoControle == CONTROLE_LUZ_MANUAL) {
    indiceAtual = 1;
  } else {
    indiceAtual = releParaItemIndex(releSelecionadoControle);
  }

  int inicio = indiceAtual - visiveis / 2;
  if (inicio < 0) inicio = 0;
  if (inicio > totalItens - visiveis) inicio = totalItens - visiveis;
  if (inicio < 0) inicio = 0;

  display.setTextSize(1);
  for (int linha = 0; linha < visiveis && (inicio + linha) < totalItens; linha++) {
    int idx = inicio + linha;
    int releIdx = itemIndexParaRele(idx);
    int y = yListaTopo + linha * 8;

    display.setCursor(0, y);
    display.print(idx == indiceAtual ? ">" : " ");

    if (releIdx == CONTROLE_MODO_CULTIVO) {
      display.print("Modo Cultivo");
      display.setCursor(SCREEN_WIDTH - 34, y);
      display.print(modoAtual == VEGETACAO ? "Vega" : "Flora");
    } else if (releIdx == CONTROLE_LUZ_MANUAL) {
      display.print("Luz Manual");
      display.setCursor(SCREEN_WIDTH - 28, y);
      display.print(textoModoControleLuz());
    } else {
      display.print(nomeReleControle(releIdx));
      display.setCursor(SCREEN_WIDTH - 22, y);
      display.print(estadoReles[releIdx] ? "ON" : "OFF");
    }
  }
}

// ---------- tela de menu (confirmação p/ abrir a configuração) ----------
// Tela intermediária mostrada quando a navegação chega na TELA_CONFIGURACAO
// (única tela "interativa" — ver telaInterativa()). Mostra o mesmo
// cabeçalho de tabela "Dispositivos"/"Status" que vai aparecer na lista,
// como prévia, com a instrução para segurar o botão e abrir de fato.
void desenharMenuOLED() {
  display.setTextSize(1);

  desenharCabecalhoTabela(true);

  const int yCaixaBase = OLED_CONTEUDO_TOPO + OLED_CABECALHO_TABELA_ALTURA;

  // Instrução, centralizada, abaixo da caixa
  const char* linha1 = "Segure";
  int16_t x1, y1;
  uint16_t w, h;
  display.getTextBounds(linha1, 0, 0, &x1, &y1, &w, &h);
  display.setCursor((SCREEN_WIDTH - (int)w) / 2, yCaixaBase + 12);
  display.print(linha1);

  const char* linha2 = "Para Abrir.";
  display.getTextBounds(linha2, 0, 0, &x1, &y1, &w, &h);
  display.setCursor((SCREEN_WIDTH - (int)w) / 2, yCaixaBase + 24);
  display.print(linha2);
}

// ---------- mensagem temporária ----------
// Centraliza o texto tanto na horizontal quanto na vertical, usando toda a
// área abaixo do cabeçalho (não sobra mais rodapé reservando espaço),
// calculando a largura real do texto via getTextBounds() para não depender
// de contagem de caracteres.
void desenharMensagem() {
  display.setTextSize(1);
  int16_t x1, y1;
  uint16_t w, h;
  display.getTextBounds(linha1Mensagem, 0, 0, &x1, &y1, &w, &h);
  int centroY = OLED_CONTEUDO_TOPO + ((SCREEN_HEIGHT - OLED_CONTEUDO_TOPO) - (int)h) / 2;
  display.setCursor((SCREEN_WIDTH - (int)w) / 2, centroY);
  display.print(linha1Mensagem);
}

// ---------- tela de descanso: dados + sol/lua (animados) ----------
// Estado ocioso PADRÃO do display — mas agora, ao contrário da versão
// anterior (que era só uma animação em tela cheia), usa o MESMO cabeçalho
// compartilhado das outras telas (título, hora, barras de conexão, pulso do
// sensor) e mostra os dados essenciais de uma vez: temperatura, umidade,
// modo de cultivo e status do solo à esquerda; o ícone de sol/lua e o tempo
// restante do ciclo de luz à direita. Não tem rodapé (sem indicador de
// página), já que não faz parte do ciclo de navegação por botão.
//
// NOTA DE FONTE: o símbolo de grau (°) usa o caractere 248 da tabela
// estendida da fonte padrão do Adafruit_GFX. Acentos (ç, ã, ú etc.) NÃO são
// usados em nenhum texto do display — a fonte padrão não tem esses glifos
// (viram lixo na tela) — por isso "Vegetativo"/"Floracao" seguem sem acento,
// no mesmo padrão do restante do código.

// Posição do ícone de sol/lua, na coluna direita, ao lado dos dados
const int OLED_DESCANSO_ICONE_CX = 104;
const int OLED_DESCANSO_ICONE_CY = 34;

// Desenhado quando a luz interna está LIGADA. Núcleo "respirando" (pulsando
// de tamanho) e 8 raios girando lentamente com comprimento variável — tudo
// calculado a cada quadro, sem nenhum estado guardado entre chamadas.
// Escala compacta (cabe ao lado da coluna de dados, sem invadir o cabeçalho).
void desenharSol(int cx, int cy) {
  int r = 6;

  float t = millis() / 1000.0;

  float pulso = (sin(t * 2.0) + 1.0) / 2.0; // 0..1
  int raioNucleo = r + (int)(pulso * 2.0);

  display.fillCircle(cx, cy, raioNucleo, SSD1306_WHITE);

  float rotacao = t * 0.9;
  for (int i = 0; i < 8; i++) {
    float ang = rotacao + i * (PI / 4.0);
    float variacao = (sin(t * 3.0 + i) + 1.0) / 2.0; // 0..1, defasado por raio
    int comprimento = 2 + (int)(variacao * 3.0);

    int x1 = cx + cos(ang) * (raioNucleo + 2);
    int y1 = cy + sin(ang) * (raioNucleo + 2);
    int x2 = cx + cos(ang) * (raioNucleo + 2 + comprimento);
    int y2 = cy + sin(ang) * (raioNucleo + 2 + comprimento);
    display.drawLine(x1, y1, x2, y2, SSD1306_WHITE);
  }
}

// Desenhado quando a luz interna está DESLIGADA. Lua com leve flutuação
// vertical (efeito "boiando") — versão compacta, sem estrelas (não sobra
// espaço ao lado da coluna de dados para elas ficarem legíveis).
void desenharLua(int cx, int cy) {
  int r = 9;

  float t = millis() / 1000.0;
  int deslocY = (int)(sin(t * 0.8) * 1.5);

  display.fillCircle(cx, cy + deslocY, r, SSD1306_WHITE);
  display.fillCircle(cx + 5, cy - 3 + deslocY, r - 3, SSD1306_BLACK);
}

// Ponto de entrada da tela de descanso: cabeçalho compartilhado + coluna de
// dados (temperatura, umidade, modo, solo) + ícone sol/lua com o tempo
// restante do ciclo de luz embaixo dele.
void desenharTelaDescanso(const DateTime& now) {
  desenharCabecalho(now, "Cultivo");

  // --- Coluna de dados (esquerda) ---
  const int xDados = 0;
  const int espacoLinha = 11;
  int y = OLED_CONTEUDO_TOPO;

  display.setTextSize(1);

  display.setCursor(xDados, y);
  display.print("T: ");
  display.print(formatarTemperatura(tempAtual));
  // Símbolo de grau 2px mais alto que o resto do texto (fica melhor
  // alinhado visualmente) — desenha em y-2 e depois volta o cursor pra
  // linha original antes de continuar com o "C".
  int16_t grauX = display.getCursorX();
  int16_t grauY = display.getCursorY();
  display.setCursor(grauX, grauY - 2);
  display.write(248); // símbolo de grau
  display.setCursor(grauX + 6, grauY);
  display.print("C");
  y += espacoLinha;

  display.setCursor(xDados, y);
  display.print("U: ");
  if (isnan(umidAtual)) {
    display.print("--");
  } else {
    display.print(umidAtual, 0);
  }
  display.print("%");
  y += espacoLinha;

  display.setCursor(xDados, y);
  display.print("VPD: ");
  display.print(formatarVPD(calcularVPD(tempAtual, umidAtual)));
  y += espacoLinha;

  display.setCursor(xDados, y);
  display.print("S: ");
  display.print(estadoSolo());

  // --- Ícone (direita): sol ou lua, conforme o estado real do relé de luz ---
  if (estadoReles[RELE_LUZ]) {
    desenharSol(OLED_DESCANSO_ICONE_CX, OLED_DESCANSO_ICONE_CY);
  } else {
    desenharLua(OLED_DESCANSO_ICONE_CX, OLED_DESCANSO_ICONE_CY);
  }

  // --- Sob o ícone: normalmente o tempo até a próxima troca de estado da
  // luz; mas se a luz estiver em override manual (LUZ_MANUAL_LIGADA/
  // DESLIGADA), mostra em vez disso o tempo restante desse override
  // ("LM: HHhMMm"). Quando esse tempo chega a zero, controlarLuz() já
  // retoma o automático sozinho (ver alternarModoControleLuz()), e na
  // próxima chamada este trecho volta a mostrar o tempoRestante() normal.
  String restante;
  if (modoControleLuz != LUZ_AUTOMATICA) {
    restante = "LM: " + tempoRestanteOverrideLuz();
  } else {
    restante = tempoRestante(now);
  }
  int16_t rx1, ry1;
  uint16_t rw, rh;
  display.getTextBounds(restante, 0, 0, &rx1, &ry1, &rw, &rh);
  int xTexto = OLED_DESCANSO_ICONE_CX - (int)rw / 2;
  if (xTexto + (int)rw > SCREEN_WIDTH - 1) {
    xTexto = SCREEN_WIDTH - 1 - (int)rw;
  }
  if (xTexto < 0) {
    xTexto = 0;
  }
  display.setCursor(xTexto, 54);
  display.print(restante);
}

// ---------- renderização geral ----------
// Único ponto que efetivamente escreve no display (display.display()).
// Só redesenha quando atualizarOLED está true (dirty flag) — evita
// transferências I2C desnecessárias, já que o SSD1306 é relativamente lento
// para atualizar via I2C. Despacha o desenho de acordo com estadoOLED (e,
// dentro de OLED_EXIBINDO, de acordo com telaSelecionada).
void renderizarOLED(const DateTime& now) {
  if (!atualizarOLED) return;
  atualizarOLED = false;

  display.clearDisplay();

  if (estadoOLED == OLED_DESCANSO) {
    desenharTelaDescanso(now);
    display.display();
    return;
  }

  // Cada tela pode ter seu próprio título no cabeçalho. O rodapé padrão
  // antigo (pontos de página + modo + solo) não existe mais em nenhuma tela
  // do design atual — TELA_PRINCIPAL tem sua própria linha "Solo:",
  // TELA_CONFIGURACAO usa a tabela no topo, e mensagens temporárias (ex:
  // "Porta Liberada") ficam centralizadas sem nada embaixo.
  const char* titulo = "CULTIVO";
  if (estadoOLED == OLED_EXIBINDO && telaSelecionada == TELA_PRINCIPAL) {
    titulo = "Informacoes";
  } else if (telaSelecionada == TELA_CONFIGURACAO) {
    titulo = "Config";
  }

  desenharCabecalho(now, titulo);

  switch (estadoOLED) {
    case OLED_MENSAGEM:
      desenharMensagem();
      break;
    case OLED_MENU:
      desenharMenuOLED();
      break;
    case OLED_EXIBINDO:
      switch (telaSelecionada) {
        case TELA_PRINCIPAL:
          desenharConteudoPrincipal();
          break;
        case TELA_CONFIGURACAO:
          desenharConteudoConfiguracao();
          break;
        default:
          break;
      }
      break;
    default:
      break;
  }

  display.display();
}

// =========================================================================

// ---------------- BUZZER ----------------
// Liga o buzzer e agenda o desligamento automático — não bloqueia o loop()
// com delay(). Quem efetivamente desliga é atualizarBuzzer().
void beep(unsigned long tempo) {
  digitalWrite(BUZZER, HIGH);
  buzzerLigado = true;
  buzzerDesligaEm = millis() + tempo;
}

void atualizarBuzzer() {
  if (buzzerLigado && millis() >= buzzerDesligaEm) {
    digitalWrite(BUZZER, LOW);
    buzzerLigado = false;
  }
}

// ---------------- RELÉS ----------------
// Único ponto do código que escreve fisicamente num pino de relé — todo o
// resto do sistema deve ligar/desligar relés chamando esta função (nunca
// digitalWrite direto), para manter estadoReles[] sempre sincronizado com a
// realidade.
// A polaridade elétrica (relé ativo em nível baixo ou alto) é definida pela
// constante RELE_ATIVO_EM_BAIXO, lá no topo do arquivo.
void acionarRele(int numero, bool ligado) {
  estadoReles[numero] = ligado;
  int nivelAtivo = RELE_ATIVO_EM_BAIXO ? LOW : HIGH;
  int nivelInativo = RELE_ATIVO_EM_BAIXO ? HIGH : LOW;
  digitalWrite(reles[numero], ligado ? nivelAtivo : nivelInativo);
}

// Avança a seleção no menu de configuração, no ciclo fixo:
// Modo Cultivo -> Luz Manual -> RELES_MANUAIS[0] -> ... -> RELES_MANUAIS[ultimo] -> Modo Cultivo ...
// (hoje: Modo Cultivo -> Luz Manual -> Desumidificador -> Ventilador -> Entrada de Ar 1 -> Entrada de Ar 2 -> Saida de Ar -> ...)
void avancarReleControle() {
  if (releSelecionadoControle == CONTROLE_MODO_CULTIVO) {
    releSelecionadoControle = CONTROLE_LUZ_MANUAL;
  } else if (releSelecionadoControle == CONTROLE_LUZ_MANUAL) {
    releSelecionadoControle = RELES_MANUAIS[0];
  } else {
    int posAtual = releParaItemIndex(releSelecionadoControle) - 2;
    if (posAtual + 1 >= TOTAL_RELES_MANUAIS) {
      releSelecionadoControle = CONTROLE_MODO_CULTIVO;
    } else {
      releSelecionadoControle = RELES_MANUAIS[posAtual + 1];
    }
  }
  oledVoltaDescansoEm = millis() + TEMPO_EXIBICAO_OLED_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarOLED = true;
}

// Ativa/alterna o item atualmente selecionado no menu de configuração
// (chamado na pressão longa). Roteia para a função certa dependendo do
// "código" selecionado — modo de cultivo, override de luz, ou um relé comum.
void alternarReleSelecionado() {
  if (releSelecionadoControle == CONTROLE_MODO_CULTIVO) {
    alternarModoCultivo();
    return;
  }

  if (releSelecionadoControle == CONTROLE_LUZ_MANUAL) {
    alternarModoControleLuz();
    return;
  }

  if (releSelecionadoControle < PRIMEIRO_RELE_MANUAL || releSelecionadoControle >= TOTAL_RELES) {
    return;
  }

  bool novoEstado = !estadoReles[releSelecionadoControle];
  acionarRele(releSelecionadoControle, novoEstado);
  registrarEventoRele(releSelecionadoControle, novoEstado, "manual");
  salvarEstadoNaMemoria();

  oledVoltaDescansoEm = millis() + TEMPO_EXIBICAO_OLED_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarOLED = true;
}

// Alterna o override manual de luz em ciclo: Automático -> Ligada -> Desligada
// -> Automático... Não interrompe o cálculo do fotoperíodo (ver
// controlarLuz()) — só define até quando a sobreposição manual deve valer.
void alternarModoControleLuz() {
  switch (modoControleLuz) {
    case LUZ_AUTOMATICA:
      modoControleLuz = LUZ_MANUAL_LIGADA;
      break;
    case LUZ_MANUAL_LIGADA:
      modoControleLuz = LUZ_MANUAL_DESLIGADA;
      break;
    case LUZ_MANUAL_DESLIGADA:
    default:
      modoControleLuz = LUZ_AUTOMATICA;
      break;
  }

  // O timer do fotoperíodo continua sendo calculado normalmente em
  // controlarLuz(); aqui só definimos até quando o override deve valer.
  // Ao expirar (ou ao voltar manualmente para "Auto"), o controle
  // automático assume de novo sem qualquer ação extra do usuário.
  overrideLuzAte = (modoControleLuz == LUZ_AUTOMATICA) ? 0 : (millis() + DURACAO_OVERRIDE_LUZ_MS);

  const char* origem = (modoControleLuz == LUZ_AUTOMATICA) ? "retomou_automatico" : "override_manual";
  registrarEventoRele(RELE_LUZ, modoControleLuz == LUZ_MANUAL_LIGADA, origem);

  oledVoltaDescansoEm = millis() + TEMPO_EXIBICAO_OLED_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarOLED = true;
  // Grava o novo estado do override (ou a volta pro automático) na NVRAM —
  // sem isso, o modo manual "desaparecia" silenciosamente a cada reinício
  // (ver comentário em salvarEstadoNaMemoria()).
  salvarEstadoNaMemoria();
}

// Mesmo efeito de alternarModoControleLuz(), mas definindo o modo diretamente
// (em vez de "avançar" no ciclo) — usado pelo dashboard web (que já sabe
// exatamente o que o usuário escolheu). De propósito NÃO mexe nos
// temporizadores do menu OLED local (oledVoltaDescansoEm/
// ultimaInteracaoMenuEm) — esses são exclusivos de quem está de fato na
// frente do aparelho, mesmo padrão já usado em definirModoCultivoRemoto().
void definirModoControleLuzRemoto(ModoControleLuz novoModo) {
  if (novoModo == modoControleLuz) return; // nada mudou, evita gravar/piscar à toa

  modoControleLuz = novoModo;
  overrideLuzAte = (modoControleLuz == LUZ_AUTOMATICA) ? 0 : (millis() + DURACAO_OVERRIDE_LUZ_MS);

  const char* origem = (modoControleLuz == LUZ_AUTOMATICA) ? "retomou_automatico" : "override_manual";
  registrarEventoRele(RELE_LUZ, modoControleLuz == LUZ_MANUAL_LIGADA, origem);

  atualizarOLED = true;
  salvarEstadoNaMemoria();
}

// Nomes amigáveis dos relés, usados na TELA_CONFIGURACAO.
// Ajuste aqui se o cabeamento físico dos exaustores/ventiladores mudar.
// Índice 3 não tem case próprio de propósito: não existe nada conectado no
// hardware físico e nunca é exibido no menu (RELES_MANUAIS não o inclui).
const char* nomeReleControle(int indiceRele) {
  switch (indiceRele) {
    case 0:
      return "Luz";
    case 1:
      return "Desumidificador";
    case 2:
      return "Ventilador";
    case 4:
      return "Entrada de Ar 1";
    case 5:
      return "Entrada de Ar 2";
    case 6:
      return "Trava Acesso";
    case 7:
      return "Saida de Ar";
    default:
      return "Rele";
  }
}

// Alterna entre Vegetativo e Floração — isso muda o horário do fotoperíodo
// usado por luzDeveEstarLigada() e tempoRestante() automaticamente a partir
// da próxima checagem.
void alternarModoCultivo() {
  if (modoAtual == VEGETACAO) {
    modoAtual = FLORACAO;
    mostrarMensagemTemporaria("Modo: Floracao", 1500);
    registrarEventoModo("floracao", "manual");
  } else {
    modoAtual = VEGETACAO;
    mostrarMensagemTemporaria("Modo: Vegetativo", 1500);
    registrarEventoModo("vegetativo", "manual");
  }

  oledVoltaDescansoEm = millis() + TEMPO_EXIBICAO_OLED_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarOLED = true;
  salvarEstadoNaMemoria();
}

// Mesmo efeito de alternarModoCultivo(), mas definindo o modo diretamente —
// usado pelo dashboard web (que já sabe exatamente qual modo o usuário
// escolheu, não precisa "alternar"). Mesmo padrão de
// definirModoControleLuzRemoto(): não mexe nos temporizadores do menu OLED
// local.
void definirModoCultivoRemoto(ModoCultivo novoModo) {
  if (novoModo == modoAtual) return; // nada mudou, evita gravar/piscar à toa

  modoAtual = novoModo;
  mostrarMensagemTemporaria(modoAtual == FLORACAO ? "Modo: Floracao" : "Modo: Vegetativo", 1500);
  registrarEventoModo(modoAtual == FLORACAO ? "floracao" : "vegetativo", "web");
  atualizarOLED = true;
  salvarEstadoNaMemoria();
}

// Libera a trava/fechadura por "duracaoMs" (pulso, não estado persistente).
// Quem fecha de novo é atualizarAcesso(), chamada a cada loop().
void liberarAcessoPor(unsigned long duracaoMs) {
  acionarRele(RELE_ACESSO, true);
  acessoAtivo = true;
  acessoDesligaEm = millis() + duracaoMs;
  atualizarOLED = true;
}

// Empacota o mesmo feedback (relé + LED + beep + mensagem no OLED) usado
// pelo endpoint /api/acesso do dashboard web.
void liberarAcessoRemoto() {
  liberarAcessoPor(3000);
  beep(150);
  mostrarMensagemTemporaria("Porta Liberada", 1500);
  registrarEventoAcesso("web", "liberado");
}

void atualizarAcesso() {
  if (acessoAtivo && millis() >= acessoDesligaEm) {
    acionarRele(RELE_ACESSO, false);
    acessoAtivo = false;
    atualizarOLED = true;
  }
}

// ---------------- PERSISTÊNCIA (NVRAM DO RTC DS1307) ----------------
// O DS1307 possui 56 bytes de RAM com backup de bateria (endereços 0x08-0x3F),
// preservados mesmo com queda de energia. Usamos os 8 primeiros bytes para
// guardar: [0] marcador de validade, [1] bitmask dos relés manuais,
// [2] modo de cultivo, [3] modo do override manual da luz, [4..7] instante
// (unixtime, 32 bits) em que esse override expira.
//
// IMPORTANTE: a expiração do override é gravada como horário ABSOLUTO
// (unixtime do RTC), nunca como millis(). millis() zera a cada boot, então
// guardar "overrideLuzAte" (que é um timestamp de millis()) não faria
// sentido depois de reiniciar — é exatamente isso que causava o modo manual
// "sumir" silenciosamente a cada queda de energia/reset e a luz voltar pro
// automático sem avisar ninguém (ver alternarModoControleLuz() e
// restaurarEstadoDaMemoria()).
const uint8_t NVRAM_MARCADOR_VALIDO = 0xA5;
const uint8_t NVRAM_ENDERECO_BASE = 0;
const uint8_t NVRAM_TAMANHO = 8;

const uint8_t NVRAM_MODO_LUZ_AUTO = 0;
const uint8_t NVRAM_MODO_LUZ_LIGADA = 1;
const uint8_t NVRAM_MODO_LUZ_DESLIGADA = 2;

void salvarEstadoNaMemoria() {
  if (!rtcDisponivel) return;

  uint8_t bitmaskReles = 0;
  for (int i = 0; i < TOTAL_RELES; i++) {
    // Luz e trava nunca são restauradas por aqui (luz é sempre recalculada
    // pelo horário/override; trava nunca volta ligada, por segurança) — só
    // os relés manuais (RELES_MANUAIS) de fato usam esse bitmask de volta.
    // Gravar o bit delas aqui só guardaria um valor morto, nunca lido.
    if (i == RELE_LUZ || i == RELE_ACESSO) continue;
    if (estadoReles[i]) {
      bitmaskReles |= (1 << i);
    }
  }

  uint8_t modoLuzByte = NVRAM_MODO_LUZ_AUTO;
  uint32_t expiraUnix = 0;
  if (modoControleLuz != LUZ_AUTOMATICA) {
    modoLuzByte = (modoControleLuz == LUZ_MANUAL_LIGADA) ? NVRAM_MODO_LUZ_LIGADA : NVRAM_MODO_LUZ_DESLIGADA;
    unsigned long restanteMs = (overrideLuzAte > millis()) ? (overrideLuzAte - millis()) : 0;
    expiraUnix = obterAgora().unixtime() + (restanteMs / 1000UL);
  }

  uint8_t buffer[NVRAM_TAMANHO];
  buffer[0] = NVRAM_MARCADOR_VALIDO;
  buffer[1] = bitmaskReles;
  buffer[2] = (modoAtual == FLORACAO) ? 1 : 0;
  buffer[3] = modoLuzByte;
  buffer[4] = (uint8_t)(expiraUnix >> 24);
  buffer[5] = (uint8_t)(expiraUnix >> 16);
  buffer[6] = (uint8_t)(expiraUnix >> 8);
  buffer[7] = (uint8_t)(expiraUnix);

  rtc.writenvram(NVRAM_ENDERECO_BASE, buffer, NVRAM_TAMANHO);
}

void restaurarEstadoDaMemoria() {
  if (!rtcDisponivel) return;

  uint8_t buffer[NVRAM_TAMANHO];
  rtc.readnvram(buffer, NVRAM_TAMANHO, NVRAM_ENDERECO_BASE);

  Serial.print("NVRAM lida - marcador=0x");
  Serial.print(buffer[0], HEX);
  Serial.print(" bitmask=0x");
  Serial.print(buffer[1], HEX);
  Serial.print(" modoCultivo=");
  Serial.print(buffer[2]);
  Serial.print(" modoLuz=");
  Serial.print(buffer[3]);
  Serial.print(" expiraUnix=");
  Serial.println(((uint32_t)buffer[4] << 24) | ((uint32_t)buffer[5] << 16) |
                  ((uint32_t)buffer[6] << 8) | (uint32_t)buffer[7]);

  if (buffer[0] != NVRAM_MARCADOR_VALIDO) {
    // Memória nunca foi gravada (RTC novo/zerado, ou gravado por uma versão
    // antiga do firmware com layout de 3 bytes): mantém os padrões de fábrica.
    Serial.println("NVRAM sem marcador valido - mantendo padroes de fabrica");
    return;
  }

  uint8_t bitmaskReles = buffer[1];
  modoAtual = (buffer[2] == 1) ? FLORACAO : VEGETACAO;

  // Restaura apenas os relés manuais de RELES_MANUAIS (exaustores/ventiladores;
  // os índices vazios nunca são tocados aqui). A luz (índice 0) é
  // recalculada abaixo (automático ou override restaurado), e o relé de
  // acesso/trava nunca é restaurado como ligado por segurança.
  for (int i = 0; i < TOTAL_RELES_MANUAIS; i++) {
    int idx = RELES_MANUAIS[i];
    bool ligado = bitmaskReles & (1 << idx);
    acionarRele(idx, ligado);
  }

  // Restaura o override manual da luz, SE ainda não tiver expirado. A
  // comparação é feita em horário absoluto (unixtime do RTC), então funciona
  // corretamente mesmo depois de reiniciar (diferente de millis(), que
  // zeraria e invalidaria qualquer comparação).
  //
  // Validação extra: o byte precisa ser exatamente um dos 3 valores válidos
  // (AUTO/LIGADA/DESLIGADA). Isso protege contra "lixo" que possa ter ficado
  // nos endereços 3-7 da NVRAM de antes desta lógica existir (o marcador
  // 0xA5 sozinho não garante que o restante do layout novo já foi escrito
  // por esta versão do firmware — a NVRAM do DS1307 não é apagada quando o
  // ESP32 é regravado, só quando alguém escreve nela).
  uint8_t modoLuzByte = buffer[3];
  bool modoLuzValido = (modoLuzByte == NVRAM_MODO_LUZ_AUTO ||
                         modoLuzByte == NVRAM_MODO_LUZ_LIGADA ||
                         modoLuzByte == NVRAM_MODO_LUZ_DESLIGADA);

  if (!modoLuzValido) {
    Serial.println("Byte de modoLuz invalido na NVRAM (dado antigo/residual) - mantendo automatico");
  } else if (modoLuzByte != NVRAM_MODO_LUZ_AUTO) {
    uint32_t expiraUnix = ((uint32_t)buffer[4] << 24) | ((uint32_t)buffer[5] << 16) |
                           ((uint32_t)buffer[6] << 8) | (uint32_t)buffer[7];
    uint32_t agoraUnix = obterAgora().unixtime();

    if (expiraUnix > agoraUnix) {
      modoControleLuz = (modoLuzByte == NVRAM_MODO_LUZ_LIGADA) ? LUZ_MANUAL_LIGADA : LUZ_MANUAL_DESLIGADA;
      overrideLuzAte = millis() + ((unsigned long)(expiraUnix - agoraUnix) * 1000UL);
      Serial.println("Override manual da luz restaurado da NVRAM (ainda nao expirou)");
    } else {
      Serial.println("Override manual da luz tinha expirado durante o desligamento - mantendo automatico");
    }
  }

  Serial.println("Estado dos reles e modo de cultivo restaurados da memoria do RTC");
  atualizarOLED = true;
}

// ---------------- TEMPO ----------------
// Conexão inicial (bloqueante, só usada uma vez no setup()). Tenta por até
// WIFI_TIMEOUT_MS e retorna se conseguiu ou não.
bool conectarWiFi() {
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.persistent(false);
  WiFi.disconnect(true, true);
  delay(100);
  WiFi.begin(ssid, password);

  unsigned long inicio = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - inicio < WIFI_TIMEOUT_MS) {
    delay(250);
  }

  Serial.print("Status WiFi: ");
  Serial.println(static_cast<int>(WiFi.status()));
  return WiFi.status() == WL_CONNECTED;
}

// Reconexão contínua e não-bloqueante (chamada a cada loop()). Usa backoff:
// tenta a cada RETENTATIVA_WIFI_INICIAL_MS no primeiro minuto desconectado
// (JANELA_WIFI_INICIAL_MS), depois espaça para RETENTATIVA_WIFI_POSTERIOR_MS
// — evita martelar reconexões indefinidamente se a rede cair por muito tempo.
void atualizarConexaoWiFi() {
  wl_status_t statusAtual = WiFi.status();

  if (statusAtual == WL_CONNECTED) {
    if (!wifiConectado) {
      wifiConectado = true;
      wifiDesconectadoDesde = 0;
      Serial.println("WiFi reconectado");
      iniciarMDNS(); // o IP pode ter mudado — reinicia o responder mDNS
    }
    return;
  }

  if (wifiConectado) {
    wifiConectado = false;
    wifiDesconectadoDesde = millis();
    Serial.println("WiFi desconectado");
  } else if (wifiDesconectadoDesde == 0) {
    wifiDesconectadoDesde = millis();
  }

  unsigned long tempoDesconectado = millis() - wifiDesconectadoDesde;
  unsigned long intervaloAtual = tempoDesconectado < JANELA_WIFI_INICIAL_MS
    ? RETENTATIVA_WIFI_INICIAL_MS
    : RETENTATIVA_WIFI_POSTERIOR_MS;

  if (millis() - ultimaTentativaWiFi < intervaloAtual) {
    return;
  }

  ultimaTentativaWiFi = millis();
  Serial.println("Tentando reconectar WiFi...");
  WiFi.mode(WIFI_STA);
  WiFi.disconnect(true, true);
  delay(100);
  WiFi.begin(ssid, password);
}

// Múltiplos servidores NTP: se um estiver bloqueado/fora do ar na rede do
// usuário, a próxima tentativa roda com outro par de servidores.
const char* SERVIDORES_NTP[] = {
  "pool.ntp.org", "time.google.com", "a.st1.ntp.br", "time.windows.com"
};
const int TOTAL_SERVIDORES_NTP = sizeof(SERVIDORES_NTP) / sizeof(SERVIDORES_NTP[0]);
int indiceServidorNTP = 0;

// Tenta sincronizar a hora via NTP, alternando entre pares de servidores
// (SERVIDORES_NTP) a cada falha — protege contra um servidor específico
// estar bloqueado/fora do ar na rede do usuário. Se conseguir e o RTC estiver
// disponível, também ajusta o RTC físico (assim ele mantém a hora certa
// mesmo sem WiFi depois, até a próxima queda de energia).
bool sincronizarHoraNTP() {
  if (!wifiConectado) {
    return false;
  }

  const char* servidorPrimario = SERVIDORES_NTP[indiceServidorNTP % TOTAL_SERVIDORES_NTP];
  const char* servidorSecundario = SERVIDORES_NTP[(indiceServidorNTP + 1) % TOTAL_SERVIDORES_NTP];

  Serial.print("NTP: tentando sincronizar com ");
  Serial.print(servidorPrimario);
  Serial.print(" / ");
  Serial.println(servidorSecundario);

  configTime(GMT_OFFSET_SEC, DAYLIGHT_OFFSET_SEC, servidorPrimario, servidorSecundario);

  struct tm timeinfo;
  unsigned long inicio = millis();
  while (!getLocalTime(&timeinfo) && millis() - inicio < NTP_TIMEOUT_MS) {
    delay(250);
  }

  if (!getLocalTime(&timeinfo)) {
    Serial.println("NTP: falhou (timeout). Se isso se repetir com todos os servidores, "
                    "a rede provavelmente bloqueia a porta UDP 123 (comum em redes "
                    "corporativas/escolares) - teste com outra rede (ex: hotspot do celular).");
    indiceServidorNTP++; // roda para outro par de servidores na próxima tentativa
    return false;
  }

  char bufferHora[32];
  snprintf(bufferHora, sizeof(bufferHora), "%04d-%02d-%02d %02d:%02d:%02d",
    timeinfo.tm_year + 1900, timeinfo.tm_mon + 1, timeinfo.tm_mday,
    timeinfo.tm_hour, timeinfo.tm_min, timeinfo.tm_sec);
  Serial.print("NTP: sincronizado com sucesso -> ");
  Serial.println(bufferHora);

  if (rtcDisponivel) {
    rtc.adjust(DateTime(
      timeinfo.tm_year + 1900,
      timeinfo.tm_mon + 1,
      timeinfo.tm_mday,
      timeinfo.tm_hour,
      timeinfo.tm_min,
      timeinfo.tm_sec
    ));
    Serial.println("NTP: RTC ajustado com a hora sincronizada");
  }

  horaSincronizada = true;
  return true;
}

// Dispara sincronizarHoraNTP() periodicamente (a cada RETENTATIVA_NTP_MS) só
// enquanto ainda não tiver sincronizado com sucesso desde o boot (uma vez
// que horaSincronizada vira true, para de tentar — o RTC assume dali em diante).
void atualizarSincronizacaoHora() {
  if (horaSincronizada) return;
  if (!wifiConectado) return;
  if (millis() - ultimaTentativaNTP < RETENTATIVA_NTP_MS) return;

  ultimaTentativaNTP = millis();
  if (sincronizarHoraNTP()) {
    atualizarOLED = true;
  }
}

// Fonte única de "que horas são agora" para todo o sistema. Ordem de
// prioridade (WiFi/NTP em primeiro lugar, RTC como reserva):
//   1) Relógio interno do ESP32, DESDE QUE já tenha sincronizado via NTP
//      nesta sessão (horaSincronizada). Uma vez sincronizado, ele continua
//      contando sozinho (cristal interno do chip) independente do WiFi
//      continuar conectado ou não, e sobrevive a qualquer problema no RTC
//      externo (inclusive ele ser desconectado com o sistema ligado).
//   2) RTC físico (se disponível e com ano plausível, >= 2024) — usado só
//      enquanto ainda não sincronizou via NTP nesta sessão (ex: acabou de
//      ligar sem WiFi, ou a internet caiu antes da primeira sincronização).
//      É o "backup" que mantém a hora minimamente certa sem internet.
//   3) Data fixa de fallback (2026-01-01 00:00:00), usada só quando não há
//      NENHUMA fonte de hora disponível (sem NTP ainda E sem RTC).
DateTime obterAgora() {
  if (horaSincronizada) {
    time_t agoraEpoch = time(nullptr);
    if (agoraEpoch > 100000) {
      // IMPORTANTE: time(nullptr) no ESP32 devolve o epoch UTC "cru" —
      // configTime()/GMT_OFFSET_SEC só afeta getLocalTime()/localtime(), não
      // o valor de time() em si. E o construtor DateTime(time_t) da RTClib
      // faz uma decodificação "ingênua" (sem fuso horário nenhum). Por isso
      // é preciso somar o offset manualmente aqui — sem isso, a hora fica
      // adiantada em GMT_OFFSET_SEC (3h, no caso do Brasil/UTC-3).
      return DateTime((uint32_t)(agoraEpoch + GMT_OFFSET_SEC + DAYLIGHT_OFFSET_SEC));
    }
  }

  if (rtcDisponivel) {
    DateTime agoraRTC = rtc.now();

    if (agoraRTC.year() >= 2024) {
      return agoraRTC;
    }
  }

  return DateTime(2026, 1, 1, 0, 0, 0);
}

// Formata a hora atual como string "AAAA-MM-DD HH:MM:SS" (usado no JSON de
// status do dashboard web — ver construirStatusJson()).
String obterTimestampAtual() {
  DateTime now = obterAgora();
  char buffer[32];
  snprintf(
    buffer,
    sizeof(buffer),
    "%04d-%02d-%02d %02d:%02d:%02d",
    now.year(),
    now.month(),
    now.day(),
    now.hour(),
    now.minute(),
    now.second()
  );
  return String(buffer);
}

String valorJsonFloat(float valor, int casas) {
  return isnan(valor) ? String("null") : String(valor, casas);
}

String escaparJson(const String& valor) {
  String saida = "";
  saida.reserve(valor.length() + 8);

  for (int i = 0; i < valor.length(); i++) {
    char c = valor[i];
    if (c == '\\' || c == '"') {
      saida += '\\';
      saida += c;
    } else if (c == '\n') {
      saida += "\\n";
    } else if (c == '\r') {
      saida += "\\r";
    } else {
      saida += c;
    }
  }

  return saida;
}

bool enviarJsonHostinger(const char* endpoint, const String& payload) {
  if (!wifiConectado || WiFi.status() != WL_CONNECTED) {
    return false;
  }

  WiFiClientSecure client;
  client.setInsecure(); // HTTPS sem fixar certificado, mais simples para hospedagem compartilhada.

  HTTPClient http;
  String url = String(HOSTINGER_BASE_URL) + endpoint;
  if (!http.begin(client, url)) {
    Serial.println("Hostinger: falha ao iniciar HTTP");
    return false;
  }

  http.setTimeout(HOSTINGER_HTTP_TIMEOUT_MS);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", HOSTINGER_API_KEY);

  int status = http.POST(payload);
  bool ok = status >= 200 && status < 300;
  if (!ok) {
    Serial.print("Hostinger: erro HTTP ");
    Serial.print(status);
    Serial.print(" em ");
    Serial.println(endpoint);
  }

  http.end();
  return ok;
}

void enviarLeituraHostinger() {
  String payload = "{";
  payload += "\"timestamp\":\"" + escaparJson(obterTimestampAtual()) + "\",";
  payload += "\"temp_interna\":" + valorJsonFloat(tempAtual, 1) + ",";
  payload += "\"umid_interna\":" + valorJsonFloat(umidAtual, 0) + ",";
  payload += "\"temp_externa\":" + valorJsonFloat(tempExterna, 1) + ",";
  payload += "\"umid_externa\":" + valorJsonFloat(umidExterna, 0) + ",";
  payload += "\"solo_percentual\":" + String(soloPercentual);
  payload += "}";

  enviarJsonHostinger("/registrar_leitura.php", payload);
}

void enviarComandoHostinger(const char* tipo, const char* alvo, const String& valor, const char* origem) {
  String payload = "{";
  payload += "\"timestamp\":\"" + escaparJson(obterTimestampAtual()) + "\",";
  payload += "\"tipo\":\"" + escaparJson(tipo) + "\",";
  payload += "\"alvo\":\"" + escaparJson(alvo) + "\",";
  payload += "\"valor\":\"" + escaparJson(valor) + "\",";
  payload += "\"origem\":\"" + escaparJson(origem) + "\"";
  payload += "}";

  enviarJsonHostinger("/registrar_comando.php", payload);
}

void enfileirarComandoHostinger(const char* tipo, const char* alvo, const String& valor, const char* origem) {
  String payload = "{";
  payload += "\"timestamp\":\"" + escaparJson(obterTimestampAtual()) + "\",";
  payload += "\"tipo\":\"" + escaparJson(tipo) + "\",";
  payload += "\"alvo\":\"" + escaparJson(alvo) + "\",";
  payload += "\"valor\":\"" + escaparJson(valor) + "\",";
  payload += "\"origem\":\"" + escaparJson(origem) + "\"";
  payload += "}";

  if (filaComandosTotal >= HOSTINGER_FILA_COMANDOS_MAX) {
    filaComandosInicio = (filaComandosInicio + 1) % HOSTINGER_FILA_COMANDOS_MAX;
    filaComandosTotal--;
    Serial.println("Hostinger: fila de comandos cheia, descartando comando antigo");
  }

  filaComandosHostinger[filaComandosFim] = payload;
  filaComandosFim = (filaComandosFim + 1) % HOSTINGER_FILA_COMANDOS_MAX;
  filaComandosTotal++;
}

void atualizarFilaComandosHostinger() {
  if (filaComandosTotal <= 0) return;
  if (millis() - ultimoEnvioComandoHostingerEm < INTERVALO_ENVIO_COMANDOS_HOSTINGER_MS) return;

  ultimoEnvioComandoHostingerEm = millis();
  String payload = filaComandosHostinger[filaComandosInicio];
  if (!enviarJsonHostinger("/registrar_comando.php", payload)) {
    return;
  }

  filaComandosHostinger[filaComandosInicio] = "";
  filaComandosInicio = (filaComandosInicio + 1) % HOSTINGER_FILA_COMANDOS_MAX;
  filaComandosTotal--;
}

// FUNÇÕES DE LOG — ponto único para registrar comandos feitos no sistema.
// Hoje os eventos são enviados para a Hostinger; o dashboard local continua
// independente e funcionando mesmo se a internet cair.
void registrarEventoModo(const char* estadoModo, const char* origem) {
  enfileirarComandoHostinger("modo_cultivo", "modo_cultivo", estadoModo, origem);
}

void registrarEventoRele(int indiceRele, bool ligado, const char* origem) {
  enfileirarComandoHostinger("rele", nomeReleControle(indiceRele), ligado ? "ligado" : "desligado", origem);
}

void registrarEventoAcesso(const char* origem, const char* resultado) {
  enfileirarComandoHostinger("acesso", "Trava Acesso", resultado, origem);
}

// ---------------- DHT / SHT40 ----------------
// Lê o sensor de temperatura/umidade do ar (qual sensor físico depende de
// WOKWI_SIMULATION). Só aceita um novo valor se a variação for significativa
// (>= 0.1°C ou >= 1% de umidade) — evita marcar a tela pra redesenhar por
// ruído mínimo do sensor. Independente disso, dispara o "pulso" visual de
// sensor vivo a cada leitura válida (ver pulsoSensorAtivo).
void atualizarDHT() {
  if (millis() - ultimoDHT < INTERVALO_DHT_MS) return;
  ultimoDHT = millis();

  float novaTemp = NAN;
  float novaUmid = NAN;

#if WOKWI_SIMULATION
  for (int tentativa = 0; tentativa < 3; tentativa++) {
    novaTemp = dht.readTemperature();
    novaUmid = dht.readHumidity();

    if (!isnan(novaTemp) && !isnan(novaUmid)) {
      break;
    }

    delay(20);
  }
#else
  if (sht40Disponivel) {
    sensors_event_t humidity, temp;
    memset(&humidity, 0, sizeof(humidity));
    memset(&temp, 0, sizeof(temp));

    if (sht4.getEvent(&humidity, &temp)) {
      novaTemp = temp.temperature;
      novaUmid = humidity.relative_humidity;
    } else {
      // Leitura falhou (CRC/timeout no I2C) — mantém NAN em vez de usar
      // lixo de memória das structs, evitando valores fantasmas na tela.
      Serial.println("Falha na leitura do SHT40 (CRC/timeout)");
    }
  }
#endif

  if (!isnan(novaTemp) && (isnan(tempAtual) || fabs(tempAtual - novaTemp) >= 0.1f)) {
    tempAtual = novaTemp;
    atualizarOLED = true;
  }

  if (!isnan(novaUmid) && (isnan(umidAtual) || fabs(umidAtual - novaUmid) >= 1.0f)) {
    umidAtual = novaUmid;
    atualizarOLED = true;
  }

  // Pulso visual: pisca o indicador de "sensor vivo" a cada leitura válida,
  // mesmo quando o valor não mudou o suficiente para atualizar temp/umidade.
  // Fica ao lado de "Interna" na TELA_PRINCIPAL — só força redesenho quando
  // essa tela está de fato aberta.
  if (!isnan(novaTemp) && !isnan(novaUmid)) {
    pulsoSensorAtivo = true;
    pulsoSensorAte = millis() + DURACAO_PULSO_SENSOR_MS;
    if (telaSelecionada == TELA_PRINCIPAL && estadoOLED == OLED_EXIBINDO) {
      atualizarOLED = true;
    }
  }
}

// Apaga o indicador de "sensor vivo" quando o tempo do pulso expira
// (chamada a cada loop(), complementa a ativação feita em atualizarDHT()).
void atualizarPulsoSensor() {
  if (pulsoSensorAtivo && millis() >= pulsoSensorAte) {
    pulsoSensorAtivo = false;
    if (telaSelecionada == TELA_PRINCIPAL && estadoOLED == OLED_EXIBINDO) {
      atualizarOLED = true;
    }
  }
}

// Lê o sensor externo (fora da estufa, GPIO16). Independente do sensor
// interno — mesmo critério de "só aceita se mudou o suficiente" e o mesmo
// esquema de pulso visual (bolinha ao lado de "Externa"), mas com seu
// próprio indicador (pulsoSensorExternoAtivo), já que é um sensor separado.
void atualizarDHTExterno() {
  if (millis() - ultimoDHTExterno < INTERVALO_DHT_EXTERNO_MS) return;
  ultimoDHTExterno = millis();

  float novaTemp = dhtExterno.readTemperature();
  float novaUmid = dhtExterno.readHumidity();
  bool mudou = false;

  if (!isnan(novaTemp) && (isnan(tempExterna) || fabs(tempExterna - novaTemp) >= 0.1f)) {
    tempExterna = novaTemp;
    mudou = true;
  }

  if (!isnan(novaUmid) && (isnan(umidExterna) || fabs(umidExterna - novaUmid) >= 1.0f)) {
    umidExterna = novaUmid;
    mudou = true;
  }

  if (mudou && telaSelecionada == TELA_PRINCIPAL && estadoOLED == OLED_EXIBINDO) {
    atualizarOLED = true;
  }

  if (!isnan(novaTemp) && !isnan(novaUmid)) {
    pulsoSensorExternoAtivo = true;
    pulsoSensorExternoAte = millis() + DURACAO_PULSO_SENSOR_MS;
    if (telaSelecionada == TELA_PRINCIPAL && estadoOLED == OLED_EXIBINDO) {
      atualizarOLED = true;
    }
  }
}

// Apaga o indicador de "sensor vivo" externo quando o tempo do pulso expira
// (chamada a cada loop(), complementa a ativação feita em atualizarDHTExterno()).
void atualizarPulsoSensorExterno() {
  if (pulsoSensorExternoAtivo && millis() >= pulsoSensorExternoAte) {
    pulsoSensorExternoAtivo = false;
    if (telaSelecionada == TELA_PRINCIPAL && estadoOLED == OLED_EXIBINDO) {
      atualizarOLED = true;
    }
  }
}

// ---------------- SOLO ----------------
void atualizarSolo() {
  if (millis() - ultimoSolo < INTERVALO_SOLO_MS) return;
  ultimoSolo = millis();

  // Faz várias leituras e tira a média: o ADC do ESP32 é ruidoso, uma
  // leitura única pode oscilar bastante entre ciclos.
  long soma = 0;
  for (int i = 0; i < SOLO_AMOSTRAS; i++) {
    soma += analogRead(SOIL_PIN);
    delayMicroseconds(200);
  }
  int leitura = soma / SOLO_AMOSTRAS;

  // Mapeamento invertido e calibrado: ADC alto (perto de SOLO_ADC_SECO) =
  // 0% (seco); ADC baixo (perto de SOLO_ADC_UMIDO) = 100% (úmido). Isso é o
  // oposto de um map(leitura, 0, 4095, 0, 100) direto, que é o que geralmente
  // "funciona por acaso" na simulação mas fica invertido no sensor real.
  int percentual = map(leitura, SOLO_ADC_SECO, SOLO_ADC_UMIDO, 0, 100);
  percentual = constrain(percentual, 0, 100);

  if (percentual != soloPercentual) {
    soloPercentual = percentual;
    soloBruto = leitura;
    atualizarOLED = true;
    Serial.print("Solo - ADC bruto: ");
    Serial.print(soloBruto);
    Serial.print(" | Umidade: ");
    Serial.print(soloPercentual);
    Serial.println("%");
  }

  // Pulso visual: pisca a cada ciclo de leitura, mesmo sem mudança de valor
  // (mesmo padrão dos sensores de temperatura/umidade internos e externos).
  pulsoSensorSoloAtivo = true;
  pulsoSensorSoloAte = millis() + DURACAO_PULSO_SENSOR_MS;
  if (telaSelecionada == TELA_PRINCIPAL && estadoOLED == OLED_EXIBINDO) {
    atualizarOLED = true;
  }
}

// Apaga o indicador de "sensor vivo" do solo quando o tempo do pulso expira
// (chamada a cada loop(), complementa a ativação feita em atualizarSolo()).
void atualizarPulsoSensorSolo() {
  if (pulsoSensorSoloAtivo && millis() >= pulsoSensorSoloAte) {
    pulsoSensorSoloAtivo = false;
    if (telaSelecionada == TELA_PRINCIPAL && estadoOLED == OLED_EXIBINDO) {
      atualizarOLED = true;
    }
  }
}

// ---------------- CULTIVO ----------------
// Regra fixa do fotoperíodo (horários "cravados" no código — se precisar de
// horários configuráveis pelo usuário no futuro, é aqui que entraria essa
// lógica):
//   Vegetativo: luz ligada das 16h às 10h (18h de luz / 6h de escuro)
//   Floração:   luz ligada das 19h às 7h  (12h de luz / 12h de escuro)
bool luzDeveEstarLigada(const DateTime& now) {
  int hora = now.hour();

  if (modoAtual == VEGETACAO) {
    return (hora >= 16 || hora < 10);
  }

  return (hora >= 19 || hora < 7);
}

// Aplica o estado da luz a cada loop(): respeita o override manual quando
// ativo (ver modoControleLuz), mas SEM PARAR de calcular luzDeveEstarLigada()
// — é o que garante que o fotoperíodo "continua contando" por baixo do
// override e assume de novo automaticamente quando ele expira.
void controlarLuz(const DateTime& now) {
  // Expira o override manual sozinho após DURACAO_OVERRIDE_LUZ_MS, retomando
  // o controle automático sem qualquer ação do usuário.
  if (modoControleLuz != LUZ_AUTOMATICA && overrideLuzAte > 0 && millis() >= overrideLuzAte) {
    modoControleLuz = LUZ_AUTOMATICA;
    overrideLuzAte = 0;
    atualizarOLED = true;
  }

  bool estadoAnterior = estadoReles[RELE_LUZ];

  // luzDeveEstarLigada() é sempre calculado, mesmo em modo manual: o timer
  // do fotoperíodo nunca é pausado, só o resultado pode ser sobreposto.
  bool estadoAutomatico = luzDeveEstarLigada(now);
  bool novoEstado;

  switch (modoControleLuz) {
    case LUZ_MANUAL_LIGADA:
      novoEstado = true;
      break;
    case LUZ_MANUAL_DESLIGADA:
      novoEstado = false;
      break;
    case LUZ_AUTOMATICA:
    default:
      novoEstado = estadoAutomatico;
      break;
  }

  acionarRele(RELE_LUZ, novoEstado);

  if (estadoAnterior != novoEstado) {
    registrarEventoRele(RELE_LUZ, novoEstado, modoControleLuz == LUZ_AUTOMATICA ? "automatico_luz" : "override_manual_luz");
    atualizarOLED = true;
  }
}

// Calcula quanto tempo falta para a luz mudar de estado (ligar ou desligar),
// formatado como "HHhMMm". A matemática lida com o "embrulho" da meia-noite
// (ex: ciclo que começa às 19h e termina às 7h do dia seguinte) tratando os
// horários em minutos desde 00:00 e cobrindo os 4 casos possíveis:
// intervalo "normal" (início < fim) vs. "invertido" (atravessa a meia-noite),
// cada um com luz ligada ou desligada no momento da consulta.
String tempoRestante(const DateTime& now) {
  int minutosAgora = now.hour() * 60 + now.minute();

  int inicio;
  int fim;

  if (modoAtual == VEGETACAO) {
    inicio = 16 * 60;
    fim = 10 * 60;
  } else {
    inicio = 19 * 60;
    fim = 7 * 60;
  }

  bool ligado;
  int minutosRestantes;

  if (inicio < fim) {
    ligado = (minutosAgora >= inicio && minutosAgora < fim);

    if (ligado) {
      minutosRestantes = fim - minutosAgora;
    } else {
      if (minutosAgora < inicio) {
        minutosRestantes = inicio - minutosAgora;
      } else {
        minutosRestantes = (24 * 60 - minutosAgora) + inicio;
      }
    }
  } else {
    ligado = (minutosAgora >= inicio || minutosAgora < fim);

    if (ligado) {
      if (minutosAgora >= inicio) {
        minutosRestantes = (24 * 60 - minutosAgora) + fim;
      } else {
        minutosRestantes = fim - minutosAgora;
      }
    } else {
      minutosRestantes = inicio - minutosAgora;
    }
  }

  int horas = minutosRestantes / 60;
  int minutos = minutosRestantes % 60;

  char buffer[10];
  snprintf(buffer, sizeof(buffer), "%02dh%02dm", horas, minutos);
  return String(buffer);
}

// Calcula quanto tempo falta para o override manual da luz expirar (volta
// sozinho ao automático quando chega a 00h00m — ver controlarLuz()),
// formatado como "HHhMMm" igual tempoRestante(). Só faz sentido chamar
// enquanto modoControleLuz != LUZ_AUTOMATICA (overrideLuzAte > 0).
String tempoRestanteOverrideLuz() {
  unsigned long agora = millis();
  unsigned long restanteMs = (overrideLuzAte > agora) ? (overrideLuzAte - agora) : 0;
  unsigned long minutosRestantes = restanteMs / 60000UL;

  int horas = minutosRestantes / 60;
  int minutos = minutosRestantes % 60;

  char buffer[10];
  snprintf(buffer, sizeof(buffer), "%02dh%02dm", horas, minutos);
  return String(buffer);
}

// Botão físico dedicado (BOTAO_TRV_PIN) que libera a trava de acesso
// diretamente, sem precisar do app — mesmo debounce por software usado no
// botão do OLED, mas com sua própria lógica independente.
void atualizarBotaoAcesso() {
  static bool ultimoEstadoLido = HIGH;
  static bool estadoEstavel = HIGH;
  static unsigned long debounce = 0;
  static bool acionamentoProcessado = false;

  bool leitura = digitalRead(BOTAO_TRV_PIN);

  if (leitura != ultimoEstadoLido) {
    debounce = millis();
    ultimoEstadoLido = leitura;
  }

  if ((millis() - debounce) < DEBOUNCE_BOTAO_MS) {
    return;
  }

  if (leitura != estadoEstavel) {
    estadoEstavel = leitura;

    if (estadoEstavel == HIGH) {
      acionamentoProcessado = false;
    }
  }

  if (estadoEstavel == LOW && !acionamentoProcessado) {
      acionamentoProcessado = true;

      liberarAcessoPor(3000);
      beep(150);
      mostrarMensagemTemporaria("Porta Liberada", 1500);
      registrarEventoAcesso("botao", "liberado");
  }
}

// ---------------- HISTÓRICO (LittleFS) ----------------
// Monta e sobe o sistema de arquivos interno. Chamado uma vez no setup().
// LittleFS.begin(true) formata automaticamente na primeira vez (partição
// nova/vazia) — não precisa fazer nada manual antes.
void iniciarHistorico() {
  if (!LittleFS.begin(true)) {
    Serial.println("Falha ao montar LittleFS - historico desativado");
    historicoDisponivel = false;
    return;
  }

  historicoDisponivel = true;

  if (!LittleFS.exists(HISTORICO_ARQUIVO)) {
    File arquivo = LittleFS.open(HISTORICO_ARQUIVO, "w");
    if (arquivo) {
      arquivo.println(HISTORICO_CABECALHO);
      arquivo.close();
    }
    historicoTotalLinhas = 0;
    Serial.println("Historico: arquivo novo criado");
    return;
  }

  // Arquivo já existe (reinício) — conta quantas linhas de dados já tem
  // (descontando o cabeçalho), pra saber se precisa rotacionar logo de cara.
  File arquivo = LittleFS.open(HISTORICO_ARQUIVO, "r");
  int linhas = 0;
  if (arquivo) {
    while (arquivo.available()) {
      if (arquivo.readStringUntil('\n').length() > 0) linhas++;
    }
    arquivo.close();
  }
  historicoTotalLinhas = (linhas > 0) ? linhas - 1 : 0; // -1 = desconta o cabeçalho
  if (historicoTotalLinhas < 0) historicoTotalLinhas = 0;

  Serial.print("Historico: ");
  Serial.print(historicoTotalLinhas);
  Serial.println(" linha(s) existente(s)");
}

// Se o arquivo passou do limite, reescreve mantendo só as HISTORICO_MAX_LINHAS
// mais recentes. Como o histórico local é só um cache operacional, o limite
// fica estrito em 7 dias; o histórico completo mora na Hostinger.
//
// Processa o arquivo EM STREAMING (lê e já escreve linha por linha, num
// arquivo temporário) — nunca guarda o arquivo inteiro na memória. Um array
// com milhares de Strings não caberia com folga na RAM/pilha do ESP32.
void rotacionarHistoricoSeNecessario() {
  if (historicoTotalLinhas <= HISTORICO_MAX_LINHAS) return;

  int totalASaltar = historicoTotalLinhas - HISTORICO_MAX_LINHAS; // quantas linhas antigas descartar

  File leitura = LittleFS.open(HISTORICO_ARQUIVO, "r");
  if (!leitura) return;

  const char* ARQUIVO_TEMP = "/historico.tmp";
  File escrita = LittleFS.open(ARQUIVO_TEMP, "w");
  if (!escrita) {
    leitura.close();
    return;
  }

  escrita.println(HISTORICO_CABECALHO);
  leitura.readStringUntil('\n'); // descarta o cabeçalho da leitura

  int linhaAtual = 0;
  int gravadas = 0;
  while (leitura.available()) {
    String linha = leitura.readStringUntil('\n');
    if (linha.length() == 0) continue;
    linhaAtual++;
    if (linhaAtual <= totalASaltar) continue; // pula as mais antigas
    escrita.println(linha);
    gravadas++;
  }

  leitura.close();
  escrita.close();

  LittleFS.remove(HISTORICO_ARQUIVO);
  LittleFS.rename(ARQUIVO_TEMP, HISTORICO_ARQUIVO);

  historicoTotalLinhas = gravadas;
  Serial.print("Historico rotacionado - mantidas ");
  Serial.print(gravadas);
  Serial.println(" linha(s)");
}

// Grava uma linha com o "retrato" atual do sistema — sensores, luz, modo,
// relés manuais e trava. Chamada periodicamente (ver atualizarHistorico()).
void registrarHistorico() {
  if (!historicoDisponivel) return;

  File arquivo = LittleFS.open(HISTORICO_ARQUIVO, "a");
  if (!arquivo) {
    Serial.println("Historico: falha ao abrir arquivo para gravar");
    return;
  }

  String linha = obterTimestampAtual() + ",";
  linha += (isnan(tempAtual) ? "" : String(tempAtual, 1)) + ",";
  linha += (isnan(umidAtual) ? "" : String(umidAtual, 0)) + ",";
  linha += (isnan(tempExterna) ? "" : String(tempExterna, 1)) + ",";
  linha += (isnan(umidExterna) ? "" : String(umidExterna, 0)) + ",";
  linha += String(soloPercentual) + ",";
  linha += String(estadoReles[RELE_LUZ] ? 1 : 0) + ",";
  linha += String(modoAtual == VEGETACAO ? "vegetativo" : "floracao") + ",";
  linha += String(estadoReles[RELE_VENTILADOR] ? 1 : 0) + ",";
  linha += String(estadoReles[RELE_ENTRADA_AR_1] ? 1 : 0) + ",";
  linha += String(estadoReles[RELE_ENTRADA_AR_2] ? 1 : 0) + ",";
  linha += String(estadoReles[RELE_SAIDA_AR] ? 1 : 0) + ",";
  linha += String(acessoAtivo ? 1 : 0);

  arquivo.println(linha);
  arquivo.close();
  historicoTotalLinhas++;

  rotacionarHistoricoSeNecessario();
}

// Chamada a cada loop() — só grava no cache local a cada INTERVALO_HISTORICO_MS
// (10 min). Não envia mais nada pra Hostinger daqui (ver
// atualizarEnvioLeituraHostinger(), que roda no seu próprio intervalo,
// bem mais curto).
void atualizarHistorico() {
  if (millis() - ultimoRegistroHistoricoEm < INTERVALO_HISTORICO_MS) return;

  ultimoRegistroHistoricoEm = millis();
  registrarHistorico();
}

// Chamada a cada loop() — envia a leitura atual (temperatura/umidade
// interna e externa, solo) pra Hostinger a cada
// INTERVALO_ENVIO_LEITURA_HOSTINGER_MS (1 min), independente do registro
// local. Silenciosamente não faz nada sem WiFi (enviarJsonHostinger() já
// checa isso e retorna false).
void atualizarEnvioLeituraHostinger() {
  if (millis() - ultimoEnvioLeituraHostingerEm < INTERVALO_ENVIO_LEITURA_HOSTINGER_MS) return;

  ultimoEnvioLeituraHostingerEm = millis();
  enviarLeituraHostinger();
}

// Monta um JSON com as últimas "limite" linhas do histórico, pro dashboard
// desenhar o gráfico. Processa o arquivo EM STREAMING (mesmo motivo de
// rotacionarHistoricoSeNecessario(): nunca guarda um array de Strings do
// tamanho do arquivo inteiro) — usa historicoTotalLinhas (já conhecido) pra
// calcular quantas linhas do começo pular, sem precisar bufferizar nada.
String construirHistoricoJson(int limite) {
  if (!historicoDisponivel) return "[]";
  if (limite > historicoTotalLinhas) limite = historicoTotalLinhas;
  if (limite <= 0) return "[]";

  File arquivo = LittleFS.open(HISTORICO_ARQUIVO, "r");
  if (!arquivo) return "[]";

  arquivo.readStringUntil('\n'); // descarta o cabeçalho

  int totalASaltar = historicoTotalLinhas - limite;
  int linhaAtual = 0;
  int adicionadas = 0;

  String json = "[";
  while (arquivo.available()) {
    String linha = arquivo.readStringUntil('\n');
    if (linha.length() == 0) continue;
    linhaAtual++;
    if (linhaAtual <= totalASaltar) continue; // pula as mais antigas

    if (adicionadas > 0) json += ",";
    // Cada linha do CSV vira um array de campos no JSON (o próprio JS do
    // dashboard sabe a ordem das colunas — mesma ordem do HISTORICO_CABECALHO).
    json += "[";
    int campoInicio = 0;
    for (int pos = 0; pos <= linha.length(); pos++) {
      if (pos == linha.length() || linha[pos] == ',') {
        json += "\"" + linha.substring(campoInicio, pos) + "\"";
        if (pos != linha.length()) json += ",";
        campoInicio = pos + 1;
      }
    }
    json += "]";
    adicionadas++;
  }
  json += "]";

  arquivo.close();
  return json;
}

// ---------------- WEBSERVER LOCAL (dashboard) ----------------
// Monta o "retrato" atual do sistema em JSON — usado tanto pela rota
// /api/status quanto como corpo de resposta das rotas de ação (assim a
// página web já recebe o estado atualizado sem precisar de uma segunda
// requisição). Construção manual de string (sem lib externa tipo
// ArduinoJson) porque o formato é simples e fixo — evita mais uma
// dependência só pra isso.
// Nome curto da zona de VPD, pros mesmos limites usados em corParaVPD() —
// usado só pra exibição (dashboard); a lógica de cor do LED não depende
// desta função, cada uma calcula os limites de forma independente.
String zonaVPD(float vpd) {
  if (vpd < 0.4) return "Muito umido";
  if (vpd < 0.8) return "Propagacao/Veg inicial";
  if (vpd < 1.2) return "Veg tardio/Flora inicial";
  if (vpd < 1.6) return "Flora media/tardia";
  return "Muito seco";
}

String construirStatusJson() {
  DateTime now = obterAgora();

  String json = "{";
  json += "\"temp_interna\":" + (isnan(tempAtual) ? String("null") : String(tempAtual, 1)) + ",";
  json += "\"umid_interna\":" + (isnan(umidAtual) ? String("null") : String(umidAtual, 0)) + ",";
  json += "\"temp_externa\":" + (isnan(tempExterna) ? String("null") : String(tempExterna, 1)) + ",";
  json += "\"umid_externa\":" + (isnan(umidExterna) ? String("null") : String(umidExterna, 0)) + ",";
  if (isnan(tempAtual) || isnan(umidAtual)) {
    json += "\"vpd\":null,";
    json += "\"vpd_zona\":\"--\",";
  } else {
    float vpd = calcularVPD(tempAtual, umidAtual);
    json += "\"vpd\":" + String(vpd, 2) + ",";
    json += "\"vpd_zona\":\"" + zonaVPD(vpd) + "\",";
  }
  json += "\"solo_percentual\":" + String(soloPercentual) + ",";
  json += "\"solo_estado\":\"" + String(estadoSolo()) + "\",";
  json += "\"modo_cultivo\":\"" + String(modoAtual == VEGETACAO ? "vegetativo" : "floracao") + "\",";
  json += "\"luz_ligada\":" + String(estadoReles[RELE_LUZ] ? "true" : "false") + ",";
  json += "\"luz_modo\":\"" +
          String(modoControleLuz == LUZ_AUTOMATICA ? "auto" : (modoControleLuz == LUZ_MANUAL_LIGADA ? "ligada" : "desligada")) +
          "\",";
  json += "\"luz_tempo_restante\":\"" +
          (modoControleLuz != LUZ_AUTOMATICA ? tempoRestanteOverrideLuz() : tempoRestante(now)) + "\",";
  json += "\"reles\":{";
  json += "\"desumidificador\":" + String(estadoReles[RELE_DESUMIDIFICADOR] ? "true" : "false") + ",";
  json += "\"ventilador\":" + String(estadoReles[RELE_VENTILADOR] ? "true" : "false") + ",";
  json += "\"entrada_ar_1\":" + String(estadoReles[RELE_ENTRADA_AR_1] ? "true" : "false") + ",";
  json += "\"entrada_ar_2\":" + String(estadoReles[RELE_ENTRADA_AR_2] ? "true" : "false") + ",";
  json += "\"saida_ar\":" + String(estadoReles[RELE_SAIDA_AR] ? "true" : "false");
  json += "},";
  json += "\"acesso_ativo\":" + String(acessoAtivo ? "true" : "false") + ",";
  json += "\"wifi_conectado\":" + String(wifiConectado ? "true" : "false") + ",";
  json += "\"timestamp\":\"" + obterTimestampAtual() + "\"";
  json += "}";
  return json;
}

// Página única (HTML + CSS + JS, tudo embutido) — não depende de
// SPIFFS/LittleFS nem de internet pra carregar (nenhum CDN externo é
// usado). Ela consulta /api/status a cada 2s via fetch() e manda as ações
// pros endpoints /api/* abaixo. Fica na RAM (não em PROGMEM) de propósito —
// ver comentário em configurarServidorWeb() sobre por quê.
const char PAGINA_HTML[] = R"HTMLPAGE(<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cultivo</title>
<style>
  :root {
    color-scheme: dark;
    --bg: #101214;
    --card: #1b1e22;
    --card2: #191c1f;
    --texto: #eef0f2;
    --muted: #8b95a1;
    --verde: #22c55e;
    --verde-escuro: #14532d;
    --vermelho: #7f1d1d;
    --vermelho-texto: #fca5a5;
    --borda: #2a2e33;
  }
  * { box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    background: var(--bg);
    color: var(--texto);
    margin: 0;
    padding: 16px;
  }
  .wrap { max-width: 1180px; margin: 0 auto; }

  header.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
  }
  .brand { display: flex; align-items: center; gap: 8px; font-size: 19px; font-weight: 700; }
  .brand .titulo-curto { display: inline; }
  .brand .titulo-longo { display: none; }
  .conexao { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--muted); }
  .dot { width: 9px; height: 9px; border-radius: 50%; background: #444; flex: none; }
  .dot.on { background: var(--verde); box-shadow: 0 0 6px var(--verde); }
  .conexao-texto { display: none; }
  .btn-remoto {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: var(--texto);
    background: var(--card2);
    border: 1px solid var(--borda);
    border-radius: 999px;
    padding: 7px 12px;
    text-decoration: none;
    white-space: nowrap;
  }

  .card {
    background: var(--card);
    border-radius: 14px;
    padding: 14px;
  }
  .card-label { font-size: 12px; color: var(--muted); margin-bottom: 6px; }
  .card-valor { font-size: 20px; font-weight: 700; }
  .card-luz .card-valor { color: var(--verde); }

  .stats-mobile {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 14px;
  }
  .stats-desktop { display: none; }

  .vpd-card {
    background: var(--card);
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 20px;
  }
  .vpd-topo { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; }
  .vpd-label { font-size: 13px; color: var(--muted); display: flex; align-items: center; gap: 6px; }
  .vpd-label .vpd-label-desktop { display: none; }
  .vpd-valor { font-size: 20px; font-weight: 700; }
  .vpd-barra {
    position: relative;
    height: 10px;
    border-radius: 6px;
    overflow: visible;
    background: linear-gradient(to right,
      #AB0025 0%, #AB0025 20%,
      #FE69FF 20%, #FE69FF 40%,
      #0EAD31 40%, #0EAD31 60%,
      #DBCB0F 60%, #DBCB0F 80%,
      #AB0025 80%, #AB0025 100%);
  }
  .vpd-marcador {
    position: absolute;
    top: -4px;
    width: 3px;
    height: 18px;
    background: #fff;
    border-radius: 2px;
    box-shadow: 0 0 4px rgba(0,0,0,.6);
    left: 50%;
    transition: left .3s ease;
  }
  .vpd-rodape { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; gap: 10px; flex-wrap: wrap; }
  .vpd-escala { display: none; flex: 1; font-size: 11px; color: var(--muted); }
  .vpd-escala span { flex: 1; text-align: center; }
  .vpd-escala span:first-child { text-align: left; }
  .vpd-escala span:last-child { text-align: right; }
  .vpd-zona {
    background: var(--verde-escuro);
    color: var(--verde);
    font-size: 12px;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 999px;
  }

  section { margin-bottom: 22px; }
  section h2 {
    font-size: 12px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin: 0 0 10px;
    font-weight: 700;
  }

  .botoes { display: flex; gap: 8px; flex-wrap: wrap; }
  button, .btn-link {
    flex: 1;
    min-width: 90px;
    padding: 13px 10px;
    border: none;
    border-radius: 10px;
    background: var(--card2);
    color: var(--texto);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    text-align: center;
    text-decoration: none;
    display: block;
  }
  button.ativo { background: var(--verde); color: #05230f; }
  button.perigo {
    background: var(--vermelho);
    color: var(--vermelho-texto);
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
  }

  .switch-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--card);
    border-radius: 12px;
    padding: 13px 14px;
    margin-bottom: 8px;
  }
  .switch-row span { font-size: 14px; display: flex; align-items: center; gap: 6px; }
  .toggle {
    width: 46px; height: 26px;
    border-radius: 13px;
    background: #333;
    position: relative;
    border: none;
    flex: none;
    cursor: pointer;
  }
  .toggle.on { background: var(--verde); }
  .toggle .bola {
    position: absolute; top: 3px; left: 3px;
    width: 20px; height: 20px;
    border-radius: 50%;
    background: #fff;
    transition: left .15s;
  }
  .toggle.on .bola { left: 23px; }

  /* Histórico: no celular a tela é mais estreita que o gráfico, então em
     vez de espremer (o que deixaria os traços/números ilegíveis), o
     gráfico mantém o tamanho original e o contêiner ganha rolagem lateral
     — arrasta pro lado no celular pra ver o resto. No desktop, a coluna é
     larga o bastante e o gráfico cabe inteiro sem precisar rolar.
     min-width:0 é necessário porque itens de grid/flex, por padrão, não
     encolhem além do tamanho do conteúdo — sem isso, o contêiner "empurra"
     a página inteira pra ficar mais larga em vez de rolar por dentro. */
  .sec-historico { min-width: 0; }
  .grafico-scroll {
    width: 100%;
    min-width: 0;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: 14px;
  }
  #graficoHistorico {
    width: 600px;
    height: 160px;
    background: var(--card);
    border-radius: 14px;
    display: block;
  }

  .sec-exportar { display: none; }

  .status-linha { font-size: 12px; color: var(--muted); text-align: center; margin-top: 8px; }

  .conteudo-principal {
    display: grid;
    grid-template-areas:
      "modo"
      "luz"
      "reles"
      "acesso"
      "historico"
      "exportar";
    gap: 22px;
  }
  .sec-modo { grid-area: modo; }
  .sec-luz { grid-area: luz; }
  .sec-reles { grid-area: reles; }
  .sec-acesso { grid-area: acesso; }
  .sec-historico { grid-area: historico; }
  .sec-exportar { grid-area: exportar; }

  @media (min-width: 900px) {
    body { padding: 24px 32px; }
    .brand { font-size: 22px; }
    .brand .titulo-curto { display: none; }
    .brand .titulo-longo { display: inline; }
    .conexao-texto { display: inline; }

    .stats-mobile { display: none; }
    .stats-desktop {
      display: flex;
      gap: 12px;
      margin-bottom: 14px;
    }
    .stats-desktop .card { flex: 1; }
    .stats-desktop .card-valor { font-size: 22px; }

    .vpd-label .vpd-label-desktop { display: inline; }
    .vpd-escala { display: flex; }

    .sec-exportar { display: block; }

    .conteudo-principal {
      grid-template-columns: 2fr 1fr;
      grid-template-areas:
        "historico modo"
        "historico luz"
        "reles acesso"
        "reles exportar";
      column-gap: 28px;
    }
  }
</style>
</head>
<body>
<div class="wrap">

  <header class="topbar">
    <div class="brand">
      <span>&#127793;</span>
      <span class="titulo-curto">Cultivo</span>
      <span class="titulo-longo">Estufa &mdash; Cultivo</span>
    </div>
    <div class="conexao">
      <span class="dot" id="dotOnline"></span>
      <span class="conexao-texto" id="conexaoTexto">--</span>
      <a class="btn-remoto" href="https://powderblue-rhinoceros-609254.hostingersite.com" target="_blank" rel="noopener">&#127760; Site remoto</a>
    </div>
  </header>

  <div class="stats-mobile">
    <div class="card"><div class="card-label">Interna</div><div class="card-valor" id="statInternaMobile">-- &middot; --</div></div>
    <div class="card"><div class="card-label">Externa</div><div class="card-valor" id="statExternaMobile">-- &middot; --</div></div>
    <div class="card"><div class="card-label">Solo</div><div class="card-valor" id="statSoloMobile">--</div></div>
    <div class="card card-luz"><div class="card-label">Luz</div><div class="card-valor" id="statLuzMobile">--</div></div>
  </div>

  <div class="stats-desktop">
    <div class="card"><div class="card-label">Temp. interna</div><div class="card-valor" id="statTempInterna">--</div></div>
    <div class="card"><div class="card-label">Umid. interna</div><div class="card-valor" id="statUmidInterna">--</div></div>
    <div class="card"><div class="card-label">Temp. externa</div><div class="card-valor" id="statTempExterna">--</div></div>
    <div class="card"><div class="card-label">Umid. externa</div><div class="card-valor" id="statUmidExterna">--</div></div>
    <div class="card"><div class="card-label">Solo</div><div class="card-valor" id="statSolo">--</div></div>
    <div class="card card-luz"><div class="card-label">&#9728; Luz</div><div class="card-valor" id="statLuz">--</div></div>
  </div>

  <div class="vpd-card">
    <div class="vpd-topo">
      <span class="vpd-label">&#128274; VPD <span class="vpd-label-desktop">(d&eacute;ficit de press&atilde;o de vapor)</span></span>
      <span class="vpd-valor" id="vpdValor">-- kPa</span>
    </div>
    <div class="vpd-barra">
      <div class="vpd-marcador" id="vpdMarcador"></div>
    </div>
    <div class="vpd-rodape">
      <div class="vpd-escala"><span>0</span><span>0,4</span><span>0,8</span><span>1,2</span><span>1,6</span><span>2,0+</span></div>
      <div class="vpd-zona" id="vpdZonaPill">--</div>
    </div>
  </div>

  <div class="conteudo-principal">

    <section class="sec-historico">
      <h2>Hist&oacute;rico (&uacute;ltimas 24h)</h2>
      <div class="grafico-scroll">
        <canvas id="graficoHistorico" width="600" height="160"></canvas>
      </div>
    </section>

    <section class="sec-modo">
      <h2>Modo de Cultivo</h2>
      <div class="botoes">
        <button id="btnVega" onclick="setModoCultivo('vegetativo')">Vegetativo</button>
        <button id="btnFlora" onclick="setModoCultivo('floracao')">Flora&ccedil;&atilde;o</button>
      </div>
    </section>

    <section class="sec-luz">
      <h2>Controle da Luz</h2>
      <div class="botoes">
        <button id="btnLuzAuto" onclick="setLuzManual('auto')">Autom&aacute;tico</button>
        <button id="btnLuzOn" onclick="setLuzManual('ligada')">Ligar</button>
        <button id="btnLuzOff" onclick="setLuzManual('desligada')">Desligar</button>
      </div>
    </section>

    <section class="sec-reles">
      <h2>Rel&eacute;s</h2>
      <div class="switch-row"><span>Desumidificador</span><button class="toggle" id="tglDesumidificador" onclick="toggleRele(1,'tglDesumidificador')"><div class="bola"></div></button></div>
      <div class="switch-row"><span>Ventilador</span><button class="toggle" id="tglVentilador" onclick="toggleRele(2,'tglVentilador')"><div class="bola"></div></button></div>
      <div class="switch-row"><span>Entrada de Ar 1</span><button class="toggle" id="tglEntrada1" onclick="toggleRele(4,'tglEntrada1')"><div class="bola"></div></button></div>
      <div class="switch-row"><span>Entrada de Ar 2</span><button class="toggle" id="tglEntrada2" onclick="toggleRele(5,'tglEntrada2')"><div class="bola"></div></button></div>
      <div class="switch-row"><span>Sa&iacute;da de Ar</span><button class="toggle" id="tglSaida" onclick="toggleRele(7,'tglSaida')"><div class="bola"></div></button></div>
    </section>

    <section class="sec-acesso">
      <h2>Acesso</h2>
      <button class="perigo" onclick="abrirPorta()">&#128275; Liberar Porta (3s)</button>
    </section>

    <section class="sec-exportar">
      <h2>Exportar</h2>
      <a class="btn-link" href="/api/historico/csv">&#11015; Baixar CSV completo</a>
    </section>

  </div>

  <div class="status-linha" id="statusLinha">conectando...</div>

</div>

<script>
async function atualizarStatus() {
  try {
    const r = await fetch('/api/status');
    const d = await r.json();

    const tI = (d.temp_interna===null?'--':d.temp_interna.toFixed(1).replace('.',',')+'\u00b0C');
    const uI = (d.umid_interna===null?'--':d.umid_interna+'%');
    const tE = (d.temp_externa===null?'--':d.temp_externa.toFixed(1).replace('.',',')+'\u00b0C');
    const uE = (d.umid_externa===null?'--':d.umid_externa+'%');
    const soloTxt = d.solo_estado + ' ' + d.solo_percentual + '%';
    const luzTxt = (d.luz_modo==='auto' ? '' : 'LM ') + d.luz_tempo_restante;

    document.getElementById('statInternaMobile').textContent = tI + ' \u00b7 ' + uI;
    document.getElementById('statExternaMobile').textContent = tE + ' \u00b7 ' + uE;
    document.getElementById('statSoloMobile').textContent = soloTxt;
    document.getElementById('statLuzMobile').textContent = luzTxt;

    document.getElementById('statTempInterna').textContent = tI;
    document.getElementById('statUmidInterna').textContent = uI;
    document.getElementById('statTempExterna').textContent = tE;
    document.getElementById('statUmidExterna').textContent = uE;
    document.getElementById('statSolo').textContent = soloTxt;
    document.getElementById('statLuz').textContent = luzTxt;

    document.getElementById('vpdValor').textContent = (d.vpd===null ? '--' : d.vpd.toFixed(2).replace('.',',') + ' kPa');
    document.getElementById('vpdZonaPill').textContent = d.vpd_zona || '--';
    const pct = (d.vpd===null) ? 50 : Math.max(0, Math.min(100, (d.vpd / 2.0) * 100));
    document.getElementById('vpdMarcador').style.left = pct + '%';

    document.getElementById('btnVega').classList.toggle('ativo', d.modo_cultivo==='vegetativo');
    document.getElementById('btnFlora').classList.toggle('ativo', d.modo_cultivo==='floracao');

    document.getElementById('btnLuzAuto').classList.toggle('ativo', d.luz_modo==='auto');
    document.getElementById('btnLuzOn').classList.toggle('ativo', d.luz_modo==='ligada');
    document.getElementById('btnLuzOff').classList.toggle('ativo', d.luz_modo==='desligada');

    setToggle('tglDesumidificador', d.reles.desumidificador);
    setToggle('tglVentilador', d.reles.ventilador);
    setToggle('tglEntrada1', d.reles.entrada_ar_1);
    setToggle('tglEntrada2', d.reles.entrada_ar_2);
    setToggle('tglSaida', d.reles.saida_ar);

    document.getElementById('dotOnline').classList.toggle('on', d.wifi_conectado);
    document.getElementById('conexaoTexto').textContent = 'cultivo.local \u2014 atualizado ' + d.timestamp.substring(11);
    document.getElementById('statusLinha').textContent = 'atualizado ' + d.timestamp + (d.wifi_conectado ? '' : ' (sem wifi)');
  } catch (e) {
    document.getElementById('statusLinha').textContent = 'sem conexao com o dispositivo';
    document.getElementById('dotOnline').classList.remove('on');
  }
}

function setToggle(id, ligado) {
  document.getElementById(id).classList.toggle('on', ligado);
}

async function setModoCultivo(valor) {
  await fetch('/api/modo_cultivo?valor=' + valor);
  atualizarStatus();
}

async function setLuzManual(modo) {
  await fetch('/api/luz_manual?modo=' + modo);
  atualizarStatus();
}

async function toggleRele(id, elId) {
  const ligado = document.getElementById(elId).classList.contains('on');
  await fetch('/api/rele?id=' + id + '&estado=' + (ligado ? 0 : 1));
  atualizarStatus();
}

async function abrirPorta() {
  await fetch('/api/acesso');
  atualizarStatus();
}

async function atualizarGraficoHistorico() {
  try {
    const r = await fetch('/api/historico/json?limite=144');
    const linhas = await r.json();
    desenharGrafico(linhas);
  } catch (e) {
    // silencioso
  }
}

function desenharGrafico(linhas) {
  const canvas = document.getElementById('graficoHistorico');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const w = canvas.width, h = canvas.height;
  ctx.clearRect(0, 0, w, h);

  if (!linhas || linhas.length < 2) {
    ctx.fillStyle = '#666';
    ctx.font = '13px sans-serif';
    ctx.fillText('Sem dados suficientes ainda', 12, h / 2);
    return;
  }

  const temps = linhas.map(l => parseFloat(l[1])).filter(v => !isNaN(v));
  if (temps.length < 2) {
    ctx.fillStyle = '#666';
    ctx.fillText('Sem leituras de temperatura ainda', 12, h / 2);
    return;
  }

  const minT = Math.min(...temps), maxT = Math.max(...temps);
  const margem = 12;
  const faixa = Math.max(maxT - minT, 1);

  ctx.strokeStyle = '#22c55e';
  ctx.lineWidth = 2;
  ctx.beginPath();
  let primeiro = true;
  linhas.forEach((linha, i) => {
    const v = parseFloat(linha[1]);
    if (isNaN(v)) return;
    const x = (i / (linhas.length - 1)) * (w - margem * 2) + margem;
    const y = h - margem - ((v - minT) / faixa) * (h - margem * 2);
    if (primeiro) { ctx.moveTo(x, y); primeiro = false; }
    else ctx.lineTo(x, y);
  });
  ctx.stroke();

  ctx.fillStyle = '#8b95a1';
  ctx.font = '11px sans-serif';
  ctx.fillText(maxT.toFixed(1) + '\u00b0C', 4, 14);
  ctx.fillText(minT.toFixed(1) + '\u00b0C', 4, h - 6);
}

atualizarStatus();
setInterval(atualizarStatus, 2000);
atualizarGraficoHistorico();
setInterval(atualizarGraficoHistorico, 60000);
</script>
</body>
</html>
)HTMLPAGE";

// Liga o responder mDNS, permitindo acessar o dashboard em
// http://cultivo.local/ em vez de precisar saber o IP, e também anuncia o
// serviço de OTA (ver iniciarOTA()) — precisa reanunciar os dois toda vez,
// porque MDNS.end()/begin() reseta TODOS os serviços registrados antes.
// Chamada de dois lugares: uma vez no setup() (boot normal com WiFi já
// conectado) e de novo sempre que atualizarConexaoWiFi() detectar uma
// reconexão (o IP pode ter mudado, e o responder mDNS do ESP32 não se
// adapta sozinho a isso — precisa reiniciar).
void iniciarMDNS() {
  MDNS.end(); // seguro chamar mesmo se nunca foi iniciado antes
  if (MDNS.begin(MDNS_HOSTNAME)) {
    MDNS.addService("http", "tcp", 80);
    MDNS.enableArduino(3232, true); // reanuncia o serviço de OTA (porta padrão do ArduinoOTA)
    Serial.print("mDNS ativo: http://");
    Serial.print(MDNS_HOSTNAME);
    Serial.println(".local/");
  } else {
    Serial.println("Falha ao iniciar mDNS (dashboard ainda funciona pelo IP)");
  }
}

// Liga o serviço de OTA (atualização de firmware pela rede WiFi) — depois
// disso, dá pra gravar o ESP32 direto do PlatformIO/Arduino IDE sem cabo
// USB, usando a porta 3232 (padrão) e a senha OTA_PASSWORD. Chamada uma
// única vez no setup(), depois que o mDNS já está de pé (usa o mesmo
// responder — ver setMdnsEnabled(false) abaixo, pra não duplicar/conflitar).
void iniciarOTA() {
  ArduinoOTA.setHostname(MDNS_HOSTNAME);
  ArduinoOTA.setPassword(OTA_PASSWORD);
  ArduinoOTA.setMdnsEnabled(false); // mDNS já foi iniciado por iniciarMDNS() — evita duplicar

  ArduinoOTA.onStart([]() {
    otaEmAndamento = true;
    String tipo = (ArduinoOTA.getCommand() == U_FLASH) ? "firmware" : "sistema de arquivos";
    Serial.println("OTA: iniciando atualizacao de " + tipo);
    // Deixa tudo num estado seguro antes de começar: desliga a trava (não
    // queremos ela destravada durante os ~30s+ que a atualização leva) e
    // avisa na tela pra ninguém desligar o equipamento no meio do processo.
    mensagemInicializacao("Atualizando...", "Nao desligue!");
  });

  ArduinoOTA.onEnd([]() {
    Serial.println("OTA: concluido, reiniciando");
    mensagemInicializacao("Atualizado!", "Reiniciando...");
  });

  ArduinoOTA.onProgress([](unsigned int progresso, unsigned int total) {
    static int ultimoPct = -1;
    int pct = (total > 0) ? (progresso * 100) / total : 0;
    if (pct == ultimoPct) return; // só atualiza a tela quando o % muda, pra não atrasar a transferência
    ultimoPct = pct;
    Serial.printf("OTA: %d%%\n", pct);
    mensagemInicializacao("Atualizando...", String(pct) + "%");
  });

  ArduinoOTA.onError([](ota_error_t erro) {
    otaEmAndamento = false;
    Serial.printf("OTA: erro [%u]: ", (unsigned int)erro);
    if (erro == OTA_AUTH_ERROR) Serial.println("falha de autenticacao");
    else if (erro == OTA_BEGIN_ERROR) Serial.println("falha ao iniciar");
    else if (erro == OTA_CONNECT_ERROR) Serial.println("falha de conexao");
    else if (erro == OTA_RECEIVE_ERROR) Serial.println("falha ao receber dados");
    else if (erro == OTA_END_ERROR) Serial.println("falha ao finalizar");
    mensagemInicializacao("Erro na", "atualizacao OTA");
    delay(2000);
    atualizarOLED = true; // força redesenhar a tela normal de novo
  });

  ArduinoOTA.begin();
  Serial.print("OTA pronto - porta 3232, hostname ");
  Serial.println(MDNS_HOSTNAME);
}


// de falhas. Retorna true se a requisição pode prosseguir; se retornar
// false, já cuidou de mandar a resposta certa (401 ou 429) — quem chamar só
// precisa dar "return" na sequência.
bool autenticado(AsyncWebServerRequest *request) {
  if (webBloqueadoAte > 0 && millis() < webBloqueadoAte) {
    request->send(429, "text/plain", "Muitas tentativas — aguarde alguns minutos.");
    return false;
  }

  if (!request->authenticate(WEB_AUTH_USER, WEB_AUTH_PASS)) {
    webFalhasAutenticacao++;
    if (webFalhasAutenticacao >= MAX_FALHAS_AUTH) {
      webBloqueadoAte = millis() + BLOQUEIO_AUTH_MS;
      webFalhasAutenticacao = 0;
      Serial.println("Dashboard web: bloqueado temporariamente por excesso de tentativas de login");
    }
    request->requestAuthentication();
    return false;
  }

  webFalhasAutenticacao = 0; // login certo zera o contador de falhas
  return true;
}

// Registra todas as rotas HTTP e sobe o servidor. Chamada uma única vez, no
// setup(). As rotas de ação usam GET com querystring (em vez de POST/JSON no
// corpo) de propósito, pra ficar simples de testar até direto pela barra de
// endereço do navegador — não é o mais "RESTful", mas é pragmático para um
// dashboard local numa rede confiável.
//
// SEGURANÇA: todas as rotas abaixo exigem HTTP Basic Auth (usuário/senha em
// WEB_AUTH_USER/WEB_AUTH_PASS, ver topo do arquivo — TROQUE os valores
// padrão) e há um bloqueio temporário após tentativas repetidas erradas
// (ver autenticado()). Ainda assim, HTTP Basic Auth por si só NÃO é
// criptografado — dá pra fazer sniffing da senha numa rede não confiável.
// Se for expor esse dispositivo além da rede local (ex: pela internet via
// um túnel/proxy), use um transporte com HTTPS (o túnel cuida disso) e
// nunca exponha a porta 80 direto pra internet sem isso.
void configurarServidorWeb() {
  server.on("/", HTTP_GET, [](AsyncWebServerRequest *request) {
    if (!autenticado(request)) return;
    // send() simples (mesma sobrecarga já usada nas rotas de JSON abaixo, e
    // que já provou compilar sem erro) em vez de send_P()/beginResponse_P():
    // essas variantes "_P" têm assinaturas que mudam entre versões da lib
    // ESPAsyncWebServer, o que causava erro de compilação. Sem PROGMEM a
    // página fica na RAM em vez da flash (~6KB — irrelevante pro ESP32, que
    // tem centenas de KB de RAM livre), então não compensa insistir nisso.
    request->send(200, "text/html", PAGINA_HTML);
  });

  server.on("/api/status", HTTP_GET, [](AsyncWebServerRequest *request) {
    if (!autenticado(request)) return;
    request->send(200, "application/json", construirStatusJson());
  });

  server.on("/api/rele", HTTP_GET, [](AsyncWebServerRequest *request) {
    if (!autenticado(request)) return;
    if (!request->hasParam("id") || !request->hasParam("estado")) {
      request->send(400, "text/plain", "parametros id e estado sao obrigatorios");
      return;
    }

    int id = request->getParam("id")->value().toInt();
    int estado = request->getParam("estado")->value().toInt();

    bool idValido = false;
    for (int i = 0; i < TOTAL_RELES_MANUAIS; i++) {
      if (RELES_MANUAIS[i] == id) {
        idValido = true;
        break;
      }
    }
    if (!idValido) {
      request->send(400, "text/plain", "id de rele invalido");
      return;
    }

    aplicarComandoRele(id, estado);
    request->send(200, "application/json", construirStatusJson());
  });

  server.on("/api/modo_cultivo", HTTP_GET, [](AsyncWebServerRequest *request) {
    if (!autenticado(request)) return;
    if (!request->hasParam("valor")) {
      request->send(400, "text/plain", "parametro valor e obrigatorio");
      return;
    }
    String valor = request->getParam("valor")->value();
    definirModoCultivoRemoto(valor == "floracao" ? FLORACAO : VEGETACAO);
    request->send(200, "application/json", construirStatusJson());
  });

  server.on("/api/luz_manual", HTTP_GET, [](AsyncWebServerRequest *request) {
    if (!autenticado(request)) return;
    if (!request->hasParam("modo")) {
      request->send(400, "text/plain", "parametro modo e obrigatorio");
      return;
    }
    String modo = request->getParam("modo")->value();
    ModoControleLuz novoModo = LUZ_AUTOMATICA;
    if (modo == "ligada") novoModo = LUZ_MANUAL_LIGADA;
    else if (modo == "desligada") novoModo = LUZ_MANUAL_DESLIGADA;
    definirModoControleLuzRemoto(novoModo);
    request->send(200, "application/json", construirStatusJson());
  });

  server.on("/api/acesso", HTTP_GET, [](AsyncWebServerRequest *request) {
    if (!autenticado(request)) return;
    liberarAcessoRemoto();
    request->send(200, "application/json", construirStatusJson());
  });

  // Últimas N linhas do histórico, em JSON, pro dashboard desenhar o
  // gráfico. Parâmetro opcional ?limite=N (padrão 144 = ~24h, a 1
  // amostra/10min); teto de segurança em 300 amostras por requisição.
  server.on("/api/historico/json", HTTP_GET, [](AsyncWebServerRequest *request) {
    if (!autenticado(request)) return;
    int limite = 144;
    if (request->hasParam("limite")) {
      limite = request->getParam("limite")->value().toInt();
    }
    if (limite <= 0) limite = 144;
    if (limite > 300) limite = 300;
    request->send(200, "application/json", construirHistoricoJson(limite));
  });

  // Baixa o arquivo CSV completo (até HISTORICO_MAX_LINHAS linhas) — abre
  // direto no Excel/Planilhas Google.
  server.on("/api/historico/csv", HTTP_GET, [](AsyncWebServerRequest *request) {
    if (!autenticado(request)) return;
    if (!historicoDisponivel || !LittleFS.exists(HISTORICO_ARQUIVO)) {
      request->send(404, "text/plain", "historico indisponivel");
      return;
    }
    AsyncWebServerResponse *response = request->beginResponse(LittleFS, HISTORICO_ARQUIVO, "text/csv");
    response->addHeader("Content-Disposition", "attachment; filename=historico_cultivo.csv");
    request->send(response);
  });

  server.onNotFound([](AsyncWebServerRequest *request) {
    request->send(404, "text/plain", "nao encontrado");
  });

  server.begin();
  Serial.println("Servidor web iniciado (com autenticacao)");
}

// ---------------- SETUP ----------------
// OLED, RTC e SHT40 dividem o MESMO barramento I2C (SDA_PIN/SCL_PIN). Um
// dispositivo desligado mas ainda fisicamente ligado a esse barramento (ex:
// RTC sem alimentação) pode, através dos diodos de proteção internos dele,
// prender a linha SDA em nível baixo — travando a comunicação de QUALQUER
// outro dispositivo no mesmo barramento, incluindo o OLED (mesmo ele não
// tendo nada a ver com o RTC em si). É a explicação mais provável pro OLED
// não ligar quando o RTC está desconectado/desligado.
//
// Este é o truque clássico de "I2C bus recovery": pulsa o SCL manualmente
// até a linha SDA se soltar, e força uma condição de STOP, ANTES de chamar
// Wire.begin() de verdade. Não resolve 100% dos casos de hardware (se o RTC
// estiver curto-circuitando o barramento de outra forma, por exemplo), mas
// resolve o caso comum de "device desligado prendendo SDA em LOW".
void recuperarBarramentoI2C() {
  pinMode(SDA_PIN, INPUT_PULLUP);
  pinMode(SCL_PIN, OUTPUT);
  digitalWrite(SCL_PIN, HIGH);

  // Até 9 pulsos de clock (o suficiente pra "empurrar" um byte inteiro que
  // possa ter ficado pela metade num dispositivo travado) — para assim que
  // SDA soltar sozinho.
  for (int i = 0; i < 9; i++) {
    if (digitalRead(SDA_PIN) == HIGH) break;
    digitalWrite(SCL_PIN, LOW);
    delayMicroseconds(5);
    digitalWrite(SCL_PIN, HIGH);
    delayMicroseconds(5);
  }

  // Condição de STOP manual (SDA sobe enquanto SCL está em HIGH), deixando
  // o barramento num estado limpo e conhecido antes do Wire.begin() real.
  pinMode(SDA_PIN, OUTPUT);
  digitalWrite(SDA_PIN, LOW);
  delayMicroseconds(5);
  digitalWrite(SCL_PIN, HIGH);
  delayMicroseconds(5);
  digitalWrite(SDA_PIN, HIGH);
  delayMicroseconds(5);
}

// Helper usado só durante o boot, antes do sistema de telas normal estar
// ativo (cabeçalho/rodapé/estadoOLED ainda não existem nesse momento).
void mensagemInicializacao(const String& linha1, const String& linha2) {
  display.clearDisplay();
  display.setTextSize(1);
  display.setTextColor(SSD1306_WHITE);
  display.setCursor(0, 20);
  display.print(linha1);
  if (linha2.length() > 0) {
    display.setCursor(0, 34);
    display.print(linha2);
  }
  display.display();
}

// Sequência de inicialização, na ordem que realmente importa:
//   1) Periféricos "burros" primeiro (buzzer, relés — já garantindo que
//      começam desligados, respeitando RELE_ATIVO_EM_BAIXO).
//   2) Pinos de entrada (solo, botões) e leitura inicial de estado do botão.
//   3) Recuperação + inicialização do barramento I2C (recuperarBarramentoI2C()
//      + Wire.begin nos pinos SDA/SCL) — precisa vir ANTES de qualquer
//      dispositivo I2C (display, RTC, SHT40).
//   4) Display OLED (com retentativa), sensor de ar (DHT ou SHT40 conforme
//      WOKWI_SIMULATION).
//   5) RTC: detecta, verifica se o oscilador está rodando (bateria viva) e,
//      se não estiver, ajusta pra hora de compilação como ponto de partida.
//   6) Restaura relés/modo salvos na NVRAM do RTC (queda de energia anterior).
//   7) WiFi (bloqueante, só aqui) -> NTP -> sobe o dashboard web.
//   8) Feedback sonoro/visual de boot concluído e entra em OLED_DESCANSO.
void setup() {
  Serial.begin(115200);

  pinMode(BUZZER, OUTPUT);
  digitalWrite(BUZZER, LOW);

  for (int i = 0; i < TOTAL_RELES; i++) {
    pinMode(reles[i], OUTPUT);
    digitalWrite(reles[i], RELE_ATIVO_EM_BAIXO ? HIGH : LOW); // garante que iniciam desligados
    estadoReles[i] = false;
  }

  pinMode(SOIL_PIN, INPUT);
  pinMode(BOTAO_OLED_PIN, INPUT_PULLUP);
  pinMode(BOTAO_TRV_PIN, INPUT_PULLUP);
  ultimoEstadoBotao = digitalRead(BOTAO_OLED_PIN);
  estadoEstavelBotaoOLED = ultimoEstadoBotao;

  pixel.begin();
  pixel.show();

  recuperarBarramentoI2C(); // destrava o barramento antes de iniciar de vez (ver comentário acima)
  Wire.begin(SDA_PIN, SCL_PIN);

  bool displayOk = false;
  for (int tentativa = 0; tentativa < 3 && !displayOk; tentativa++) {
    displayOk = display.begin(SSD1306_SWITCHCAPVCC, SCREEN_ADDRESS);
    if (!displayOk) delay(50);
  }
  if (!displayOk) {
    Serial.println("Falha ao iniciar o display OLED (mesmo apos tentativas)");
  }
  display.setRotation(0);
  mensagemInicializacao("Iniciando...");

#if WOKWI_SIMULATION
  dht.begin();
#else
  sht40Disponivel = sht4.begin();
  if (sht40Disponivel) {
    sht4.setPrecision(SHT4X_HIGH_PRECISION);
    sht4.setHeater(SHT4X_NO_HEATER);
  }
#endif
  dhtExterno.begin(); // sensor externo (fora da estufa) — existe nos dois modos

  rtcDisponivel = rtc.begin();
  Serial.print("RTC detectado: ");
  Serial.println(rtcDisponivel ? "sim" : "NAO - verifique fiacao SDA/SCL");

  if (rtcDisponivel && !rtc.isrunning()) {
    // Oscilador do DS1307 parado (bateria de backup ausente/descarregada ou
    // primeira ligacao). Sem isso, rtc.now() fica travado em 2000-01-01
    // 00:00:00 e o codigo ignora esse valor por ser < 2024, resultando na
    // hora fixa de fallback (00:00). Ajustamos para a hora de compilacao
    // como ponto de partida; sera corrigido de verdade assim que o WiFi
    // sincronizar via NTP.
    Serial.println("RTC parado (sem bateria?). Ajustando para hora de compilacao...");
    rtc.adjust(DateTime(F(__DATE__), F(__TIME__)));
  }

  restaurarEstadoDaMemoria();
  iniciarHistorico();

  mensagemInicializacao("Conectando WiFi");
  ultimaTentativaWiFi = millis();
  wifiConectado = conectarWiFi();
  Serial.println(wifiConectado ? "WiFi conectado" : "Falha ao conectar WiFi");

  if (wifiConectado) {
    mensagemInicializacao("Sincronizando", "hora via NTP");
    horaSincronizada = sincronizarHoraNTP();
    iniciarMDNS();
    iniciarOTA();
  }

  // Servidor web local: sobe independente do WiFi ter conectado agora ou não
  // (fica escutando na porta 80 e passa a responder assim que houver IP —
  // inclusive depois de uma reconexão automática mais tarde).
  configurarServidorWeb();

  if (wifiConectado && horaSincronizada) {
    mensagemInicializacao("Sistema OK", "Hora sincronizada");
  } else if (rtcDisponivel) {
    mensagemInicializacao("Sistema OK", "Usando RTC local");
  } else {
    mensagemInicializacao("Sem RTC/WiFi", "Hora limitada");
  }

  beep(300);
  delay(1200);

  if (wifiConectado) {
    // Mostra o endereço do dashboard por alguns segundos no boot — primeiro
    // o nome mDNS (mais fácil de digitar/lembrar), depois o IP numérico
    // (necessário em situações onde ".local" não resolve — Windows sem
    // Bonjour instalado, PlatformIO fazendo upload OTA, etc.). Os dois
    // também continuam disponíveis no Serial, como antes.
    mensagemInicializacao("Dashboard:", String(MDNS_HOSTNAME) + ".local");
    Serial.print("Dashboard disponivel em: http://");
    Serial.print(MDNS_HOSTNAME);
    Serial.println(".local/ (ou pelo IP abaixo)");
    Serial.print("IP: http://");
    Serial.println(WiFi.localIP());
    delay(1800);

    mensagemInicializacao("IP:", WiFi.localIP().toString());
    delay(1800);
  }

  estadoOLED = OLED_DESCANSO;
  atualizarOLED = true;
  renderizarOLED(obterAgora());
}

// ---------------- LOOP ----------------
// Loop principal: tudo aqui é não-bloqueante (nenhum delay() longo), cada
// atualizarX() cuida do próprio "debounce"/intervalo internamente e decide
// sozinho se algo realmente precisa acontecer neste ciclo. Ordem sem
// dependências rígidas entre si, exceto: controlarLuz() e renderizarOLED()
// precisam do "now" mais recente, por isso vêm depois de obterAgora().
void loop() {
  ArduinoOTA.handle(); // processa upload de firmware, se algum estiver em andamento
  if (otaEmAndamento) {
    // Durante a atualização de verdade, pausa TODO o resto (relés, sensores,
    // servidor web) — evita qualquer interferência (ex: um relé mudando de
    // estado no meio da gravação da flash) e dá prioridade máxima de CPU
    // pra transferência não estourar timeout. Volta ao normal sozinho
    // (reboot em caso de sucesso; ou o onError já reseta a flag).
    return;
  }

  atualizarLEDVPD();            // cor do NeoPixel reflete a zona do VPD atual
  atualizarBuzzer();           // desliga o beep quando o tempo expira
  atualizarAcesso();           // fecha a trava quando o tempo expira
  atualizarMensagemTemporaria(); // fecha mensagens temporárias do OLED
  atualizarBotaoOLED();        // lê o botão de navegação (debounce + clique curto/longo)
  atualizarBotaoAcesso();      // lê o botão físico de liberar acesso
  atualizarTimeoutOLED();      // volta pra tela de descanso por inatividade
  atualizarDHT();              // lê temperatura/umidade do ar (a cada 2s)
  atualizarDHTExterno();       // lê temperatura/umidade externas (fora da estufa)
  atualizarPulsoSensor();      // apaga o indicador de "sensor vivo" (interno)
  atualizarPulsoSensorExterno(); // apaga o indicador de "sensor vivo" (externo)
  atualizarSolo();             // lê umidade do solo (a cada 1s, com média)
  atualizarPulsoSensorSolo();  // apaga o indicador de "sensor vivo" (solo)
  atualizarSincronizacaoHora(); // tenta NTP periodicamente até sincronizar
  atualizarConexaoWiFi();      // reconecta WiFi com backoff se cair
  atualizarHistorico();        // grava uma linha no CSV local a cada 10 min (cache de 7 dias)
  atualizarEnvioLeituraHostinger(); // envia a leitura atual pra Hostinger a cada 1 min
  atualizarFilaComandosHostinger(); // envia comandos enfileirados (rele/trava/modo/luz) pra Hostinger
  // (o dashboard web local não precisa de nenhuma chamada aqui — o ESPAsyncWebServer
  // roda por trás via AsyncTCP, então já responde sozinho a qualquer momento)

  // Mantém relógio/temporizadores visíveis atualizados mesmo sem mudança de sensor
  if (estadoOLED != OLED_DESCANSO && millis() - ultimoRefreshPeriodicoOLED >= INTERVALO_REFRESH_PERIODICO_MS) {
    ultimoRefreshPeriodicoOLED = millis();
    atualizarOLED = true;
  }

  // Anima a tela de descanso (sol/lua) em quadros curtos
  if (estadoOLED == OLED_DESCANSO && millis() - ultimoFrameAnimacaoDescanso >= INTERVALO_ANIMACAO_DESCANSO_MS) {
    ultimoFrameAnimacaoDescanso = millis();
    atualizarOLED = true;
  }

  DateTime now = obterAgora(); // uma única leitura de "agora" para o resto do ciclo

  controlarLuz(now);   // aplica fotoperíodo/override no relé de luz
  renderizarOLED(now);  // só efetivamente desenha se atualizarOLED == true
}
