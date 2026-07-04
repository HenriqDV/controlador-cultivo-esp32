// =============================================================================
//  PROJETO: CULTIVO — Automação de estufa/grow tent (ESP32-S3)
// =============================================================================
//
//  VISÃO GERAL
//  -----------
//  Controla luz (fotoperíodo automático por horário + override manual),
//  exaustores/ventiladores, trava de acesso (porta) e monitora temperatura,
//  umidade do ar e umidade do solo. Interface local via display OLED SSD1306
//  (128x64, com faixa amarela de 16px no topo) + botão físico, e interface
//  remota via app Blynk. O estado dos relés e o modo de cultivo sobrevivem
//  a quedas de energia, gravados na NVRAM com bateria do RTC DS1307.
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
//    Atuadores:
//      BUZZER = 17   |   LED_PIN = 4 na simulação Wokwi / 48 no hardware físico
//      (NeoPixel de status — pino muda sozinho com WOKWI_SIMULATION, pois no
//      hardware real é o LED RGB embutido da placa ESP32-S3, no pino 48)
//    Botões:
//      BOTAO_OLED_PIN = 5 (navegação do menu)  |  BOTAO_TRV_PIN = 19 (abre acesso)
//    Relés (array "reles", índice = função — ver seção RELÉS UTILIZADOS):
//      {42, 41, 40, 39, 38, 37, 36, 35}
//      ATENÇÃO: em módulos ESP32-S3 com PSRAM Octal, GPIO35/36/37 podem estar
//      reservados — ver aviso ao lado da declaração do array mais abaixo.
//
//  PERSISTÊNCIA ENTRE REINÍCIOS
//  ------------------------------
//  O DS1307 tem 56 bytes de RAM com bateria de backup. Usamos os 3 primeiros
//  para gravar o estado dos relés manuais e o modo de cultivo (ver seção
//  "PERSISTÊNCIA (NVRAM DO RTC DS1307)"). A luz nunca é restaurada diretamente
//  (é recalculada pelo horário) e a trava de acesso nunca volta ligada, por
//  segurança.
//
//  INTEGRAÇÃO COM BLYNK (pinos virtuais) — ver tabela completa na seção
//  "BLYNK — CALLBACKS DE ENTRADA (comandos do app)" mais abaixo.
//
//  CALIBRAÇÃO NECESSÁRIA NO HARDWARE FÍSICO
//  -------------------------------------------
//    - SOLO_ADC_SECO / SOLO_ADC_UMIDO (seção CALIBRAÇÃO DO SENSOR DE SOLO)
//    - RELE_ATIVO_EM_BAIXO (se os relés vierem invertidos ao ligar o sistema)
//    - ssid / password (rede WiFi real)
//
// =============================================================================

#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <RTClib.h>
#define BLYNK_TEMPLATE_ID "TMPL2FtN5v0dw"
#define BLYNK_TEMPLATE_NAME "Cultivo"
#define BLYNK_AUTH_TOKEN "BcpfT8DnE3wl0bKuR7dIEs2S_G1KEOgC"
#define WOKWI_SIMULATION 1
#if !WOKWI_SIMULATION
#include <Adafruit_SHT4x.h>
#endif
#include <DHT.h>
#include <Adafruit_NeoPixel.h>
#include <WiFi.h>
#include <BlynkSimpleEsp32.h>
#include <time.h>
#include <math.h>

// ---------------- WIFI ----------------
// "Wokwi-GUEST" é a rede aberta com acesso à internet disponível dentro do
// simulador Wokwi — use para testar a integração com o Blynk real. Antes de
// gravar no hardware físico, troque pelo SSID e senha da sua rede WiFi real.
const char* ssid = "Wokwi-GUEST";
const char* password = "";

const long GMT_OFFSET_SEC = -3 * 3600;
const int DAYLIGHT_OFFSET_SEC = 0;
const unsigned long WIFI_TIMEOUT_MS = 15000;
const unsigned long RETENTATIVA_WIFI_INICIAL_MS = 5000;
const unsigned long RETENTATIVA_WIFI_POSTERIOR_MS = 30000;
const unsigned long JANELA_WIFI_INICIAL_MS = 60000;
const unsigned long NTP_TIMEOUT_MS = 10000;
const unsigned long RETENTATIVA_NTP_MS = 60000;
const unsigned long INTERVALO_TELEMETRIA_BLYNK_MS = 120000;
const unsigned long INTERVALO_ESTADO_BLYNK_MS = 2000;
const unsigned long RETENTATIVA_BLYNK_MS = 5000;

// ---------------- PINOS ----------------
#define SDA_PIN 8
#define SCL_PIN 9

#if WOKWI_SIMULATION
#define DHTPIN 15
#define DHTTYPE DHT22
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
// NÃO podem ser usados como GPIO comum. Se for o seu caso, remapeie os
// relés que caírem nesses pinos (índices 5, 6 e 7 do array acima) para
// outros GPIOs livres da sua placa antes de montar o circuito.
// OBS.: Foi utilizado um ESP32-S3 com variante "N8R2" aonde os pinos 
// GPIO35, GPIO36 e GPIO37 podem ser usados normalmente.

// ---------------- RELÉS UTILIZADOS ----------------
// Índices dentro do array "reles[8]" (definido acima) e seus papéis fixos.
// Índices 0 e 1 são especiais (controlados por lógica própria); 2 a 7 são
// "manuais" (o usuário liga/desliga livremente pelo menu ou pelo Blynk).
const int RELE_LUZ = 0;              // controlado por controlarLuz() (fotoperíodo + override manual)
const int RELE_ACESSO = 1;           // trava/fechadura, pulso temporário via liberarAcessoPor()
const int PRIMEIRO_RELE_MANUAL = 2;  // primeiro índice de relé "livre" (usado em loops e limites)
const int CONTROLE_MODO_CULTIVO = -1; // sentinela: item de menu "Modo Cultivo" (não é relé de verdade)
const int CONTROLE_LUZ_MANUAL = -2;   // sentinela: item de menu "Luz Manual" (override, não é relé de verdade)
const int RELE_EXAUSTOR_1 = 2;       // usado só para compor o status "exaustão ativa" no Blynk (V7)
const int RELE_EXAUSTOR_2 = 3;

// ---------------- DISPLAY OLED ----------------
#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64
#define OLED_RESET -1
#define SCREEN_ADDRESS 0x3C
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, OLED_RESET);

// O painel físico tem uma faixa amarela de 16px no topo e o restante azul.
// Esses limites garantem que nada fique cortado na costura entre as cores.
const int OLED_ALTURA_CABECALHO = 16;                 // faixa amarela (linhas 0-15)
const int OLED_ALTURA_RODAPE = 11;                     // faixa de rodapé (dentro da azul)
const int OLED_CONTEUDO_TOPO = OLED_ALTURA_CABECALHO + 2;         // margem de 2px após a costura
const int OLED_CONTEUDO_BASE = SCREEN_HEIGHT - OLED_ALTURA_RODAPE; // linha do separador do rodapé

