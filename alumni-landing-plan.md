# Alumni Landing Experience Plan

## Objective
Keep the landing-page look and feel for alumni after login, but change the top navigation and page content into an alumni-focused experience.

## Approved Scope
1. Alumni users should land on a landing-style home after login instead of the separate alumni dashboard layout.
2. The top navigation on the landing-style pages should switch from guest actions to alumni feature links.
3. The landing page content should keep the same general UI direction, but be modified for logged-in alumni.

## Implementation Status: ✅ COMPLETE

### 1. Route and controller flow ✅
- `HomeController::index()` now passes `landing_mode` to the template:
  - `'guest'` for unauthenticated users → renders public landing page
  - `'alumni'` for authenticated alumni → renders landing-style alumni home
- Admin users still redirect to `admin_dashboard`.
- Staff users still redirect to `staff_dashboard`.
- Alumni users no longer render `home/alumni_dashboard.html.twig`; they render `home/landing.html.twig` with alumni data.

**Files changed:**
- `src/Controller/HomeController.php` — alumni branch now passes `landing_mode: 'alumni'` and alumni data to landing template

### 2. Landing layout navigation ✅
- `templates/home/landing_layout.html.twig` now detects alumni mode:
  ```twig
  {% set isLoggedInAlumni = app.user and is_granted('ROLE_ALUMNI') and not is_granted('ROLE_STAFF') and not is_granted('ROLE_ADMIN') %}
  {% set landingMode = landing_mode is defined ? landing_mode : (isLoggedInAlumni ? 'alumni' : 'guest') %}
  {% set isAlumniLanding = landingMode == 'alumni' %}
  ```
- Guest navigation preserved for unauthenticated users.
- Alumni navigation mode added with links for:
  - Alumni Home → `app_home`
  - Announcements → `announcement_index`
  - Jobs → `job_board_index`
  - Tracer Survey → `gts_new`
  - My Profile → `app_profile`
  - Logout → `app_logout`

**Files changed:**
- `templates/home/landing_layout.html.twig` — conditional nav based on `isAlumniLanding`

### 3. Landing page content adaptation ✅
- `templates/home/landing.html.twig` now adapts based on `landing_mode`:
  - Hero section: shows "Welcome back, {firstName}" for alumni vs public marketing copy
  - CTA buttons: alumni gets "Take Tracer Survey" / "Open My Profile" vs guest "Join Now" / "Learn how it works"
  - Feature cards: alumni sees actual announcement/job/survey content vs public marketing links
  - Bottom section: alumni sees profile status, milestone spotlight vs public "Contact Support" / "Read FAQ"
- Alumni-specific summary badges added: announcement count, job highlights, survey status

**Files changed:**
- `templates/home/landing.html.twig` — conditional content blocks for `isAlumniLanding`

### 4. Data reuse ✅
- `HomeController` passes to alumni landing template:
  - `recentAnnouncements` — 3 most recent active announcements
  - `recentJobs` — 4 most recent active job postings
  - `milestoneAlumni` — 3 featured alumni with honors/achievements
  - `hasGtsSurvey` — boolean indicating tracer survey completion
  - `alumni` — the authenticated user's alumni record (if linked)
- Admin and staff dashboards remain unchanged.

**Files changed:**
- `src/Controller/HomeController.php` — alumni branch loads and passes these variables

### 5. Validation ✅
- Twig lint passed on all touched templates:
  ```
  php bin/console lint:twig templates/home/landing.html.twig templates/home/landing_layout.html.twig templates/security/login.html.twig
  [OK] All 3 Twig files contain valid syntax.
  ```
- Diagnostics passed on all touched PHP files:
  - `src/Controller/HomeController.php` — no errors
  - `src/Controller/SecurityController.php` — no errors
  - `templates/home/landing_layout.html.twig` — no errors
  - `templates/home/landing.html.twig` — no errors

## Notes
- This plan changes the alumni user experience without replacing the whole public landing-page design.
- The goal is to make the authenticated alumni view feel like a modified landing page, not a completely separate dashboard theme.
- The login page (`templates/security/login.html.twig`) still uses guest navigation intentionally — authenticated users are redirected away from login before the template renders.
