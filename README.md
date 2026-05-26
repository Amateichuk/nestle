# Drupal 9 Project

This is a Drupal 9 project built with Composer and developed using DDEV as a local development environment.

---

## Requirements

Before starting, make sure you have installed:

- Docker
- DDEV
- Git

---

## Installation

### 1. Clone repository
```
git clone <repository-url>
cd <project-folder>
```

### 2. Start DDEV environment
`ddev start`

### 3. Install dependencies
`ddev composer install`

### 4. Import database (if available)
`ddev drush sql-cli < db.sql`

### 5. Run database updates (if needed)
```
ddev drush updb -y
ddev drush cr
```

## Access project
After setup, open the project in browser:

`ddev launch`

Or check project URLs:

`ddev describe`

Default login (if fresh install)
```
Username: admin
Password: admin
```

Useful commands
```
ddev start        # Start project
ddev stop         # Stop project
ddev restart      # Restart containers
ddev ssh          # Enter container
ddev drush cr     # Clear cache
ddev logs         # View logs
```
Notes
This project uses DDEV for local development.
All configuration is managed via Composer.
