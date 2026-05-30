#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <RTClib.h>

// Credenciais Blynk
#define BLYNK_TEMPLATE_ID   "SEU_TEMPLATE_ID"
#define BLYNK_TEMPLATE_NAME "Nome do Template"
#define BLYNK_AUTH_TOKEN    "SEU_AUTH_TOKEN"

// Wokwi:
#define WOKWI_SIMULATION 0
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
// Credenciais Wi-Fi
const char* ssid     = "SUA_REDE";
const char* password = "SUA_SENHA";

const long GMT_OFFSET_SEC = -3 * 3600;
const int DAYLIGHT_OFFSET_SEC = 0;
const unsigned long WIFI_TIMEOUT_MS = 15000;
const unsigned long WIFI_TIMEOUT_INICIAL_MS = 5000;
const unsigned long RETENTATIVA_WIFI_INICIAL_MS = 5000;
const unsigned long RETENTATIVA_WIFI_POSTERIOR_MS = 30000;
const unsigned long JANELA_WIFI_INICIAL_MS = 60000;
const unsigned long NTP_TIMEOUT_MS = 10000;
const unsigned long NTP_TIMEOUT_INICIAL_MS = 3000;
const unsigned long RETENTATIVA_NTP_MS = 60000;
const unsigned long INTERVALO_TELEMETRIA_BLYNK_MS = 2000;
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
const int SOIL_DRY_RAW = 3200;
const int SOIL_WET_RAW = 2300;
const int SOIL_AMOSTRAS = 8;

#define BOTAO_LCD_PIN 5
#define BOTAO_TRV_PIN 19

#define BUZZER 17
#define LED_PIN 48

int reles[8] = {42, 41, 40, 39, 38, 37, 36, 35};

// ---------------- RELÉS UTILIZADOS ----------------
const int RELE_LUZ = 0;
const int RELE_ACESSO = 1;
const int PRIMEIRO_RELE_MANUAL = 2;
const int CONTROLE_MODO_CULTIVO = -1;
const int RELE_EXAUSTOR_1 = 2;
const int RELE_EXAUSTOR_2 = 3;

// ---------------- OBJETOS ----------------
LiquidCrystal_I2C lcd(0x27, 16, 2);
RTC_DS3231 rtc;
#if WOKWI_SIMULATION
DHT dht(DHTPIN, DHTTYPE);
#else
Adafruit_SHT4x sht4 = Adafruit_SHT4x();
#endif
Adafruit_NeoPixel pixel(1, LED_PIN, NEO_GRB + NEO_KHZ800);

// ---------------- LED ----------------
unsigned long tempoLED = 0;
int brilho = 0;
int direcao = 5;

enum EstadoLED {
  AGUARDANDO,
  CONECTANDO_WIFI,
  CONECTANDO_BLYNK,
  BLYNK_CONECTADO,
  PORTA_ABERTA_LED,
  LED_OK,
  ERRO
};
EstadoLED estadoLED = AGUARDANDO;
unsigned long ledAte = 0;

// ---------------- CULTIVO ----------------
enum ModoCultivo { VEGETACAO, FLORACAO };
ModoCultivo modoAtual = VEGETACAO;

// ---------------- LCD / BOTAO ----------------
enum TelaLCD {
  TELA_DATA_HORA,
  TELA_SENSOR_INTERNO,
  TELA_STATUS_LED,
  TELA_MODO_VEGETATIVO,
  TELA_CONTROLE_RELES,
  TOTAL_TELAS_LCD
};

enum EstadoLCD {
  LCD_DESLIGADO,
  LCD_MENU,
  LCD_EXIBINDO,
  LCD_MENSAGEM
};

EstadoLCD estadoLCD = LCD_DESLIGADO;
TelaLCD telaSelecionada = TELA_DATA_HORA;
bool mensagemTemporariaAtiva = false;
unsigned long mensagemAte = 0;
String linha1Mensagem = "";
bool atualizarLCD = true;
bool lcdLigado = false;
unsigned long lcdDesligaEm = 0;
bool ultimoEstadoBotao = HIGH;
bool estadoEstavelBotaoLCD = HIGH;
bool botaoPressionado = false;
unsigned long botaoMudouEm = 0;
unsigned long botaoPressionadoEm = 0;
const unsigned long DEBOUNCE_BOTAO_MS = 50;
const unsigned long PRESSAO_LONGA_MS = 1200;
const unsigned long TEMPO_EXIBICAO_LCD_MS = 15000;
int releSelecionadoControle = CONTROLE_MODO_CULTIVO;

