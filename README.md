# 🌿 GrowController ESP32 — Automação para Cultivo Indoor

Sistema de automação para estufa de cultivo indoor baseado em **ESP32-S3**, com controle de iluminação, ventilação, monitoramento de sensores, interface LCD, acesso remoto via **Blynk** e sincronização de horário via **NTP**.

---

## Funcionalidades

- **Controle automático de iluminação** com base no modo de cultivo e horário do dia
- **Dois modos de cultivo**: Vegetação (16h luz) e Floração (12h luz)
- **Monitoramento ambiental** com sensor de temperatura e umidade (SHT40 ou DHT22)
- **Sensor de umidade do solo** analógico com calibração por faixa de leitura bruta
- **Controle de 8 relés** para luz, acesso, exaustores, ventiladores e periféricos
- **Trava eletrônica de acesso** com liberação temporizada via botão físico ou app remoto
- **Interface LCD 16x2 I2C** com múltiplas telas e navegação por botão físico
- **LED NeoPixel** de status com animações por estado do sistema
- **Buzzer** para feedback sonoro em ações
- **Controle remoto via Blynk** (IoT): telemetria, comandos e navegação de menu
- **Sincronização de hora via NTP** com fallback para RTC DS3231
- **Reconexão automática** de Wi-Fi e Blynk com estratégia de backoff
- **Modo de simulação Wokwi** para testes sem hardware físico

---

## Hardware Necessário

### Barramento I2C
Os dispositivos SHT40, DS3231 e LCD compartilham o mesmo barramento I2C.

| Sinal | Pino ESP32-S3 | Conectar em |
|---|---|---|
| SDA | 8 | SDA do LCD + SDA do DS3231 + SDA do SHT40 |
| SCL | 9 | SCL do LCD + SCL do DS3231 + SCL do SHT40 |
| 3.3V | 3V3 | VCC do LCD + VCC do DS3231 + VCC do SHT40 |
| GND | GND | GND do LCD + GND do DS3231 + GND do SHT40 |

> O LCD 16x2 I2C deve estar configurado no endereço **0x27** (padrão da maioria dos módulos PCF8574).

---

### Sensor de Temperatura e Umidade

**Hardware real → SHT40** (padrão quando `WOKWI_SIMULATION 0`)

| Pino SHT40 | Pino ESP32-S3 |
|---|---|
| VIN | 3V3 |
| GND | GND |
| SDA | 8 |
| SCL | 9 |

**Simulação Wokwi → DHT22** (quando `WOKWI_SIMULATION 1`)

| Pino DHT22 | Pino ESP32-S3 |
|---|---|
| VCC | 3V3 |
| GND | GND |
| DATA | 15 |

> Resistor de pull-up de 10kΩ entre DATA e VCC é recomendado para o DHT22.

---

### Módulo RTC DS3231

| Pino DS3231 | Pino ESP32-S3 |
|---|---|
| VCC | 3V3 |
| GND | GND |
| SDA | 8 |
| SCL | 9 |

---

### Sensor de Umidade do Solo (analógico)

| Pino do sensor | Pino ESP32-S3 |
|---|---|
| VCC | 3V3 |
| GND | GND |
| AOUT (sinal analógico) | 6 |

> Os valores de calibração estão definidos no código como `SOIL_DRY_RAW 3200` (seco) e `SOIL_WET_RAW 2300` (úmido). Ajuste conforme seu sensor.

---

### Módulo de 8 Relés

Todos os relés são **ativo em LOW** (HIGH = desligado, LOW = ligado). Conecte o sinal de controle de cada canal ao pino correspondente do ESP32-S3.

| Canal do módulo | Pino ESP32-S3 | Função |
|---|---|---|
| IN1 | 42 | Luz de Cultivo |
| IN2 | 41 | Trava de Acesso |
| IN3 | 40 | Saída de Ar Vivosun |
| IN4 | 39 | Entrada de Ar Fan |
| IN5 | 38 | Ventilador Principal |
| IN6 | 37 | Saída de Ar Dois |
| IN7 | 36 | Entrada de Ar Dois |
| IN8 | 35 | Ventilador Dois |
| VCC | 5V | Alimentação do módulo |
| GND | GND | GND comum |

