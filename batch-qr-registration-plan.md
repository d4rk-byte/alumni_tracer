# Simple Batch QR Registration Plan

## Overview

This feature is a simple QR-based registration flow by batch year.

An admin generates a QR code for a batch year. When a student scans that QR code, the system opens a registration form for that batch. The student fills in:

- College
- Student ID
- First Name
- Middle Name (optional)
- Last Name
- Email
- Password

After submission, the system creates both the login account and the linked alumni profile so the student can use the system immediately.

Important security rule: the system stores passwords only as hashed values. It does not store readable passwords.

## Final Scope

This version is intentionally simple.

It does:

- generate a QR code by batch year
- open a registration page for that batch year
- let the student choose a college from active colleges
- create `User` and `Alumni` records together
- allow immediate login after registration

It does not do:

- QR campaign management
- QR expiration
- token-based QR links
- QR registration analytics
- QR registration history tables
- roster validation
- approval workflow for QR registrants

## Current Implementation Shape

The implemented flow uses the batch year directly in the URL.

### Admin Side

- Route: `/admin/qr-registration`
- Controller: `src/Controller/QrRegistrationController.php`
- Template: `templates/admin/qr_registration/index.html.twig`

The admin page lets the admin enter a batch year, then generates:

- the public registration link
- the QR preview
- a QR download button

The QR code points directly to the public registration route for that batch year.

### Public Side

- Route: `/register/qr/{batchYear}`
- Controller: `src/Controller/QrRegistrationController.php`
- Form: `src/Form/QrRegistrationFormType.php`
- Template: `templates/registration/qr_register.html.twig`

When a student scans the QR code, they are taken to the registration form for that batch year.

## How The Flow Works

1. Admin opens the QR Registration page.
2. Admin chooses a batch year.
3. System generates a public URL and QR code for that batch year.
4. Student scans the QR code.
5. Student lands on the registration page for that batch year.
6. Student selects a college and fills in required details.
7. System validates duplicates and password rules.
8. System creates the `User` account.
9. System creates the linked `Alumni` record.
10. Student is redirected to login with a success message.

## Fields Captured

The QR registration form collects:

- `college`
- `studentId`
- `firstName`
- `middleName` optional
- `lastName`
- `email`
- `plainPassword`
- `dataPrivacyConsent`

## Data Mapping

### User

The system creates a `User` record with:

- `email` = submitted email
- `firstName` = submitted first name
- `lastName` = submitted last name
- `schoolId` = submitted student ID
- `roles` = `ROLE_ALUMNI`
- `accountStatus` = `active`
- `dpaConsent` = `true`
- `dpaConsentDate` = current date and time
- `password` = hashed password

### Alumni

The system creates an `Alumni` record with:

- `studentNumber` = submitted student ID
- `firstName` = submitted first name
- `middleName` = submitted middle name
- `lastName` = submitted last name
- `emailAddress` = submitted email
- `college` = selected college name
- `yearGraduated` = batch year from URL
- `user` = linked `User`

## Batch Definition

Batch means graduation year.

The batch year is passed through the route and stored in `Alumni.yearGraduated`.

Example:

- `/register/qr/2024`

means the registration is for batch year `2024`.

## College Source

College choices come from the existing active college records.

Source:

- `src/Repository/CollegeRepository.php`

Current behavior:

- the form loads active colleges only
- the student chooses one college from that list
- the selected college is stored as text in `Alumni.college`

## Validation Rules

### Batch Year Validation

- batch year must be a 4-digit year from the route
- invalid batch years return not found

### Form Validation

- college is required
- student ID is required
- first name is required
- last name is required
- email is required and must be valid
- password must match the existing password strength rules
- privacy consent is required

### Duplicate Validation

Before saving, the system checks:

- `User.email`
- `Alumni.emailAddress`
- `User.schoolId`
- `Alumni.studentNumber`

If a duplicate is found, the form is blocked with a friendly validation error.

## Login Behavior

QR registrants are created with `accountStatus = active`.

This is required because the current security checker blocks `pending` accounts from logging in.

Source:

- `src/Security/UserChecker.php`

Because your requirement was that after filling the form they can already use the system, QR registration uses immediate access.

## Admin UI Summary

The admin QR page is simple and does not save QR configurations to the database.

It provides:

- batch year input
- generated public URL
- QR code preview
- copy link action
- download QR action

It has been added to the admin sidebar under Alumni Management.

## Public UI Summary

The student registration page shows:

- the batch year
- the registration form
- privacy notice
- password guidance
- a submit button to create the account

If there are no active colleges, the page shows a blocking message instead of allowing submission.

## Files Involved

### Added

- `src/Controller/QrRegistrationController.php`
- `src/Form/QrRegistrationFormType.php`
- `templates/admin/qr_registration/index.html.twig`
- `templates/registration/qr_register.html.twig`

### Updated

- `templates/base.html.twig`

## What Was Removed From The Earlier Plan

The following ideas were removed because they were more complex than your request:

- `BatchRegistrationCampaign`
- `BatchRegistrationRecord`
- QR token tables
- campaign CRUD
- campaign-based reporting
- campaign-level approval modes
- roster validation for the first version

## Acceptance Criteria

- Admin can open the QR Registration page.
- Admin can enter a batch year and generate a QR code.
- The generated QR code opens `/register/qr/{batchYear}`.
- Student can select a college from active colleges.
- Student can submit Student ID, First Name, Middle Name optional, Last Name, Email, and Password.
- System creates both `User` and `Alumni` records.
- Duplicate email is blocked.
- Duplicate student ID is blocked.
- Student can log in immediately after successful registration.

## Current Limitations

This simple version has tradeoffs.

- Anyone with the QR code for a batch can open the form.
- There is no QR expiration or disable rule by batch.
- There is no registration history table for QR-specific reporting.
- There is no validation against an official batch roster.
- The college is stored as text on `Alumni`, not as a relational link.

These are acceptable for a simple first version, but they should be kept in mind.

## Recommended Next Improvements

If you want to improve this simple version later, the best next steps are:

1. Add QR registration reporting.
2. Add optional approval instead of immediate activation.
3. Add roster validation by batch year.
4. Add per-college or per-batch restrictions if needed.
5. Add QR expiration or deactivation if misuse becomes a problem.

## Final Recommendation

Keep this feature simple for now.

The current implementation already matches your core request better than the earlier campaign-based design:

- QR by batch year
- student selects college
- student fills in credentials
- account is created
- student can use the system

Only add the more advanced controls later if real usage shows they are needed.