// ---------------- DHT ----------------
float tempAtual = NAN;
float umidAtual = NAN;
unsigned long ultimoDHT = 0;
const unsigned long INTERVALO_DHT_MS = 2000;
// ---------------- SOLO ----------------
int soloBruto = 0;
int soloPercentual = 0;
unsigned long ultimoSolo = 0;
const unsigned long INTERVALO_SOLO_MS = 1000;

// ---------------- BUZZER ----------------
bool buzzerLigado = false;
unsigned long buzzerDesligaEm = 0;

// ---------------- ACESSO ----------------
bool acessoAtivo = false;
unsigned long acessoDesligaEm = 0;

// ---------------- STATUS ----------------
bool rtcDisponivel = false;
#if !WOKWI_SIMULATION
bool sht40Disponivel = false;
#endif
bool wifiConectado = false;
bool horaSincronizada = false;
bool ntpConfigurado = false;
bool blynkConfigurado = false;
unsigned long ultimaTentativaWiFi = 0;
unsigned long wifiDesconectadoDesde = 0;
unsigned long ultimaTentativaNTP = 0;
unsigned long ultimaInteracaoMenuEm = 0;
unsigned long ultimoEnvioTelemetriaBlynkEm = 0;
unsigned long ultimoEnvioEstadoBlynkEm = 0;
unsigned long ultimaTentativaBlynkEm = 0;
bool estadoBlynkPendente = true;

// ---------------- ESTADO DOS RELÉS ----------------
bool estadoReles[8] = {false, false, false, false, false, false, false, false};

// ---------------- PROTOTIPOS ----------------
void beep(unsigned long tempo = 200);
void avancarReleControle();
void alternarReleSelecionado();
void alternarModoCultivo();
bool telaInterativa(TelaLCD tela);
TelaLCD proximaTelaLCD(TelaLCD telaAtualBase);
void abrirTelaRapidaLCD();
void avancarNavegacaoLCD();
const char* nomeReleControle(int indiceRele);
bool conectarWiFi(unsigned long timeoutMs = WIFI_TIMEOUT_MS);
void atualizarConexaoWiFi();
bool rtcHoraValida();
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
bool sincronizarHoraNTP(unsigned long timeoutMs = NTP_TIMEOUT_MS);

BLYNK_CONNECTED() {
  estadoBlynkPendente = true;
  Blynk.syncVirtual(V10, V11, V12, V13, V14, V15, V16, V17);
}

BLYNK_WRITE(V10) {
  ModoCultivo modoDesejado = param.asInt() == 1 ? FLORACAO : VEGETACAO;
  if (modoDesejado != modoAtual) {
    modoAtual = modoDesejado;
    mostrarMensagemTemporaria(modoAtual == FLORACAO ? "Modo: Floracao" : "Modo: Vegetativo", 1500);
    estadoBlynkPendente = true;
    atualizarLCD = true;
    definirEstadoLEDTemporario(LED_OK, 700);
  }
}

BLYNK_WRITE(V11) { aplicarComandoReleBlynk(2, param.asInt()); }
BLYNK_WRITE(V12) { aplicarComandoReleBlynk(3, param.asInt()); }
BLYNK_WRITE(V13) { aplicarComandoReleBlynk(4, param.asInt()); }
BLYNK_WRITE(V14) { aplicarComandoReleBlynk(5, param.asInt()); }
BLYNK_WRITE(V15) { aplicarComandoReleBlynk(6, param.asInt()); }
BLYNK_WRITE(V16) { aplicarComandoReleBlynk(7, param.asInt()); }

BLYNK_WRITE(V17) {
  if (param.asInt() == 1) {
    liberarAcessoPor(3000);
    definirEstadoLEDTemporario(LED_OK, 1000);
    beep(150);
    mostrarMensagemTemporaria("Porta Liberada", 1500);
    Blynk.virtualWrite(V17, 0);
  }
}

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

void definirEstadoLEDTemporario(EstadoLED novoEstado, unsigned long duracaoMs) {
  estadoLED = novoEstado;
  ledAte = millis() + duracaoMs;
}

