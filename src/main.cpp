// =============================================================================
//  CULTIVO — automação de estufa (ESP32-S3)
// =============================================================================
//  Luz (fotoperíodo + override manual), exaustores, trava de acesso e sensores
//  de temperatura/umidade do ar e do solo. Interface local: OLED SSD1306 +
//  botão. Interface remota: dashboard na Hostinger (pasta hostinger/), que o
//  ESP32 consulta por polling HTTPS — não há servidor HTTP no dispositivo.
//  Gravação do firmware só por USB.
//
//  WOKWI_SIMULATION: 1 = simulador (DHT22; SSID "Wokwi-GUEST" no secrets.h)
//                    0 = hardware (SHT40 via I2C)
//
//  PINOS
//    I2C (OLED + RTC DS1307 + SHT40): SDA 8, SCL 9
//    Solo (ADC) 6 | DHT externo 16 | DHT interno 15 (só simulação)
//    Buzzer 17 | NeoPixel 4 (Wokwi) / 48 (placa) | Botão OLED 5 | Botão trava 19
//    Relés {42,41,40,39,38,37,36,35}:
//      0 Luz  1 Desumidificador  2 Ventilador  3 (vazio)
//      4 Entrada de Ar 1  5 Entrada de Ar 2  6 Trava  7 Saída de Ar
//
//  NVRAM do RTC: bytes 0-7 relés/modo/override da luz, 8-12 horários de luz.
//
//  CALIBRAR NO HARDWARE: SOLO_ADC_SECO/SOLO_ADC_UMIDO, RELE_ATIVO_EM_BAIXO.
// =============================================================================

// Necessário em .cpp (o .ino incluía automaticamente).
#include <Arduino.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <RTClib.h>
#define WOKWI_SIMULATION 0

// Versão enviada ao painel (tela Dispositivo). Atualize a cada nova gravação.
#define FIRMWARE_VERSAO "2.4.0"
#if !WOKWI_SIMULATION
#include <Adafruit_SHT4x.h>
#endif
#include <DHT.h>
#include <Adafruit_NeoPixel.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <LittleFS.h>
#include <ArduinoJson.h>
#include <Preferences.h>
#include <WiFiManager.h>
#include <time.h>
#include <math.h>

// Copie secrets.example.h para secrets.h (não versionado) e preencha.
#include "secrets.h"

// ---------------- WIFI E CONEXÃO COM O PAINEL ----------------
// O portal "SmartDemetra-Setup" (192.168.4.1) grava rede, endereço do painel e token
// na memória (NVS). Sem nada gravado, valem os valores do secrets.h.
const char* PORTAL_SSID = "SmartDemetra-Setup";
const unsigned long PORTAL_TIMEOUT_S = 300;         // fecha o portal após 5 min
const unsigned long BOTAO_PORTAL_MS = 3000;         // segurar o botão do OLED ao ligar
const unsigned long RETENTATIVA_VINCULO_MS = 30000;
const char* TOKEN_HW_PREFIXO = "sd_hw_";
Preferences prefs;
String cfgSsid, cfgSenha, cfgUrl, cfgToken, cfgVinculo;
unsigned long ultimaTentativaVinculoEm = 0;

// ---------------- HISTÓRICO LOCAL (LittleFS, CSV) ----------------
const char* HISTORICO_ARQUIVO = "/historico.csv";
const char* HISTORICO_CABECALHO =
  "timestamp,temp_interna,umid_interna,temp_externa,umid_externa,solo_percentual,"
  "luz,modo_cultivo,ventilador,entrada_ar_1,entrada_ar_2,saida_ar,acesso";
const unsigned long INTERVALO_HISTORICO_MS = 10UL * 60UL * 1000UL; // registra localmente a cada 10 min
// 7 dias a cada 10 min; o histórico completo fica na Hostinger.
const int HISTORICO_MAX_LINHAS = 1008;
unsigned long ultimoRegistroHistoricoEm = 0;
int historicoTotalLinhas = 0;   // contagem de linhas de dados (sem contar o cabeçalho)
bool historicoDisponivel = false; // LittleFS montado com sucesso

// Envio de leitura pra Hostinger (independente do histórico local).
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

// ---------------- HOSTINGER ----------------
const unsigned long HOSTINGER_HTTP_TIMEOUT_MS = 7000;
const unsigned long INTERVALO_ENVIO_COMANDOS_HOSTINGER_MS = 1000;
const int HOSTINGER_FILA_COMANDOS_MAX = 20;
String filaComandosHostinger[HOSTINGER_FILA_COMANDOS_MAX];
int filaComandosInicio = 0;
int filaComandosFim = 0;
int filaComandosTotal = 0;
unsigned long ultimoEnvioComandoHostingerEm = 0;

// Polling da fila de comandos do dashboard.
const unsigned long INTERVALO_POLL_COMANDOS_HOSTINGER_MS = 4UL * 1000UL; // 4 segundos
unsigned long ultimaConsultaComandosPendentesEm = 0;

// ---------------- PINOS ----------------
#define SDA_PIN 8
#define SCL_PIN 9

#if WOKWI_SIMULATION
#define DHTPIN 15
#define DHTTYPE DHT22
#endif

// Sensor externo: DHT11 no hardware, DHT22 no Wokwi.
#define DHT_EXT_PIN 16
#if WOKWI_SIMULATION
#define DHT_EXT_TYPE DHT22
#else
#define DHT_EXT_TYPE DHT11
#endif

#define SOIL_PIN 6

// ---------------- CALIBRAÇÃO DO SENSOR DE SOLO ----------------
// Seco = ADC alto, úmido = ADC baixo. Anote "soloBruto" no ar (SECO) e na
// água (UMIDO).
const int SOLO_ADC_SECO = 4095;   // calibrado: leitura bruta com o sensor seco
const int SOLO_ADC_UMIDO = 2240;  // calibrado: leitura bruta com o sensor em água
const int SOLO_AMOSTRAS = 8;      // nº de leituras médias por ciclo (reduz ruído do ADC)

#define BOTAO_OLED_PIN 5
#define BOTAO_TRV_PIN 19

#define BUZZER 17

// NeoPixel: LED externo no Wokwi, LED RGB da placa no hardware.
#if WOKWI_SIMULATION
#define LED_PIN 4
#else
#define LED_PIN 48
#endif

// Troque para false se os relés ligarem invertidos.
#define RELE_ATIVO_EM_BAIXO true

int reles[8] = {42, 41, 40, 39, 38, 37, 36, 35};
// Placas com PSRAM Octal (ex.: N16R8) reservam GPIO35-37: remapeie se for o caso.

