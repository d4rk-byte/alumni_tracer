# Admin Panel Refactor Plan

Date: 2026-04-16
Project: NORSU Alumni Tracker

## 1. Goal
Reorganize the admin experience around core operations (alumni management, tracer compliance, reporting, governance), without breaking existing routes or non-admin dashboards.

## 2. Current State (Confirmed in Codebase)
- Sidebar is centralized in templates/base.html.twig.
- Admin dashboard is rendered from HomeController index action (route app_home).
- Existing routes already cover many target modules:
  - Alumni listing and profile management: alumni_index, alumni_show, alumni_edit
  - Verification workflow: admin_verification
  - Reports and import/export: report_index, report_export, report_import
  - GTS responses: gts_index
  - Survey analytics/preview: admin_survey_analytics, admin_survey_preview
  - Job board management: job_board_manage
  - Email communications: admin_email_index
  - Academic reference data: admin_colleges, admin_departments
  - User management: admin_users
  - Audit trail: admin_audit_log

## 3. Target Information Architecture
Top-level sidebar groups:
1. Dashboard
2. Alumni Management
3. Graduate Tracer Study
4. Communications
5. Reports and Analytics
6. Academic Management
7. Administration

### 3.1 Target Sidebar Structure
1. Dashboard
2. Alumni Management
   - Alumni Directory
   - Profile Verification
   - Import and Export Data
3. Graduate Tracer Study
   - Survey Builder
   - GTS Responses
   - GTS Analytics
   - Survey Distribution
4. Communications
   - Announcements
   - Job Board
   - Email Campaigns
   - Notification Settings
5. Reports and Analytics
   - Employment Reports
   - GTS Completion Reports
   - Engagement Reports
   - Export Center
   - CHED Compliance Reports
6. Academic Management
   - Colleges
   - Departments
   - Courses and Programs
   - Batch Years
7. Administration
   - Manage Users
   - Roles and Permissions
   - Audit Log
   - System Settings
   - Email Configuration

## 4. Route Compatibility Strategy (Do Not Break Existing URLs)
Keep existing route names for all currently working pages. Change labels and grouping in the sidebar first.

### 4.1 Mapping Existing Routes to New Navigation
- Dashboard
  - app_home (admin users continue landing here initially)
- Alumni Management
  - Alumni Directory -> alumni_index
  - Profile Verification -> admin_verification
  - Import and Export Data -> report_import, report_export
- Graduate Tracer Study
  - Survey Builder -> staff_gts_questions_index (staff editor), admin_survey_preview (admin preview)
  - GTS Responses -> gts_index
  - GTS Analytics -> admin_survey_analytics
  - Survey Distribution -> admin_email_index (temporary until dedicated distributor exists)
- Communications
  - Announcements -> announcement_index
  - Job Board -> job_board_manage
  - Email Campaigns -> admin_email_index
- Reports and Analytics
  - report_index (anchor page)
  - report_export (download entry)
- Academic Management
  - Colleges -> admin_colleges
  - Departments -> admin_departments
- Administration
  - Manage Users -> admin_users, alumni_index sub-view for alumni accounts
  - Audit Log -> admin_audit_log
  - Email Configuration -> admin_email_index (temporary until settings page exists)

### 4.2 New Route Aliases to Add Later (Optional but Recommended)
Create module-oriented aliases while preserving old names:
- admin_dashboard
- admin_alumni_directory
- admin_gts_analytics
- admin_reports_index
- admin_settings_index

## 5. Module Scope and Delivery

### 5.1 Dashboard (Refocus)
Deliver:
- KPI cards: total alumni, employment rate, pending verification, GTS response count.
- Breakdowns: alumni by college, department, batch year.
- Recent activity: latest GTS responses and announcements.
- Quick actions: New Announcement, Add Survey Question, Verify Registrations, Open Reports.

Acceptance criteria:
- Admin sees KPI-first layout above fold.
- Staff and alumni dashboard behavior remains unchanged.
- No route change required for existing dashboard access.

### 5.2 Alumni Management (Core Module)
Deliver:
- Unified Alumni Directory entry point.
- Strong filters: batch year, college, department, location, employment status.
- Profile detail and edit continuity.
- Profile verification access linked from same module.
- Import/export moved from Reports UI into this module as primary location.

Acceptance criteria:
- All core alumni operations discoverable in one sidebar group.
- Existing forms and pagination still work.

### 5.3 Graduate Tracer Study (Compliance Module)
Deliver:
- Group survey builder/editor, responses, analytics under one section.
- Add analytics view enhancements for employment, salary, and degree relevance summaries.
- Add batch-targeted survey distribution workflow (phase implementation with email queue/records).

Acceptance criteria:
- Admin can move from question management to responses and analytics without leaving module group.
- Core compliance metrics accessible in at most two clicks from sidebar.

### 5.4 Communications (Engagement Module)
Deliver:
- Group announcements, job board management, and email campaigns.
- Add placeholder Notification Settings page if full implementation is deferred.

Acceptance criteria:
- Communications tasks no longer scattered across administration and staff sections.