void aplicarComandoReleBlynk(int indiceRele, int valor) {
  bool novoEstado = valor == 1;
  if (estadoReles[indiceRele] == novoEstado) return;

  acionarRele(indiceRele, novoEstado);
  registrarEventoRele(indiceRele, novoEstado, "blynk");
  atualizarLCD = true;
  definirEstadoLEDTemporario(LED_OK, 700);
}

// ---------------- LCD ----------------
void mostrarMensagemTemporaria(const String& linha1, unsigned long duracaoMs) {
  ligarLCD();
  estadoLCD = LCD_MENSAGEM;
  linha1Mensagem = linha1;
  mensagemTemporariaAtiva = true;
  mensagemAte = millis() + duracaoMs;
  lcdDesligaEm = mensagemAte;
  atualizarLCD = true;
}

void atualizarMensagemTemporaria() {
  if (mensagemTemporariaAtiva && millis() >= mensagemAte) {
    mensagemTemporariaAtiva = false;
    atualizarLCD = true;
  }
}

void ligarLCD() {
  if (lcdLigado) return;
  lcd.display();
  lcd.backlight();
  lcdLigado = true;
  atualizarLCD = true;
}

void desligarLCD() {
  lcd.clear();
  lcd.noBacklight();
  lcd.noDisplay();
  lcdLigado = false;
  estadoLCD = LCD_DESLIGADO;
  mensagemTemporariaAtiva = false;
  lcdDesligaEm = 0;
  atualizarLCD = false;
}

bool telaInterativa(TelaLCD tela) {
  return tela == TELA_CONTROLE_RELES;
}

TelaLCD proximaTelaLCD(TelaLCD telaAtualBase) {
  int proximaTela = static_cast<int>(telaAtualBase) + 1;
  if (proximaTela >= TOTAL_TELAS_LCD) {
    proximaTela = 0;
  }
  return static_cast<TelaLCD>(proximaTela);
}

void abrirTelaRapidaLCD() {
  ligarLCD();
  telaSelecionada = TELA_DATA_HORA;
  estadoLCD = LCD_EXIBINDO;
  lcdDesligaEm = millis() + TEMPO_EXIBICAO_LCD_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarLCD = true;
}

void selecionarTelaLCD() {
  ligarLCD();
  estadoLCD = LCD_EXIBINDO;
  if (telaSelecionada == TELA_CONTROLE_RELES) {
    releSelecionadoControle = CONTROLE_MODO_CULTIVO;
  }
  lcdDesligaEm = millis() + TEMPO_EXIBICAO_LCD_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarLCD = true;
}

void atualizarLCDTimeout() {
  if (estadoLCD != LCD_DESLIGADO && lcdDesligaEm > 0 && millis() >= lcdDesligaEm) {
    desligarLCD();
  }
}

void avancarNavegacaoLCD() {
  telaSelecionada = proximaTelaLCD(telaSelecionada);
  estadoLCD = telaInterativa(telaSelecionada) ? LCD_MENU : LCD_EXIBINDO;
  lcdDesligaEm = millis() + TEMPO_EXIBICAO_LCD_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarLCD = true;
}

void tratarCliqueCurtoBotaoLCD() {
  if (estadoLCD == LCD_DESLIGADO) {
    abrirTelaRapidaLCD();
    return;
  }

  if (estadoLCD == LCD_MENU) {
    avancarNavegacaoLCD();
    return;
  }

  if (estadoLCD == LCD_EXIBINDO && telaSelecionada == TELA_CONTROLE_RELES) {
    avancarReleControle();
    return;
  }

  if (estadoLCD == LCD_EXIBINDO || estadoLCD == LCD_MENU) {
    avancarNavegacaoLCD();
  }
}