// ---------------- ÍNDICES DOS RELÉS ----------------
const int TOTAL_RELES = 8;           // índices 0-7
const int RELE_LUZ = 0;              // ver controlarLuz()
const int RELE_DESUMIDIFICADOR = 1;  // "Desumidificador"
const int RELE_VENTILADOR = 2;       // "Ventilador"
const int RELE_VAZIO_2 = 3;          // não utilizado
const int RELE_ENTRADA_AR_1 = 4;     // "Entrada de Ar 1"
const int RELE_ENTRADA_AR_2 = 5;     // "Entrada de Ar 2"
const int RELE_ACESSO = 6;           // trava (pulso)
const int RELE_SAIDA_AR = 7;         // "Saida de Ar" (exaustor de saída)
const int PRIMEIRO_RELE_MANUAL = 1;  // menor índice de relé "manual" (usado só como limite de segurança)
const int CONTROLE_MODO_CULTIVO = -1; // sentinela: item de menu "Modo Cultivo" (não é relé de verdade)
const int CONTROLE_LUZ_MANUAL = -2;   // sentinela: item de menu "Luz Manual" (override, não é relé de verdade)

// Relés exibidos no menu e no dashboard (o índice 3 fica de fora).
const int RELES_MANUAIS[] = {RELE_DESUMIDIFICADOR, RELE_VENTILADOR, RELE_ENTRADA_AR_1, RELE_ENTRADA_AR_2, RELE_SAIDA_AR};
const int TOTAL_RELES_MANUAIS = sizeof(RELES_MANUAIS) / sizeof(RELES_MANUAIS[0]);

// ---------------- DISPLAY OLED ----------------
#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64
#define OLED_RESET -1
#define SCREEN_ADDRESS 0x3C
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, OLED_RESET);

// Painel com faixa amarela de 16px no topo.
const int OLED_ALTURA_CABECALHO = 16;                       // faixa amarela (linhas 0-15)
const int OLED_CONTEUDO_TOPO = OLED_ALTURA_CABECALHO + 2;    // margem de 2px após a costura

// ---------------- OBJETOS ----------------
RTC_DS1307 rtc;
#if WOKWI_SIMULATION
DHT dht(DHTPIN, DHTTYPE);
#else
Adafruit_SHT4x sht4 = Adafruit_SHT4x();
#endif
DHT dhtExterno(DHT_EXT_PIN, DHT_EXT_TYPE);
Adafruit_NeoPixel pixel(1, LED_PIN, NEO_GRB + NEO_KHZ800);

// ---------------- LED (cor = zona do VPD) ----------------
unsigned long tempoLED = 0; // efeito a cada 20ms
int brilho = 30;
int direcao = 5; // sentido do "respirar" (+ = aumentando, - = diminuindo)
const int LED_BRILHO_MIN = 30;  // nunca apaga de vez — sempre dá pra ver a cor
const int LED_BRILHO_MAX = 150;

// ---------------- CULTIVO ----------------
enum ModoCultivo { VEGETACAO, FLORACAO };
ModoCultivo modoAtual = VEGETACAO;

// Fotoperíodo: início configurável pelo dashboard, duração fixa (18/6 e 12/12).
const int DURACAO_LUZ_VEGETACAO_MIN = 18 * 60;
const int DURACAO_LUZ_FLORACAO_MIN = 12 * 60;
const int INICIO_LUZ_VEGETACAO_PADRAO_MIN = 16 * 60;
const int INICIO_LUZ_FLORACAO_PADRAO_MIN = 19 * 60;
int inicioLuzVegetacaoMin = INICIO_LUZ_VEGETACAO_PADRAO_MIN;
int inicioLuzFloracaoMin = INICIO_LUZ_FLORACAO_PADRAO_MIN;

// Override manual da luz: expira sozinho e o timer segue contando por baixo.
enum ModoControleLuz { LUZ_AUTOMATICA, LUZ_MANUAL_LIGADA, LUZ_MANUAL_DESLIGADA };
ModoControleLuz modoControleLuz = LUZ_AUTOMATICA;
unsigned long overrideLuzAte = 0;
const unsigned long DURACAO_OVERRIDE_LUZ_MS = 30UL * 60UL * 1000UL; // padrão, se o painel não enviar outra
int duracaoOverrideLuzMin = 30;  // 15, 30, 60 ou 120 (Configurações → Luz, salvo na memória do RTC)
unsigned long duracaoOverrideLuzMs() {
  return duracaoOverrideLuzMin > 0 ? (unsigned long)duracaoOverrideLuzMin * 60UL * 1000UL : DURACAO_OVERRIDE_LUZ_MS;
}

// ---------------- OLED / BOTÃO ----------------
// Clique curto: próxima tela/item. Clique longo: entra no menu/alterna item.
enum TelaOLED {
  TELA_PRINCIPAL,      // temperatura/umidade interna e externa
  TELA_CONFIGURACAO,    // menu com abas "Dispositivos" (relés) e "Status"
  TOTAL_TELAS_OLED
};

enum EstadoOLED {
  OLED_DESCANSO,   // tela de descanso
  OLED_MENU,       // aguardando clique longo
  OLED_EXIBINDO,   // exibindo uma TelaOLED
  OLED_MENSAGEM    // mensagem temporária centralizada (ex: "Modo: Floracao")
};

EstadoOLED estadoOLED = OLED_DESCANSO;
TelaOLED telaSelecionada = TELA_PRINCIPAL;
bool mensagemTemporariaAtiva = false;
unsigned long mensagemAte = 0;      // timestamp em que a mensagem temporária deve sumir
String linha1Mensagem = "";
bool atualizarOLED = true;          // "dirty flag": true = precisa redesenhar no próximo loop()
unsigned long oledVoltaDescansoEm = 0; // volta ao descanso (0 = desativado)
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
const unsigned long INTERVALO_REFRESH_PERIODICO_MS = 1000; // redesenho periódico
const unsigned long INTERVALO_ANIMACAO_DESCANSO_MS = 60;   // ~16 fps para a animação sol/lua
unsigned long ultimoFrameAnimacaoDescanso = 0;
int releSelecionadoControle = CONTROLE_MODO_CULTIVO; // item atualmente destacado no menu de configuração

// ---------------- SENSOR DE AR (DHT22 no Wokwi / SHT40 no hardware) ----------------
float tempAtual = NAN;
float umidAtual = NAN;
unsigned long ultimoDHT = 0;
const unsigned long INTERVALO_DHT_MS = 2000; // intervalo mínimo recomendado p/ DHT22; SHT40 aceita mais rápido
bool pulsoSensorAtivo = false;      // indicador visual de "sensor vivo" (bolinha ao lado de "Interna", na TELA_PRINCIPAL)
unsigned long pulsoSensorAte = 0;
const unsigned long DURACAO_PULSO_SENSOR_MS = 300; // por quanto tempo o pulso fica visível a cada leitura

// ---------------- SENSOR EXTERNO (DHT) ----------------
float tempExterna = NAN;
float umidExterna = NAN;
unsigned long ultimoDHTExterno = 0;
const unsigned long INTERVALO_DHT_EXTERNO_MS = 2000; // seguro tanto para DHT11 (>=1s) quanto DHT22 (>=2s)
bool pulsoSensorExternoAtivo = false;
unsigned long pulsoSensorExternoAte = 0;

// ---------------- SOLO ----------------
int soloBruto = 0;       // ADC bruto (útil p/ calibrar)
int soloPercentual = 0;  // 0 = seco, 100 = encharcado, já calibrado
unsigned long ultimoSolo = 0;
const unsigned long INTERVALO_SOLO_MS = 1000;
bool pulsoSensorSoloAtivo = false;  // indicador visual de "sensor vivo" (bolinha ao lado de "Solo", na TELA_PRINCIPAL)
unsigned long pulsoSensorSoloAte = 0;

