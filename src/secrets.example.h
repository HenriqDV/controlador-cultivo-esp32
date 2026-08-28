#pragma once
// =============================================================================
// TEMPLATE de segredos do firmware — este arquivo É pra commitar no git.
// =============================================================================
// Copie este arquivo pra "secrets.h" (mesma pasta, junto de main.cpp) e
// preencha os valores reais lá. secrets.h está no .gitignore — nunca vai
// pro repositório.
//
//   cp secrets.example.h secrets.h
//
// Se secrets.h não existir, a compilação falha em "#include "secrets.h""
// dentro de main.cpp — é o sinal de que você esqueceu de criar o seu.
// =============================================================================

// Rede WiFi real (não é a "Wokwi-GUEST" da simulação)
#define SECRET_WIFI_SSID     "SUA_REDE_WIFI"
#define SECRET_WIFI_PASSWORD "SUA_SENHA_WIFI"

// Senha de atualização OTA — PRECISA ser idêntica ao --auth= do ambiente
// "esp32-s3-devkitc-1-ota" em platformio.ini (que lê a mesma variável de
// ambiente OTA_PASSWORD, ver comentário lá).
#define SECRET_OTA_PASSWORD  "SUA_SENHA_OTA"

// Backend da Hostinger — mesma API_KEY configurada em hostinger/config.php
#define SECRET_HOSTINGER_BASE_URL "https://SEU-DOMINIO.hostingersite.com"
#define SECRET_HOSTINGER_API_KEY  "SUA_API_KEY_IGUAL_AO_CONFIG_PHP"