void atualizarBotaoLCD() {
  bool leitura = digitalRead(BOTAO_LCD_PIN);

  if (leitura != ultimoEstadoBotao) {
    botaoMudouEm = millis();
    ultimoEstadoBotao = leitura;
  }

  if (millis() - botaoMudouEm < DEBOUNCE_BOTAO_MS) {
    return;
  }

  if (leitura == estadoEstavelBotaoLCD) {
    return;
  }

  estadoEstavelBotaoLCD = leitura;
  ultimaInteracaoMenuEm = millis();

  if (estadoEstavelBotaoLCD == LOW) {
    botaoPressionado = true;
    botaoPressionadoEm = millis();
    return;
  }

  if (botaoPressionado) {
    unsigned long tempoPressionado = millis() - botaoPressionadoEm;
    botaoPressionado = false;

    if (tempoPressionado >= PRESSAO_LONGA_MS && estadoLCD == LCD_MENU) {
      beep(80);
      selecionarTelaLCD();
    } else if (tempoPressionado >= PRESSAO_LONGA_MS &&
               estadoLCD == LCD_EXIBINDO &&
               telaSelecionada == TELA_CONTROLE_RELES) {
      beep(80);
      alternarReleSelecionado();
    } else if (tempoPressionado >= DEBOUNCE_BOTAO_MS) {
      beep(80);
      tratarCliqueCurtoBotaoLCD();
    }
  }
}

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
void acionarRele(int numero, bool ligado) {
  estadoReles[numero] = ligado;
  digitalWrite(reles[numero], ligado ? LOW : HIGH);
  estadoBlynkPendente = true;
}

void avancarReleControle() {
  if (releSelecionadoControle == CONTROLE_MODO_CULTIVO) {
    releSelecionadoControle = PRIMEIRO_RELE_MANUAL;
  } else {
    releSelecionadoControle++;
    if (releSelecionadoControle >= 8) {
      releSelecionadoControle = CONTROLE_MODO_CULTIVO;
    }
  }
  lcdDesligaEm = millis() + TEMPO_EXIBICAO_LCD_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarLCD = true;
}

void alternarReleSelecionado() {
  if (releSelecionadoControle == CONTROLE_MODO_CULTIVO) {
    alternarModoCultivo();
    return;
  }

  if (releSelecionadoControle < PRIMEIRO_RELE_MANUAL || releSelecionadoControle >= 8) {
    return;
  }

  bool novoEstado = !estadoReles[releSelecionadoControle];
  acionarRele(releSelecionadoControle, novoEstado);
  registrarEventoRele(releSelecionadoControle, novoEstado, "manual");

  lcdDesligaEm = millis() + TEMPO_EXIBICAO_LCD_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarLCD = true;
  definirEstadoLEDTemporario(LED_OK, 800);
}

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

  lcdDesligaEm = millis() + TEMPO_EXIBICAO_LCD_MS;
  ultimaInteracaoMenuEm = millis();
  atualizarLCD = true;
  definirEstadoLEDTemporario(LED_OK, 1000);
  estadoBlynkPendente = true;
}

void liberarAcessoPor(unsigned long duracaoMs) {
  acionarRele(RELE_ACESSO, true);
  acessoAtivo = true;
  acessoDesligaEm = millis() + duracaoMs;
  atualizarLCD = true;
}

void atualizarAcesso() {
  if (acessoAtivo && millis() >= acessoDesligaEm) {
    acionarRele(RELE_ACESSO, false);
    acessoAtivo = false;
    atualizarLCD = true;
  }
}

// ---------------- TEMPO ----------------
bool conectarWiFi(unsigned long timeoutMs) {
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.persistent(false);
  WiFi.disconnect(true, true);
  delay(100);
  WiFi.begin(ssid, password);

  unsigned long inicio = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - inicio < timeoutMs) {
    delay(250);
  }

  Serial.print("Status WiFi: ");
  Serial.println(static_cast<int>(WiFi.status()));
  return WiFi.status() == WL_CONNECTED;
}

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

bool sincronizarHoraNTP(unsigned long timeoutMs) {
  if (!wifiConectado) {
    return false;
  }

  if (!ntpConfigurado) {
    configTime(GMT_OFFSET_SEC, DAYLIGHT_OFFSET_SEC, "pool.ntp.org", "time.nist.gov");
    ntpConfigurado = true;
  }

  struct tm timeinfo;
  unsigned long inicio = millis();
  while (!getLocalTime(&timeinfo) && millis() - inicio < timeoutMs) {
    delay(250);
  }

  if (!getLocalTime(&timeinfo)) {
    return false;
  }

  if (rtcDisponivel) {
    rtc.adjust(DateTime(
      timeinfo.tm_year + 1900,
      timeinfo.tm_mon + 1,
      timeinfo.tm_mday,
      timeinfo.tm_hour,
      timeinfo.tm_min,
      timeinfo.tm_sec
    ));
  }

  horaSincronizada = true;
  return true;
}

