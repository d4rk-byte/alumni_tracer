# Google Auth, OTP Verification, and Onboarding Plan

## Overview

This plan covers three related registration and sign-in improvements:

1. Keep Google sign-in as the social login path.
2. Add email OTP verification for manual registration before the real account is created.
3. Add a required onboarding flow for Google sign-in when important alumni details are still missing.

The goal is to make registration safer, reduce incomplete alumni accounts, and ensure both manual and Google-based sign-up end in a complete alumni profile that can be reviewed through the existing approval flow.

## Recommended Stack

Use the existing Symfony stack already present in this repository.

- Google sign-in: keep the custom OAuth flow in `src/Controller/GoogleAuthController.php`
- Email delivery: use Brevo SMTP through Symfony Mailer
- OTP throttling: use Symfony RateLimiter
- Temporary pre-verification storage: use Doctrine with a dedicated draft entity
- Onboarding UI: use Symfony forms rendered in Twig, opened through a modal or Turbo-backed partial
- College and department options: use existing `College` and `Department` entities as the source of choices

Do not add Firebase or a second auth system. The current project already has a working Google OAuth controller and Symfony Security integration.

## Current Implementation Shape

### Manual Registration

Current route and flow:

- Route: `/register`
- Controller: `src/Controller/RegistrationController.php`
- Form: `src/Form/RegistrationFormType.php`
- Service: `src/Service/AlumniRegistrationService.php`

Current behavior:

- validates submitted data
- creates `User` and linked `Alumni` immediately
- sets `accountStatus = pending`
- sends notification email to staff/admin
- does not verify the submitted email with OTP

### Google Sign-In

Current route and flow:

- Route start: `/connect/google`
- Route callback: `/connect/google/check`
- Controller: `src/Controller/GoogleAuthController.php`

Current behavior:

- authenticates with Google using email/profile scopes
- creates a `User` immediately if the email is not found
- sets role to alumni
- sets `accountStatus = active`
- logs the user in immediately
- does not collect school ID, batch, college, department, or middle name

This is the main behavior that needs to change.

## Final Target Behavior

### Manual Registration

1. User fills out the registration form.
2. System validates duplicates and required fields.
3. System stores a temporary registration draft instead of creating the real account immediately.
4. System sends a 6-digit OTP to the submitted email.
5. User enters the OTP.
6. If OTP is valid, system creates the real `User` and linked `Alumni`.
7. The new user remains `pending` for admin approval.

### Google Registration / Sign-In

1. User signs in with Google.
2. System verifies Google email and identity.
3. If the Google user already has a complete linked alumni profile, login proceeds normally.
4. If the user is new or missing required alumni data, show a required onboarding form.
5. User fills or edits:
   - school ID
   - batch year
   - college
   - department
   - first name
   - middle name
   - last name
6. System saves the linked `User` and `Alumni` data.
7. Account remains `pending` unless you explicitly want Google-based accounts to be auto-approved.

## Architectural Decisions

### 1. Keep Custom Google OAuth

Use the existing `GoogleAuthController` instead of adding a new OAuth bundle.

Why:

- the controller already exchanges tokens and fetches the Google profile
- the app already logs users in through Symfony Security
- changing only the post-auth behavior is lower risk than replacing the whole integration

### 2. Add a Registration Draft Entity

Use a dedicated draft table for manual OTP verification.

Why:

- session-only OTP storage is fragile
- drafts survive refresh and accidental navigation
- drafts allow OTP retries, expiration, and resend limits
- drafts avoid creating half-finished `User` rows before email ownership is proven

### 3. Use Brevo SMTP for OTP

Use synchronous mail sending for OTP instead of queueing it.

Why:

- OTP is part of the user’s immediate flow
- queued delivery would make verification timing unpredictable
- survey and batch email can remain async through Messenger

### 4. Use Existing Academic Data as Choice Source

Load college and department choices from:

- `src/Entity/College.php`
- `src/Entity/Department.php`

Persist selected values into the current `Alumni` string fields first.

Why:

- `Alumni` currently stores `college` and `course` as strings
- this avoids a larger schema refactor right away
- it still ensures the user chooses only valid managed academic options

Suggested mapping for first rollout:

- selected college name -> `Alumni.college`
- selected department name -> `Alumni.course`

## Data Model Changes

### Existing Entity Updates

#### `src/Entity/User.php`

Add fields for identity and onboarding state:

- `googleSubject` nullable string
- `emailVerifiedAt` nullable datetime
- `profileCompletedAt` nullable datetime
- `requiresOnboarding` boolean default false

Optional but recommended later:

- `middleName` nullable string if you want names fully mirrored at the `User` level

#### `src/Entity/Alumni.php`

No structural change required for the first rollout.

Reuse these existing fields:

- `studentNumber`
- `middleName`
- `yearGraduated`
- `college`
- `course`

### New Entity

#### `src/Entity/RegistrationDraft.php`

Create a new entity for manual registration before OTP confirmation.

Recommended fields:

- `id`
- `email`
- `studentId`
- `firstName`
- `middleName` nullable
- `lastName`
- `plainData` JSON or normalized explicit fields
- `yearGraduated`
- `college` nullable
- `department` nullable
- `passwordHashTemp` or encrypted/plain staging strategy
- `otpCodeHash`
- `otpExpiresAt`
- `verifyAttempts`
- `resendCount`
- `createdAt`
- `verifiedAt` nullable
- `flowType` (`manual` or future-safe value)

Important note:

- do not store the plain OTP
- store only a hashed OTP value

## New Services To Add

### `src/Service/RegistrationOtpService.php`

Responsibilities:

- generate OTP
- hash OTP
- verify OTP
- enforce expiry checks
- enforce resend cooldown
- enforce verify attempt limits

### `src/Service/GoogleOnboardingService.php`

Responsibilities:

- inspect whether the Google-authenticated user is complete enough to proceed
- prefill onboarding values from Google profile and existing user/alumni data
- apply onboarding changes to `User` and `Alumni`

### `src/Service/RegistrationDraftService.php`

Responsibilities:

- create or replace drafts
- save staged manual registration payload
- mark draft verified
- finalize draft into real `User` + `Alumni` through `AlumniRegistrationService`

### `src/Service/NotificationService.php`

Extend this service or split a dedicated mailer service for:

- OTP email sending
- Google onboarding notices if needed

Recommended first step:

- keep `NotificationService`
- add a dedicated method like `sendRegistrationOtp()`

## New Forms To Add

### `src/Form/RegistrationOtpVerificationType.php`

Fields:

- `otpCode`

### `src/Form/GoogleOnboardingType.php`

Fields:

- `schoolId`
- `yearGraduated`
- `college`
- `department`
- `firstName`
- `middleName`
- `lastName`

Choice loading:

- colleges from active `College` rows
- departments filtered by selected college if you want dependent dropdown behavior

## New Controllers Or Routes To Add

### Manual OTP Flow

You can keep this inside `RegistrationController`, but splitting it will be cleaner.

Recommended new controller:

#### `src/Controller/RegistrationOtpController.php`

Suggested routes:

- `GET|POST /register/verify-email`
- `POST /register/resend-otp`

Responsibilities:

- render OTP screen
- verify OTP
- resend OTP with rate limit
- finalize draft into real account after verification

### Google Onboarding

Recommended new controller:

#### `src/Controller/GoogleOnboardingController.php`

Suggested routes:

- `GET /connect/google/onboarding`
- `POST /connect/google/onboarding`

Responsibilities:

- render and process missing-details form
- save school ID, batch, college, department, and editable names
- mark profile complete
- redirect to the appropriate dashboard or alumni profile

## Existing Files To Update

### `src/Controller/GoogleAuthController.php`

Change from this current behavior:

- auto-create active alumni user
- auto-login immediately with incomplete profile

To this new behavior:

- find by `googleSubject` first, then by email if needed
- if new Google user, create minimal user shell with verified email and onboarding required
- if required fields are missing, redirect to Google onboarding route
- only complete normal login flow after onboarding is done

### `src/Controller/RegistrationController.php`

Change from this current behavior:

- create real user immediately on submit

To this new behavior:

- validate form
- create registration draft
- send OTP
- redirect to OTP verification page

### `src/Service/AlumniRegistrationService.php`

Keep this as the single place that creates `User` + `Alumni`.

Enhance it so it can support:

- optional `emailVerifiedAt`
- optional `profileCompletedAt`
- optional Google identity metadata
- optional mapped college and department values

Do not duplicate account creation logic in controllers.

### `src/Form/RegistrationFormType.php`

Keep the current manual registration form, but consider adding:

- `middleName`
- optional managed `college`
- optional managed `department`

If you want manual registration to collect the same fields as Google onboarding, this form should be expanded to match.

### `templates/registration/register.html.twig`

Update to:

- show the new field set if expanded
- redirect the user cleanly into the OTP step after submit

### `templates/security/login.html.twig`

Optional update:

- add clearer Google sign-in messaging for incomplete onboarding users

## New Templates To Add

### `templates/registration/verify_email_otp.html.twig`

Purpose:

- OTP code entry screen
- resend action
- expiration guidance

### `templates/security/google_onboarding_modal.html.twig`

Purpose:

- modal content for Google missing-details form

### `templates/security/google_onboarding_page.html.twig`

Purpose:

- fallback full-page version of the onboarding form
- useful if modal/Turbo is unavailable

## New Migrations To Add First

### Migration 1: User Auth and Onboarding Fields

Add fields to `user`:

- `google_subject`
- `email_verified_at`
- `profile_completed_at`
- `requires_onboarding`

### Migration 2: Registration Draft Table

Create `registration_draft` with OTP and staged registration payload fields.

This should be the first new entity table added for the OTP flow.

## File-By-File Implementation Checklist

## Phase 1: Data Foundation

### Add first