// ---------------- BUZZER (não bloqueante) ----------------
bool buzzerLigado = false;
unsigned long buzzerDesligaEm = 0;

// ---------------- ACESSO (trava, pulso) ----------------
bool acessoAtivo = false;
unsigned long acessoDesligaEm = 0;

// ---------------- STATUS (conectividade e sincronismo) ----------------
bool rtcDisponivel = false;             // RTC DS1307 detectado no I2C
#if !WOKWI_SIMULATION
bool sht40Disponivel = false;           // SHT40 detectado (hardware)
#endif
bool wifiConectado = false;
bool horaSincronizada = false;          // NTP sincronizado desde o boot
unsigned long ultimaTentativaWiFi = 0;
unsigned long wifiDesconectadoDesde = 0;
unsigned long ultimaTentativaNTP = 0;
unsigned long ultimaInteracaoMenuEm = 0;      // marca a última interação local com o menu do OLED

// ---------------- ESTADO DOS RELÉS (escrito só por acionarRele()) ----------------
bool estadoReles[TOTAL_RELES] = {false, false, false, false, false, false, false, false};

// ---------------- PROTÓTIPOS ----------------
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
void salvarFotoperiodoNaMemoria();
void restaurarFotoperiodoDaMemoria();
void registrarEventoFotoperiodo(ModoCultivo modo, const char* origem);
void definirFotoperiodoRemoto(ModoCultivo modo, int inicioMin);
void mensagemInicializacao(const String& linha1, const String& linha2 = "");
void iniciarHistorico();
void registrarHistorico();
void rotacionarHistoricoSeNecessario();
void atualizarHistorico();
void atualizarComandosPendentesHostinger();
void aplicarComandoRemotoWeb(const String& tipo, const String& alvo, const String& valor);

// ---------------- VPD ----------------
// VPD (kPa) = 0.6108 * e^(17.27*T/(T+237.3)) * (1 - UR/100)
float calcularVPD(float tempC, float umidPercent) {
  float svp = 0.6108 * exp((17.27 * tempC) / (tempC + 237.3));
  return svp * (1.0 - umidPercent / 100.0);
}

// Zonas: <0.4 muito úmido | 0.4-0.8 propagação | 0.8-1.2 veg tardio/flora inicial
//        | 1.2-1.6 flora | >1.6 muito seco
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

// Cor pela zona do VPD com efeito de respiração (~50Hz).
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
    // Sem leitura ainda: branco fraco.
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

// Comando de relé vindo do dashboard; ignora se o estado já é o pedido.
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

// Mensagem centralizada por duracaoMs; depois volta à tela de descanso.
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

bool telaInterativa(TelaOLED tela) {
  return tela == TELA_CONFIGURACAO;
}

TelaOLED proximaTelaOLED(TelaOLED telaAtualBase) {
  int proximaTela = static_cast<int>(telaAtualBase) + 1;
  if (proximaTela >= TOTAL_TELAS_OLED) {
    proximaTela = 0;
  }
  return static_cast<TelaOLED>(proximaTela);
}

void abrirTelaRapidaOLED() {
  telaSelecionada = TELA_PRINCIPAL;
  estadoOLED = OLED_EXIBINDO;
  oledVoltaDescansoEm = millis() + TEMPO_EXIBICAO_OLED_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarOLED = true;
}

// Clique longo no menu: abre a lista de dispositivos no primeiro item.
void selecionarTelaOLED() {
  estadoOLED = OLED_EXIBINDO;
  if (telaSelecionada == TELA_CONFIGURACAO) {
    releSelecionadoControle = CONTROLE_MODO_CULTIVO;
  }
  oledVoltaDescansoEm = millis() + TEMPO_EXIBICAO_OLED_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarOLED = true;
}

// Volta à tela de descanso após o tempo sem interação.
void atualizarTimeoutOLED() {
  if (estadoOLED != OLED_DESCANSO && oledVoltaDescansoEm > 0 && millis() >= oledVoltaDescansoEm) {
    estadoOLED = OLED_DESCANSO;
    mensagemTemporariaAtiva = false;
    oledVoltaDescansoEm = 0;
    atualizarOLED = true;
  }
}

// Telas interativas param em OLED_MENU aguardando clique longo.
void avancarNavegacaoOLED() {
  telaSelecionada = proximaTelaOLED(telaSelecionada);
  estadoOLED = telaInterativa(telaSelecionada) ? OLED_MENU : OLED_EXIBINDO;
  oledVoltaDescansoEm = millis() + TEMPO_EXIBICAO_OLED_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarOLED = true;
}

// Clique curto: acorda o display, alterna Informações/Configurações ou avança item.
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

// Debounce + diferencia clique curto de longo.
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

// Ícone de WiFi desenhado pixel a pixel (vazio quando desconectado).
void desenharIconeConexao(int x, int y) {
  if (!wifiConectado) return;

  int cx = x + 4; // centro horizontal do ícone
  int cy = y + 7; // "base" do leque (pontinho fica aqui)

  // Pontinho central
  display.drawPixel(cx, cy, SSD1306_WHITE);

  // Arco interno
  display.drawPixel(cx - 2, cy - 2, SSD1306_WHITE);
  display.drawPixel(cx - 1, cy - 3, SSD1306_WHITE);
  display.drawPixel(cx,     cy - 3, SSD1306_WHITE);
  display.drawPixel(cx + 1, cy - 3, SSD1306_WHITE);
  display.drawPixel(cx + 2, cy - 2, SSD1306_WHITE);

  // Arco externo
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

// Cabeçalho comum: título, hora, modo (V/F) e WiFi; ocupa a faixa amarela.
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

  // Modo de cultivo abreviado: V = vegetativo, F = floração.
  display.setCursor(SCREEN_WIDTH - 24, y);
  display.print(modoAtual == VEGETACAO ? "V" : "F");

  desenharIconeConexao(SCREEN_WIDTH - 13, y);

  // Separador exatamente na costura entre a faixa amarela e a azul
  display.drawFastHLine(0, OLED_ALTURA_CABECALHO - 1, SCREEN_WIDTH, SSD1306_WHITE);
}

// Formato BR (vírgula); "--,-" se NAN.
String formatarTemperatura(float valor) {
  if (isnan(valor)) return "--,-";

  char buffer[10];
  dtostrf(valor, 4, 1, buffer);
  String texto = String(buffer);
  texto.trim();
  texto.replace(".", ",");
  return texto;
}

// Formato BR, 2 casas; "--" se NAN.
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

// ---------- tela principal: interna x externa + linha do solo ----------
// Rótulos curtos ("T:"/"U:") para caber em colunas de ~62px.
void desenharConteudoPrincipal() {
  const int xDivisor = 64;              // linha vertical central
  const int xColunaExterna = xDivisor + 4;
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

  // --- Última linha: status do solo ---
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

  // --- Separador vertical (não desce até a linha do solo) ---
  display.drawFastVLine(xDivisor, OLED_CONTEUDO_TOPO - 1, yDivisorSala - (OLED_CONTEUDO_TOPO - 1), SSD1306_WHITE);
}

