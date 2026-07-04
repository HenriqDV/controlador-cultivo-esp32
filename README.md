# 🌿 GrowController ESP32 — Automação para Cultivo Indoor

Sistema de automação para estufa de cultivo indoor baseado em **ESP32-S3**, com controle de iluminação, ventilação, monitoramento de sensores, interface **OLED SSD1306** com telas dedicadas, acesso remoto via **Blynk**, persistência de estado entre quedas de energia e sincronização de horário via **NTP** com fallback para RTC.

---

## Funcionalidades

- **Controle automático de iluminação** com base no modo de cultivo e horário do dia
- **Override manual da luz** (Auto / Ligada / Desligada) que **não desabilita** o fotoperíodo — o timer continua contando por baixo e retoma sozinho após 30 minutos
- **Dois modos de cultivo**: Vegetação (18h luz) e Floração (12h luz)
- **Monitoramento ambiental** com sensor de temperatura e umidade (SHT40 ou DHT22, conforme o modo de compilação)
- **Sensor de umidade do solo** analógico com calibração por faixa de leitura bruta e média de múltiplas amostras (redução de ruído do ADC)
- **Controle de 8 relés** para luz, acesso, exaustores, ventiladores e periféricos
- **Trava eletrônica de acesso** com liberação temporizada via botão físico ou app remoto
- **Display OLED SSD1306 (128x64)** com 4 telas — incluindo uma tela de descanso animada (sol/lua) — e navegação por botão físico
- **Indicador de conexão** no cabeçalho em formato de barras, cada uma com significado fixo (WiFi / Blynk configurado / Blynk conectado)
- **Indicador de "sensor vivo"**: pisca a cada leitura válida do sensor de ar, mesmo sem mudança de valor
- **Persistência entre quedas de energia**: estado dos relés manuais e modo de cultivo sobrevivem a reinícios, gravados na NVRAM com bateria do RTC DS1307
- **LED NeoPixel** de status com animações por estado do sistema (interno no hardware físico, externo na simulação)
- **Buzzer** para feedback sonoro em ações
- **Controle remoto via Blynk** (IoT): telemetria, comandos e navegação de menu
- **Sincronização de hora via NTP** com múltiplos servidores de fallback e reserva no RTC DS1307
- **Reconexão automática** de Wi-Fi e Blynk com estratégia de backoff
- **Modo de simulação Wokwi** para testes sem hardware físico, com troca automática de sensor, LED e pinagem

---

## Hardware Necessário

### Barramento I2C
Os dispositivos SHT40, RTC DS1307 e display OLED compartilham o mesmo barramento I2C.

| Sinal | Pino ESP32-S3 | Conectar em |
|---|---|---|
| SDA | 8 | SDA do OLED + SDA do RTC DS1307 + SDA do SHT40 |
| SCL | 9 | SCL do OLED + SCL do RTC DS1307 + SCL do SHT40 |
| 3.3V | 3V3 | VCC do OLED + VCC do RTC DS1307 + VCC do SHT40 |
| GND | GND | GND do OLED + GND do RTC DS1307 + GND do SHT40 |

> O display OLED deve estar configurado no endereço **0x3C** (padrão da maioria dos módulos SSD1306 128x64).

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

### Módulo RTC DS1307

| Pino DS1307 | Pino ESP32-S3 |
|---|---|
| VCC | 3V3 |
| GND | GND |
| SDA | 8 |
| SCL | 9 |

> O DS1307 tem 56 bytes de memória RAM com bateria de backup (CR2032), usada não só para manter a hora, mas também para **gravar o estado dos relés manuais e o modo de cultivo entre reinícios**. Sem a bateria (ou com ela descarregada), o oscilador para e a hora fica travada em 2000-01-01 — o firmware detecta isso no boot (`rtc.isrunning()`) e ajusta para a hora de compilação como ponto de partida até o próximo sincronismo NTP.

---

### Display OLED SSD1306 (128x64)

| Pino OLED | Pino ESP32-S3 |
|---|---|
| VCC | 3V3 |
| GND | GND |
| SDA | 8 |
| SCL | 9 |

> Compatível com os módulos "bicolores" (faixa amarela de 16px no topo + azul no restante) — o layout das telas já reserva essa faixa para o cabeçalho e evita desenhar conteúdo na costura entre as cores.

---

### Sensor de Umidade do Solo (analógico)

| Pino do sensor | Pino ESP32-S3 |
|---|---|
| VCC | 3V3 |
| GND | GND |
| AOUT (sinal analógico) | 6 |