> Módulos de relé com optoacoplador geralmente exigem alimentação de **5V** no VCC do módulo. O sinal de controle de 3.3V do ESP32-S3 é suficiente para acionar os optoacopladores na maioria dos modelos.

---

### LED NeoPixel (WS2812B)

| Pino NeoPixel | Pino ESP32-S3 |
|---|---|
| VCC / 5V | 5V |
| GND | GND |
| DIN (dados) | 48 |

> O pino 48 é o LED RGB integrado em algumas versões do ESP32-S3 DevKit. Se estiver usando um NeoPixel externo, conecte o DIN ao pino 48 com um resistor de 300–500Ω em série.

---

### Buzzer

| Pino Buzzer | Pino ESP32-S3 |
|---|---|
| + (positivo) | 17 |
| − (negativo) | GND |

> Use um buzzer **passivo** (sem oscilador interno). O código aciona o pino diretamente em HIGH/LOW.

---

### Botões

| Botão | Pino ESP32-S3 | Modo |
|---|---|---|
| Botão Menu LCD | 5 | INPUT_PULLUP — conectar entre o pino e GND |
| Botão Acesso (TRV) | 19 | INPUT_PULLUP — conectar entre o pino e GND |

> Nenhum resistor externo é necessário — o pull-up interno do ESP32-S3 é habilitado via `INPUT_PULLUP`.

---

## Modos de Cultivo

### 🌱 Vegetação
- **Luz ligada:** 16h00 até 10h00 (18h de luz / 6h de escuro)
- Ideal para crescimento vegetativo

### 🌸 Floração
- **Luz ligada:** 19h00 até 07h00 (12h de luz / 12h de escuro)
- Ideal para indução e manutenção da fase de floração

O modo pode ser alternado pelo botão físico (menu LCD), pelo app Blynk (V10) ou diretamente na tela de controle de relés.

---

## Interface LCD

O display é ativado por pressão curta no botão LCD e desliga automaticamente após **15 segundos** de inatividade.

### Telas disponíveis

| Tela | Conteúdo |
|---|---|
| Data e Hora | Data atual (DD/MM/AA) e hora (HH:MM) |
| Sensor Interno | Temperatura (°C) e umidade (%) |
| Status do LED | Estado da luz de cultivo e tempo restante |
| Modo de Cultivo | Modo atual (Vegetativo/Floração) e estado do solo |
| Controle de Relés | Seleção e acionamento de relés e modo de cultivo |

### Navegação por botão

| Ação | Resultado |
|---|---|
| Clique curto (LCD desligado) | Liga o LCD na tela de Data/Hora |
| Clique curto (exibindo) | Avança para a próxima tela |
| Clique curto (menu Controles) | Avança o item selecionado no menu |
| Pressão longa (menu) | Confirma seleção de tela |
| Pressão longa (tela Controles) | Alterna estado do item selecionado |

---

## Relés

| Índice | Identificação | Controle |
|---|---|---|
| 0 | Luz de Cultivo | Automático (horário) |
| 1 | Trava de Acesso | Automático (temporizado) |
| 2 | Saída de Ar Vivosun | Manual / Blynk V11 |
| 3 | Entrada de Ar Fan | Manual / Blynk V12 |
| 4 | Ventilador Principal | Manual / Blynk V13 |
| 5 | Saída de Ar Dois | Manual / Blynk V14 |
| 6 | Entrada de Ar Dois | Manual / Blynk V15 |
| 7 | Ventilador Dois | Manual / Blynk V16 |

Todos os relés são **ativo em LOW** (estado padrão HIGH = desligado).

---

## Integração Blynk

O projeto utiliza **Blynk IoT** para monitoramento e controle remoto.

### Pinos virtuais — Telemetria (leitura no app)

| Virtual Pin | Dado |
|---|---|
| V1 | Umidade do ar (%) |
| V2 | Temperatura (°C) |
| V4 | Umidade do solo (%) |
| V5 | Estado da luz (0/1) |
| V6 | Modo de cultivo (texto) |
| V7 | Exaustores ativos (0/1) |
| V8 | Timestamp atual |
| V9 | Tempo restante do ciclo |