- `src/Entity/RegistrationDraft.php`
- `src/Repository/RegistrationDraftRepository.php`
- `migrations/VersionYYYYMMDDHHMMSS.php` for `registration_draft`
- update `src/Entity/User.php`
- second migration for new `user` auth/onboarding columns

### Then validate

- schema migration runs cleanly
- Doctrine mapping is valid

## Phase 2: Manual Registration OTP

### Add

- `src/Service/RegistrationDraftService.php`
- `src/Service/RegistrationOtpService.php`
- `src/Form/RegistrationOtpVerificationType.php`
- `src/Controller/RegistrationOtpController.php`
- `templates/registration/verify_email_otp.html.twig`

### Update

- `src/Controller/RegistrationController.php`
- `src/Service/NotificationService.php`
- optionally `src/Form/RegistrationFormType.php`

### Outcome

- manual registration creates draft only
- OTP email is sent
- verified OTP creates final account

## Phase 3: Google Onboarding

### Add

- `src/Form/GoogleOnboardingType.php`
- `src/Service/GoogleOnboardingService.php`
- `src/Controller/GoogleOnboardingController.php`
- `templates/security/google_onboarding_modal.html.twig`
- `templates/security/google_onboarding_page.html.twig`

### Update

- `src/Controller/GoogleAuthController.php`

### Outcome

- Google user is not treated as fully complete until onboarding is done
- required academic fields are collected before normal use

## Phase 4: Academic Choice Integration

### Add or update

- repository queries for active colleges and departments
- dependent department filtering if needed

Likely touched files:

- `src/Controller/GoogleOnboardingController.php`
- `src/Form/GoogleOnboardingType.php`
- possibly `src/Form/RegistrationFormType.php`

### Outcome

- college and department come from managed academic data instead of free text

## Phase 5: Security and Rate Limiting

### Update

- `config/packages/rate_limiter.yaml` if not already present
- `src/Service/RegistrationOtpService.php`
- `config/services.yaml` if explicit service wiring is needed

### Outcome

- resend cooldown
- verification attempt throttling
- safer OTP flow

## Suggested Routing Summary

### Existing

- `/register`
- `/connect/google`
- `/connect/google/check`

### New

- `/register/verify-email`
- `/register/resend-otp`
- `/connect/google/onboarding`

## Validation Rules

### Manual Registration Draft

- email required and unique against real users
- student ID required and unique against real users
- batch year required
- OTP expires after fixed time window
- resend limited
- verify attempts limited

### Google Onboarding

- school ID required
- batch year required
- college required
- department required
- first and last name required
- middle name optional
- user can edit Google-provided names before final save

## Testing Plan

### Add tests for manual OTP flow

- submit registration creates draft, not final user
- OTP verification creates final account
- expired OTP fails
- wrong OTP increments attempts
- resend is rate-limited

### Add tests for Google onboarding flow

- new Google user is redirected to onboarding
- completed onboarding saves required fields
- complete Google user can log in without onboarding
- wrong or missing academic choice is rejected

## Open Decisions

Choose explicitly before implementation:

1. Should Google users be `pending` after onboarding, or `active` immediately?
2. Should manual registration also collect college and department now, or keep them only for Google onboarding first?
3. Should `middleName` also be added to `User`, or remain only on `Alumni`?
4. Should department be stored in `Alumni.course`, or should a dedicated field be added later?

## Recommended Answer To Those Decisions

For the safest rollout:

1. Google users should still be `pending` after onboarding.
2. Manual registration should also collect the same academic fields so both flows stay aligned.
3. Keep `middleName` on `Alumni` first unless the UI truly needs it on `User` everywhere.
4. Store department in `Alumni.course` for the first rollout to avoid a larger schema refactor.

## Exact Files To Add First

If implementation starts now, add these first in this order:

1. `src/Entity/RegistrationDraft.php`
2. `src/Repository/RegistrationDraftRepository.php`
3. migration for `registration_draft`
4. update `src/Entity/User.php`
5. migration for new user auth/onboarding columns
6. `src/Service/RegistrationDraftService.php`
7. `src/Service/RegistrationOtpService.php`
8. `src/Form/RegistrationOtpVerificationType.php`
9. `src/Controller/RegistrationOtpController.php`
10. `templates/registration/verify_email_otp.html.twig`
11. `src/Form/GoogleOnboardingType.php`
12. `src/Service/GoogleOnboardingService.php`
13. `src/Controller/GoogleOnboardingController.php`
14. `templates/security/google_onboarding_modal.html.twig`
15. `templates/security/google_onboarding_page.html.twig`
16. update `src/Controller/RegistrationController.php`
17. update `src/Controller/GoogleAuthController.php`
18. update `src/Service/NotificationService.php`

## Outcome

After this plan is implemented:

- manual registration proves email ownership with OTP before account creation
- Google sign-in works without losing required alumni data
- incomplete Google sign-ins are guided through a structured onboarding step
- academic fields come from existing managed records
- the system keeps one consistent account creation path through existing services