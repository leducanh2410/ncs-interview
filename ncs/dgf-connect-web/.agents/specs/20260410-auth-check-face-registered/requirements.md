# Requirements Document

## Introduction

This feature extends the `/auth/check` API endpoint to include the `face_registered` property within the `user` object of its response. Currently, `/admin/users` already exposes `face_registered` for each user via the `User` model's accessor, but `/auth/check` does not append this attribute before serializing the authenticated user. Adding this field ensures consistency across endpoints and allows consumers of the auth check response to know whether the current user has a registered face for the configured face recognition provider. No breaking changes are introduced; the field is additive.

### Feature Summary

Include `face_registered` in the `user` object returned by `GET /auth/check`, consistent with how `/admin/users` already returns it.

### Business Value

Provides the authenticated user's face registration status via the auth check endpoint, enabling frontend or native clients to make decisions based on whether the user has enrolled in face recognition without requiring a separate API call.

### Scope

**Included:**
- Append `face_registered` to the `user` object in the `GET /auth/check` response (regular users only)

**Excluded:**
- Changes to `/auth/check-manager` or any other auth endpoint
- Frontend changes (the `User` type already declares `face_registered: boolean`)
- Changes to the `getFaceRegisteredAttribute()` accessor logic
- New database migrations or schema changes

## Glossary

| Term | Definition |
|------|------------|
| Auth_Check | The `GET /auth/check` endpoint that validates an authenticated regular user's session and returns user context |
| Face_Registered | A boolean attribute on the User model indicating whether the user has a registered face for the current face recognition provider |
| Auth_Controller | `app/Http/Controllers/AuthController.php` — handles authentication flows including the auth check endpoint |
| User_Model | `app/Models/User.php` — Eloquent model with a `getFaceRegisteredAttribute()` accessor that queries the `UserFace` table |
| getAuthData | Private method in `Auth_Controller` that assembles and returns the user context payload |

## Requirements

### Requirement 1: Include face_registered in /auth/check Response

**User Story:** As a client application, I want the `/auth/check` response to include `face_registered` in the `user` object, so that I can determine whether the authenticated user has enrolled in face recognition without an additional API call.

#### Acceptance Criteria

1. WHEN an authenticated regular user calls `GET /auth/check` THEN `Auth_Controller` SHALL include `face_registered` as a boolean field within the `user` object in the response
2. IF the user has a `UserFace` record matching the currently configured face recognition provider THEN `Auth_Controller` SHALL return `face_registered: true` in the `user` object
3. IF the user has no `UserFace` record matching the currently configured face recognition provider THEN `Auth_Controller` SHALL return `face_registered: false` in the `user` object
4. WHILE appending `student_card_expiry_alert`, `residence_card_expiry_alert`, and `passport_expiry_alert` to the user THEN `Auth_Controller` SHALL also append `face_registered` in the same operation
5. WHERE the caller is a manager (uses `GET /auth/check-manager`) THEN `Auth_Controller` SHALL NOT include `face_registered` in the response, consistent with current behavior

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: `User_Model.getFaceRegisteredAttribute()` (already implemented), `UserFace` relationship (already implemented)

## Non-Functional Requirements

### Performance Requirements
- WHEN `GET /auth/check` is called THEN `Auth_Controller` SHALL resolve `face_registered` via a single EXISTS query on the `user_faces` table without degrading overall endpoint response time

### Security Requirements
- WHEN returning `face_registered` THEN `Auth_Controller` SHALL only include it for authenticated users with the regular user role, consistent with existing behavior

### Usability Requirements
- WHEN `face_registered` is returned THEN `Auth_Controller` SHALL use the same field name and boolean type as the `/admin/users` endpoint for consistency

### Reliability Requirements
- WHEN the `UserFace` table has no record for the user THEN `User_Model` SHALL return `false` for `face_registered` without throwing an exception

## Constraints and Assumptions

### Technical Constraints
- The change must reuse the existing `getFaceRegisteredAttribute()` accessor on `User_Model` — no new logic is introduced
- The append must occur inside the `else` branch of `getAuthData()` (regular users only, not managers)

### Assumptions
- The `UserFace` relationship and `getFaceRegisteredAttribute()` accessor are stable and correct
- No frontend changes are required; the frontend `User` type already declares `face_registered: boolean`
- The `/auth/check-manager` endpoint intentionally does not need this field

## Success Criteria

### Definition of Done
- [ ] All acceptance criteria are met
- [ ] Non-functional requirements are satisfied
- [ ] Testing criteria are passed