// ---------- tela de configuração ----------
// Índice do item no menu -> código em releSelecionadoControle (CONTROLE_* ou relé).
int itemIndexParaRele(int indiceItem) {
  if (indiceItem == 0) return CONTROLE_MODO_CULTIVO;
  if (indiceItem == 1) return CONTROLE_LUZ_MANUAL;
  int posManual = indiceItem - 2;
  if (posManual < 0 || posManual >= TOTAL_RELES_MANUAIS) return CONTROLE_MODO_CULTIVO; // segurança
  return RELES_MANUAIS[posManual];
}

// Inverso de itemIndexParaRele().
int releParaItemIndex(int releCodigo) {
  for (int i = 0; i < TOTAL_RELES_MANUAIS; i++) {
    if (RELES_MANUAIS[i] == releCodigo) {
      return i + 2;
    }
  }
  return 0; // fallback
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

// Legenda "Dispositivos | Status" no topo da lista.
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

// Lista rolável centrada no item selecionado.
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

// ---------- confirmação para abrir a configuração ----------
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

// ---------- mensagem temporária (centralizada) ----------
void desenharMensagem() {
  display.setTextSize(1);
  int16_t x1, y1;
  uint16_t w, h;
  display.getTextBounds(linha1Mensagem, 0, 0, &x1, &y1, &w, &h);
  int centroY = OLED_CONTEUDO_TOPO + ((SCREEN_HEIGHT - OLED_CONTEUDO_TOPO) - (int)h) / 2;
  display.setCursor((SCREEN_WIDTH - (int)w) / 2, centroY);
  display.print(linha1Mensagem);
}

// ---------- tela de descanso: dados + sol/lua animados ----------
// A fonte padrão não tem acentos: textos do OLED sem acentuação; ° = char 248.

// Posição do ícone de sol/lua, na coluna direita, ao lado dos dados
const int OLED_DESCANSO_ICONE_CX = 104;
const int OLED_DESCANSO_ICONE_CY = 34;

// Luz ligada: sol pulsando com raios girando.
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

// Luz desligada: lua flutuando.
void desenharLua(int cx, int cy) {
  int r = 9;

  float t = millis() / 1000.0;
  int deslocY = (int)(sin(t * 0.8) * 1.5);

  display.fillCircle(cx, cy + deslocY, r, SSD1306_WHITE);
  display.fillCircle(cx + 5, cy - 3 + deslocY, r - 3, SSD1306_BLACK);
}

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
  // Grau 2px acima da linha do texto.
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

  // Tempo até a próxima troca da luz, ou do override manual ("LM: HHhMMm").
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

// ---------- renderização ----------
// Único ponto que chama display.display(); só redesenha se atualizarOLED.
void renderizarOLED(const DateTime& now) {
  if (!atualizarOLED) return;
  atualizarOLED = false;

  display.clearDisplay();

  if (estadoOLED == OLED_DESCANSO) {
    desenharTelaDescanso(now);
    display.display();
    return;
  }

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
// Único ponto que escreve nos pinos de relé (mantém estadoReles[] em sincronia).
void acionarRele(int numero, bool ligado) {
  estadoReles[numero] = ligado;
  int nivelAtivo = RELE_ATIVO_EM_BAIXO ? LOW : HIGH;
  int nivelInativo = RELE_ATIVO_EM_BAIXO ? HIGH : LOW;
  digitalWrite(reles[numero], ligado ? nivelAtivo : nivelInativo);
}

// Ciclo do menu: Modo Cultivo -> Luz Manual -> relés de RELES_MANUAIS.
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

// Clique longo: alterna o item selecionado (modo, luz ou relé).
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

// Ciclo do override: Automático -> Ligada -> Desligada.
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

  overrideLuzAte = (modoControleLuz == LUZ_AUTOMATICA) ? 0 : (millis() + duracaoOverrideLuzMs());

  const char* origem = (modoControleLuz == LUZ_AUTOMATICA) ? "retomou_automatico" : "override_manual";
  registrarEventoRele(RELE_LUZ, modoControleLuz == LUZ_MANUAL_LIGADA, origem);

  oledVoltaDescansoEm = millis() + TEMPO_EXIBICAO_OLED_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarOLED = true;
  salvarEstadoNaMemoria();
}

// Versão do dashboard: define o modo direto, sem mexer nos timers do menu OLED.
void definirModoControleLuzRemoto(ModoControleLuz novoModo) {
  if (novoModo == modoControleLuz) return; // nada mudou, evita gravar/piscar à toa

  modoControleLuz = novoModo;
  overrideLuzAte = (modoControleLuz == LUZ_AUTOMATICA) ? 0 : (millis() + duracaoOverrideLuzMs());

  const char* origem = (modoControleLuz == LUZ_AUTOMATICA) ? "retomou_automatico" : "override_manual";
  registrarEventoRele(RELE_LUZ, modoControleLuz == LUZ_MANUAL_LIGADA, origem);

  atualizarOLED = true;
  salvarEstadoNaMemoria();
}

// Nomes exibidos no OLED e enviados à Hostinger (o dashboard usa os mesmos).
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

// Alterna entre vegetação e floração (muda a janela de luz).
void alternarModoCultivo() {
  if (modoAtual == VEGETACAO) {
    modoAtual = FLORACAO;
    mostrarMensagemTemporaria("Modo: Floracao", 1500);
    registrarEventoModo("floracao", "manual");
    registrarEventoFotoperiodo(FLORACAO, "manual");
  } else {
    modoAtual = VEGETACAO;
    mostrarMensagemTemporaria("Modo: Vegetativo", 1500);
    registrarEventoModo("vegetativo", "manual");
    registrarEventoFotoperiodo(VEGETACAO, "manual");
  }

  oledVoltaDescansoEm = millis() + TEMPO_EXIBICAO_OLED_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarOLED = true;
  salvarEstadoNaMemoria();
}

// Versão do dashboard: define o modo direto.
void definirModoCultivoRemoto(ModoCultivo novoModo) {
  if (novoModo == modoAtual) return; // nada mudou, evita gravar/piscar à toa

  modoAtual = novoModo;
  mostrarMensagemTemporaria(modoAtual == FLORACAO ? "Modo: Floracao" : "Modo: Vegetativo", 1500);
  registrarEventoModo(modoAtual == FLORACAO ? "floracao" : "vegetativo", "web");
  registrarEventoFotoperiodo(modoAtual, "web"); // confirma ao painel o timer em uso na nova fase
  atualizarOLED = true;
  salvarEstadoNaMemoria();
}

// Pulso na trava; atualizarAcesso() fecha quando expira.
void liberarAcessoPor(unsigned long duracaoMs) {
  acionarRele(RELE_ACESSO, true);
  acessoAtivo = true;
  acessoDesligaEm = millis() + duracaoMs;
  atualizarOLED = true;
}

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
// [0] marcador  [1] bitmask relés manuais  [2] modo  [3] override luz
// [4..7] expiração do override em unixtime (millis() zera no boot).
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
    // Luz e trava não são restauradas.
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
    Serial.println("NVRAM sem marcador valido - mantendo padroes de fabrica");
    return;
  }

  uint8_t bitmaskReles = buffer[1];
  modoAtual = (buffer[2] == 1) ? FLORACAO : VEGETACAO;

  // Restaura só os relés manuais; a trava nunca volta ligada.
  for (int i = 0; i < TOTAL_RELES_MANUAIS; i++) {
    int idx = RELES_MANUAIS[i];
    bool ligado = bitmaskReles & (1 << idx);
    acionarRele(idx, ligado);
  }

  // Restaura o override se ainda não expirou; valida o byte contra lixo antigo.
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

