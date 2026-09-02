# 📘 Guide d'Intégration Webhook GeniusPay

Ce guide vous aidera à intégrer les webhooks GeniusPay dans votre serveur pour recevoir des notifications en temps réel sur les événements de paiement.

---

## � Sandbox vs Live

L'intégration webhook est **identique** pour le Sandbox et le Live. Seules les clés et l'environnement changent.

| Aspect | Sandbox | Live |
|--------|---------|------|
| **Webhook Secret** | `whsec_sandbox_xxxxxxxxxxxxx` | `whsec_live_xxxxxxxxxxxxx` |
| **Header Environment** | `X-Webhook-Environment: sandbox` | `X-Webhook-Environment: live` |
| **Payload Field** | `"environment": "sandbox"` | `"environment": "live"` |
| **Configuration** | Dashboard > API > Sandbox Webhooks | Dashboard > API > Live Webhooks |
| **Intégration Code** | ✅ Identique | ✅ Identique |

**Important:** Utilisez le secret webhook approprié selon votre environnement. Le code d'intégration reste le même pour les deux modes.

---

## � Sécurité et Signature

Chaque webhook envoyé par GeniusPay inclut une signature HMAC SHA-256 dans le header `X-Webhook-Signature`. Vous **devez toujours vérifier** cette signature pour garantir l'authenticité des requêtes.

### Format de la signature

```
signature = HMAC-SHA256(timestamp + "." + json_payload, secret)
```

- **timestamp** : Valeur du header `X-Webhook-Timestamp`
- **json_payload** : Corps de la requête en JSON (stringify)
- **secret** : Votre clé secrète webhook (commence par `whsec_`)

---

## 📦 Structure du Payload

### Headers HTTP

| Header | Type | Description |
|--------|------|-------------|
| `Content-Type` | `application/json` | Format du contenu |
| `X-Webhook-Signature` | `string` | Signature HMAC SHA-256 |
| `X-Webhook-Timestamp` | `string` | Timestamp Unix de l'envoi |
| `X-Webhook-Event` | `string` | Type d'événement |
| `X-Webhook-Delivery` | `string` | ID unique de livraison (optionnel) |
| `X-Webhook-Retry` | `integer` | Numéro de tentative (optionnel) |
| `User-Agent` | `string` | `GeniusPay-Webhook/1.0` |

### Corps de la requête (JSON)

```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "event": "payment.success",
  "timestamp": 1735587600,
  "created_at": "2025-12-30T12:00:00.000000Z",
  "data": {
    "object": "transaction",
    "id": 12345,
    "reference": "TXN-2025-001",
    "amount": 10000.00,
    "currency": "XOF",
    "fees": 250.00,
    "net_amount": 9750.00,
    "status": "completed",
    "payment_method": "mobile_money",
    "provider": "wave",
    "customer_name": "Jean Kouassi",
    "customer_phone": "+2250748123456",
    "merchant_id": 25,
    "metadata": {
      "order_id": "ORD-2025-123",
      "customer_email": "jean@example.com"
    }
  },
  "environment": "live",
  "api_version": "2024-01-01"
}
```

**Note:** Pour le Sandbox, le champ `"environment"` sera `"sandbox"` au lieu de `"live"`. Le reste du payload est identique.

---

## 🎯 Événements disponibles

| Événement | Description |
|-----------|-------------|
| `payment.initiated` | Paiement initié |
| `payment.success` | Paiement réussi |
| `payment.failed` | Paiement échoué |
| `payment.cancelled` | Paiement annulé |
| `payment.refunded` | Paiement remboursé |
| `payment.expired` | Paiement expiré |
| `cashout.requested` | Demande de retrait |
| `cashout.approved` | Retrait approuvé |
| `cashout.completed` | Retrait complété |
| `cashout.failed` | Retrait échoué |
| `webhook.test` | Test de webhook |

---

## ⚙️ Configuration

### .env - Sandbox et Live