> A leitura bruta é invertida por natureza em sensores capacitivos/resistivos reais (seco = ADC alto, úmido = ADC baixo). Os valores de calibração estão definidos no código como `SOLO_ADC_SECO` e `SOLO_ADC_UMIDO` — ajuste-os com os valores reais do seu sensor (veja o log no Serial Monitor, que imprime a leitura bruta a cada mudança). Valores de referência calibrados neste projeto: seco = `4095`, úmido = `2240`.
>
> ⚠️ Confira também a tensão de alimentação: se o sensor for alimentado em 5V com a saída ligada direto num GPIO do ESP32 (limite de 3,3V), a leitura pode saturar no máximo independente da umidade real.

---

### Módulo de 8 Relés

Todos os relés são **ativo em LOW** por padrão (HIGH = desligado, LOW = ligado), configurável pela constante `RELE_ATIVO_EM_BAIXO`. Conecte o sinal de controle de cada canal ao pino correspondente do ESP32-S3.

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
>
> ⚠️ **Atenção (ESP32-S3 com PSRAM Octal):** em módulos como variantes "N16R8" (WROOM-2), os pinos **GPIO35, GPIO36 e GPIO37** são usados internamente pela PSRAM e **não podem** ser usados como GPIO comum — isso afeta diretamente os canais IN6, IN7 e IN8 da tabela acima. Confira a serigrafia do módulo (WROOM-1 = Quad, livre / WROOM-2 = Octal, reservado) ou teste empiricamente antes de montar o circuito definitivo; remapeie esses relés para outros GPIOs livres se for o seu caso.

---

### LED de Status (NeoPixel)

O pino do LED **muda automaticamente** conforme o modo de compilação (`WOKWI_SIMULATION`):

| Modo | Pino | Observação |
|---|---|---|
| Simulação Wokwi (`1`) | 4 | LED NeoPixel externo (WS2812B) |
| Hardware físico (`0`) | 48 | LED RGB **embutido** na placa ESP32-S3 DevKit (não precisa de fiação externa) |

Se estiver usando um NeoPixel externo no hardware físico em vez do LED embutido, ajuste `LED_PIN` manualmente para o GPIO desejado e conecte com um resistor de 300–500Ω em série no DIN.

---

### Buzzer

| Pino Buzzer | Pino ESP32-S3 |
|---|---|
| + (positivo) | 17 |
| − (negativo) | GND |

> Use um buzzer **passivo** (sem oscilador interno). O código aciona o pino diretamente em HIGH/LOW, sem bloquear o loop principal (desligamento agendado por temporizador).

---

### Botões

| Botão | Pino ESP32-S3 | Modo |
|---|---|---|
| Botão Menu OLED | 5 | INPUT_PULLUP — conectar entre o pino e GND |
| Botão Acesso (TRV) | 19 | INPUT_PULLUP — conectar entre o pino e GND |

> Nenhum resistor externo é necessário — o pull-up interno do ESP32-S3 é habilitado via `INPUT_PULLUP`. Ambos os botões têm debounce por software.

---

## Modos de Cultivo

### 🌱 Vegetação
- **Luz ligada:** 16h00 até 10h00 (18h de luz / 6h de escuro)
- Ideal para crescimento vegetativo

### 🌸 Floração
- **Luz ligada:** 19h00 até 07h00 (12h de luz / 12h de escuro)
- Ideal para indução e manutenção da fase de floração

O modo pode ser alternado pelo botão físico (menu do OLED), pelo app Blynk (V10) ou diretamente na tela de configuração.

### Override manual da luz

Além do modo automático, existe um item dedicado **"Luz Manual"** no menu de configuração, com 3 estados alternados por pressão longa:

```
Auto → Ligada → Desligada → Auto → ...
```

Pontos importantes sobre esse recurso:

- O cálculo do fotoperíodo **nunca para** — mesmo em modo manual, o sistema continua calculando se a luz "deveria" estar ligada pelo horário; o override apenas sobrepõe a saída final do relé.
- Por segurança, o override **expira sozinho após 30 minutos**, retomando o controle automático sem qualquer ação do usuário — evita esquecer a luz travada manualmente e comprometer a floração por vazamento de luz.
- A tela secundária do OLED mostra "(Manual)" ao lado do estado da luz e um contador regressivo ("Auto em: Xmin") enquanto o override estiver ativo.

---

## Interface OLED (SSD1306 128x64)

O display é ativado por pressão curta no botão do menu e volta automaticamente para a tela de descanso após **15 segundos** de inatividade.

### Telas disponíveis

| Tela | Conteúdo |
|---|---|
| Descanso (padrão/ociosa) | Ilustração animada em tela cheia: sol (luz ligada) ou lua com estrelas cintilantes (luz desligada), com o nome "GreenGrow" no cabeçalho |
| Principal | Temperatura e umidade do ar, com indicador de "sensor vivo" |
| Secundária | Modo de cultivo, estado da luz (com aviso de override manual) e tempo restante do ciclo |
| Configuração | Menu interativo rolável: Modo Cultivo, Luz Manual e os 6 relés manuais |

