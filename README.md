# IOSSS Student Placement System

IOSSS (Integrated Secondary Students Selection System) is a PHP and MySQL web
application for collecting Form One applications and assigning candidates to
secondary schools. Candidates rank three schools. After administrators upload
marks, the system assigns available places in rounds, considering first choices
before second and third choices.

This repository contains coursework submitted to Dr. Ogalo.

## Features

- Candidate registration with account creation, personal details and three
  ranked school choices.
- Student portal for editing an application while the application window is
  open, tracking marks and viewing published placement results.
- Staff portal with separate system-administrator and school-administrator
  access.
- School and capacity management, application-cycle dates, and marks entry or
  CSV upload.
- Selection runs, provisional and published results, school lists and CSV
  exports.
- Login throttling, session controls, CSRF protection for staff actions,
  password hashing and audit logging.

## Requirements

- PHP 8.1 or newer with the `mysqli` extension and MySQL native driver support
  (`mysqli_stmt::get_result()` is used).
- MySQL or MariaDB.
- A web server that can run PHP, such as Apache with PHP or PHP's built-in
  development server.
- A compatible IOSSS database schema. **This repository currently does not
  include schema/migration SQL**, so database installation and initial
  administrator setup must be completed separately before the application can
  run.

There are no Composer or JavaScript package dependencies in this project.

## Database configuration

The application reads database settings from environment variables, with
defaults for the local class-work setup:

| Variable | Description | Example for local development |
| --- | --- | --- |
| `IOSSS_DB_HOST` | MySQL/MariaDB hostname | `localhost` |
| `IOSSS_DB_USER` | Database username | `root` |
| `IOSSS_DB_PASS` | Database password | Set locally; there is no password default |
| `IOSSS_DB_NAME` | Database/schema name | `iosss_db` |

The host, username and database name defaults are for the local assignment
environment. Set `IOSSS_DB_PASS` in your local shell or PHP environment before
connecting to the database. A password is deliberately not stored in this
repository.

For a local development server, provide the database password when starting
PHP (replace the example value with your local database password):

```sh
IOSSS_DB_PASS='your-local-password' php -S 127.0.0.1:8000
```

Then open <http://127.0.0.1:8000/>. Environment assignments on the command
apply only to that server process.

### Schema and initial administrator

Before starting the site, install a database schema compatible with the PHP
queries. The application expects tables including `students`, `applications`,
`choices`, `schools`, `marks`, `selection`, `users`, `settings`,
`login_attempts`, and `audit_log`, with the columns and keys used by the PHP
code. This repository currently has no SQL schema or migration files; do not
assume that creating an empty database is sufficient.

Create the initial system-administrator account as part of database
provisioning. Store a `password_hash()` value, never a plain-text password.
There is no built-in default staff account. After initial setup, manage school
administrator accounts through the system's staff tools.

## Run locally

1. Install PHP and MySQL/MariaDB, and enable `mysqli`.
2. Provision a database using a schema compatible with the application.
3. Set `IOSSS_DB_PASS` in the environment used to start PHP. Set other
   `IOSSS_DB_*` variables too if your local database settings differ from the
   defaults.
4. From the project root, start the local server:

   ```sh
   php -S 127.0.0.1:8000
   ```

   Set the environment variables in the same shell before starting PHP.
5. Visit <http://127.0.0.1:8000/> and sign in to the staff portal at
   <http://127.0.0.1:8000/staff/login.html>.
6. As the system administrator, configure the selection cycle and schools,
   then open applications. Upload candidate marks, run selection after
   applications close, check the results and publish them.

The PHP built-in server is for local development only. Use a properly
configured production web server with HTTPS for deployment.

## Application pages

| Page | Purpose |
| --- | --- |
| `/` | Public home page and application-cycle information |
| `/iosss_application.html` | Candidate registration and application |
| `/login.html` | Student sign-in |
| `/staff/login.html` | Staff sign-in |
| `/student/dashboard.php` | Student status and published placement |
| `/student/application.php` | Edit a candidate application |
| `/admin/dashboard.php` | System-administrator workflow overview |
| `/admin/cycle.php` | Configure application and result dates |
| `/admin/schools.php` | Manage schools, capacity and school-admin accounts |
| `/admin/marks.php` | Enter or import candidate marks |
| `/admin/selection.php` | Run selection and publish results |
| `/admin/school.php` | View selected candidates for a school |

## Marks CSV format

The first row must contain these column names (case-insensitive, any order):

```csv
index_no,english,kiswahili,mathematics,science,sst
```

Each mark must be a whole number from 0 to 100. The importer also accepts
`index`, `indexno`, or `index number` for `index_no`, and `social studies` for
`sst`. CSV uploads are limited to 5 MB by the application; PHP's
`upload_max_filesize` and `post_max_size` must also allow the file size.

## Selection behavior

Selection runs in three rounds. In each round, unplaced candidates are
considered for that choice, and schools allocate remaining capacity to the
highest aggregate marks first. Equal aggregates are ordered by index number for
repeatable results. A candidate can be placed in at most one school.

The administrator dashboard indicates when marks or applications have changed
since the last selection run. Rerun selection before publishing changed results.
Candidates see results only after the system administrator publishes them.

## Security and deployment notes

- This is a class work assignment configured for local demonstration. The
  included database defaults are for that purpose; use appropriate credentials
  and deployment safeguards before hosting it for real users.
- The local `.vscode` directory may contain machine-specific editor
  settings; it is excluded from Git by the repository `.gitignore`.

## Repository structure

```text
admin/              Staff administration pages
assets/             Stylesheets, JavaScript, fonts and images
php/                Shared PHP logic, database connection and request handlers
staff/              Staff sign-in page
student/            Candidate portal pages
index.html          Public home page
iosss_application.html
                    Candidate registration form
login.html          Student sign-in page
```

## License and data handling

No license file is currently included