```env
# Sandbox Webhooks
GENIUSPAY_WEBHOOK_SECRET_SANDBOX=whsec_sandbox_xxxxxxxxxxxxx

# Live Webhooks
GENIUSPAY_WEBHOOK_SECRET_LIVE=whsec_live_xxxxxxxxxxxxx

# Mode actuel (sandbox ou live)
GENIUSPAY_MODE=sandbox
```

### config/services.php

```php
'geniuspay' => [
    'webhook_secret' => env('GENIUSPAY_MODE') === 'sandbox'
        ? env('GENIUSPAY_WEBHOOK_SECRET_SANDBOX')
        : env('GENIUSPAY_WEBHOOK_SECRET_LIVE'),
],
```

### Routes

```php
// routes/webhooks.php
Route::post('/webhooks/geniuspay', [GeniusPayWebhookController::class, 'handle']);
```

---

## 💻 Exemples d'Implémentation

### PHP (Laravel)

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GeniusPayWebhookController extends Controller
{
    /**
     * Endpoint webhook GeniusPay
     * Fonctionne pour Sandbox ET Live
     * Seul le secret change selon l'environnement
     */
    public function handle(Request $request)
    {
        // 1. Récupérer les headers
        $signature = $request->header('X-Webhook-Signature');
        $timestamp = $request->header('X-Webhook-Timestamp');
        $event = $request->header('X-Webhook-Event');
        $environment = $request->header('X-Webhook-Environment'); // sandbox ou live
        
        // 2. Vérifier que tous les headers requis sont présents
        if (!$signature || !$timestamp || !$event) {
            Log::warning('GeniusPay Webhook: Headers manquants', [
                'headers' => $request->headers->all(),
                'environment' => $environment
            ]);
            return response()->json([
                'type' => 'about:blank',
                'title' => 'Bad Request',
                'status' => 400,
                'detail' => 'Required header is not present.',
                'instance' => $request->path()
            ], 400);
        }
        
        // 3. Récupérer le payload
        $payload = $request->all();
        
        // 4. Vérifier la signature (même code pour Sandbox et Live)
        $secret = config('services.geniuspay.webhook_secret');
        if (!$this->verifySignature($signature, $payload, $timestamp, $secret)) {
            Log::warning('GeniusPay Webhook: Signature invalide', [
                'event' => $event,
                'signature' => $signature
            ]);
            return response()->json([
                'type' => 'about:blank',
                'title' => 'Unauthorized',
                'status' => 401,
                'detail' => 'Invalid signature',
                'instance' => $request->path()
            ], 401);
        }
        
        // 5. Vérifier le timestamp (protection contre replay attack)
        $currentTime = time();
        $timeDiff = abs($currentTime - (int)$timestamp);
        if ($timeDiff > 300) { // 5 minutes
            Log::warning('GeniusPay Webhook: Timestamp trop ancien', [
                'timestamp' => $timestamp,
                'diff' => $timeDiff
            ]);
            return response()->json([
                'type' => 'about:blank',
                'title' => 'Bad Request',
                'status' => 400,
                'detail' => 'Timestamp too old',
                'instance' => $request->path()
            ], 400);
        }
        
        // 6. Traiter l'événement
        try {
            $this->processEvent($event, $payload);
            
            Log::info('GeniusPay Webhook: Événement traité', [
                'event' => $event,
                'transaction_id' => $payload['data']['id'] ?? null
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Webhook processed successfully'
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('GeniusPay Webhook: Erreur de traitement', [
                'event' => $event,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'type' => 'about:blank',
                'title' => 'Internal Server Error',
                'status' => 500,
                'detail' => 'Failed to process webhook',
                'instance' => $request->path()
            ], 500);
        }
    }
    
    /**
     * Vérifier la signature HMAC
     */
    private function verifySignature(string $signature, array $payload, string $timestamp, string $secret): bool
    {
        $data = $timestamp . '.' . json_encode($payload);
        $expectedSignature = hash_hmac('sha256', $data, $secret);
        return hash_equals($expectedSignature, $signature);
    }
    
    /**
     * Traiter l'événement selon son type
     */
    private function processEvent(string $event, array $payload): void
    {
        $data = $payload['data'];
        
        switch ($event) {
            case 'payment.success':
                // Marquer la commande comme payée
                $this->handlePaymentSuccess($data);
                break;
                
            case 'payment.failed':
                // Notifier le client de l'échec
                $this->handlePaymentFailed($data);
                break;
                
            case 'payment.refunded':
                // Traiter le remboursement
                $this->handlePaymentRefunded($data);
                break;
                
            case 'cashout.completed':
                // Confirmer le retrait
                $this->handleCashoutCompleted($data);
                break;
                
            case 'webhook.test':
                // Ne rien faire, c'est juste un test
                Log::info('GeniusPay Webhook: Test reçu');
                break;
                
            default:
                Log::info('GeniusPay Webhook: Événement non géré', ['event' => $event]);
        }
    }
    
    private function handlePaymentSuccess(array $data): void
    {
        // Exemple: Mettre à jour une commande
        $orderId = $data['metadata']['order_id'] ?? null;
        if ($orderId) {
            // Order::where('reference', $orderId)->update(['status' => 'paid']);
            Log::info("Commande {$orderId} marquée comme payée");
        }
    }
    
    private function handlePaymentFailed(array $data): void
    {
        // Logique de gestion d'échec
        Log::info('Paiement échoué', ['reference' => $data['reference']]);
    }
    
    private function handlePaymentRefunded(array $data): void
    {
        // Logique de remboursement
        Log::info('Paiement remboursé', ['reference' => $data['reference']]);
    }
    
    private function handleCashoutCompleted(array $data): void
    {
        // Logique de retrait complété
        Log::info('Retrait complété', ['amount' => $data['amount']]);
    }
}
```

**Route (routes/api.php):**

```php
Route::post('/webhooks/geniuspay', [GeniusPayWebhookController::class, 'handle'])
    ->middleware('api')
    ->name('webhooks.geniuspay');
```

**Configuration (config/services.php):**

```php
'geniuspay' => [
    'webhook_secret' => env('GENIUSPAY_WEBHOOK_SECRET', ''),
],
```

**.env:**

```
GENIUSPAY_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

---

### Node.js (Express)

```javascript
const express = require('express');
const crypto = require('crypto');
const bodyParser = require('body-parser');

const app = express();

// ⚠️ Important: Utiliser bodyParser.raw() pour préserver le body original
app.use('/webhooks/geniuspay', bodyParser.raw({ type: 'application/json' }));

const WEBHOOK_SECRET = process.env.GENIUSPAY_WEBHOOK_SECRET;

// Endpoint webhook
app.post('/webhooks/geniuspay', async (req, res) => {
    try {
        // 1. Récupérer les headers
        const signature = req.headers['x-webhook-signature'];
        const timestamp = req.headers['x-webhook-timestamp'];
        const event = req.headers['x-webhook-event'];
        
        // 2. Vérifier les headers requis
        if (!signature || !timestamp || !event) {
            console.warn('GeniusPay Webhook: Headers manquants');
            return res.status(400).json({
                type: 'about:blank',
                title: 'Bad Request',
                status: 400,
                detail: 'Required header is not present.',
                instance: req.path
            });
        }
        
        // 3. Parser le payload
        const payload = JSON.parse(req.body.toString());
        
        // 4. Vérifier la signature
        if (!verifySignature(signature, payload, timestamp, WEBHOOK_SECRET)) {
            console.warn('GeniusPay Webhook: Signature invalide');
            return res.status(401).json({
                type: 'about:blank',
                title: 'Unauthorized',
                status: 401,
                detail: 'Invalid signature',
                instance: req.path
            });
        }
        
        // 5. Vérifier le timestamp
        const currentTime = Math.floor(Date.now() / 1000);
        const timeDiff = Math.abs(currentTime - parseInt(timestamp));
        if (timeDiff > 300) { // 5 minutes
            console.warn('GeniusPay Webhook: Timestamp trop ancien');
            return res.status(400).json({
                type: 'about:blank',
                title: 'Bad Request',
                status: 400,
                detail: 'Timestamp too old',
                instance: req.path
            });
        }
        
        // 6. Traiter l'événement
        await processEvent(event, payload);
        
        console.log('GeniusPay Webhook: Événement traité', {
            event,
            transaction_id: payload.data?.id
        });
        
        return res.status(200).json({
            success: true,
            message: 'Webhook processed successfully'
        });
        
    } catch (error) {
        console.error('GeniusPay Webhook: Erreur', error);
        return res.status(500).json({
            type: 'about:blank',
            title: 'Internal Server Error',
            status: 500,
            detail: 'Failed to process webhook',
            instance: req.path
        });
    }
});

/**
 * Vérifier la signature HMAC
 */
function verifySignature(signature, payload, timestamp, secret) {
    const data = `${timestamp}.${JSON.stringify(payload)}`;
    const expectedSignature = crypto
        .createHmac('sha256', secret)
        .update(data)
        .digest('hex');
    return crypto.timingSafeEqual(
        Buffer.from(signature),
        Buffer.from(expectedSignature)
    );
}

/**
 * Traiter l'événement selon son type
 */
async function processEvent(event, payload) {
    const data = payload.data;
    
    switch (event) {
        case 'payment.success':
            await handlePaymentSuccess(data);
            break;
            
        case 'payment.failed':
            await handlePaymentFailed(data);
            break;
            
        case 'payment.refunded':
            await handlePaymentRefunded(data);
            break;
            
        case 'cashout.completed':
            await handleCashoutCompleted(data);
            break;
            
        case 'webhook.test':
            console.log('GeniusPay Webhook: Test reçu');
            break;
            
        default:
            console.log('GeniusPay Webhook: Événement non géré', { event });
    }
}

async function handlePaymentSuccess(data) {
    const orderId = data.metadata?.order_id;
    if (orderId) {
        // await Order.update({ status: 'paid' }, { where: { reference: orderId } });
        console.log(`Commande ${orderId} marquée comme payée`);
    }
}

async function handlePaymentFailed(data) {
    console.log('Paiement échoué', { reference: data.reference });
}

async function handlePaymentRefunded(data) {
    console.log('Paiement remboursé', { reference: data.reference });
}

async function handleCashoutCompleted(data) {
    console.log('Retrait complété', { amount: data.amount });
}

app.listen(3000, () => {
    console.log('Serveur webhook démarré sur le port 3000');
});
```

---

### Python (Flask)

```python
import os
import hmac
import hashlib
import json
import time
from flask import Flask, request, jsonify
import logging

app = Flask(__name__)
logging.basicConfig(level=logging.INFO)

WEBHOOK_SECRET = os.getenv('GENIUSPAY_WEBHOOK_SECRET')

@app.route('/webhooks/geniuspay', methods=['POST'])
def geniuspay_webhook():
    try:
        # 1. Récupérer les headers
        signature = request.headers.get('X-Webhook-Signature')
        timestamp = request.headers.get('X-Webhook-Timestamp')
        event = request.headers.get('X-Webhook-Event')
        
        # 2. Vérifier les headers requis
        if not signature or not timestamp or not event:
            logging.warning('GeniusPay Webhook: Headers manquants')
            return jsonify({
                'type': 'about:blank',
                'title': 'Bad Request',
                'status': 400,
                'detail': 'Required header is not present.',
                'instance': request.path
            }), 400
        
        # 3. Récupérer le payload
        payload = request.get_json(force=True)
        
        # 4. Vérifier la signature
        if not verify_signature(signature, payload, timestamp, WEBHOOK_SECRET):
            logging.warning('GeniusPay Webhook: Signature invalide')
            return jsonify({
                'type': 'about:blank',
                'title': 'Unauthorized',
                'status': 401,
                'detail': 'Invalid signature',
                'instance': request.path
            }), 401
        
        # 5. Vérifier le timestamp
        current_time = int(time.time())
        time_diff = abs(current_time - int(timestamp))
        if time_diff > 300:  # 5 minutes
            logging.warning('GeniusPay Webhook: Timestamp trop ancien')
            return jsonify({
                'type': 'about:blank',
                'title': 'Bad Request',
                'status': 400,
                'detail': 'Timestamp too old',
                'instance': request.path
            }), 400
        
        # 6. Traiter l'événement
        process_event(event, payload)
        
        logging.info(f'GeniusPay Webhook: Événement traité - {event}')
        
        return jsonify({
            'success': True,
            'message': 'Webhook processed successfully'
        }), 200
        
    except Exception as e:
        logging.error(f'GeniusPay Webhook: Erreur - {str(e)}')
        return jsonify({
            'type': 'about:blank',
            'title': 'Internal Server Error',
            'status': 500,
            'detail': 'Failed to process webhook',
            'instance': request.path
        }), 500

def verify_signature(signature, payload, timestamp, secret):
    """Vérifier la signature HMAC"""
    data = f"{timestamp}.{json.dumps(payload, separators=(',', ':'))}"
    expected_signature = hmac.new(
        secret.encode('utf-8'),
        data.encode('utf-8'),
        hashlib.sha256
    ).hexdigest()
    return hmac.compare_digest(signature, expected_signature)

def process_event(event, payload):
    """Traiter l'événement selon son type"""
    data = payload.get('data', {})
    
    handlers = {
        'payment.success': handle_payment_success,
        'payment.failed': handle_payment_failed,
        'payment.refunded': handle_payment_refunded,
        'cashout.completed': handle_cashout_completed,
        'webhook.test': lambda d: logging.info('GeniusPay Webhook: Test reçu')
    }
    
    handler = handlers.get(event)
    if handler:
        handler(data)
    else:
        logging.info(f'GeniusPay Webhook: Événement non géré - {event}')

def handle_payment_success(data):
    order_id = data.get('metadata', {}).get('order_id')
    if order_id:
        # Update order status in database
        logging.info(f"Commande {order_id} marquée comme payée")

def handle_payment_failed(data):
    logging.info(f"Paiement échoué - {data.get('reference')}")

def handle_payment_refunded(data):
    logging.info(f"Paiement remboursé - {data.get('reference')}")

def handle_cashout_completed(data):
    logging.info(f"Retrait complété - {data.get('amount')}")

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000)
```

---

### Java (Spring Boot)

**Controller (GeniusPayWebhookController.java):**

```java
package com.example.webhooks.controller;

import com.example.webhooks.dto.WebhookRequest;
import com.example.webhooks.service.GeniusPayWebhookService;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.*;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import java.nio.charset.StandardCharsets;
import java.security.InvalidKeyException;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.time.Instant;
import java.util.HashMap;
import java.util.Map;

@RestController
@RequestMapping("/webhooks")
public class GeniusPayWebhookController {

    private static final Logger logger = LoggerFactory.getLogger(GeniusPayWebhookController.class);
    
    @Autowired
    private GeniusPayWebhookService webhookService;
    
    @Autowired
    private ObjectMapper objectMapper;
    
    private static final String WEBHOOK_SECRET = System.getenv("GENIUSPAY_WEBHOOK_SECRET");
    private static final int TIMESTAMP_TOLERANCE_SECONDS = 300; // 5 minutes

    @PostMapping("/geniuspay")
    public ResponseEntity<Map<String, Object>> handleWebhook(
            @RequestHeader(value = "X-Webhook-Signature", required = false) String signature,
            @RequestHeader(value = "X-Webhook-Timestamp", required = false) String timestamp,
            @RequestHeader(value = "X-Webhook-Event", required = false) String event,
            @RequestBody String rawBody) {
        
        try {
            // 1. Vérifier que tous les headers requis sont présents
            if (signature == null || timestamp == null || event == null) {
                logger.warn("GeniusPay Webhook: Headers manquants");
                return createErrorResponse(
                    HttpStatus.BAD_REQUEST,
                    "Bad Request",
                    "Required header is not present."
                );
            }
            
            // 2. Parser le payload
            JsonNode payload = objectMapper.readTree(rawBody);
            
            // 3. Vérifier la signature
            if (!verifySignature(signature, rawBody, timestamp, WEBHOOK_SECRET)) {
                logger.warn("GeniusPay Webhook: Signature invalide - Event: {}", event);
                return createErrorResponse(
                    HttpStatus.UNAUTHORIZED,
                    "Unauthorized",
                    "Invalid signature"
                );
            }
            
            // 4. Vérifier le timestamp (protection contre replay attack)
            long currentTime = Instant.now().getEpochSecond();
            long webhookTimestamp = Long.parseLong(timestamp);
            long timeDiff = Math.abs(currentTime - webhookTimestamp);
            
            if (timeDiff > TIMESTAMP_TOLERANCE_SECONDS) {
                logger.warn("GeniusPay Webhook: Timestamp trop ancien - Diff: {} secondes", timeDiff);
                return createErrorResponse(
                    HttpStatus.BAD_REQUEST,
                    "Bad Request",
                    "Timestamp too old"
                );
            }
            
            // 5. Traiter l'événement
            webhookService.processEvent(event, payload);
            
            logger.info("GeniusPay Webhook: Événement traité - Event: {}, Transaction ID: {}", 
                event, payload.path("data").path("id").asText());
            
            Map<String, Object> response = new HashMap<>();
            response.put("success", true);
            response.put("message", "Webhook processed successfully");
            return ResponseEntity.ok(response);
            
        } catch (Exception e) {
            logger.error("GeniusPay Webhook: Erreur de traitement - Event: {}, Error: {}", 
                event, e.getMessage(), e);
            return createErrorResponse(
                HttpStatus.INTERNAL_SERVER_ERROR,
                "Internal Server Error",
                "Failed to process webhook"
            );
        }
    }
    
    /**
     * Vérifier la signature HMAC SHA-256
     */
    private boolean verifySignature(String signature, String rawBody, String timestamp, String secret) {
        try {
            String data = timestamp + "." + rawBody;
            
            Mac hmacSha256 = Mac.getInstance("HmacSHA256");
            SecretKeySpec secretKey = new SecretKeySpec(secret.getBytes(StandardCharsets.UTF_8), "HmacSHA256");
            hmacSha256.init(secretKey);
            
            byte[] hash = hmacSha256.doFinal(data.getBytes(StandardCharsets.UTF_8));
            String expectedSignature = bytesToHex(hash);
            
            return MessageDigest.isEqual(
                signature.getBytes(StandardCharsets.UTF_8),
                expectedSignature.getBytes(StandardCharsets.UTF_8)
            );
            
        } catch (NoSuchAlgorithmException | InvalidKeyException e) {
            logger.error("Erreur lors de la vérification de la signature", e);
            return false;
        }
    }
    
    /**
     * Convertir bytes en hex string
     */
    private String bytesToHex(byte[] bytes) {
        StringBuilder result = new StringBuilder();
        for (byte b : bytes) {
            result.append(String.format("%02x", b));
        }
        return result.toString();
    }
    
    /**
     * Créer une réponse d'erreur standardisée
     */
    private ResponseEntity<Map<String, Object>> createErrorResponse(
            HttpStatus status, String title, String detail) {
        Map<String, Object> errorResponse = new HashMap<>();
        errorResponse.put("type", "about:blank");
        errorResponse.put("title", title);
        errorResponse.put("status", status.value());
        errorResponse.put("detail", detail);
        errorResponse.put("instance", "/webhooks/geniuspay");
        return ResponseEntity.status(status).body(errorResponse);
    }
}
```

**Service (GeniusPayWebhookService.java):**

```java
package com.example.webhooks.service;

import com.fasterxml.jackson.databind.JsonNode;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Service;

@Service
public class GeniusPayWebhookService {

    private static final Logger logger = LoggerFactory.getLogger(GeniusPayWebhookService.class);

    /**
     * Traiter l'événement selon son type
     */
    public void processEvent(String event, JsonNode payload) {
        JsonNode data = payload.path("data");
        
        switch (event) {
            case "payment.success":
                handlePaymentSuccess(data);
                break;
                
            case "payment.failed":
                handlePaymentFailed(data);
                break;
                
            case "payment.refunded":
                handlePaymentRefunded(data);
                break;
                
            case "cashout.completed":
                handleCashoutCompleted(data);
                break;
                
            case "webhook.test":
                logger.info("GeniusPay Webhook: Test reçu");
                break;
                
            default:
                logger.info("GeniusPay Webhook: Événement non géré - Event: {}", event);
        }
    }
    
    private void handlePaymentSuccess(JsonNode data) {
        String orderId = data.path("metadata").path("order_id").asText(null);
        if (orderId != null && !orderId.isEmpty()) {
            // Mettre à jour le statut de la commande dans la base de données
            // orderRepository.updateStatus(orderId, "paid");
            logger.info("Commande {} marquée comme payée", orderId);
        }
    }
    
    private void handlePaymentFailed(JsonNode data) {
        String reference = data.path("reference").asText();
        logger.info("Paiement échoué - Reference: {}", reference);
        // Envoyer une notification au client
    }
    
    private void handlePaymentRefunded(JsonNode data) {
        String reference = data.path("reference").asText();
        double amount = data.path("amount").asDouble();
        logger.info("Paiement remboursé - Reference: {}, Amount: {}", reference, amount);
        // Traiter le remboursement
    }
    
    private void handleCashoutCompleted(JsonNode data) {
        double amount = data.path("amount").asDouble();
        logger.info("Retrait complété - Amount: {}", amount);
        // Confirmer le retrait
    }
}
```

**Configuration (application.properties):**

```properties
# GeniusPay Webhook Configuration
geniuspay.webhook.secret=${GENIUSPAY_WEBHOOK_SECRET}

# Server Configuration
server.port=8080

# Logging
logging.level.com.example.webhooks=INFO
```

**Dépendances (pom.xml pour Maven):**

```xml
<dependencies>
    <!-- Spring Boot Web -->
    <dependency>
        <groupId>org.springframework.boot</groupId>
        <artifactId>spring-boot-starter-web</artifactId>
    </dependency>
    
    <!-- Jackson pour JSON -->
    <dependency>
        <groupId>com.fasterxml.jackson.core</groupId>
        <artifactId>jackson-databind</artifactId>
    </dependency>
    
    <!-- Logging -->
    <dependency>
        <groupId>org.springframework.boot</groupId>
        <artifactId>spring-boot-starter-logging</artifactId>
    </dependency>
</dependencies>
```

**Dépendances (build.gradle pour Gradle):**

```gradle
dependencies {
    implementation 'org.springframework.boot:spring-boot-starter-web'
    implementation 'com.fasterxml.jackson.core:jackson-databind'
    implementation 'org.springframework.boot:spring-boot-starter-logging'
}
```

**Classe principale (Application.java):**

```java
package com.example.webhooks;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;

@SpringBootApplication
public class WebhookApplication {
    public static void main(String[] args) {
        SpringApplication.run(WebhookApplication.class, args);
    }
}
```

**Variable d'environnement:**

```bash
export GENIUSPAY_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

---

## 🧪 Tester votre Implémentation

### Avec curl

```bash
curl -X POST http://votre-serveur.com/webhooks/geniuspay \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Signature: 3bda91086064c14253f55a91310f503810a55ea4f6a5583f97779c3fa5ade013" \
  -H "X-Webhook-Timestamp: 1735587600" \
  -H "X-Webhook-Event: webhook.test" \
  -d '{
    "id": "test-uuid",
    "event": "webhook.test",
    "timestamp": 1735587600,
    "created_at": "2025-12-30T12:00:00.000000Z",
    "data": {
      "object": "webhook.test",
      "message": "This is a test webhook"
    },
    "environment": "sandbox",
    "api_version": "2024-01-01"
  }'
```

### Depuis le Dashboard GeniusPay

1. Accédez à **Paramètres** → **Webhooks**
2. Cliquez sur le bouton de test (icône éclair) pour votre webhook
3. Vérifiez que vous recevez une réponse 200 OK

---

## ⚠️ Meilleures Pratiques

### 1. **Toujours vérifier la signature**
Ne jamais traiter un webhook sans vérifier sa signature HMAC.

### 2. **Vérifier le timestamp**
Rejeter les webhooks avec un timestamp trop ancien (> 5 minutes) pour éviter les replay attacks.

### 3. **Répondre rapidement**
Votre endpoint doit répondre dans les 10 secondes. Si le traitement est long:
- Retourner 200 OK immédiatement
- Traiter l'événement en arrière-plan (queue/job)

### 4. **Idempotence**
Gérer les duplicatas (même webhook reçu plusieurs fois):
```php
// Exemple avec une table de tracking
if (WebhookLog::where('delivery_id', $deliveryId)->exists()) {
    return response()->json(['success' => true, 'message' => 'Already processed']);
}
```

### 5. **Logs et Monitoring**
Logger tous les webhooks reçus pour faciliter le debug:
- Timestamp de réception
- Événement
- ID de transaction
- Statut du traitement

### 6. **HTTPS obligatoire**
GeniusPay n'envoie des webhooks que vers des URLs HTTPS en production.

### 7. **Gestion des erreurs**
Retourner des codes HTTP appropriés:
- `200` : Webhook traité avec succès
- `400` : Requête malformée
- `401` : Signature invalide
- `500` : Erreur serveur (GeniusPay réessaiera)

### 8. **Retry automatique**
En cas d'échec (5xx, timeout), GeniusPay réessaiera automatiquement:
- Tentative 1 : Immédiat
- Tentative 2 : Après 5 minutes
- Tentative 3 : Après 30 minutes
- Tentative 4 : Après 2 heures
- Tentative 5 : Après 6 heures

---

## 🔧 Debugging

### Vérifier votre signature localement

**PHP:**
```php
$secret = 'whsec_xxx';
$timestamp = '1735587600';
$payload = ['event' => 'webhook.test', 'data' => []];
$data = $timestamp . '.' . json_encode($payload);
$signature = hash_hmac('sha256', $data, $secret);
echo $signature;
```

**Node.js:**
```javascript
const crypto = require('crypto');
const secret = 'whsec_xxx';
const timestamp = '1735587600';
const payload = {event: 'webhook.test', data: {}};
const data = `${timestamp}.${JSON.stringify(payload)}`;
const signature = crypto.createHmac('sha256', secret).update(data).digest('hex');
console.log(signature);
```

**Python:**
```python
import hmac, hashlib, json
secret = 'whsec_xxx'
timestamp = '1735587600'
payload = {'event': 'webhook.test', 'data': {}}
data = f"{timestamp}.{json.dumps(payload)}"
signature = hmac.new(secret.encode(), data.encode(), hashlib.sha256).hexdigest()
print(signature)
```

### Erreurs communes

| Erreur | Cause | Solution |
|--------|-------|----------|
| `Required header is not present` | Header manquant | Vérifier que tous les headers `X-Webhook-*` sont présents |
| `Invalid signature` | Signature incorrecte | Vérifier le secret webhook et le format de calcul |
| `Failed to read request` | Body mal formé | Utiliser `bodyParser.raw()` en Node.js ou `force=True` en Python |
| `Timestamp too old` | Webhook trop ancien | Vérifier l'horloge du serveur (NTP) |

---

## 📞 Support

Pour toute question ou problème:
- **Email:** support@genius.ci
- **Documentation API:** https://docs.genius.ci
- **Dashboard:** https://merchant.genius.ci

---

**Dernière mise à jour:** 30 décembre 2025  
**Version API:** 2024-01-01