Todas as telas (exceto a de descanso) compartilham um cabeçalho comum — nome do sistema, relógio e indicador de conexão — e um rodapé com indicador de página, modo de cultivo e status do solo.

### Indicador de conexão (cabeçalho)

Três barras de sinal, cada uma com significado **fixo e independente** (não é uma contagem de nível):

| Barra | Significado |
|---|---|
| 1ª | WiFi conectado |
| 2ª | Blynk configurado (`Blynk.config()` já foi chamado) |
| 3ª | Blynk realmente conectado ao servidor |

Isso permite diagnosticar exatamente em qual etapa a conexão travou (ex: 1ª e 2ª cheias, 3ª vazia = token do Blynk incorreto ou servidor inacessível).

### Navegação por botão

| Ação | Resultado |
|---|---|
| Clique curto (tela de descanso) | Acorda o display na Tela Principal |
| Clique curto (exibindo) | Avança para a próxima tela |
| Clique curto (menu Configuração) | Avança o item selecionado no menu |
| Pressão longa (confirmação de menu) | Confirma entrada na tela de Configuração |
| Pressão longa (tela Configuração) | Alterna estado do item selecionado |

O mesmo fluxo pode ser acionado remotamente pelo Blynk (V18 = clique curto, V19 = clique longo).

---

## Relés

| Índice | Identificação | Controle |
|---|---|---|
| 0 | Luz de Cultivo | Automático (horário) + override manual |
| 1 | Trava de Acesso | Automático (temporizado) |
| 2 | Saída de Ar Vivosun | Manual / Blynk V11 |
| 3 | Entrada de Ar Fan | Manual / Blynk V12 |
| 4 | Ventilador Principal | Manual / Blynk V13 |
| 5 | Saída de Ar Dois | Manual / Blynk V14 |
| 6 | Entrada de Ar Dois | Manual / Blynk V15 |
| 7 | Ventilador Dois | Manual / Blynk V16 |

Todos os relés são **ativo em LOW** por padrão (`RELE_ATIVO_EM_BAIXO`).

### Persistência entre quedas de energia

O estado dos relés manuais (2 a 7) e o modo de cultivo são gravados na NVRAM do RTC DS1307 sempre que mudam, e restaurados automaticamente no boot. **Não são restaurados**, por design:
- **Luz (relé 0):** é sempre recalculada pelo horário assim que o sistema liga.
- **Acesso/trava (relé 1):** nunca volta ligada sozinha, por segurança.
- **Override manual de luz:** sempre volta para "Automático" após um reinício.

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
| V18 | Clique curto no menu do OLED |
| V19 | Clique longo no menu do OLED |

> O override manual de luz (Auto/Ligada/Desligada) ainda não tem um pino virtual dedicado — hoje só é acessível pelo menu local do OLED.

---

## LED de Status (NeoPixel)

| Estado | Cor / Animação |
|---|---|
| Blynk conectado / sistema ocioso | Azul pulsante suave |
| Conectando Wi-Fi | Laranja pulsante |
| Conectando Blynk | Ciano pulsante |
| Porta liberada | Verde sólido |
| Ação confirmada (LED_OK) | Verde sólido temporário |
| Erro | Vermelho sólido (definido, mas ainda não disparado por nenhuma condição do código — ponto de extensão futuro) |

---

## Sincronização de Hora

A hora é obtida por ordem de prioridade:

1. **RTC DS1307** local (usado sempre que disponível e com ano plausível, ≥ 2024)
2. **NTP** via internet, com múltiplos servidores de fallback (`pool.ntp.org`, `time.google.com`, `a.st1.ntp.br`, `time.windows.com`) — alterna de servidor a cada falha, fuso UTC-3
3. **Fallback** — data fixa `2026-01-01 00:00:00` caso nenhuma fonte esteja disponível (RTC sem bateria/nunca sincronizado e sem WiFi)

Quando o NTP sincroniza com sucesso, o RTC é atualizado automaticamente.

---

## Reconexão Automática

- **Wi-Fi:** tenta a cada 5 segundos no primeiro minuto, depois a cada 30 segundos
- **Blynk:** tenta a cada 5 segundos, somente quando Wi-Fi está ativo e a interface local não está em uso
- **NTP:** retenta a cada 60 segundos até sincronizar, alternando entre servidores a cada tentativa

---

## Simulação Wokwi