### 5.5 Reports and Analytics (Insights Module)
Deliver:
- Consolidated reporting landing page with clear report categories.
- Exports surfaced in one place (CSV first; PDF as extension).
- CHED-oriented summary block using tracer-compatible metrics.

Acceptance criteria:
- Admin can generate employment and completion reports from one module.

### 5.6 Academic Management (Reference Data)
Deliver:
- Keep current Colleges and Departments pages.
- Add Courses/Programs CRUD.
- Add Batch Year reference management.
- Connect alumni filters and forms to normalized references.

Acceptance criteria:
- Alumni can be filtered by normalized program and batch year dimensions.
- New reference data is manageable without direct database edits.

### 5.7 Administration (System Control)
Deliver:
- Keep Manage Users and Audit Log.
- Add role-permission matrix UI (starting with read/update grants by module).
- Add System Settings page (institution name, logo, contact details).
- Add Email Configuration page (SMTP host, port, sender identity, test email action).

Acceptance criteria:
- Access control and settings are separated from daily operational modules.

## 6. Phased Implementation Plan

### Phase 1: Navigation Cleanup
Tasks:
- Refactor sidebar grouping in templates/base.html.twig.
- Move Import Data menu item under Alumni Management.
- Keep route names untouched.
- Preserve active-state logic for each moved link.

Exit criteria:
- No broken links.
- Active-state highlighting works for all grouped routes.
- Mobile sidebar toggle still behaves correctly.

### Phase 2: Dashboard Refocus
Tasks:
- Prioritize KPI cards and compliance widgets in admin dashboard template.
- Add or reorder quick actions for admin-critical workflows.
- Keep existing data queries initially.

Exit criteria:
- Admin dashboard shows alumni and employment metrics first.
- No regression for staff and alumni dashboard routing.

### Phase 3: Controller Separation
Tasks:
- Create dedicated AdminDashboardController.
- Move admin-only query logic out of HomeController.
- Keep HomeController role routing behavior intact.

Exit criteria:
- Admin dashboard logic no longer mixed with general home controller.
- app_home behavior remains compatible.

### Phase 4: Reports Consolidation
Tasks:
- Build a unified reports landing page and section cards.
- Link report exports and tracer analytics from one entry.

Exit criteria:
- Reporting navigation is centralized and consistent.

### Phase 5: Missing Data Modules
Tasks:
- Create Program/Course entity and CRUD screens.
- Create BatchYear entity and CRUD screens.
- Add foreign key relationships or validated references in alumni records.

Exit criteria:
- Alumni filters and reporting use normalized program/batch dimensions.

### Phase 6: Governance and Settings
Tasks:
- Implement role-permission matrix UI and persistence strategy.
- Add system settings and email configuration pages.
- Add notification settings page (basic controls first).

Exit criteria:
- Governance and settings are accessible in Administration module with proper role checks.

## 7. Immediate Sprint Checklist (Execution Order)
1. Rebuild sidebar grouping by module.
2. Normalize dashboard quick links to target module entry pages.
3. Keep route compatibility (no route renames in first sprint).
4. Create dedicated admin dashboard endpoint and controller.
5. Move admin-only dashboard query logic out of HomeController.
6. Validate active-state behavior for all moved links.
7. Validate mobile sidebar behavior and responsiveness.
8. Run regression pass for admin/staff/alumni role navigation.

## 8. Missing Features and Why They Matter
| Missing Feature | Why It Matters | Delivery Priority |
|---|---|---|
| Alumni Directory as named module | Core purpose of the tracker; discoverability and daily admin workflow | P0 |
| Employment Status Tracking clarity | Required for tracer and CHED reporting quality | P0 |
| GTS Analytics depth | Converts survey responses into actionable insight | P0 |
| Roles and Permissions matrix | Security and least-privilege access control | P1 |
| Courses and Programs module | Proper alumni categorization and better filtering | P1 |
| Batch Years reference module | Consistent cohort reporting and distribution targeting | P1 |
| Dashboard KPI-first layout | Admin context and action speed at login | P0 |

## 9. Technical Guardrails
- Do not change or remove existing route names in early phases.
- Keep existing role boundaries:
  - Admin full system controls.
  - Staff operational workflows.
  - Alumni self-service only.
- Preserve query performance on dashboard and reports (cache heavy counters where possible).
- Add migration scripts for new reference entities with safe defaults.

## 10. Definition of Done
- Sidebar reflects all 7 target modules.
- Admin dashboard is KPI-first and action-oriented.
- Admin dashboard logic is isolated from public and alumni home flow.
- Reports and tracer analytics are discoverable from one analytics module.
- Programs/Courses and Batch Years are manageable reference data.
- Governance pages exist for roles, settings, and email config.
- Regression checks pass for route navigation, active states, and mobile sidebar.

## 11. Recommended Build Sequence (Practical)
1. Navigation cleanup (fastest UX impact, lowest risk).
2. Dashboard refocus (high visibility gain).
3. Controller separation (maintainability).
4. Reports consolidation.
5. Programs and Batch Year modules.
6. Governance and settings.

This plan is intentionally route-safe first, then architecture cleanup, then missing-module expansion.