// Fotoperíodo: bloco próprio com marcador (versões antigas caem no padrão).
//   [8] 0x5B  [9..10] início veg (min)  [11..12] início flora (min)
const uint8_t NVRAM_FOTO_ENDERECO = 8;
const uint8_t NVRAM_FOTO_TAMANHO = 5;
const uint8_t NVRAM_FOTO_MARCADOR = 0x5B;

bool inicioLuzValido(int minutos) {
  return minutos >= 0 && minutos < 24 * 60;
}

void salvarFotoperiodoNaMemoria() {
  if (!rtcDisponivel) return;
  uint8_t buffer[NVRAM_FOTO_TAMANHO];
  buffer[0] = NVRAM_FOTO_MARCADOR;
  buffer[1] = (uint8_t)(inicioLuzVegetacaoMin >> 8);
  buffer[2] = (uint8_t)(inicioLuzVegetacaoMin & 0xFF);
  buffer[3] = (uint8_t)(inicioLuzFloracaoMin >> 8);
  buffer[4] = (uint8_t)(inicioLuzFloracaoMin & 0xFF);
  rtc.writenvram(NVRAM_FOTO_ENDERECO, buffer, NVRAM_FOTO_TAMANHO);
}

void restaurarFotoperiodoDaMemoria() {
  if (!rtcDisponivel) return;
  uint8_t buffer[NVRAM_FOTO_TAMANHO];
  rtc.readnvram(buffer, NVRAM_FOTO_TAMANHO, NVRAM_FOTO_ENDERECO);
  if (buffer[0] != NVRAM_FOTO_MARCADOR) {
    Serial.println("NVRAM sem fotoperiodo salvo - usando horarios padrao (16h veg / 19h flora)");
    return;
  }
  int veg = ((int)buffer[1] << 8) | buffer[2];
  int flora = ((int)buffer[3] << 8) | buffer[4];
  if (inicioLuzValido(veg)) inicioLuzVegetacaoMin = veg;
  if (inicioLuzValido(flora)) inicioLuzFloracaoMin = flora;
  Serial.printf("Fotoperiodo restaurado: veg inicia %02d:%02d, flora inicia %02d:%02d\n",
                inicioLuzVegetacaoMin / 60, inicioLuzVegetacaoMin % 60,
                inicioLuzFloracaoMin / 60, inicioLuzFloracaoMin % 60);
}

// Duração do modo manual da luz: [13] 0x6C  [14] minutos.
const uint8_t NVRAM_LUZ_ENDERECO = 13;
const uint8_t NVRAM_LUZ_MARCADOR = 0x6C;

bool duracaoLuzValida(int minutos) {
  return minutos == 15 || minutos == 30 || minutos == 60 || minutos == 120;
}

void salvarDuracaoLuzNaMemoria() {
  if (!rtcDisponivel) return;
  uint8_t buffer[2] = { NVRAM_LUZ_MARCADOR, (uint8_t)duracaoOverrideLuzMin };
  rtc.writenvram(NVRAM_LUZ_ENDERECO, buffer, 2);
}

void restaurarDuracaoLuzDaMemoria() {
  if (!rtcDisponivel) return;
  uint8_t buffer[2];
  rtc.readnvram(buffer, 2, NVRAM_LUZ_ENDERECO);
  if (buffer[0] == NVRAM_LUZ_MARCADOR && duracaoLuzValida(buffer[1])) {
    duracaoOverrideLuzMin = buffer[1];
  }
  Serial.printf("Modo manual da luz: %d min\n", duracaoOverrideLuzMin);
}

// Confirma ao painel a duração em uso (ao ligar e após cada comando).
void registrarEventoDuracaoLuz(const char* origem) {
  enfileirarComandoHostinger("luz_duracao", "Luz", String(duracaoOverrideLuzMin), origem);
}

void definirDuracaoLuzRemoto(int minutos) {
  if (!duracaoLuzValida(minutos)) {
    Serial.printf("Hostinger: duracao do modo manual invalida: %d\n", minutos);
    return;
  }
  if (minutos != duracaoOverrideLuzMin) {
    duracaoOverrideLuzMin = minutos;
    salvarDuracaoLuzNaMemoria();
    // Um manual já ativo passa a valer pela nova duração, contada a partir de agora.
    if (modoControleLuz != LUZ_AUTOMATICA) overrideLuzAte = millis() + duracaoOverrideLuzMs();
  }
  registrarEventoDuracaoLuz("web");
}

// ---------------- CONFIGURAÇÃO DE REDE (NVS + portal) ----------------
// Token do hardware (sd_hw_...) se houver; senão a API_KEY do secrets.h (modo legado).
const char* chaveServidor() {
  return cfgToken.length() > 0 ? cfgToken.c_str() : SECRET_HOSTINGER_API_KEY;
}

void carregarConfigRede() {
  prefs.begin("sdemetra", true);
  cfgSsid = prefs.getString("ssid", SECRET_WIFI_SSID);
  cfgSenha = prefs.getString("senha", SECRET_WIFI_PASSWORD);
  cfgUrl = prefs.getString("url", SECRET_HOSTINGER_BASE_URL);
  cfgToken = prefs.getString("token", "");
  cfgVinculo = prefs.getString("vinculo", "");
  prefs.end();
  while (cfgUrl.endsWith("/")) cfgUrl.remove(cfgUrl.length() - 1);
}

void salvarConfigRede(const char* chave, const String& valor) {
  prefs.begin("sdemetra", false);
  if (valor.length() > 0) prefs.putString(chave, valor);
  else prefs.remove(chave);
  prefs.end();
}

bool redeConfigurada() {
  return cfgSsid.length() > 0 && cfgSsid != "SUA_REDE_WIFI";
}

// Botão do OLED pressionado por 3 s durante a inicialização abre o portal.
bool botaoPortalPressionado() {
  if (digitalRead(BOTAO_OLED_PIN) != LOW) return false;
  mensagemInicializacao("Solte para", "configurar Wi-Fi");
  unsigned long inicio = millis();
  while (digitalRead(BOTAO_OLED_PIN) == LOW) {
    if (millis() - inicio >= BOTAO_PORTAL_MS) return true;
    delay(20);
  }
  return false;
}