void atualizarSincronizacaoHora() {
  if (horaSincronizada) return;
  if (!wifiConectado) return;
  if (millis() - ultimaTentativaNTP < RETENTATIVA_NTP_MS) return;

  ultimaTentativaNTP = millis();
  if (sincronizarHoraNTP()) {
    atualizarLCD = true;
  }
}

bool rtcHoraValida() {
  if (!rtcDisponivel) return false;
  if (rtc.lostPower()) return false;
  DateTime agoraRTC = rtc.now();
  return agoraRTC.year() >= 2024;
}

DateTime obterAgora() {
  if (rtcHoraValida()) {
    return rtc.now();
  }

  time_t agoraEpoch = time(nullptr);
  if (agoraEpoch > 100000) {
    return DateTime(agoraEpoch);
  }

  return DateTime(2026, 1, 1, 0, 0, 0);
}

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

// ---------------- DHT ----------------
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
    sht4.getEvent(&humidity, &temp);
    novaTemp = temp.temperature;
    novaUmid = humidity.relative_humidity;
  }
#endif

  if (!isnan(novaTemp) && (isnan(tempAtual) || fabs(tempAtual - novaTemp) >= 0.1f)) {
    tempAtual = novaTemp;
    atualizarLCD = true;
  }

  if (!isnan(novaUmid) && (isnan(umidAtual) || fabs(umidAtual - novaUmid) >= 1.0f)) {
    umidAtual = novaUmid;
    atualizarLCD = true;
  }
}

// ---------------- SOLO ----------------
void atualizarSolo() {
  if (millis() - ultimoSolo < INTERVALO_SOLO_MS) return;
  ultimoSolo = millis();

  long somaLeituras = 0;
  for (int i = 0; i < SOIL_AMOSTRAS; i++) {
    somaLeituras += analogRead(SOIL_PIN);
    delay(2);
  }

  int leitura = static_cast<int>(somaLeituras / SOIL_AMOSTRAS);
  leitura = constrain(leitura, 0, 4095);

  int percentual = map(leitura, SOIL_DRY_RAW, SOIL_WET_RAW, 0, 100);
  percentual = constrain(percentual, 0, 100);

  if (percentual != soloPercentual) {
    soloPercentual = percentual;
    soloBruto = leitura;
    atualizarLCD = true;
  }
}

const char* estadoSolo() {
  if (soloPercentual >= 50) return "Umido";
  return "Seco";
}

// ---------------- CULTIVO ----------------
bool luzDeveEstarLigada(const DateTime& now) {
  int hora = now.hour();

  if (modoAtual == VEGETACAO) {
    return (hora >= 16 || hora < 10);
  }

  return (hora >= 19 || hora < 7);
}

void controlarLuz(const DateTime& now) {
  bool estadoAnterior = estadoReles[RELE_LUZ];
  bool novoEstado = luzDeveEstarLigada(now);

  acionarRele(RELE_LUZ, novoEstado);

  if (estadoAnterior != novoEstado) {
    registrarEventoRele(RELE_LUZ, novoEstado, "automatico_luz");
    atualizarLCD = true;
  }
}

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

// ---------------- LCD RENDER ----------------
void imprimirDoisDigitos(int valor) {
  if (valor < 10) lcd.print("0");
  lcd.print(valor);
}

String formatarTemperatura(float valor) {
  if (isnan(valor)) return "--,-";

  char buffer[10];
  dtostrf(valor, 4, 1, buffer);
  String texto = String(buffer);
  texto.trim();
  texto.replace(".", ",");
  return texto;
}

void exibirMenuLCD() {
  lcd.setCursor(0, 0);
  lcd.print("> ");

  switch (telaSelecionada) {
    case TELA_DATA_HORA:
      lcd.print("Data e Hora");
      break;
    case TELA_SENSOR_INTERNO:
      lcd.print("Sensor Interno");
      break;
    case TELA_STATUS_LED:
      lcd.print("Status do LED");
      break;
    case TELA_MODO_VEGETATIVO:
      lcd.print("Modo Cultivo");
      break;
    case TELA_CONTROLE_RELES:
      lcd.print("Controles");
      break;
    default:
      break;
  }

}

