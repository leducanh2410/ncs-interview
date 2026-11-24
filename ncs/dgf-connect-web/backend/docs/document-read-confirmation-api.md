# Document Read Confirmation API

## Overview

Employees must read and confirm required documents at two levels:
- **System-wide**: Applied upon first paper approval
- **Per-workplace**: Applied upon first approved shift at that workplace

---

## 1. Login / Auth Check Response

When a user has pending documents, the response includes a `pending_documents` array.

### Endpoints

```
POST /api/auth/login
GET  /api/auth/check
```

### Response Fields

```json
{
  "data": {
    "has_pending_documents": true,
    "pending_documents": [
      {
        "id": 1,
        "workplace_id": null,
        "title_vi": "Tài liệu bắt buộc",
        "title_en": "Required Document",
        "title_ja": "必須書類",
        "document": {
          "id": 10,
          "url": "https://example.com/document.pdf",
          "attachments": [...]
        }
      }
    ]
  }
}
```

### Response Logic

| `has_pending_documents` | Query Behavior |
|-------------------------|----------------|
| `false` | Returns `pending_documents: []` (no additional query) |
| `true` | Queries pending documents, self-heals flag if stale |

**Pending documents are ordered by `id` (ascending).**

---

## 2. Confirm Document Read

User confirms they have read a document.

### Endpoint

```
POST /api/user/document-read-configs/{config_id}/confirm
```

**Authorization**: Bearer token (authenticated user)

### Request Body

```json
{
  "device_info": {
    "model": "iPhone",
    "os": "iOS",
    "app_version": "1.2.3"
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `device_info` | object | No | Device metadata (model, OS, app version) |

### Response

```json
{
  "data": {
    "confirmed": true,
    "has_pending_documents": false
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `confirmed` | boolean | Always `true` on success (no-op if config inactive) |
| `has_pending_documents` | boolean | Updated flag status |

### Behavior

- **Idempotent**: Duplicate confirmations return success without creating new records
- **Audit data**: IP address captured automatically; `device_info` stored as JSON
- **Flag reset**: `has_pending_documents` set to `false` when all configs confirmed

---

## 3. FCM Notification Payload

Sent when user's paper is approved or first workplace shift is approved.

### Notification Structure

```json
{
  "notification": {
    "title": "You have required documents to read and confirm",
    "body": "You have required documents to read and confirm"
  },
  "data": {
    "type": "document_read_required",
    "pending_documents": "[{\"id\":1,\"workplace_id\":null,\"title_vi\":\"...\",\"title_en\":\"...\",\"title_ja\":\"...\",\"document\":{\"id\":10,\"url\":\"https://...\",\"attachments\":[]}}]"
  }
}
```

### Data Fields

| Field | Type | Description |
|-------|------|-------------|
| `type` | string | Always `"document_read_required"` |
| `pending_documents` | string | **JSON-encoded array** of pending document objects |

### Parsing `pending_documents`

The `pending_documents` field is a **JSON string**. Parse it to get the array:

```javascript
const pendingDocs = JSON.parse(notification.data.pending_documents);
```

Each object contains:

| Field | Type | Description |
|-------|------|-------------|
| `id` | number | `document_required_config_id` |
| `workplace_id` | number|null | `null` for system-wide |
| `title_vi`, `title_en`, `title_ja` | string | Multilingual titles |
| `document` | object | Document details (`id`, `url`, `attachments`) |

---

## Summary for Mobile Integration

1. **On login/auth check**: Check `has_pending_documents`. If `true`, show popup with documents from `pending_documents` array.

2. **On confirm**: Call `POST /api/user/document-read-configs/{id}/confirm` with device info. Check `has_pending_documents` in response to determine if more docs remain.

3. **On FCM notification**: When `data.type === "document_read_required"`, parse `data.pending_documents` JSON string and show popup.