// ---------------- OBJETOS ----------------
RTC_DS1307 rtc;
#if WOKWI_SIMULATION
DHT dht(DHTPIN, DHTTYPE);
#else
Adafruit_SHT4x sht4 = Adafruit_SHT4x();
#endif
Adafruit_NeoPixel pixel(1, LED_PIN, NEO_GRB + NEO_KHZ800);

// ---------------- LED ----------------
// LED NeoPixel único usado como indicador de status geral do sistema (cores
// e efeito de "respiração" conforme o estado). Tem dois modos de operação:
//   - Estado "base": recalculado a cada chamada de atualizarLED() a partir da
//     situação atual (WiFi/Blynk/porta), via obterEstadoBaseLED().
//   - Estado "temporário": sobrepõe o estado base por um tempo curto (ex: um
//     flash verde de confirmação ao apertar um botão), definido por
//     definirEstadoLEDTemporario() e expira sozinho.
unsigned long tempoLED = 0; // controla a taxa de atualização do efeito (a cada 20ms)
int brilho = 0;
int direcao = 5; // sentido do "respirar" (+ = aumentando, - = diminuindo)

enum EstadoLED {
  AGUARDANDO,        // tudo ok, sistema ocioso (efeito azul suave)
  CONECTANDO_WIFI,   // sem WiFi (efeito laranja)
  CONECTANDO_BLYNK,  // WiFi ok, mas Blynk ainda não conectou (efeito ciano)
  BLYNK_CONECTADO,   // tudo conectado (mesmo efeito de AGUARDANDO)
  PORTA_ABERTA_LED,  // acesso/trava liberado (verde fixo)
  LED_OK,            // confirmação temporária de uma ação (verde fixo, curto)
  ERRO               // reservado para uso futuro (vermelho fixo)
};
EstadoLED estadoLED = AGUARDANDO;
unsigned long ledAte = 0; // 0 = sem override temporário ativo; caso contrário, timestamp de expiração

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
// O mesmo fluxo pode ser disparado remotamente pelo Blynk (V18 = clique
// curto, V19 = clique longo) via executarCliqueCurtoMenuRemoto() /
// executarCliqueLongoMenuRemoto().
enum TelaOLED {
  TELA_PRINCIPAL,      // cabeçalho + rodapé + temperatura/umidade
  TELA_SECUNDARIA,      // cabeçalho + rodapé + modo de cultivo + tempo de luz
  TELA_CONFIGURACAO,    // cabeçalho + rodapé + menu interativo dos relés
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
bool pulsoSensorAtivo = false;      // indicador visual de "sensor vivo" (bolinha na TELA_PRINCIPAL)
unsigned long pulsoSensorAte = 0;
const unsigned long DURACAO_PULSO_SENSOR_MS = 300; // por quanto tempo o pulso fica visível a cada leitura

// ---------------- SOLO (sensor de umidade do solo) ----------------
// Ver constantes de calibração (SOLO_ADC_SECO/SOLO_ADC_UMIDO) lá em cima,
// perto da definição de SOIL_PIN.
int soloBruto = 0;       // última leitura crua do ADC (média de SOLO_AMOSTRAS), útil para recalibrar
int soloPercentual = 0;  // 0 = seco, 100 = encharcado, já calibrado
unsigned long ultimoSolo = 0;
const unsigned long INTERVALO_SOLO_MS = 1000;

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
bool blynkConfigurado = false;          // Blynk.config() já foi chamado (ver desenharIconeConexao)
unsigned long ultimaTentativaWiFi = 0;
unsigned long wifiDesconectadoDesde = 0;
unsigned long ultimaTentativaNTP = 0;
unsigned long ultimaInteracaoMenuEm = 0;      // usado por interfaceLocalEmUso() para "dar prioridade" ao usuário
unsigned long ultimoEnvioTelemetriaBlynkEm = 0;
unsigned long ultimoEnvioEstadoBlynkEm = 0;
unsigned long ultimaTentativaBlynkEm = 0;
bool estadoBlynkPendente = true;        // true = há mudança de estado ainda não publicada no Blynk

// ---------------- ESTADO DOS RELÉS ----------------
// Espelho em RAM do estado real de cada relé (índices 0-7, ver seção RELÉS
// UTILIZADOS). É a "fonte da verdade" consultada por toda a interface
// (OLED, Blynk); a escrita física no pino acontece só em acionarRele().
bool estadoReles[8] = {false, false, false, false, false, false, false, false};

// ---------------- PROTOTIPOS ----------------
// Declarações antecipadas — necessárias porque várias funções (e os
// callbacks BLYNK_WRITE logo abaixo) referenciam outras que só são
// definidas mais adiante no arquivo.
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
void registrarEventoModo(const char* estadoModo);
void registrarEventoAcesso(const char* origem, const char* resultado);
void registrarEventoRele(int indiceRele, bool ligado, const char* origem);
void conectarBlynk();
void atualizarBlynk();
void publicarTelemetriaBlynk();
void publicarEstadoBlynk();
void executarCliqueCurtoMenuRemoto();
void executarCliqueLongoMenuRemoto();
bool interfaceLocalEmUso();
EstadoLED obterEstadoBaseLED();
void aplicarComandoReleBlynk(int indiceRele, int valor);
void salvarEstadoNaMemoria();
void restaurarEstadoDaMemoria();

// =============================================================================
//  BLYNK — CALLBACKS DE ENTRADA (comandos vindos do app)
// =============================================================================
//  TABELA DE PINOS VIRTUAIS (referência única — use esta lista ao criar/
//  revisar os widgets no app Blynk):
//
//   ENTRADA (o app manda, o ESP32 recebe via BLYNK_WRITE):
//     V10  Switch  -> Modo de cultivo (0 = Vegetativo, 1 = Floração)
//     V11  Switch  -> Relé 2 (Saida Ar Vivosun)
//     V12  Switch  -> Relé 3 (Entrada Ar Fan)
//     V13  Switch  -> Relé 4 (Vent Principal)
//     V14  Switch  -> Relé 5 (Saida Ar Dois)
//     V15  Switch  -> Relé 6 (Entrada Ar Dois)
//     V16  Switch  -> Relé 7 (Ventilador Dois)
//     V17  Button  -> Libera acesso/trava por 3s (autozera após o pulso)
//     V18  Button  -> Simula clique CURTO do botão físico do OLED (autozera)
//     V19  Button  -> Simula clique LONGO do botão físico do OLED (autozera)
//
//   SAÍDA (o ESP32 publica, o app só exibe — ver publicarTelemetriaBlynk()
//   e publicarEstadoBlynk() mais abaixo):
//     V1   Umidade do ar (%)              V9   Tempo restante do ciclo de luz
//     V2   Temperatura do ar (°C)         V10  Espelho do modo de cultivo
//     V4   Umidade do solo (%)            V11-V16  Espelho do estado dos relés 2-7
//     V5   Estado do relé de luz          
//     V6   Modo de cultivo (texto)        
//     V7   Exaustão ativa (1/0)           
//     V8   Timestamp completo (string)    
//
//  Não existe controle remoto dedicado para o override manual de luz
//  (CONTROLE_LUZ_MANUAL) — hoje só é acessível pelo menu local do OLED.
// =============================================================================

BLYNK_CONNECTED() {
  // Disparado uma vez, logo após o handshake com o servidor Blynk ter
  // sucesso. Pede ao servidor os últimos valores salvos de cada pino, para
  // o dispositivo "herdar" o estado configurado no app (útil após reinício).
  estadoBlynkPendente = true;
  Blynk.syncVirtual(V10, V11, V12, V13, V14, V15, V16, V17);
}

BLYNK_WRITE(V10) {
  // Alterna o modo de cultivo a partir do app (mesmo efeito do menu local).
  ModoCultivo modoDesejado = param.asInt() == 1 ? FLORACAO : VEGETACAO;
  if (modoDesejado != modoAtual) {
    modoAtual = modoDesejado;
    mostrarMensagemTemporaria(modoAtual == FLORACAO ? "Modo: Floracao" : "Modo: Vegetativo", 1500);
    estadoBlynkPendente = true;
    atualizarOLED = true;
    definirEstadoLEDTemporario(LED_OK, 700);
    salvarEstadoNaMemoria();
  }
}

// V11-V16: liga/desliga os relés manuais 2-7 (ver aplicarComandoReleBlynk)
BLYNK_WRITE(V11) { aplicarComandoReleBlynk(2, param.asInt()); }
BLYNK_WRITE(V12) { aplicarComandoReleBlynk(3, param.asInt()); }
BLYNK_WRITE(V13) { aplicarComandoReleBlynk(4, param.asInt()); }
BLYNK_WRITE(V14) { aplicarComandoReleBlynk(5, param.asInt()); }
BLYNK_WRITE(V15) { aplicarComandoReleBlynk(6, param.asInt()); }
BLYNK_WRITE(V16) { aplicarComandoReleBlynk(7, param.asInt()); }

// V17: botão do app que libera a trava de acesso por 3s. Autozera o próprio
// widget no final para simular um botão de pulso, não um switch persistente.
BLYNK_WRITE(V17) {
  if (param.asInt() == 1) {
    liberarAcessoPor(3000);
    definirEstadoLEDTemporario(LED_OK, 1000);
    beep(150);
    mostrarMensagemTemporaria("Porta Liberada", 1500);
    Blynk.virtualWrite(V17, 0);
  }
}

// V18/V19: permitem operar o menu do OLED remotamente, sem precisar estar
// fisicamente perto do botão (útil para debug e testes).
BLYNK_WRITE(V18) {
  if (param.asInt() == 1) {
    executarCliqueCurtoMenuRemoto();
    Blynk.virtualWrite(V18, 0);
  }
}

BLYNK_WRITE(V19) {
  if (param.asInt() == 1) {
    executarCliqueLongoMenuRemoto();
    Blynk.virtualWrite(V19, 0);
  }
}

// ---------------- LED ----------------
// Calcula qual EstadoLED "base" deveria valer agora, olhando só pro status
// atual do sistema (ignora qualquer override temporário). Ordem de
// prioridade: porta aberta > sem WiFi > sem Blynk > tudo ok.
EstadoLED obterEstadoBaseLED() {
  if (acessoAtivo) {
    return PORTA_ABERTA_LED;
  }

  if (!wifiConectado) {
    return CONECTANDO_WIFI;
  }

  if (!Blynk.connected()) {
    return CONECTANDO_BLYNK;
  }

  return BLYNK_CONECTADO;
}

// Roda a cada loop(); internamente se auto-limita a ~50Hz (20ms) para não
// gastar tempo de CPU redesenhando o NeoPixel sem necessidade.
void atualizarLED() {
  if (millis() - tempoLED < 20) return;
  tempoLED = millis();

  if (ledAte > 0 && millis() >= ledAte) {
    estadoLED = AGUARDANDO;
    ledAte = 0;
  }

  EstadoLED estadoAtual = ledAte > 0 ? estadoLED : obterEstadoBaseLED();

  switch (estadoAtual) {
    case AGUARDANDO:
    case BLYNK_CONECTADO:
      brilho += direcao;
      if (brilho >= 150) {
        brilho = 150;
        direcao = -5;
      }
      if (brilho <= 0) {
        brilho = 0;
        direcao = 5;
      }
      pixel.setPixelColor(0, pixel.Color(0, 0, brilho));
      break;

    case CONECTANDO_WIFI:
      brilho += direcao;
      if (brilho >= 180) {
        brilho = 180;
        direcao = -8;
      }
      if (brilho <= 10) {
        brilho = 10;
        direcao = 8;
      }
      pixel.setPixelColor(0, pixel.Color(brilho, brilho / 3, 0));
      break;

    case CONECTANDO_BLYNK:
      brilho += direcao;
      if (brilho >= 180) {
        brilho = 180;
        direcao = -8;
      }
      if (brilho <= 10) {
        brilho = 10;
        direcao = 8;
      }
      pixel.setPixelColor(0, pixel.Color(0, brilho / 2, brilho));
      break;

    case PORTA_ABERTA_LED:
      pixel.setPixelColor(0, pixel.Color(0, 180, 0));
      break;

    case LED_OK:
      pixel.setPixelColor(0, pixel.Color(0, 150, 0));
      break;

    case ERRO:
      pixel.setPixelColor(0, pixel.Color(150, 0, 0));
      break;
  }

  pixel.show();
}

// Sobrepõe o estado base do LED por um tempo curto (ex: flash de confirmação
// ao ligar um relé). Depois de "duracaoMs", atualizarLED() volta sozinho
// para o estado base calculado por obterEstadoBaseLED().
void definirEstadoLEDTemporario(EstadoLED novoEstado, unsigned long duracaoMs) {
  estadoLED = novoEstado;
  ledAte = millis() + duracaoMs;
}

// Aplica um comando de relé vindo do Blynk (V11-V16). Só age se o valor for
// diferente do atual (evita gravar na NVRAM/piscar o LED à toa em cada
// sincronização do app).
void aplicarComandoReleBlynk(int indiceRele, int valor) {
  bool novoEstado = valor == 1;
  if (estadoReles[indiceRele] == novoEstado) return;

  acionarRele(indiceRele, novoEstado);
  registrarEventoRele(indiceRele, novoEstado, "blynk");
  salvarEstadoNaMemoria();
  atualizarOLED = true;
  definirEstadoLEDTemporario(LED_OK, 700);
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
// longa). Se for a TELA_CONFIGURACAO, reseta a seleção para o primeiro item
// ("Modo Cultivo"), garantindo um ponto de partida previsível.
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
//   - Tela de descanso    -> acorda o display (abrirTelaRapidaOLED)
//   - Menu de confirmação -> avança pro próximo item do ciclo
//   - TELA_CONFIGURACAO já aberta -> avança item selecionado dentro do menu
//   - Qualquer outra tela -> avança pra próxima tela
void tratarCliqueCurtoBotaoOLED() {
  if (estadoOLED == OLED_DESCANSO) {
    abrirTelaRapidaOLED();
    return;
  }

  if (estadoOLED == OLED_MENU) {
    avancarNavegacaoOLED();
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

// Ícone de "barras de sinal" no cabeçalho — cada barra tem um significado
// FIXO e independente (não é uma contagem de nível de sinal genérica):
void desenharIconeConexao(int x, int y) {
  // Cada barra tem um significado fixo e independente (não é mais uma
  // contagem de nível): mostra exatamente em qual etapa da conexão o
  // sistema está, útil pra diagnosticar onde travou.
  // Barra 1: WiFi conectado
  // Barra 2: Blynk configurado (Blynk.config() já foi chamado)
  // Barra 3: Blynk realmente conectado ao servidor (blynk.cloud)
  bool estados[3];
  estados[0] = wifiConectado;
  estados[1] = blynkConfigurado;
  estados[2] = Blynk.connected();

  const int alturas[3] = {3, 5, 7};
  int baseY = y + 7;

  for (int i = 0; i < 3; i++) {
    int barX = x + i * 4; // largura 3 + 1px de espaço entre barras
    int barH = alturas[i];
    int barY = baseY - barH;

    if (estados[i]) {
      display.fillRect(barX, barY, 3, barH, SSD1306_WHITE);
    } else {
      display.drawRect(barX, barY, 3, barH, SSD1306_WHITE);
    }
  }
}

// Cabeçalho comum às telas navegáveis (não é usado na tela de descanso, que
// tem seu próprio cabeçalho — ver desenharCabecalhoDescanso). Ocupa toda a
// faixa amarela do painel físico (16px) e termina com uma linha separadora
// exatamente na costura entre as duas cores.
void desenharCabecalho(const DateTime& now) {
  display.setTextSize(1);
  display.setTextColor(SSD1306_WHITE);

  // Texto e ícone centralizados verticalmente dentro da faixa amarela (0-15)
  const int y = 4;

  display.setCursor(0, y);
  display.print("CULTIVO");

  char buf[6];
  snprintf(buf, sizeof(buf), "%02d:%02d", now.hour(), now.minute());
  display.setCursor(SCREEN_WIDTH - 45, y);
  display.print(buf);

  desenharIconeConexao(SCREEN_WIDTH - 13, y);

  // Separador exatamente na costura entre a faixa amarela e a azul
  display.drawFastHLine(0, OLED_ALTURA_CABECALHO - 1, SCREEN_WIDTH, SSD1306_WHITE);
}

// Rodapé comum às telas navegáveis: linha separadora + indicador de página
// (bolinhas, uma por TelaOLED) + modo de cultivo (V/F) + status do solo.
void desenharRodape() {
  display.drawFastHLine(0, OLED_CONTEUDO_BASE, SCREEN_WIDTH, SSD1306_WHITE);

  // indicador de página (bolinhas) centralizado
  int totalPaginas = TOTAL_TELAS_OLED;
  int larguraTotal = totalPaginas * 10;
  int xInicial = (SCREEN_WIDTH - larguraTotal) / 2;
  for (int i = 0; i < totalPaginas; i++) {
    int cx = xInicial + i * 10 + 3;
    int cy = 58;
    if (i == telaSelecionada) {
      display.fillCircle(cx, cy, 3, SSD1306_WHITE);
    } else {
      display.drawCircle(cx, cy, 3, SSD1306_WHITE);
    }
  }

  display.setTextSize(1);
  display.setCursor(0, 56);
  display.print(modoAtual == VEGETACAO ? "V" : "F");

  display.setCursor(SCREEN_WIDTH - 30, 56);
  display.print(estadoSolo());
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

// Classificação simples exibida no rodapé — ver limiar em soloPercentual.
const char* estadoSolo() {
  if (soloPercentual >= 50) return "Umido";
  return "Seco";
}

// ---------- tela principal: temperatura / umidade ----------
// Mostra a temperatura em destaque (fonte grande) e a umidade abaixo, com um
// indicador de "sensor vivo" (bolinha) que pisca a cada leitura válida do
// sensor de ar — ver pulsoSensorAtivo em atualizarDHT().
void desenharConteudoPrincipal() {
  display.setTextSize(1);
  display.setCursor(0, OLED_CONTEUDO_TOPO);
  display.print("Temperatura");

  // indicador de "sensor vivo": pisca a cada leitura válida do DHT
  int cx = SCREEN_WIDTH - 8;
  int cy = OLED_CONTEUDO_TOPO + 3;
  if (pulsoSensorAtivo) {
    display.fillCircle(cx, cy, 3, SSD1306_WHITE);
  } else {
    display.drawCircle(cx, cy, 3, SSD1306_WHITE);
  }

  display.setTextSize(2);
  display.setCursor(0, OLED_CONTEUDO_TOPO + 10);
  display.print(formatarTemperatura(tempAtual));
  display.print("C");

  display.setTextSize(1);
  display.setCursor(0, OLED_CONTEUDO_BASE - 8);
  display.print("Umidade: ");
  if (isnan(umidAtual)) {
    display.print("--");
  } else {
    display.print(umidAtual, 0);
  }
  display.print("%");
}

// ---------- tela secundária: modo de cultivo / tempo de luz ----------
// Mostra o modo de cultivo, o estado atual da luz (com aviso "(Manual)" se
// houver um override ativo) e, na última linha, ou o tempo restante do
// ciclo automático, ou a contagem regressiva até o override expirar.
void desenharConteudoSecundaria(const DateTime& now) {
  display.setTextSize(1);

  display.setCursor(0, OLED_CONTEUDO_TOPO);
  display.print("Modo: ");
  display.print(modoAtual == VEGETACAO ? "Vegetativo" : "Floracao");

  display.setCursor(0, OLED_CONTEUDO_TOPO + 12);
  display.print("Luz: ");
  display.print(estadoReles[RELE_LUZ] ? "Ligada" : "Desligada");
  if (modoControleLuz != LUZ_AUTOMATICA) {
    display.print(" (Manual)");
  }

  display.setCursor(0, OLED_CONTEUDO_BASE - 8);
  if (modoControleLuz != LUZ_AUTOMATICA) {
    unsigned long restanteMs = (overrideLuzAte > millis()) ? (overrideLuzAte - millis()) : 0;
    unsigned long restanteMin = restanteMs / 60000UL;
    display.print("Auto em: ");
    display.print(restanteMin);
    display.print("min");
  } else {
    display.print("Restante: ");
    display.print(tempoRestante(now));
  }
}

// ---------- tela de configuração: menu interativo dos relés ----------
// Traduz um índice sequencial de item de menu (0..7) para o "código" real
// selecionável em releSelecionadoControle (que mistura sentinelas negativas
// com índices de relé — ver constantes CONTROLE_* lá em cima).
int itemIndexParaRele(int indiceItem) {
  if (indiceItem == 0) return CONTROLE_MODO_CULTIVO;
  if (indiceItem == 1) return CONTROLE_LUZ_MANUAL;
  return indiceItem; // itens 2..7 == relés 2..7 (PRIMEIRO_RELE_MANUAL == 2)
}

// Texto curto do estado do override de luz, usado tanto aqui quanto na
// TELA_SECUNDARIA.
const char* textoModoControleLuz() {
  switch (modoControleLuz) {
    case LUZ_MANUAL_LIGADA: return "ON";
    case LUZ_MANUAL_DESLIGADA: return "OFF";
    case LUZ_AUTOMATICA:
    default: return "Auto";
  }
}

// Lista rolável com 8 itens totais (Modo Cultivo, Luz Manual, relés 2-7),
// mostrando só "visiveis" por vez, com a janela sempre centralizada no item
// selecionado (releSelecionadoControle) e travada nas bordas da lista.
void desenharConteudoConfiguracao() {
  const int totalItens = 8; // modo de cultivo + luz manual + 6 relés manuais
  const int visiveis = 4;

  int indiceAtual;
  if (releSelecionadoControle == CONTROLE_MODO_CULTIVO) {
    indiceAtual = 0;
  } else if (releSelecionadoControle == CONTROLE_LUZ_MANUAL) {
    indiceAtual = 1;
  } else {
    indiceAtual = releSelecionadoControle;
  }

  int inicio = indiceAtual - visiveis / 2;
  if (inicio < 0) inicio = 0;
  if (inicio > totalItens - visiveis) inicio = totalItens - visiveis;
  if (inicio < 0) inicio = 0;

  display.setTextSize(1);
  for (int linha = 0; linha < visiveis && (inicio + linha) < totalItens; linha++) {
    int idx = inicio + linha;
    int releIdx = itemIndexParaRele(idx);
    int y = OLED_CONTEUDO_TOPO + linha * 8;

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
// (única tela "interativa" — ver telaInterativa()): exige pressão longa
// antes de entrar de fato, pra evitar entrar sem querer com um clique curto.
void desenharMenuOLED() {
  display.setTextSize(1);
  display.setCursor(14, OLED_CONTEUDO_TOPO + 8);
  display.print("Configuracoes");
  display.setCursor(4, OLED_CONTEUDO_TOPO + 22);
  display.print("Segure p/ abrir");
}

// ---------- mensagem temporária ----------
// Centraliza o texto tanto na horizontal quanto na vertical, mas só dentro
// da área de conteúdo (entre cabeçalho e rodapé), calculando a largura real
// do texto via getTextBounds() para não depender de contagem de caracteres.
void desenharMensagem() {
  display.setTextSize(1);
  int16_t x1, y1;
  uint16_t w, h;
  display.getTextBounds(linha1Mensagem, 0, 0, &x1, &y1, &w, &h);
  int centroY = OLED_CONTEUDO_TOPO + ((OLED_CONTEUDO_BASE - OLED_CONTEUDO_TOPO) - (int)h) / 2;
  display.setCursor((SCREEN_WIDTH - (int)w) / 2, centroY);
  display.print(linha1Mensagem);
}

// ---------- tela de descanso: sol / lua (animados) ----------
// Estado ocioso padrão do display: NÃO usa o cabeçalho/rodapé comuns — tem
// seu próprio cabeçalho ("GreenGrow") e ocupa a tela cheia com uma animação
// vetorial (sem bitmap) que troca sozinha entre sol/lua conforme o relé de
// luz (RELE_LUZ). Tudo é desenhado só na faixa azul, abaixo da costura, pra
// não conflitar com a faixa amarela do painel físico.

// Centro vertical da área de animação: apenas a faixa azul, abaixo da costura
const int OLED_DESCANSO_CENTRO_Y = OLED_ALTURA_CABECALHO + (SCREEN_HEIGHT - OLED_ALTURA_CABECALHO) / 2;

void desenharCabecalhoDescanso() {
  display.setTextSize(1);
  display.setTextColor(SSD1306_WHITE);

  int16_t x1, y1;
  uint16_t w, h;
  display.getTextBounds("GreenGrow", 0, 0, &x1, &y1, &w, &h);
  display.setCursor((SCREEN_WIDTH - (int)w) / 2, (OLED_ALTURA_CABECALHO - (int)h) / 2);
  display.print("GreenGrow");

  // Separador exatamente na costura entre a faixa amarela e a azul
  display.drawFastHLine(0, OLED_ALTURA_CABECALHO - 1, SCREEN_WIDTH, SSD1306_WHITE);
}

// Desenhado quando a luz interna está LIGADA. Usa millis()/1000.0 como base
// de tempo para animar: núcleo "respirando" (pulsando de tamanho) e 8 raios
// girando lentamente com comprimento variável — tudo calculado a cada
// quadro, sem nenhum estado guardado entre chamadas.
void desenharSol() {
  int cx = SCREEN_WIDTH / 2;
  int cy = OLED_DESCANSO_CENTRO_Y;
  int r = 9;

  float t = millis() / 1000.0;

  // "respiração": o núcleo do sol pulsa suavemente de tamanho
  float pulso = (sin(t * 2.0) + 1.0) / 2.0; // 0..1
  int raioNucleo = r + (int)(pulso * 2.0);

  display.fillCircle(cx, cy, raioNucleo, SSD1306_WHITE);

  // raios giram lentamente ao redor do sol e alternam de comprimento
  float rotacao = t * 0.9;
  for (int i = 0; i < 8; i++) {
    float ang = rotacao + i * (PI / 4.0);
    float variacao = (sin(t * 3.0 + i) + 1.0) / 2.0; // 0..1, defasado por raio
    int comprimento = 5 + (int)(variacao * 3.0);

    int x1 = cx + cos(ang) * (raioNucleo + 3);
    int y1 = cy + sin(ang) * (raioNucleo + 3);
    int x2 = cx + cos(ang) * (raioNucleo + 3 + comprimento);
    int y2 = cy + sin(ang) * (raioNucleo + 3 + comprimento);
    display.drawLine(x1, y1, x2, y2, SSD1306_WHITE);
  }
}

// Desenhado quando a luz interna está DESLIGADA. Lua com leve flutuação
// vertical (efeito "boiando") e 6 estrelas que piscam cada uma em seu
// próprio período (lista fixa "estrelas[]" com posição e velocidade).
void desenharLua() {
  int cx = SCREEN_WIDTH / 2;
  int cy = OLED_DESCANSO_CENTRO_Y;
  int r = 13;

  float t = millis() / 1000.0;

  // leve flutuação vertical da lua (efeito "boiando")
  int deslocY = (int)(sin(t * 0.8) * 2.0);

  display.fillCircle(cx, cy + deslocY, r, SSD1306_WHITE);
  display.fillCircle(cx + 8, cy - 4 + deslocY, r - 3, SSD1306_BLACK);

  // estrelas cintilantes: cada uma pisca em um ciclo próprio, todas dentro da faixa azul
  struct Estrela { int x; int y; unsigned long periodo; };
  static const Estrela estrelas[6] = {
    {14, 22, 900}, {112, 20, 1200}, {10, 58, 1000},
    {116, 55, 750}, {60, 18, 1350}, {96, 60, 1000}
  };

  for (int i = 0; i < 6; i++) {
    unsigned long fase = millis() % estrelas[i].periodo;
    if (fase < estrelas[i].periodo / 2) {
      display.drawPixel(estrelas[i].x, estrelas[i].y, SSD1306_WHITE);
    }
  }
}

// Ponto de entrada da tela de descanso: desenha o cabeçalho próprio e decide
// entre sol/lua com base no estado real do relé de luz.
void desenharTelaDescanso() {
  desenharCabecalhoDescanso();

  if (estadoReles[RELE_LUZ]) {
    desenharSol();
  } else {
    desenharLua();
  }
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
    desenharTelaDescanso();
    display.display();
    return;
  }

  desenharCabecalho(now);
  desenharRodape();

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
        case TELA_SECUNDARIA:
          desenharConteudoSecundaria(now);
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

// Diz se a interface local (botão/menu do OLED) está "ocupada" agora.
// MOTIVO DE EXISTIR: conectar/publicar no Blynk pode segurar o loop() por
// tempo suficiente pra deixar o botão "travado" (sem resposta imediata).
// Por isso, conectarBlynk() e atualizarBlynk() checam isto antes de fazer
// qualquer operação de rede, dando prioridade total à resposta ao usuário.
// Considera "em uso": botão pressionado agora, qualquer tela/menu/mensagem
// diferente da tela de descanso aberta, ou interação recente (< 1.5s).
bool interfaceLocalEmUso() {
  if (botaoPressionado || digitalRead(BOTAO_OLED_PIN) == LOW) {
    return true;
  }

  if (estadoOLED != OLED_DESCANSO || mensagemTemporariaAtiva) {
    return true;
  }

  return millis() - ultimaInteracaoMenuEm < 1500;
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
// digitalWrite direto), para manter estadoReles[] e estadoBlynkPendente
// sempre sincronizados com a realidade.
// A polaridade elétrica (relé ativo em nível baixo ou alto) é definida pela
// constante RELE_ATIVO_EM_BAIXO, lá no topo do arquivo.
void acionarRele(int numero, bool ligado) {
  estadoReles[numero] = ligado;
  int nivelAtivo = RELE_ATIVO_EM_BAIXO ? LOW : HIGH;
  int nivelInativo = RELE_ATIVO_EM_BAIXO ? HIGH : LOW;
  digitalWrite(reles[numero], ligado ? nivelAtivo : nivelInativo);
  estadoBlynkPendente = true;
}

// Avança a seleção no menu de configuração, no ciclo fixo:
// Modo Cultivo -> Luz Manual -> relé 2 -> ... -> relé 7 -> Modo Cultivo ...
void avancarReleControle() {
  if (releSelecionadoControle == CONTROLE_MODO_CULTIVO) {
    releSelecionadoControle = CONTROLE_LUZ_MANUAL;
  } else if (releSelecionadoControle == CONTROLE_LUZ_MANUAL) {
    releSelecionadoControle = PRIMEIRO_RELE_MANUAL;
  } else {
    releSelecionadoControle++;
    if (releSelecionadoControle >= 8) {
      releSelecionadoControle = CONTROLE_MODO_CULTIVO;
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

  if (releSelecionadoControle < PRIMEIRO_RELE_MANUAL || releSelecionadoControle >= 8) {
    return;
  }

  bool novoEstado = !estadoReles[releSelecionadoControle];
  acionarRele(releSelecionadoControle, novoEstado);
  registrarEventoRele(releSelecionadoControle, novoEstado, "manual");
  salvarEstadoNaMemoria();

  oledVoltaDescansoEm = millis() + TEMPO_EXIBICAO_OLED_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarOLED = true;
  definirEstadoLEDTemporario(LED_OK, 800);
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
  definirEstadoLEDTemporario(LED_OK, 800);
  estadoBlynkPendente = true;
}

// Nomes amigáveis dos relés manuais (2-7), usados na TELA_CONFIGURACAO.
// Ajuste aqui se o cabeamento físico dos exaustores/ventiladores mudar.
const char* nomeReleControle(int indiceRele) {
  switch (indiceRele) {
    case 0:
      return "Luz";
    case 1:
      return "Trava Acesso";
    case 2:
      return "Saida Ar Vivosun";
    case 3:
      return "Entrada Ar Fan";
    case 4:
      return "Vent Principal";
    case 5:
      return "Saida Ar Dois";
    case 6:
      return "Entrada Ar Dois";
    case 7:
      return "Ventilador Dois";
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
    registrarEventoModo("floracao");
  } else {
    modoAtual = VEGETACAO;
    mostrarMensagemTemporaria("Modo: Vegetativo", 1500);
    registrarEventoModo("vegetativo");
  }

  oledVoltaDescansoEm = millis() + TEMPO_EXIBICAO_OLED_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarOLED = true;
  definirEstadoLEDTemporario(LED_OK, 1000);
  estadoBlynkPendente = true;
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

void atualizarAcesso() {
  if (acessoAtivo && millis() >= acessoDesligaEm) {
    acionarRele(RELE_ACESSO, false);
    acessoAtivo = false;
    atualizarOLED = true;
  }
}

// ---------------- PERSISTÊNCIA (NVRAM DO RTC DS1307) ----------------
// O DS1307 possui 56 bytes de RAM com backup de bateria (endereços 0x08-0x3F),
// preservados mesmo com queda de energia. Usamos os 3 primeiros bytes para
// guardar: [0] byte marcador de validade, [1] bitmask dos relés, [2] modo de cultivo.
const uint8_t NVRAM_MARCADOR_VALIDO = 0xA5;
const uint8_t NVRAM_ENDERECO_BASE = 0;

void salvarEstadoNaMemoria() {
  if (!rtcDisponivel) return;

  uint8_t bitmaskReles = 0;
  for (int i = 0; i < 8; i++) {
    if (estadoReles[i]) {
      bitmaskReles |= (1 << i);
    }
  }

  uint8_t buffer[3];
  buffer[0] = NVRAM_MARCADOR_VALIDO;
  buffer[1] = bitmaskReles;
  buffer[2] = (modoAtual == FLORACAO) ? 1 : 0;

  rtc.writenvram(NVRAM_ENDERECO_BASE, buffer, 3);
}

void restaurarEstadoDaMemoria() {
  if (!rtcDisponivel) return;

  uint8_t buffer[3];
  rtc.readnvram(buffer, 3, NVRAM_ENDERECO_BASE);

  if (buffer[0] != NVRAM_MARCADOR_VALIDO) {
    // Memória nunca foi gravada (RTC novo/zerado): mantém os padrões de fábrica.
    return;
  }

  uint8_t bitmaskReles = buffer[1];
  modoAtual = (buffer[2] == 1) ? FLORACAO : VEGETACAO;

  // Restaura apenas os relés manuais (exaustores/ventiladores). A luz (índice 0)
  // é recalculada automaticamente por controlarLuz() a partir do horário, e o
  // relé de acesso/trava (índice 1) nunca é restaurado como ligado por segurança.
  for (int i = PRIMEIRO_RELE_MANUAL; i < 8; i++) {
    bool ligado = bitmaskReles & (1 << i);
    acionarRele(i, ligado);
  }

  Serial.println("Estado dos reles e modo de cultivo restaurados da memoria do RTC");
  atualizarOLED = true;
  estadoBlynkPendente = true;
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
      blynkConfigurado = false;
      wifiDesconectadoDesde = 0;
      Serial.println("WiFi reconectado");
    }
    return;
  }

  if (wifiConectado) {
    wifiConectado = false;
    blynkConfigurado = false;
    wifiDesconectadoDesde = millis();
    Serial.println("WiFi desconectado");
    Blynk.disconnect();
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

  char bufferHora[20];
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
// prioridade: RTC físico (se disponível e com ano plausível, >= 2024) ->
// relógio interno do ESP32 já sincronizado via NTP -> data fixa de fallback
// (2026-01-01 00:00:00), usada só quando não há nenhuma fonte de hora
// confiável (RTC sem bateria/nunca sincronizado E sem WiFi).
DateTime obterAgora() {
  if (rtcDisponivel) {
    DateTime agoraRTC = rtc.now();

    if (agoraRTC.year() >= 2024) {
      return agoraRTC;
    }
  }

  time_t agoraEpoch = time(nullptr);
  if (agoraEpoch > 100000) {
    return DateTime(agoraEpoch);
  }

  return DateTime(2026, 1, 1, 0, 0, 0);
}

// Formata a hora atual como string "AAAA-MM-DD HH:MM:SS" (usado na
// telemetria do Blynk, pino V8).
String obterTimestampAtual() {
  DateTime now = obterAgora();
  char buffer[20];
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

// FUNÇÕES DE LOG — ainda são "stubs" (não fazem nada, só existem para não
// quebrar as chamadas espalhadas pelo código). Ponto de extensão pronto para
// registrar eventos de verdade no futuro (ex: gravar num cartão SD, mandar
// pra um servidor, ou publicar num tópico MQTT) sem precisar mexer em mais
// nenhum outro lugar do código — só implementar o corpo destas 3 funções.
void registrarEventoModo(const char* estadoModo) {
  (void)estadoModo;
}

void registrarEventoRele(int indiceRele, bool ligado, const char* origem) {
  (void)indiceRele;
  (void)ligado;
  (void)origem;
}

void registrarEventoAcesso(const char* origem, const char* resultado) {
  (void)origem;
  (void)resultado;
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
    estadoBlynkPendente = true;
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

// ---------------- BLYNK — conexão e publicação de dados ----------------
// Tenta conectar no servidor Blynk. Não-bloqueante em relação ao loop()
// principal (timeout curto de 1.5s) e respeita RETENTATIVA_BLYNK_MS entre
// tentativas. Cede prioridade à interface local (interfaceLocalEmUso()) —
// não tenta conectar enquanto o usuário está mexendo no menu do OLED.
void conectarBlynk() {
  if (!wifiConectado) return;
  if (Blynk.connected()) return;
  if (millis() - ultimaTentativaBlynkEm < RETENTATIVA_BLYNK_MS) return;
  if (interfaceLocalEmUso()) return;

  ultimaTentativaBlynkEm = millis();
  if (!blynkConfigurado) {
    Blynk.config(BLYNK_AUTH_TOKEN, "blynk.cloud", 80);
    blynkConfigurado = true;
    Serial.println("Blynk configurado");
  }

  Serial.println("Tentando conectar ao Blynk...");
  bool conectado = Blynk.connect(1500);
  if (conectado) {
    Serial.println("Blynk conectado");
  } else {
    Serial.println("Falha ao conectar no Blynk");
  }
}

// Publica os dados de "monitoramento" (sensores, timestamp) no Blynk a cada
// INTERVALO_TELEMETRIA_BLYNK_MS (2 minutos) — intervalo longo de propósito,
// pra não gastar franquia/tráfego de rede com dados que mudam pouco.
void publicarTelemetriaBlynk() {
  if (!Blynk.connected()) return;
  if (millis() - ultimoEnvioTelemetriaBlynkEm < INTERVALO_TELEMETRIA_BLYNK_MS) return;

  ultimoEnvioTelemetriaBlynkEm = millis();

  Blynk.beginGroup();
  Blynk.virtualWrite(V2, isnan(tempAtual) ? 0 : tempAtual);
  Blynk.virtualWrite(V1, isnan(umidAtual) ? 0 : umidAtual);
  Blynk.virtualWrite(V4, soloPercentual);
  Blynk.virtualWrite(V5, estadoReles[RELE_LUZ] ? 1 : 0);
  Blynk.virtualWrite(V6, modoAtual == VEGETACAO ? "vegetativo" : "floracao");
  Blynk.virtualWrite(V7, (estadoReles[RELE_EXAUSTOR_1] || estadoReles[RELE_EXAUSTOR_2]) ? 1 : 0);
  Blynk.virtualWrite(V8, obterTimestampAtual());
  Blynk.virtualWrite(V9, tempoRestante(obterAgora()));
  Blynk.endGroup();
}

// Publica o estado dos relés/modo no Blynk (o "espelho" do que está
// realmente ligado/desligado). Diferente da telemetria, só publica quando
// há mudança pendente (estadoBlynkPendente) — reage rápido a qualquer ação
// local ou remota, mas sem republicar o mesmo valor repetidamente.
void publicarEstadoBlynk() {
  if (!Blynk.connected()) return;
  if (!estadoBlynkPendente) return;
  if (millis() - ultimoEnvioEstadoBlynkEm < INTERVALO_ESTADO_BLYNK_MS) return;

  ultimoEnvioEstadoBlynkEm = millis();
  DateTime now = obterAgora();

  Blynk.beginGroup();
  Blynk.virtualWrite(V9, tempoRestante(now));
  Blynk.virtualWrite(V10, modoAtual == FLORACAO ? 1 : 0);
  Blynk.virtualWrite(V11, estadoReles[2] ? 1 : 0);
  Blynk.virtualWrite(V12, estadoReles[3] ? 1 : 0);
  Blynk.virtualWrite(V13, estadoReles[4] ? 1 : 0);
  Blynk.virtualWrite(V14, estadoReles[5] ? 1 : 0);
  Blynk.virtualWrite(V15, estadoReles[6] ? 1 : 0);
  Blynk.virtualWrite(V16, estadoReles[7] ? 1 : 0);
  Blynk.endGroup();
  estadoBlynkPendente = false;
}

// Aciona o menu do OLED remotamente pelo app (V18/V19) — chama exatamente a
// mesma lógica usada pelo botão físico, garantindo comportamento idêntico.
void executarCliqueCurtoMenuRemoto() {
  ultimaInteracaoMenuEm = millis();
  beep(80);
  tratarCliqueCurtoBotaoOLED();
}

void executarCliqueLongoMenuRemoto() {
  ultimaInteracaoMenuEm = millis();
  if (estadoOLED == OLED_MENU) {
    beep(80);
    selecionarTelaOLED();
  } else if (estadoOLED == OLED_EXIBINDO && telaSelecionada == TELA_CONFIGURACAO) {
    beep(80);
    alternarReleSelecionado();
  }
}

// Orquestra toda a integração com Blynk a cada loop(): conecta se
// necessário, processa mensagens pendentes (Blynk.run()), e só então tenta
// publicar telemetria/estado — sempre cedendo a vez se a interface local
// estiver em uso (ver interfaceLocalEmUso()).
void atualizarBlynk() {
  if (!wifiConectado) return;
  conectarBlynk();
  if (!Blynk.connected()) return;

  Blynk.run();
  if (interfaceLocalEmUso()) return;

  publicarTelemetriaBlynk();
  publicarEstadoBlynk();
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
      definirEstadoLEDTemporario(LED_OK, 1000);
      beep(150);
      mostrarMensagemTemporaria("Porta Liberada", 1500);
      registrarEventoAcesso("botao", "liberado");
  }
}

// ---------------- SETUP ----------------
// Helper usado só durante o boot, antes do sistema de telas normal estar
// ativo (cabeçalho/rodapé/estadoOLED ainda não existem nesse momento).
void mensagemInicializacao(const String& linha1, const String& linha2 = "") {
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
//   3) Barramento I2C (Wire.begin nos pinos SDA/SCL) — precisa vir ANTES de
//      qualquer dispositivo I2C (display, RTC, SHT40).
//   4) Display OLED, sensor de ar (DHT ou SHT40 conforme WOKWI_SIMULATION).
//   5) RTC: detecta, verifica se o oscilador está rodando (bateria viva) e,
//      se não estiver, ajusta pra hora de compilação como ponto de partida.
//   6) Restaura relés/modo salvos na NVRAM do RTC (queda de energia anterior).
//   7) WiFi (bloqueante, só aqui) -> NTP -> configura Blynk se conectou.
//   8) Feedback sonoro/visual de boot concluído e entra em OLED_DESCANSO.
void setup() {
  Serial.begin(115200);

  pinMode(BUZZER, OUTPUT);
  digitalWrite(BUZZER, LOW);

  for (int i = 0; i < 8; i++) {
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

  Wire.begin(SDA_PIN, SCL_PIN);

  if (!display.begin(SSD1306_SWITCHCAPVCC, SCREEN_ADDRESS)) {
    Serial.println("Falha ao iniciar o display OLED");
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

  mensagemInicializacao("Conectando WiFi");
  ultimaTentativaWiFi = millis();
  wifiConectado = conectarWiFi();
  Serial.println(wifiConectado ? "WiFi conectado" : "Falha ao conectar WiFi");

  if (wifiConectado) {
    mensagemInicializacao("Sincronizando", "hora via NTP");
    horaSincronizada = sincronizarHoraNTP();
    Blynk.config(BLYNK_AUTH_TOKEN, "blynk.cloud", 80);
    blynkConfigurado = true;
  }

  if (wifiConectado && horaSincronizada) {
    mensagemInicializacao("Sistema OK", "Hora sincronizada");
  } else if (rtcDisponivel) {
    mensagemInicializacao("Sistema OK", "Usando RTC local");
  } else {
    mensagemInicializacao("Sem RTC/WiFi", "Hora limitada");
  }

  beep(300);
  definirEstadoLEDTemporario(LED_OK, 1000);
  delay(1200);

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
  atualizarLED();              // efeito visual de status (NeoPixel)
  atualizarBuzzer();           // desliga o beep quando o tempo expira
  atualizarAcesso();           // fecha a trava quando o tempo expira
  atualizarMensagemTemporaria(); // fecha mensagens temporárias do OLED
  atualizarBotaoOLED();        // lê o botão de navegação (debounce + clique curto/longo)
  atualizarBotaoAcesso();      // lê o botão físico de liberar acesso
  atualizarTimeoutOLED();      // volta pra tela de descanso por inatividade
  atualizarDHT();              // lê temperatura/umidade do ar (a cada 2s)
  atualizarPulsoSensor();      // apaga o indicador de "sensor vivo"
  atualizarSolo();             // lê umidade do solo (a cada 1s, com média)
  atualizarSincronizacaoHora(); // tenta NTP periodicamente até sincronizar
  atualizarConexaoWiFi();      // reconecta WiFi com backoff se cair
  atualizarBlynk();            // conecta/roda/publica no Blynk

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