void exibirTelaSelecionada(const DateTime& now) {
  switch (telaSelecionada) {
    case TELA_DATA_HORA:
      lcd.setCursor(0, 0);
      lcd.print("DATA: ");
      imprimirDoisDigitos(now.day());
      lcd.print("/");
      imprimirDoisDigitos(now.month());
      lcd.print("/");
      imprimirDoisDigitos(now.year() % 100);

      lcd.setCursor(0, 1);
      lcd.print("HORA: ");
      imprimirDoisDigitos(now.hour());
      lcd.print(":");
      imprimirDoisDigitos(now.minute());
      break;

    case TELA_SENSOR_INTERNO:
      lcd.setCursor(0, 0);
      lcd.print("SENSOR INTERNO");

      lcd.setCursor(0, 1);
      lcd.print("T:");
      lcd.print(formatarTemperatura(tempAtual));
      lcd.print(" U:");
      if (isnan(umidAtual)) {
        lcd.print("--");
      } else {
        lcd.print(umidAtual, 0);
      }
      lcd.print("%");
      break;

    case TELA_STATUS_LED:
      lcd.setCursor(0, 0);
      lcd.print("LED: ");
      if (estadoReles[RELE_LUZ]) lcd.print("Ligado");
      else lcd.print("Desligado");

      lcd.setCursor(0, 1);
      lcd.print("REST: ");
      lcd.print(tempoRestante(now));
      break;

    case TELA_MODO_VEGETATIVO:
      lcd.setCursor(0, 0);
      lcd.print("MODO: ");
      if (modoAtual == VEGETACAO) lcd.print("Vegetativo");
      else lcd.print("Floracao");

      lcd.setCursor(0, 1);
      lcd.print("SOLO: ");
      lcd.print(estadoSolo());
      break;

    case TELA_CONTROLE_RELES:
      lcd.setCursor(0, 0);
      if (releSelecionadoControle == CONTROLE_MODO_CULTIVO) {
        lcd.print("Modo Cultivo");
      } else {
        lcd.print(nomeReleControle(releSelecionadoControle));
      }

      lcd.setCursor(0, 1);
      if (releSelecionadoControle == CONTROLE_MODO_CULTIVO) {
        lcd.print("Estado: ");
        lcd.print(modoAtual == VEGETACAO ? "Vega" : "Flora");
      } else {
        lcd.print("Estado: ");
        lcd.print(estadoReles[releSelecionadoControle] ? "ON" : "OFF");
      }
      break;

    default:
      break;
  }
}

void renderizarLCD(const DateTime& now) {
  if (!lcdLigado || !atualizarLCD) return;

  atualizarLCD = false;
  lcd.clear();

  if (estadoLCD == LCD_MENSAGEM) {
    lcd.setCursor(0, 0);
    lcd.print(linha1Mensagem.substring(0, 16));
    return;
  }

  if (estadoLCD == LCD_MENU) {
    exibirMenuLCD();
    return;
  }

  if (estadoLCD == LCD_EXIBINDO) {
    exibirTelaSelecionada(now);
  }
}

bool interfaceLocalEmUso() {
  if (botaoPressionado || digitalRead(BOTAO_LCD_PIN) == LOW) {
    return true;
  }

  if (lcdLigado || estadoLCD != LCD_DESLIGADO || mensagemTemporariaAtiva) {
    return true;
  }

  return millis() - ultimaInteracaoMenuEm < 1500;
}

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
  bool conectado = Blynk.connect(500);
  if (conectado) {
    Serial.println("Blynk conectado");
  } else {
    Serial.println("Falha ao conectar no Blynk");
  }
}