// Portal de configuração (bloqueante, até 5 min). Grava o que foi preenchido.
void abrirPortalConfiguracao() {
  mensagemInicializacao("SmartDemetra-Setup", "Abra 192.168.4.1");
  WiFiManager wm;
  wm.setTitle("Smart Demetra");
  wm.setConfigPortalTimeout(PORTAL_TIMEOUT_S);
  wm.setConnectTimeout(20);
  wm.setBreakAfterConfig(true); // grava os campos mesmo se a rede não conectar
  WiFiManagerParameter campoUrl("url", "Endereço do painel (https://...)", cfgUrl.c_str(), 120);
  WiFiManagerParameter campoToken("token", "Token de vinculação (ou token sd_hw_)", "", 60);
  wm.addParameter(&campoUrl);
  wm.addParameter(&campoToken);

  bool salvou = false;
  wm.setSaveConfigCallback([&salvou]() { salvou = true; });
  wm.startConfigPortal(PORTAL_SSID);
  if (!salvou) {
    Serial.println("Portal fechado sem alteracoes");
    return;
  }

  if (WiFi.SSID().length() > 0) {
    salvarConfigRede("ssid", WiFi.SSID());
    salvarConfigRede("senha", WiFi.psk());
  }
  String url = String(campoUrl.getValue());
  url.trim();
  if (url.startsWith("http")) salvarConfigRede("url", url);

  String token = String(campoToken.getValue());
  token.trim();
  if (token.startsWith(TOKEN_HW_PREFIXO)) {
    salvarConfigRede("token", token);
    salvarConfigRede("vinculo", "");
  } else if (token.length() > 0) {
    token.toUpperCase();
    salvarConfigRede("vinculo", token);
  }
  carregarConfigRede();
  mensagemInicializacao("Configuracao", "salva");
  delay(1200);
}

// Troca o token de vinculação pelo token definitivo (uma vez, no primeiro contato).
void atualizarVinculoHostinger() {
  if (cfgVinculo.length() == 0 || !wifiConectado || WiFi.status() != WL_CONNECTED) return;
  if (ultimaTentativaVinculoEm != 0 && millis() - ultimaTentativaVinculoEm < RETENTATIVA_VINCULO_MS) return;
  ultimaTentativaVinculoEm = millis();

  WiFiClientSecure client;
  client.setInsecure();
  HTTPClient http;
  if (!http.begin(client, cfgUrl + "/vincular.php")) return;
  http.setTimeout(HOSTINGER_HTTP_TIMEOUT_MS);
  http.addHeader("Content-Type", "application/json");

  String payload = "{";
  payload += "\"codigo\":\"" + escaparJson(cfgVinculo) + "\",";
  payload += "\"firmware\":\"" FIRMWARE_VERSAO "\",";
  payload += "\"modelo\":\"" + escaparJson(String(ESP.getChipModel())) + "\",";
  payload += "\"mac\":\"" + escaparJson(WiFi.macAddress()) + "\"";
  payload += "}";

  int status = http.POST(payload);
  String resposta = http.getString();
  http.end();

  if (status == 200) {
    StaticJsonDocument<256> doc;
    if (!deserializeJson(doc, resposta) && doc["token"].is<const char*>()) {
      String token = doc["token"].as<String>();
      if (token.startsWith(TOKEN_HW_PREFIXO)) {
        salvarConfigRede("token", token);
        salvarConfigRede("vinculo", "");
        cfgToken = token;
        cfgVinculo = "";
        Serial.println("Dispositivo vinculado ao painel");
        mostrarMensagemTemporaria("Vinculado!", 2500);
      }
    }
  } else if (status == 403) {
    // Token de vinculação inválido ou expirado: descarta (gere outro no painel).
    salvarConfigRede("vinculo", "");
    cfgVinculo = "";
    Serial.println("Token de vinculacao invalido ou expirado");
    mostrarMensagemTemporaria("Vinculo invalido", 2500);
  } else {
    Serial.printf("Vinculo: HTTP %d, tentando de novo em 30 s\n", status);
  }
}

// ---------------- WIFI / HORA ----------------
// Conexão inicial (bloqueante, só no setup).
bool conectarWiFi() {
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.persistent(false);
  WiFi.disconnect(true, true);
  delay(100);
  WiFi.begin(cfgSsid.c_str(), cfgSenha.c_str());

  unsigned long inicio = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - inicio < WIFI_TIMEOUT_MS) {
    delay(250);
  }

  Serial.print("Status WiFi: ");
  Serial.println(static_cast<int>(WiFi.status()));
  return WiFi.status() == WL_CONNECTED;
}