### Pinos virtuais — Controle (escrita no app)

| Virtual Pin | Função |
|---|---|
| V10 | Modo de cultivo (0=Vegetação, 1=Floração) |
| V11–V16 | Relés 2 a 7 (0=OFF, 1=ON) |
| V17 | Liberar acesso por 3 segundos |
| V18 | Clique curto no menu LCD |
| V19 | Clique longo no menu LCD |

---

## LED de Status (NeoPixel)

| Estado | Cor / Animação |
|---|---|
| Blynk conectado | Azul pulsante suave |
| Conectando Wi-Fi | Laranja pulsante |
| Conectando Blynk | Ciano pulsante |
| Porta liberada | Verde sólido |
| Ação confirmada (LED_OK) | Verde sólido temporário |
| Erro | Vermelho sólido |

---

## Sincronização de Hora

A hora é obtida por ordem de prioridade:

1. **NTP** via internet (`pool.ntp.org`, `time.nist.gov`) — fuso UTC-3
2. **RTC DS3231** local — utilizado quando sem conectividade
3. **Fallback** — data fixa `2026-01-01 00:00:00` caso nenhuma fonte esteja disponível

Quando o NTP sincroniza com sucesso, o RTC é atualizado automaticamente.

---

## Reconexão Automática

- **Wi-Fi:** tenta a cada 5 segundos no primeiro minuto, depois a cada 30 segundos
- **Blynk:** tenta a cada 5 segundos, somente quando Wi-Fi está ativo e interface local não está em uso
- **NTP:** retenta a cada 60 segundos até sincronizar

---

## Simulação Wokwi

Defina `WOKWI_SIMULATION 1` no código para ativar o modo de simulação, que substitui o sensor SHT40 pelo DHT22 e desativa dependências de hardware específico do ambiente físico.

---

## Dependências

Instale as seguintes bibliotecas via Arduino Library Manager ou PlatformIO:

- `LiquidCrystal_I2C`
- `RTClib` (Adafruit)
- `Adafruit SHT4x`
- `DHT sensor library` (Adafruit)
- `Adafruit NeoPixel`
- `Blynk` (BlynkSimpleEsp32)
- `WiFi` (built-in ESP32)

---

## Configuração

Antes de compilar, edite as seguintes constantes no início do arquivo:

```cpp
// Credenciais Wi-Fi
const char* ssid     = "SUA_REDE";
const char* password = "SUA_SENHA";

// Credenciais Blynk
#define BLYNK_TEMPLATE_ID   "SEU_TEMPLATE_ID"
#define BLYNK_TEMPLATE_NAME "Nome do Template"
#define BLYNK_AUTH_TOKEN    "SEU_AUTH_TOKEN"
```

---

## Estrutura do Código

```
setup()
├── Inicialização de pinos, relés, LCD, sensores, RTC, NeoPixel
├── Conexão Wi-Fi (com timeout)
├── Sincronização NTP
└── Configuração Blynk

loop()
├── atualizarLED()           — animações NeoPixel por estado
├── atualizarBuzzer()        — desliga buzzer após tempo configurado
├── atualizarAcesso()        — fecha trava após tempo de liberação
├── atualizarMensagemTemporaria() — expira mensagens no LCD
├── atualizarBotaoLCD()      — leitura e debounce do botão do menu
├── atualizarBotaoAcesso()   — leitura e debounce do botão de acesso
├── atualizarLCDTimeout()    — desliga LCD por inatividade
├── atualizarDHT()           — leitura do sensor de temp/umidade
├── atualizarSolo()          — leitura do sensor de umidade do solo
├── atualizarSincronizacaoHora() — retry NTP periódico
├── atualizarConexaoWiFi()   — reconexão automática Wi-Fi
├── atualizarBlynk()         — run, telemetria e estado Blynk
├── controlarLuz(now)        — liga/desliga relé 0 por horário
└── renderizarLCD(now)       — renderiza tela ativa no display
```

---

## Licença

Este projeto é de uso pessoal/educacional. Adapte livremente para seu ambiente de cultivo.