void publicarTelemetriaBlynk() {
  if (!Blynk.connected()) return;
  if (millis() - ultimoEnvioTelemetriaBlynkEm < INTERVALO_TELEMETRIA_BLYNK_MS) return;

  ultimoEnvioTelemetriaBlynkEm = millis();

  Blynk.beginGroup();
  if (!isnan(tempAtual)) Blynk.virtualWrite(V2, tempAtual);
  if (!isnan(umidAtual)) Blynk.virtualWrite(V1, umidAtual);
  Blynk.virtualWrite(V4, soloPercentual);
  Blynk.virtualWrite(V5, estadoReles[RELE_LUZ] ? 1 : 0);
  Blynk.virtualWrite(V6, modoAtual == VEGETACAO ? "vegetativo" : "floracao");
  Blynk.virtualWrite(V7, (estadoReles[RELE_EXAUSTOR_1] || estadoReles[RELE_EXAUSTOR_2]) ? 1 : 0);
  Blynk.virtualWrite(V8, obterTimestampAtual());
  Blynk.virtualWrite(V9, tempoRestante(obterAgora()));
  Blynk.endGroup();
}

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

void executarCliqueCurtoMenuRemoto() {
  ultimaInteracaoMenuEm = millis();
  beep(80);
  tratarCliqueCurtoBotaoLCD();
}

void executarCliqueLongoMenuRemoto() {
  ultimaInteracaoMenuEm = millis();
  if (estadoLCD == LCD_MENU) {
    beep(80);
    selecionarTelaLCD();
  } else if (estadoLCD == LCD_EXIBINDO && telaSelecionada == TELA_CONTROLE_RELES) {
    beep(80);
    alternarReleSelecionado();
  }
}

void atualizarBlynk() {
  if (!wifiConectado) return;
  conectarBlynk();
  if (!Blynk.connected()) return;

  Blynk.run();
  if (interfaceLocalEmUso()) return;

  publicarTelemetriaBlynk();
  publicarEstadoBlynk();
}
//BOTAO_TRV_PIN
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
void setup() {
  Serial.begin(115200);

  pinMode(BUZZER, OUTPUT);
  digitalWrite(BUZZER, LOW);

  for (int i = 0; i < 8; i++) {
    pinMode(reles[i], OUTPUT);
    digitalWrite(reles[i], HIGH);
    estadoReles[i] = false;
  }

  pinMode(SOIL_PIN, INPUT);
  pinMode(BOTAO_LCD_PIN, INPUT_PULLUP);
  pinMode(BOTAO_TRV_PIN, INPUT_PULLUP);
  ultimoEstadoBotao = digitalRead(BOTAO_LCD_PIN);
  estadoEstavelBotaoLCD = ultimoEstadoBotao;

  pixel.begin();
  pixel.show();

  Wire.begin(SDA_PIN, SCL_PIN);

  lcd.init();
  lcd.backlight();
  lcd.clear();
  lcd.print("Iniciando...");

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

#if WOKWI_SIMULATION
#endif

  lcd.clear();
  lcd.print("Conectando WiFi");
  ultimaTentativaWiFi = millis();
  wifiConectado = conectarWiFi(WIFI_TIMEOUT_INICIAL_MS);
  Serial.println(wifiConectado ? "WiFi conectado" : "Falha ao conectar WiFi");

  if (wifiConectado) {
    lcd.clear();
    lcd.print("Sincronizando");
    horaSincronizada = sincronizarHoraNTP(NTP_TIMEOUT_INICIAL_MS);
    Blynk.config(BLYNK_AUTH_TOKEN, "blynk.cloud", 80);
    blynkConfigurado = true;
  }

  lcd.clear();
  if (wifiConectado && horaSincronizada) {
    lcd.print("Sistema OK");
    lcd.setCursor(0, 1);
    lcd.print("Hora sincronizada");
  } else if (rtcHoraValida()) {
    lcd.print("Sistema OK");
    lcd.setCursor(0, 1);
    lcd.print("Usando RTC local");
  } else {
    lcd.print("Sem RTC/WiFi");
    lcd.setCursor(0, 1);
    lcd.print("Hora limitada");
  }

  beep(300);
  definirEstadoLEDTemporario(LED_OK, 1000);
  delay(1200);
  desligarLCD();
}

// ---------------- LOOP ----------------
void loop() {
  atualizarLED();
  atualizarBuzzer();
  atualizarAcesso();
  atualizarMensagemTemporaria();
  atualizarBotaoLCD();
  atualizarBotaoAcesso();
  atualizarLCDTimeout();
  atualizarDHT();
  atualizarSolo();
  atualizarSincronizacaoHora();
  atualizarConexaoWiFi();
  atualizarBlynk();

  DateTime now = obterAgora();

  controlarLuz(now);
  renderizarLCD(now);
}