O projeto possui suporte nativo ao [Wokwi](https://wokwi.com), plataforma de simulação de hardware para Arduino e ESP32 que roda direto no navegador, sem necessidade de nenhum componente físico.

### Como ativar

No início do arquivo `.ino`, altere a linha:

```cpp
#define WOKWI_SIMULATION 0  // hardware real
```
para:
```cpp
#define WOKWI_SIMULATION 1  // simulação Wokwi
```

### O que muda com a flag ativada

| Comportamento | Hardware real (`0`) | Simulação Wokwi (`1`) |
|---|---|---|
| Sensor de temp/umidade | SHT40 via I2C | DHT22 no pino 15 |
| Biblioteca do sensor | `Adafruit_SHT4x` | `DHT.h` |
| Inicialização do sensor | `sht4.begin()` | `dht.begin()` |
| Leitura do sensor | `sht4.getEvent()` | `dht.readTemperature/Humidity()` |
| LED de status (NeoPixel) | Pino 48 (LED embutido) | Pino 4 (LED externo) |
| Rede Wi-Fi | Precisa ser a rede real do usuário | `"Wokwi-GUEST"` (aberta, com internet real dentro do simulador) |

### O que pode ser testado na simulação

- Navegação completa pelo menu do OLED (botão físico virtual ou via Blynk V18/V19)
- Leitura e exibição de temperatura e umidade pelo DHT22
- Leitura analógica do solo (variando o potenciômetro)
- Controle de relés pelo menu ou pelo app Blynk
- Alternância entre modos Vegetação e Floração, e override manual de luz
- Lógica de horário para controle automático da luz
- Conexão Wi-Fi, NTP e Blynk (o Wokwi suporta Wi-Fi simulado com acesso real à internet)
- Animação da tela de descanso (sol/lua) e indicador de sensor vivo

---

## Dependências

Instale as seguintes bibliotecas via Arduino Library Manager ou PlatformIO:

- `Adafruit GFX Library`
- `Adafruit SSD1306`
- `RTClib` (Adafruit)
- `Adafruit SHT4x` (+ dependências: `Adafruit BusIO`, `Adafruit Unified Sensor`)
- `DHT sensor library` (Adafruit)
- `Adafruit NeoPixel`
- `Blynk` (BlynkSimpleEsp32)
- `WiFi` (built-in ESP32)

---

## Configuração

Antes de compilar, edite as seguintes constantes no início do arquivo:

```cpp
// Modo de compilação
#define WOKWI_SIMULATION 0   // 0 = hardware físico | 1 = simulação Wokwi

// Credenciais Wi-Fi (trocar pela rede real ao gravar no hardware físico)
const char* ssid     = "SUA_REDE";
const char* password = "SUA_SENHA";

// Credenciais Blynk
#define BLYNK_TEMPLATE_ID   "SEU_TEMPLATE_ID"
#define BLYNK_TEMPLATE_NAME "Nome do Template"
#define BLYNK_AUTH_TOKEN    "SEU_AUTH_TOKEN"
```

E calibre o sensor de solo com os valores reais do seu hardware:

```cpp
const int SOLO_ADC_SECO = 4095;   // leitura bruta com o sensor seco (ver Serial Monitor)
const int SOLO_ADC_UMIDO = 2240;  // leitura bruta com o sensor em água
```

---

## Estrutura do Código

```
setup()
├── Inicialização de pinos, relés (respeitando RELE_ATIVO_EM_BAIXO), botões
├── Barramento I2C (Wire.begin)
├── Display OLED, sensor de ar (SHT40 ou DHT22)
├── RTC: detecção + verificação do oscilador (isrunning) + ajuste de emergência
├── Restauração de relés/modo salvos na NVRAM (queda de energia anterior)
├── Conexão Wi-Fi (com timeout)
├── Sincronização NTP
└── Configuração Blynk

loop()
├── atualizarLED()               — animações NeoPixel por estado
├── atualizarBuzzer()            — desliga buzzer após tempo configurado
├── atualizarAcesso()            — fecha trava após tempo de liberação
├── atualizarMensagemTemporaria()— expira mensagens no OLED
├── atualizarBotaoOLED()         — leitura, debounce e clique curto/longo do botão de menu
├── atualizarBotaoAcesso()       — leitura e debounce do botão de acesso
├── atualizarTimeoutOLED()       — volta para a tela de descanso por inatividade
├── atualizarDHT()               — leitura do sensor de temp/umidade + indicador de sensor vivo
├── atualizarPulsoSensor()       — apaga o indicador de sensor vivo
├── atualizarSolo()              — leitura (com média) e calibração do sensor de solo
├── atualizarSincronizacaoHora() — retry NTP periódico com múltiplos servidores
├── atualizarConexaoWiFi()       — reconexão automática Wi-Fi com backoff
├── atualizarBlynk()             — run, telemetria e estado Blynk
├── controlarLuz(now)            — aplica fotoperíodo/override no relé de luz
└── renderizarOLED(now)          — renderiza a tela ativa no display (só se houver mudança)
```

---

## Licença

Este projeto é de uso pessoal/educacional. Adapte livremente para seu ambiente de cultivo.
