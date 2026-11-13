# Lower West Akim NDC website deployment

## Requirements

- PHP 8.2 or newer with PDO MySQL, Fileinfo and standard session support.
- MySQL 8.4.
- HTTPS-enabled PHP hosting.
- Configure PHP uploads for at least 10 MB per file, `post_max_size` of at least 60 MB, and at least eight simultaneous uploaded files.
- The web server document root must be the project's `public/` directory. Keep `app/`, `bin/`, `database/` and this file outside the public document root.

## Database and configuration

1. Create a dedicated MySQL database and user. Grant that user access only to the website database.
2. Create the database (named `lower_west_akim` by default, or set `DB_NAME` to your hosting provider's database name). Select that database, then import `database/schema.sql` using the hosting provider's MySQL tool.
3. Configure these environment variables for the PHP application: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and `DB_PASSWORD`. Do not put database credentials in public files or commit them to source control.
4. Confirm PHP error display is disabled in production and errors are written to a private hosting log. Require HTTPS for the entire site.
5. From a trusted deployment terminal, set a unique temporary environment variable `INITIAL_CHAIRMAN_PASSWORD` (at least 12 characters) and run:

   ```text
   php bin/create-chairman.php chairman "Chairman's Full Name" chairman@example.org
   ```

   The command refuses to create a second Chairman account. Unset the temporary environment variable after the command completes. Store the account credentials securely.
6. Visit `/admin/staff-access.php` over HTTPS and choose the matching role. The Chairman can create Secretary, Deputy Secretary, Treasurer and Elected Executive accounts in **Users & roles**. There is no public self-registration; accounts are issued by the Chairman. Give each user their one-time temporary password through a separate secure channel; the user must replace it at first sign-in. Executive accounts are redirected to their personal reporting dashboard.

## Run locally

Opening `public/index.html` directly or using a static-only preview cannot run PHP authentication. Install PHP 8.2+ with PDO MySQL and MySQL, create/configure the database as above, and from the repository root set the database environment variables in PowerShell before starting the server:

```powershell
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3306'
$env:DB_NAME = 'lower_west_akim'
$env:DB_USER = 'your_database_user'
$env:DB_PASSWORD = 'your_database_password'
php -S 127.0.0.1:8000 -t public
```

Open `http://127.0.0.1:8000/`. In a second terminal, set the same database variables and `INITIAL_CHAIRMAN_PASSWORD` (12+ characters), then run the Chairman creation command above once. Sign in with that username and password at `/admin/staff-access.php`. Never use real credentials in source files or commit them to the repository.

### Firebase Hosting static preview

The Firebase Hosting configuration serves the landing page and public assets from `public/`. It deliberately excludes PHP files, the admin and API directories, and CMS uploads; Firebase Hosting cannot run this site's PHP/MySQL backend. As a result, staff sign-in, dynamic content, and backend features are not available on this static preview. Use the PHP hosting setup above for the full site.

From the repository root, authenticate with Firebase CLI and deploy to the configured project:

```powershell
npx.cmd --yes firebase-tools login
npx.cmd --yes firebase-tools deploy --only hosting --project lower-west-akim-ndc-cons
```

### Upgrade an existing installation

1. Back up the current database and uploaded website files before upgrading.
2. Select the existing website database and run `database/migrations/20261109_executive_reports.sql` once. The migration extends account roles, links existing Secretary accounts to their positions, and creates the reporting, approval-history and notification tables.
3. If the reporting migration was already applied before Treasurer support was added, run `database/migrations/20261109_treasurer_role.sql` once to enable Treasurer accounts. Do not run this step if the database already has the Treasurer role.
4. Deploy the updated PHP files with the repository root outside the web root and `public/` as the document root. Ensure the PHP worker can write to the project-level `storage/report-media/` directory, which must not be web-accessible.
5. Sign in as Chairman and create individual staff accounts from **Users & roles**. Share each one-time password securely; the user must change it at first sign-in.

## Permissions and publishing

- **Chairman:** publish or reject submitted content; create, edit, publish and manage all content; create staff accounts, assign Secretary/Deputy Secretary, Treasurer or Elected Executive roles, and disable accounts.
- **Secretary and Deputy Secretary:** create and edit their own news, events, executive profiles, projects and gallery entries. Their submissions enter the approval queue and are not public until approved by the Chairman.
- **Elected Executive:** sign in to a personal dashboard, submit activity and project reports, add photos and PDF/text supporting documents, review report status and receive approval/rejection notifications. Rejected reports can be revised and resubmitted.
- **Account passwords:** all signed-in users can change their own password from the dashboard. The Chairman can reset an editor password and issue a one-time replacement through **Users & roles**.
- **Chairman:** view reporting statistics and every executive submission, and approve or reject pending reports. A rejection requires a reason. Approval history and important actions are recorded.
- The initial setup creates one Chairman. Chairman accounts cannot be added or edited through the editor account screen.

## Images and backups

Website content images are restricted to JPEG, PNG and WebP files up to 5 MB and receive random server-generated names. Executive report photos are restricted to JPEG, PNG and WebP files up to 5 MB; supporting documents are PDF or plain text up to 10 MB. Report uploads use random server-generated names and are stored in the project-level `storage/report-media/`, outside the `public/` document root. Only approved report photos are served publicly; supporting documents require an authorized login and remain private. For Nginx or another server, ensure project-level storage is not web-accessible and script execution is denied in public upload directories. Back up the MySQL database, `public/uploads/` and `storage/report-media/` together; test restoration periodically and protect backups with access controls and encryption. Keep off-site backup copies and define a retention schedule with the hosting provider.

## Before launch

- Replace the temporary constituency logos, profile placeholders, contact details, social links and sample content with approved material.
- The public landing page initializes Firebase Analytics when the visitor's browser supports it. Serve the site over HTTPS; this integration records analytics only and does not submit or store contact or membership form data.
- The National Democratic Congress logo is served from `public/assets/ndc-logo.svg` and is shared by the public website and staff dashboards. The green/red animated background accents use the logo's `#016436` and `#cf132b` palette; reduced-motion preferences disable the animation.
- Configure and verify the site domain, HTTPS redirect, DNS, production error logging and scheduled backups with the hosting provider.
- The contact and membership forms on the public landing page remain previews and do not send or store information. Connect them to a reviewed, secure service before collecting personal or voter-ID data.
- On the full PHP deployment, the news, event, project, executive and gallery sections read approved content from `/api/content.php`; serve the public home page through `public/index.php` with `public/` as the document root. This backend integration is unavailable on the Firebase static preview.
- Use a unique password for every account. Remove editor access promptly when an officer's role changes.