// Reconexão não bloqueante com backoff.
void atualizarConexaoWiFi() {
  wl_status_t statusAtual = WiFi.status();

  if (statusAtual == WL_CONNECTED) {
    if (!wifiConectado) {
      wifiConectado = true;
      wifiDesconectadoDesde = 0;
      Serial.println("WiFi reconectado");
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
  WiFi.begin(cfgSsid.c_str(), cfgSenha.c_str());
}

const char* SERVIDORES_NTP[] = {
  "pool.ntp.org", "time.google.com", "a.st1.ntp.br", "time.windows.com"
};
const int TOTAL_SERVIDORES_NTP = sizeof(SERVIDORES_NTP) / sizeof(SERVIDORES_NTP[0]);
int indiceServidorNTP = 0;

// Alterna pares de servidores NTP a cada falha; ajusta o RTC ao sincronizar.
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

// Repete o NTP até a primeira sincronização.
void atualizarSincronizacaoHora() {
  if (horaSincronizada) return;
  if (!wifiConectado) return;
  if (millis() - ultimaTentativaNTP < RETENTATIVA_NTP_MS) return;

  ultimaTentativaNTP = millis();
  if (sincronizarHoraNTP()) {
    atualizarOLED = true;
  }
}

// Hora atual: relógio do ESP32 (após NTP) > RTC (ano >= 2024) > data fixa.
DateTime obterAgora() {
  if (horaSincronizada) {
    time_t agoraEpoch = time(nullptr);
    if (agoraEpoch > 100000) {
      // time() devolve UTC e DateTime(time_t) não aplica fuso: soma o offset aqui.
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

// "AAAA-MM-DD HH:MM:SS" para os payloads da Hostinger.
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
  String url = cfgUrl + endpoint;
  if (!http.begin(client, url)) {
    Serial.println("Hostinger: falha ao iniciar HTTP");
    return false;
  }

  http.setTimeout(HOSTINGER_HTTP_TIMEOUT_MS);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", chaveServidor());

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
  payload += "\"solo_percentual\":" + String(soloPercentual) + ",";
  // Status do dispositivo (opcionais: o servidor aceita leituras sem eles).
  payload += "\"firmware\":\"" FIRMWARE_VERSAO "\",";
  payload += "\"modelo\":\"" + escaparJson(String(ESP.getChipModel())) + "\",";
  payload += "\"mac\":\"" + escaparJson(WiFi.macAddress()) + "\",";
  payload += "\"rssi\":" + String(WiFi.RSSI()) + ",";
  payload += "\"uptime\":" + String((unsigned long)(millis() / 1000UL));
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

// Aplica um comando da fila do dashboard (mesmo tipo/alvo/valor de enviar_comando.php).
void aplicarComandoRemotoWeb(const String& tipo, const String& alvo, const String& valor) {
  if (tipo == "rele") {
    int indiceRele = -1;
    for (int i = 0; i < TOTAL_RELES_MANUAIS; i++) {
      if (String(nomeReleControle(RELES_MANUAIS[i])) == alvo) {
        indiceRele = RELES_MANUAIS[i];
        break;
      }
    }
    if (indiceRele < 0) {
      Serial.println("Hostinger: comando remoto com rele desconhecido: " + alvo);
      return;
    }
    aplicarComandoRele(indiceRele, valor == "ligado" ? 1 : 0);
  } else if (tipo == "modo_cultivo") {
    definirModoCultivoRemoto(valor == "floracao" ? FLORACAO : VEGETACAO);
  } else if (tipo == "luz_manual") {
    ModoControleLuz novoModo = LUZ_AUTOMATICA;
    if (valor == "ligada") novoModo = LUZ_MANUAL_LIGADA;
    else if (valor == "desligada") novoModo = LUZ_MANUAL_DESLIGADA;
    definirModoControleLuzRemoto(novoModo);
  } else if (tipo == "acesso") {
    liberarAcessoRemoto();
  } else if (tipo == "luz_duracao") {
    definirDuracaoLuzRemoto(valor.toInt());
  } else if (tipo == "fotoperiodo") {
    // valor = "HH:MM" (início da luz); a duração vem do modo.
    int h = -1, m = -1;
    if (valor.length() != 5 || valor.charAt(2) != ':' || sscanf(valor.c_str(), "%d:%d", &h, &m) != 2 ||
        h < 0 || h > 23 || m < 0 || m > 59) {
      Serial.println("Hostinger: horario de fotoperiodo invalido: " + valor);
      return;
    }
    if (alvo == "vegetativo") definirFotoperiodoRemoto(VEGETACAO, h * 60 + m);
    else if (alvo == "floracao") definirFotoperiodoRemoto(FLORACAO, h * 60 + m);
    else Serial.println("Hostinger: modo de fotoperiodo desconhecido: " + alvo);
  } else {
    Serial.println("Hostinger: tipo de comando remoto desconhecido: " + tipo);
  }
}

// Polling da fila do dashboard. O servidor marca como entregue na mesma
// resposta (pode perder um comando se a rede cair no meio).
void atualizarComandosPendentesHostinger() {
  if (!wifiConectado || WiFi.status() != WL_CONNECTED) return;
  if (millis() - ultimaConsultaComandosPendentesEm < INTERVALO_POLL_COMANDOS_HOSTINGER_MS) return;
  ultimaConsultaComandosPendentesEm = millis();

  WiFiClientSecure client;
  client.setInsecure(); // HTTPS sem fixar certificado
  HTTPClient http;
  String url = cfgUrl + "/comandos_pendentes.php";
  if (!http.begin(client, url)) {
    Serial.println("Hostinger: falha ao iniciar HTTP (comandos pendentes)");
    return;
  }

  http.setTimeout(HOSTINGER_HTTP_TIMEOUT_MS);
  http.addHeader("X-API-Key", chaveServidor());

  int status = http.GET();
  if (status != 200) {
    if (status > 0) {
      Serial.print("Hostinger: erro HTTP ");
      Serial.print(status);
      Serial.println(" em /comandos_pendentes.php");
    }
    http.end();
    return;
  }

  String resposta = http.getString();
  http.end();

  StaticJsonDocument<2048> doc;
  DeserializationError erroJson = deserializeJson(doc, resposta);
  if (erroJson) {
    Serial.print("Hostinger: JSON invalido em comandos pendentes: ");
    Serial.println(erroJson.c_str());
    return;
  }

  if (!(doc["ok"] | false)) return;

  JsonArray comandos = doc["comandos"].as<JsonArray>();
  for (JsonObject c : comandos) {
    String tipo = c["tipo"] | "";
    String alvo = c["alvo"] | "";
    String valor = c["valor"] | "";
    if (tipo == "" || alvo == "" || valor == "") continue;
    aplicarComandoRemotoWeb(tipo, alvo, valor);
  }
}

// Eventos executados (local ou remoto) enviados à Hostinger.
void registrarEventoModo(const char* estadoModo, const char* origem) {
  enfileirarComandoHostinger("modo_cultivo", "modo_cultivo", estadoModo, origem);
}

void registrarEventoRele(int indiceRele, bool ligado, const char* origem) {
  enfileirarComandoHostinger("rele", nomeReleControle(indiceRele), ligado ? "ligado" : "desligado", origem);
}

void registrarEventoAcesso(const char* origem, const char* resultado) {
  enfileirarComandoHostinger("acesso", "Trava Acesso", resultado, origem);
}

// Confirma o horário de luz em uso; o dashboard compara com o pedido.
void registrarEventoFotoperiodo(ModoCultivo modo, const char* origem) {
  int inicio = (modo == VEGETACAO) ? inicioLuzVegetacaoMin : inicioLuzFloracaoMin;
  char hhmm[12];
  snprintf(hhmm, sizeof(hhmm), "%02d:%02d", (inicio / 60) % 24, inicio % 60);
  enfileirarComandoHostinger("fotoperiodo", modo == VEGETACAO ? "vegetativo" : "floracao", String(hhmm), origem);
}

void definirFotoperiodoRemoto(ModoCultivo modo, int inicioMin) {
  if (!inicioLuzValido(inicioMin)) return;
  int& alvo = (modo == VEGETACAO) ? inicioLuzVegetacaoMin : inicioLuzFloracaoMin;
  if (alvo != inicioMin) {
    alvo = inicioMin;
    salvarFotoperiodoNaMemoria();
    mostrarMensagemTemporaria(modo == VEGETACAO ? "Timer Veg salvo" : "Timer Flora salvo", 1500);
    atualizarOLED = true;
  }
  // Confirma mesmo se não mudou — o painel fica sabendo que o comando chegou.
  registrarEventoFotoperiodo(modo, "web");
}

// ---------------- SENSOR DE AR ----------------
// Só atualiza se variar >= 0.1°C ou 1%; pisca o indicador a cada leitura válida.
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
      // Falha de CRC/timeout: mantém NAN.
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

  // Indicador de sensor vivo, mesmo sem mudança de valor.
  if (!isnan(novaTemp) && !isnan(novaUmid)) {
    pulsoSensorAtivo = true;
    pulsoSensorAte = millis() + DURACAO_PULSO_SENSOR_MS;
    if (telaSelecionada == TELA_PRINCIPAL && estadoOLED == OLED_EXIBINDO) {
      atualizarOLED = true;
    }
  }
}

void atualizarPulsoSensor() {
  if (pulsoSensorAtivo && millis() >= pulsoSensorAte) {
    pulsoSensorAtivo = false;
    if (telaSelecionada == TELA_PRINCIPAL && estadoOLED == OLED_EXIBINDO) {
      atualizarOLED = true;
    }
  }
}

// Sensor externo: mesma lógica do interno, com indicador próprio.
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

  // Média de várias leituras (ADC ruidoso).
  long soma = 0;
  for (int i = 0; i < SOLO_AMOSTRAS; i++) {
    soma += analogRead(SOIL_PIN);
    delayMicroseconds(200);
  }
  int leitura = soma / SOLO_AMOSTRAS;

  // Mapeamento invertido: ADC alto = seco (0%), baixo = úmido (100%).
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

  pulsoSensorSoloAtivo = true;
  pulsoSensorSoloAte = millis() + DURACAO_PULSO_SENSOR_MS;
  if (telaSelecionada == TELA_PRINCIPAL && estadoOLED == OLED_EXIBINDO) {
    atualizarOLED = true;
  }
}

void atualizarPulsoSensorSolo() {
  if (pulsoSensorSoloAtivo && millis() >= pulsoSensorSoloAte) {
    pulsoSensorSoloAtivo = false;
    if (telaSelecionada == TELA_PRINCIPAL && estadoOLED == OLED_EXIBINDO) {
      atualizarOLED = true;
    }
  }
}

// ---------------- FOTOPERÍODO ----------------
void janelaLuzAtual(int& inicio, int& fim) {
  if (modoAtual == VEGETACAO) {
    inicio = inicioLuzVegetacaoMin;
    fim = (inicioLuzVegetacaoMin + DURACAO_LUZ_VEGETACAO_MIN) % (24 * 60);
  } else {
    inicio = inicioLuzFloracaoMin;
    fim = (inicioLuzFloracaoMin + DURACAO_LUZ_FLORACAO_MIN) % (24 * 60);
  }
}

bool luzDeveEstarLigada(const DateTime& now) {
  int minutosAgora = now.hour() * 60 + now.minute();
  int inicio, fim;
  janelaLuzAtual(inicio, fim);

  if (inicio < fim) {
    return minutosAgora >= inicio && minutosAgora < fim;
  }
  return minutosAgora >= inicio || minutosAgora < fim;
}

// Override manual sobrepõe o resultado, mas o timer continua sendo calculado.
void controlarLuz(const DateTime& now) {
  if (modoControleLuz != LUZ_AUTOMATICA && overrideLuzAte > 0 && millis() >= overrideLuzAte) {
    modoControleLuz = LUZ_AUTOMATICA;
    overrideLuzAte = 0;
    atualizarOLED = true;
  }

  bool estadoAnterior = estadoReles[RELE_LUZ];

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

// Tempo até a luz trocar de estado ("HHhMMm"), tratando a virada da meia-noite.
String tempoRestante(const DateTime& now) {
  int minutosAgora = now.hour() * 60 + now.minute();

  int inicio;
  int fim;
  janelaLuzAtual(inicio, fim);

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

// Tempo até o override manual expirar ("HHhMMm").
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

// Botão físico que libera a trava.
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
// begin(true) formata na primeira vez.
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

  // Arquivo existente: conta as linhas de dados.
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

// Mantém só as HISTORICO_MAX_LINHAS mais recentes, em streaming (pouca RAM).
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

// Linha do CSV com o estado atual do sistema.
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

// Grava no histórico local a cada INTERVALO_HISTORICO_MS.
void atualizarHistorico() {
  if (millis() - ultimoRegistroHistoricoEm < INTERVALO_HISTORICO_MS) return;

  ultimoRegistroHistoricoEm = millis();
  registrarHistorico();
}

// Envia a leitura à Hostinger a cada INTERVALO_ENVIO_LEITURA_HOSTINGER_MS.
void atualizarEnvioLeituraHostinger() {
  if (millis() - ultimoEnvioLeituraHostingerEm < INTERVALO_ENVIO_LEITURA_HOSTINGER_MS) return;

  ultimoEnvioLeituraHostingerEm = millis();
  enviarLeituraHostinger();
}

// ---------------- SETUP ----------------
// Recuperação do barramento I2C: um dispositivo sem alimentação pode prender
// SDA em LOW e travar o OLED. Pulsa SCL até soltar e força um STOP.
void recuperarBarramentoI2C() {
  pinMode(SDA_PIN, INPUT_PULLUP);
  pinMode(SCL_PIN, OUTPUT);
  digitalWrite(SCL_PIN, HIGH);

  // Até 9 pulsos de clock.
  for (int i = 0; i < 9; i++) {
    if (digitalRead(SDA_PIN) == HIGH) break;
    digitalWrite(SCL_PIN, LOW);
    delayMicroseconds(5);
    digitalWrite(SCL_PIN, HIGH);
    delayMicroseconds(5);
  }

  // STOP manual.
  pinMode(SDA_PIN, OUTPUT);
  digitalWrite(SDA_PIN, LOW);
  delayMicroseconds(5);
  digitalWrite(SCL_PIN, HIGH);
  delayMicroseconds(5);
  digitalWrite(SDA_PIN, HIGH);
  delayMicroseconds(5);
}

// Mensagem de boot (antes do sistema de telas).
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

// Ordem: relés desligados -> entradas -> I2C -> OLED/sensores -> RTC ->
// NVRAM -> WiFi/NTP.
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

  recuperarBarramentoI2C(); // antes do Wire.begin()
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
  dhtExterno.begin(); // sensor externo

  rtcDisponivel = rtc.begin();
  Serial.print("RTC detectado: ");
  Serial.println(rtcDisponivel ? "sim" : "NAO - verifique fiacao SDA/SCL");

  if (rtcDisponivel && !rtc.isrunning()) {
    // Oscilador parado (sem bateria): parte da hora de compilação até o NTP.
    Serial.println("RTC parado (sem bateria?). Ajustando para hora de compilacao...");
    rtc.adjust(DateTime(F(__DATE__), F(__TIME__)));
  }

  restaurarEstadoDaMemoria();
  restaurarFotoperiodoDaMemoria();
  restaurarDuracaoLuzDaMemoria();
  iniciarHistorico();
  // Informa ao dashboard os horários de luz em uso.
  registrarEventoFotoperiodo(VEGETACAO, "boot");
  registrarEventoFotoperiodo(FLORACAO, "boot");
  registrarEventoDuracaoLuz("boot");

  carregarConfigRede();
#if !WOKWI_SIMULATION
  if (!redeConfigurada() || botaoPortalPressionado()) {
    abrirPortalConfiguracao();
  }
#endif

  mensagemInicializacao("Conectando WiFi");
  ultimaTentativaWiFi = millis();
  wifiConectado = conectarWiFi();
  Serial.println(wifiConectado ? "WiFi conectado" : "Falha ao conectar WiFi");

  if (wifiConectado) {
    mensagemInicializacao("Sincronizando", "hora via NTP");
    horaSincronizada = sincronizarHoraNTP();
  }

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
    Serial.print("WiFi conectado, IP: ");
    Serial.println(WiFi.localIP());
    mensagemInicializacao("IP:", WiFi.localIP().toString());
    delay(1800);
  }

  estadoOLED = OLED_DESCANSO;
  atualizarOLED = true;
  renderizarOLED(obterAgora());
}

// ---------------- LOOP ----------------
// Tudo não bloqueante; cada atualizarX() controla o próprio intervalo.
void loop() {
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
  atualizarHistorico();        // CSV local a cada 10 min
  atualizarEnvioLeituraHostinger(); // envia a leitura atual pra Hostinger a cada 1 min
  atualizarFilaComandosHostinger(); // envia eventos enfileirados
  atualizarComandosPendentesHostinger(); // fila de comandos do dashboard (4s)
  atualizarVinculoHostinger();   // troca o token de vinculação pelo definitivo

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
