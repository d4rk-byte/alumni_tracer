# Alumni Dashboard Feature Content Plan

## Goal
Redo the alumni dashboard and alumni feature pages so their contents are based on the real admin-managed modules and real alumni data, instead of the current generic landing-page placeholders.

## Current Gap
The current alumni feature pages are driven by a single generic config builder in `src/Controller/HomeController.php`.
That makes the pages look consistent, but the contents are too abstract:
- announcements only show summary copy instead of a real announcement feed
- jobs only show a lightweight preview instead of the actual job board structure
- tracer only shows status text instead of the alumni's real survey state and response summary
- profile only shows completion messaging instead of the actual account and alumni record details

The older `templates/home/alumni_dashboard.html.twig` already proves the repo has richer alumni-facing content for:
- announcement cards
- latest jobs
- tracer call-to-action
- alumni milestone spotlight

## Source Of Truth
Use these existing modules as the content source for the alumni experience:
- Announcements: `src/Controller/AnnouncementController.php`, `templates/announcement/index.html.twig`, `templates/announcement/show.html.twig`
- Jobs: `src/Controller/JobBoardController.php`, `templates/job_board/index.html.twig`, `templates/job_board/show.html.twig`
- Tracer Survey: `src/Controller/GtsController.php`, `templates/gts/new.html.twig`, `templates/gts/show.html.twig`
- Profile: `src/Controller/ProfileController.php`, `templates/profile/index.html.twig`
- Alumni record and milestone data: `src/Entity/Alumni.php`, `templates/home/alumni_dashboard.html.twig`
- Admin dashboard reference for prioritization and summaries: `templates/admin/dashboard.html.twig`

## Design Rule
Admin pages remain the system of record and management surface.
The alumni dashboard should show alumni-safe, self-service, read-only or self-editable views of that same data.

Do not carry admin-only actions into the alumni side:
- no announcement create, edit, delete
- no job manage, create, edit, delete for alumni
- no survey builder, campaign management, analytics hub, or verification tools
- no visibility into other alumni records beyond approved public spotlight content

## Proposed Alumni Dashboard Structure
### 1. Alumni Home
Use the home page as a self-service summary dashboard, not a marketing page.

Recommended sections:
- Account snapshot: account status, profile completion, tracer status, linked alumni record status
- Latest announcements: 3 to 6 real active announcements
- Latest jobs: 4 to 8 active, non-expired job postings
- Tracer status block: pending, completed, invited, expired, or ineligible state
- Milestone spotlight: featured alumni achievements from the existing alumni query
- Quick actions: update profile, open jobs, open announcements, start tracer, upload documents if approved for scope

Data to show:
- `recentAnnouncements`
- `recentJobs`
- `profileSnapshot`
- `alumni`
- `milestoneAlumni`
- latest survey state, not just `hasGtsSurvey`

## Feature Page Content Plan
### 2. Announcements Page
This page should mirror the real announcement module content, but in an alumni-focused presentation.

Correct content:
- title
- category
- posted date
- posted by
- short description preview
- active status only
- empty state when no active announcements exist

Recommended layout:
- top summary strip: total active announcements, latest category, latest posted date
- announcement card grid copied from the stronger parts of `templates/home/alumni_dashboard.html.twig`
- each card should have a clear read action
- optional alumni action: `feedback_submit` for responding to announcements or office notices

Implementation note:
If the landing-only rule must stay, render full announcement details inside the alumni feature page instead of linking directly to `announcement_index`.
If read-only module navigation is allowed, `announcement_show` is the correct detail target, not a generic placeholder.

### 3. Jobs Page
This page should match the real job board structure more closely.

Correct content:
- title
- company name
- employment type
- location
- salary range
- related course
- short description
- date posted
- deadline
- image when available

Recommended layout:
- filter bar based on the existing job board: search, employment type, related course
- job card grid based on `templates/job_board/index.html.twig`
- show only active and non-expired jobs
- keep alumni-friendly CTA wording like `View Details` or `Open Job`

Implementation note:
The current `JobBoardController::index()` already supports search and type filtering. The alumni landing variant should reuse that logic or extract it into a shared query method so the same results drive both pages.

### 4. Tracer Survey Page
This page should stop being a generic status card and become the alumni's tracer control center.

