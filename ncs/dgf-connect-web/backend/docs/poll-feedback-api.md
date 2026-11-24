# Poll/Feedback API

## Overview

The poll/feedback system supports two types:
- **Vote**: Users evaluate candidates with `yes` (1), `no` (-1), or `blank` (0)
- **Feedback**: Users select choices from a list

Users receive FCM notifications when a new poll is activated. The `pending_votes` flag on the user model indicates if there are polls awaiting response.

---

## 1. Login / Auth Check Response

When a user has pending polls, the response includes a `pending_votes` array.

### Endpoints

```
POST /api/auth/login
GET  /api/auth/check
```

### Response Fields

```json
{
  "data": {
    "user": { ... },
    "roles": ["user"],
    "has_pending_votes": true,
    "pending_votes": [
      {
        "id": 1,
        "type": "feedback",
        "title_vi": "Khảo sát ý kiến",
        "title_en": "Feedback Survey",
        "title_ja": "フィードバック調査",
        "description_vi": "Vui lòng cho chúng tôi biết ý kiến của bạn",
        "description_en": "Please share your feedback",
        "description_ja": "ご意見を聞かせてください",
        "is_required": true,
        "expires_at": "2026-04-15T23:59:59+09:00",
        "is_multiple": false,
        "max_choice": 1,
        "options": [
          { "id": 1, "label_vi": "Rất hài lòng", "label_en": "Very Satisfied", "label_ja": "非常に満足" },
          { "id": 2, "label_vi": "Hài lòng", "label_en": "Satisfied", "label_ja": "満足" },
          { "id": 3, "label_vi": "Không hài lòng", "label_en": "Not Satisfied", "label_ja": "不满" }
        ]
      },
      {
        "id": 2,
        "type": "vote",
        "title_vi": "Bầu chọn nhân viên xuất sắc",
        "title_en": "Vote for Outstanding Employee",
        "title_ja": "優秀な社員の投票",
        "description_vi": null,
        "description_en": null,
        "description_ja": null,
        "is_required": false,
        "expires_at": null,
        "is_multiple": false,
        "max_choice": 1,
        "options": [
          { "id": 10, "name": "Nguyen Van A" },
          { "id": 11, "name": "Tran Van B" }
        ]
      }
    ]
  }
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `has_pending_votes` | boolean | Indicates if user has pending polls |
| `pending_votes` | array | Array of pending poll objects |

### Pending Vote Object

| Field | Type | Description |
|-------|------|-------------|
| `id` | number | Poll ID |
| `type` | string | `"vote"` or `"feedback"` |
| `title_vi`, `title_en`, `title_ja` | string | Multilingual titles |
| `description_vi`, `description_en`, `description_ja` | string\|null | Multilingual descriptions |
| `is_required` | boolean | Whether poll is mandatory |
| `expires_at` | string\|null | ISO 8601 expiration datetime |
| `is_multiple` | boolean | Whether multiple selections allowed |
| `max_choice` | number | Maximum number of choices user can select |
| `options` | array | Available options (format depends on type) |

### Options Format by Type

**For `feedback` polls:**
```json
[
  { "id": 1, "label_vi": "...", "label_en": "...", "label_ja": "..." },
  { "id": 2, "label_vi": "...", "label_en": "...", "label_ja": "..." }
]
```

**For `vote` polls:**
```json
[
  { "id": 10, "name": "Candidate Name" },
  { "id": 11, "name": "Another Candidate" }
]
```

---

## 2. Submit Poll Response

Submit user's answer to a poll.

### Endpoint

```
POST /api/polls/{poll_id}/respond
```

**Authorization**: Bearer token (authenticated user)

### Request Body

**For `feedback` polls:**

```json
{
  "answer": [1, 2],
  "device_info": {
    "model": "iPhone 14",
    "os": "iOS 17.0",
    "app_version": "1.2.3"
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `answer` | array | Yes | Array of selected choice IDs |
| `device_info` | object | No | Device metadata |

**For `vote` polls:**

```json
{
  "answer": {
    "10": 1,
    "11": -1,
    "12": 0
  },
  "device_info": {
    "model": "iPhone 14",
    "os": "iOS 17.0",
    "app_version": "1.2.3"
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `answer` | object | Yes | Object mapping candidate ID to value (1=yes, -1=no, 0=blank) |
| `device_info` | object | No | Device metadata |

### Response

```json
{
  "data": {
    "submitted": true,
    "has_pending_votes": false
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `submitted` | boolean | Always `true` on success |
| `has_pending_votes` | boolean | Updated flag indicating if user has more pending polls |

### Validation Rules

**Feedback Poll:**
- Answer must be array of choice IDs
- All selected IDs must exist in poll options
- No duplicate selections allowed
- If `is_multiple=false`, exactly 1 selection required
- If `is_multiple=true`, selections must not exceed `max_choice`

**Vote Poll:**
- Answer must be object with all candidate IDs as keys
- Values must be `1` (yes), `-1` (no), or `0` (blank)
- Yes count must not exceed `max_choice`

### Error Responses

| Status | Error Code | Description |
|--------|------------|-------------|
| 403 | `poll.not_eligible` | User not eligible for this poll |
| 422 | `poll.inactive` | Poll is not active |
| 422 | `poll.expired` | Poll has expired |
| 422 | `poll.already_responded` | User has already responded |
| 422 | `poll.exceeds_max_choice` | Too many selections |
| 422 | `poll.invalid_choice` | Invalid choice ID |

---

## 3. FCM Notification Payload

Sent when a new poll is activated and user is eligible.

### Notification Structure

```json
{
  "notification": {
    "title": "New poll available",
    "body": "Feedback Survey"
  },
  "data": {
    "id": 123,
    "type": "poll_available",
    "icon": "info",
    "poll_id": 1,
    "poll_type": "feedback",
    "user_id": 42
  }
}
```

### Data Fields

| Field | Type | Description |
|-------|------|-------------|
| `id` | number | Notification ID |
| `type` | string | Always `"poll_available"` |
| `icon` | string | Always `"info"` |
| `poll_id` | number | ID of the poll |
| `poll_type` | string | `"vote"` or `"feedback"` |
| `user_id` | number | User ID |

### Parsing

Mobile app should:
1. Check if `data.type === "poll_available"`
2. Fetch poll details using `poll_id` if needed
3. Present the poll to user

---

## Summary for Mobile Integration

1. **On login/auth check**: Check `has_pending_votes`. If `true`, display polls from `pending_votes` array.

2. **On poll response**: Call `POST /api/polls/{id}/respond` with appropriate answer format based on poll type. Check `has_pending_votes` in response to determine if more polls remain.

3. **On FCM notification**: When `data.type === "poll_available"`, the user has a new poll to respond to. Poll details can be shown directly from notification or fetched from the pending_votes endpoint.

---

## Appendix: Eligibility Rules

- **Global polls**: User must have `staff_no` assigned
- **Workplace polls**: User must have `staff_no` assigned AND be a member of the poll's workplace

Users without `staff_no` (new registrations pending approval) will not receive polls.
