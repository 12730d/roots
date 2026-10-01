# Drone Face Scanner API Documentation

**Version:** 1.0.0  
**Last Updated:** 2024-06-04  
**Status:** Production Ready

---

## 📋 Table of Contents

- [Overview](#overview)
- [Authentication](#authentication)
- [API Endpoints](#api-endpoints)
- [Data Models](#data-models)
- [Error Handling](#error-handling)
- [Rate Limiting](#rate-limiting)
- [Security](#security)
- [Webhooks](#webhooks)
- [SDKs & Libraries](#sdks--libraries)

---

## 🔍 Overview

The Drone Face Scanner API provides comprehensive face detection and recognition capabilities for drone surveillance systems. Built with OpenCV and PHP, it offers secure, high-performance biometric matching with GDPR-compliant data protection.

### Key Features

- **Real-time Face Detection**: Advanced face recognition using OpenCV
- **Secure Authentication**: API key and secret based authentication
- **Subscription Tiers**: Different access levels based on subscription
- **Rate Limiting**: Configurable rate limits per subscription tier
- **Encrypted Data**: AES-256-GCM encryption for all data
- **GDPR Compliant**: Full data protection and right to be forgotten
- **Real-time Webhooks**: Event-driven notifications
- **Search Database Integration**: Cross-database face matching

### Base URL

```
Production: https://your-domain.com/api/v1/drone
Development: http://localhost:8080/api/v1/drone
```

---

## 🔐 Authentication

### API Keys

All API requests require authentication using API keys and secrets:

```php
// Headers
X-API-Key: ak_live_1234567890abcdef
X-API-Secret: sk_live_secret_xyz123
```

### Obtaining API Keys

1. Register a drone through the dashboard
2. Copy the API key and secret
3. Store the secret securely (you won't see it again)
4. Use the credentials for API requests

### Authentication Flow

```python
import requests

headers = {
    'X-API-Key': 'your_api_key',
    'X-API-Secret': 'your_api_secret',
    'Content-Type': 'application/json'
}

response = requests.get(
    'https://your-domain.com/api/v1/drone/statistics',
    headers=headers
)
```

---

## 🚀 API Endpoints

### 1. Register Drone

Register a new drone with the platform.

**Endpoint:** `POST /api/v1/drone/register`

**Request Body:**
```json
{
  "user_id": 123,
  "drone_id": "DRN-2024-001",
  "drone_name": "Surveillance Drone Alpha",
  "drone_type": "quadcopter",
  "camera_specs": {
    "resolution": "4K",
    "fps": 30
  }
}
```

**Response:**
```json
{
  "success": true,
  "drone_id": "DRN-2024-001",
  "drone_registration_id": 456,
  "api_key": "ak_live_1234567890abcdef",
  "api_secret": "sk_live_secret_xyz123",
  "subscription_tier": "premium",
  "rate_limits": {
    "per_minute": 120,
    "per_hour": 5000
  },
  "webhook_url": "https://your-domain.com/api/v1/drone/webhook",
  "status": "active"
}
```

---

### 2. Authenticate Drone

Authenticate using API credentials.

**Endpoint:** `POST /api/v1/drone/authenticate`

**Request Body:**
```json
{
  "api_key": "ak_live_1234567890abcdef",
  "api_secret": "sk_live_secret_xyz123"
}
```

**Response:**
```json
{
  "success": true,
  "drone_id": "DRN-2024-001",
  "user_id": 123,
  "subscription_tier": "premium",
  "rate_limits": {
    "per_minute": 120,
    "per_hour": 5000
  }
}
```

---

### 3. Face Scan

Scan an image for face detection and recognition.

**Endpoint:** `POST /api/v1/drone/face-scan`

**Headers:**
```
X-API-Key: your_api_key
X-API-Secret: your_api_secret
```

**Request Body:**
```json
{
  "image_data": "base64_encoded_image",
  "location": {
    "lat": 24.7136,
    "lng": 46.6753,
    "alt": 150.5
  },
  "options": {
    "confidence_threshold": 0.75,
    "max_faces": 5
  }
}
```

**Response:**
```json
{
  "success": true,
  "scan_id": "SCAN-2024-001234",
  "drone_id": "DRN-2024-001",
  "faces_detected": 2,
  "matches_found": 1,
  "matched_faces": [
    {
      "face_id": "face_abc123",
      "face_name": "John Doe",
      "confidence": 0.92,
      "user_id": 123,
      "bounding_box": {
        "top": 100,
        "right": 200,
        "bottom": 300,
        "left": 50
      }
    }
  ],
  "processing_time_ms": 245,
  "timestamp": "2024-06-04T10:30:00+00:00",
  "rate_limit_remaining": 115
}
```

---

### 4. Register Face

Register a new face for recognition.

**Endpoint:** `POST /api/v1/faces/register`

**Headers:**
```
X-API-Key: your_api_key
X-API-Secret: your_api_secret
```

**Request Body:**
```json
{
  "face_name": "Jane Smith",
  "face_image": "base64_encoded_face_image",
  "metadata": {
    "category": "vip",
    "department": "Security",
    "access_level": "high"
  }
}
```

**Response:**
```json
{
  "success": true,
  "face_id": "face_xyz789",
  "face_registration_id": 789,
  "face_name": "Jane Smith",
  "encoding_confidence": 0.95
}
```

---

### 5. Delete Face

Delete a registered face.

**Endpoint:** `DELETE /api/v1/faces/{face_id}`

**Headers:**
```
X-API-Key: your_api_key
X-API-Secret: your_api_secret
```

**Response:**
```json
{
  "success": true,
  "message": "Face deleted successfully"
}
```

---

### 6. Get Statistics

Get drone usage statistics.

**Endpoint:** `GET /api/v1/drone/statistics`

**Headers:**
```
X-API-Key: your_api_key
X-API-Secret: your_api_secret
```

**Response:**
```json
{
  "success": true,
  "data": {
    "face_statistics": {
      "total_faces": 25,
      "total_scans": 1500,
      "active_faces": 22
    },
    "scan_statistics": {
      "total_scans": 1500,
      "successful_matches": 1200,
      "avg_processing_time_ms": 245.50,
      "avg_confidence": 0.87
    },
    "timestamp": "2024-06-04T10:30:00+00:00"
  }
}
```

---

### 7. Get Drone Info

Get detailed information about a drone.

**Endpoint:** `GET /api/v1/drone/info`

**Headers:**
```
X-API-Key: your_api_key
X-API-Secret: your_api_secret
```

**Response:**
```json
{
  "success": true,
  "data": {
    "drone_id": "DRN-2024-001",
    "drone_name": "Surveillance Drone Alpha",
    "drone_type": "quadcopter",
    "status": "active",
    "subscription_tier": "premium",
    "rate_limit_per_minute": 120,
    "rate_limit_per_hour": 5000,
    "last_active": "2024-06-04T10:25:00+00:00",
    "created_at": "2024-01-15T08:00:00+00:00"
  }
}
```

---

## 📊 Data Models

### Drone Registration

```typescript
interface DroneRegistration {
  drone_id: string;
  drone_name: string;
  user_id: number;
  api_key: string;
  drone_type: string;
  camera_specs: CameraSpecs;
  status: 'active' | 'inactive' | 'blocked';
  subscription_tier: 'basic' | 'premium' | 'vip' | 'elite';
  rate_limit_per_minute: number;
  rate_limit_per_hour: number;
  last_active: string;
  created_at: string;
}
```

### Face Scan Result

```typescript
interface FaceScanResult {
  scan_id: string;
  drone_id: string;
  faces_detected: number;
  matched_faces: FaceMatch[];
  processing_time_ms: number;
  confidence_threshold: number;
  timestamp: string;
}
```

### Face Match

```typescript
interface FaceMatch {
  face_id: string;
  face_name: string;
  confidence: number;
  user_id: number;
  bounding_box: BoundingBox;
}

interface BoundingBox {
  top: number;
  right: number;
  bottom: number;
  left: number;
}
```

---

## ⚠️ Error Handling

### Error Response Format

All error responses follow this format:

```json
{
  "success": false,
  "error": "Error description",
  "code": 401,
  "timestamp": "2024-06-04T10:30:00+00:00"
}
```

### HTTP Status Codes

- `200 OK` - Request successful
- `201 Created` - Resource created successfully
- `400 Bad Request` - Invalid request parameters
- `401 Unauthorized` - Authentication failed
- `403 Forbidden` - Access denied
- `404 Not Found` - Resource not found
- `429 Too Many Requests` - Rate limit exceeded
- `500 Internal Server Error` - Server error

### Common Error Codes

| Code | Description |
|------|-------------|
| `AUTH_INVALID` | Invalid API credentials |
| `AUTH_EXPIRED` | Authentication token expired |
| `RATE_LIMIT_EXCEEDED` | Rate limit exceeded |
| `INVALID_IMAGE` | Invalid image format |
| `FACE_NOT_DETECTED` | No face detected in image |
| `ENCRYPTION_ERROR` | Data encryption/decryption error |
| `DATABASE_ERROR` | Database operation failed |

---

## 🚦 Rate Limiting

### Subscription-Based Limits

| Tier | Requests/Minute | Requests/Hour | Features |
|------|-----------------|---------------|----------|
| Basic | 60 | 1,000 | Basic face detection |
| Premium | 120 | 5,000 | Advanced recognition |
| VIP | 300 | 15,000 | Priority processing |
| Elite | Unlimited | Unlimited | Full access + support |

### Rate Limit Headers

```http
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 115
X-RateLimit-Reset: 1609824000
```

### Handling Rate Limits

```python
import time
import requests

def make_request_with_retry(url, headers, max_retries=3):
    for attempt in range(max_retries):
        response = requests.post(url, headers=headers)
        
        if response.status_code == 429:
            retry_after = int(response.headers.get('Retry-After', 60))
            time.sleep(retry_after)
            continue
            
        return response
    
    return response  # Last attempt
```

---

## 🔒 Security

### Encryption

All sensitive data is encrypted using **AES-256-GCM**:

- Images are encrypted before storage
- Face encodings are encrypted in transit
- API secrets are hashed using Argon2ID

### Data Protection

- **GDPR Compliant**: Full compliance with EU data protection regulations
- **Right to be Forgotten**: Complete data deletion on request
- **Data Minimization**: Only collect necessary data
- **Audit Logging**: All operations are logged for security

### Secure Storage

```php
// Example: Encrypting sensitive data
$encryption = new \ROOTS\Drone\Services\EncryptionService();
$encrypted = $encryption->encryptField("sensitive_data");

// Decrypting when needed
$decrypted = $encryption->decryptField($encrypted);
```

---

## 🎣 Webhooks

### Webhook Events

The API sends webhook notifications for the following events:

- `scan_complete` - Face scan completed
- `match_found` - Face match found in database
- `drone_status` - Drone status changed
- `rate_limit_warning` - Approaching rate limit

### Setting Up Webhooks

Webhooks can be configured through the dashboard:

1. Navigate to Drone Settings
2. Add webhook URL
3. Select events to subscribe
4. Provide webhook secret for signature verification

### Webhook Payload

```json
{
  "event": "match_found",
  "timestamp": "2024-06-04T10:30:00+00:00",
  "data": {
    "scan_id": "SCAN-2024-001234",
    "face_id": "face_abc123",
    "face_name": "John Doe",
    "confidence": 0.92,
    "drone_id": "DRN-2024-001"
  },
  "signature": "sha256=abc123..."
}
```

### Verifying Webhook Signatures

```python
import hmac
import hashlib

def verify_webhook_signature(payload, signature, secret):
    expected_signature = hmac.new(
        secret.encode(),
        payload.encode(),
        hashlib.sha256
    ).hexdigest()
    
    return hmac.compare_digest(expected_signature, signature)
```

---

## 📚 SDKs & Libraries

### Python SDK

```python
from drone_api import DroneClient

client = DroneClient(
    api_key="your_api_key",
    api_secret="your_api_secret",
    base_url="https://your-domain.com/api/v1/drone"
)

# Face scan
result = client.scan_face(
    image_data=open("photo.jpg", "rb").read(),
    confidence_threshold=0.75
)

print(f"Faces detected: {result['faces_detected']}")
```

### JavaScript SDK

```javascript
import { DroneClient } from '@drone-scanner/sdk';

const client = new DroneClient({
  apiKey: 'your_api_key',
  apiSecret: 'your_api_secret',
  baseUrl: 'https://your-domain.com/api/v1/drone'
});

// Face scan
const result = await client.scanFace({
  imageData: base64Image,
  confidenceThreshold: 0.75
});

console.log(`Faces detected: ${result.faces_detected}`);
```

### PHP SDK

```php
use ROOTS\Drone\Client\DroneApiClient;

$client = new DroneApiClient(
    'your_api_key',
    'your_api_secret',
    'https://your-domain.com/api/v1/drone'
);

// Face scan
$result = $client->scanFace([
    'image_data' => $base64Image,
    'confidence_threshold' => 0.75
]);

echo "Faces detected: " . $result['faces_detected'];
```

---

## 🧪 Testing

### Test Environment

- **Base URL**: `https://test.your-domain.com/api/v1/drone`
- **Test API Key**: `ak_test_1234567890abcdef`
- **Test API Secret**: `sk_test_secret_xyz123`

### Example Test

```bash
# Test face scan
curl -X POST https://test.your-domain.com/api/v1/drone/face-scan \
  -H "X-API-Key: ak_test_1234567890abcdef" \
  -H "X-API-Secret: sk_test_secret_xyz123" \
  -H "Content-Type: application/json" \
  -d '{"image_data":"base64_image","options":{"confidence_threshold":0.75}}'
```

---

## 📞 Support

- **Documentation**: https://docs.your-domain.com
- **Support Email**: support@your-domain.com
- **Status Page**: https://status.your-domain.com
- **GitHub Issues**: https://github.com/your-org/drone-scanner/issues

---

## 📄 License

© 2024 Drone Face Scanner Platform. All rights reserved.

---

## 🔗 Quick Links

- [Dashboard](https://your-domain.com/drone-dashboard)
- [API Playground](https://your-domain.com/api-playground)
- [SDK Downloads](https://github.com/your-org/drone-scanner-sdk)
- [Security Guide](https://docs.your-domain.com/security)
- [Best Practices](https://docs.your-domain.com/best-practices)