Correct content:
- verification or eligibility status
- tracer completion status
- last submission date
- current invitation status if a campaign invitation exists
- high-level summary of the alumni's latest response
- explicit next action: start survey, continue survey, or review submitted response

Recommended states:
- pending and eligible: show `Start Survey`
- completed: show submission date and a short summary of employment outcome fields
- invitation active: show invitation context and deadline
- inactive account: explain why tracer is blocked and point to profile/account resolution

Data to expose from current models:
- `alumni.tracerStatus`
- `alumni.lastTracerSubmissionAt`
- latest `GtsSurvey` row for the logged-in user
- possibly selected response summary fields such as occupation, company, place of work, employment status

Important boundary:
This page should show the alumni's own survey state only.
It must not expose admin response tables, analytics, or other respondents.

### 5. Profile Page
This page should combine account data from `User` with alumni record data from `Alumni`.

Correct content:
- profile photo or initials
- full name and email
- role and account status
- date registered and last login
- DPA consent state
- alumni student number
- course, college, year graduated
- contact number and address
- employment snapshot: company, job title, employment status, industry, salary band if available
- tracer status and last tracer submission date
- milestone or achievement fields if present

Recommended actions:
- edit account profile
- edit alumni record
- upload or manage documents if that flow is approved for the landing experience
- erase data only if the current profile flow remains allowed for alumni self-service

Implementation note:
The current `profile/index.html.twig` is account-centric. The alumni landing version should merge that with alumni-record fields instead of limiting the page to completion percentage and generic status badges.

## Recommended Technical Refactor
### 6. Replace Generic Feature Config With Page-Specific Builders
Current issue:
`buildAlumniFeaturePageConfig()` is too generic for real module-driven content.

Recommended change:
- keep `buildAlumniLandingContext()` for shared summary data
- replace the current single generic feature config with page-specific builders or view-model arrays:
  - `buildAlumniAnnouncementsPageContext()`
  - `buildAlumniJobsPageContext()`
  - `buildAlumniTracerPageContext()`
  - `buildAlumniProfilePageContext()`
- render dedicated templates or dedicated partials instead of forcing all pages through the same three-card format

Recommended template split:
- `templates/home/alumni_feature_announcements.html.twig`
- `templates/home/alumni_feature_jobs.html.twig`
- `templates/home/alumni_feature_tracer.html.twig`
- `templates/home/alumni_feature_profile.html.twig`

This is the cleanest way to let each feature page match its real module content.

## Data Additions Needed In HomeController
### 7. Extend Alumni Context
Add or derive these values for the logged-in alumni:
- latest submitted `GtsSurvey`
- tracer response summary fields for display
- possibly active survey invitation state if invitations are part of the alumni flow
- richer alumni record fields from `Alumni`
- document counts or recent documents if document management is included

Keep the route shell the same:
- `app_home`
- `app_alumni_feature_announcements`
- `app_alumni_feature_jobs`
- `app_alumni_feature_tracer_survey`
- `app_alumni_feature_profile`

## Implementation Phases
### Phase 1
Refactor `HomeController` to stop using placeholder text for alumni feature pages.

### Phase 2
Build real announcements and jobs pages first, because those already have clear content models and templates to reuse.

### Phase 3
Build a proper tracer status page using the alumni's own survey state and latest response summary.

### Phase 4
Build a combined profile page that merges `User` account information and `Alumni` record information.

### Phase 5
Refresh the alumni home so it becomes a real summary launcher for the four feature pages.

## Decision To Confirm Before Coding
There is one product rule that needs to stay consistent:
- Option A: keep the landing-only rule, so alumni pages must render real content without linking into the underlying module routes
- Option B: allow read-only detail routes like `announcement_show` and `job_board_show` from the alumni experience

The current codebase was previously pushed toward Option A.
If that rule still stands, the implementation should enrich the landing feature pages directly instead of sending the user into module pages.

## Validation Plan
After implementation:
- add or update integration tests around alumni home and feature pages
- assert real announcement and job content is rendered for alumni
- assert admin-only actions do not appear for alumni
- assert tracer page shows the correct state for pending vs completed alumni
- assert profile page includes both account and alumni-record fields
- lint touched Twig templates
- run the focused landing experience PHPUnit suite

## Suggested First Build Order
1. Announcements page
2. Jobs page
3. Tracer page
4. Profile page
5. Alumni home summary refresh
