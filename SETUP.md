# My Woodshed Music — Setup Guide

## What you have

```
mywoodshedmusic/
├── index.html          ← The app (React frontend)
├── SETUP.md            ← This file
└── api/
    ├── setup.sql       ← Database schema
    ├── config.php      ← Database credentials (edit this)
    ├── helpers.php      ← Shared functions (auth, DB, JWT)
    ├── auth.php         ← Login / register endpoints
    ├── students.php     ← Student CRUD
    ├── content.php      ← Content library CRUD
    ├── assignments.php  ← Assignment + steps CRUD
    └── progress.php     ← Student progress tracking
```

## Setup Steps

### 1. Create the database

Log into your MySQL (via phpMyAdmin, command line, or your hosting panel) and run the contents of `api/setup.sql`. This creates the `woodshed` database and all tables.

If you prefer a different database name, change it in both `setup.sql` and `config.php`.

### 2. Edit config.php

Open `api/config.php` and update:

- `DB_HOST` — usually `localhost`
- `DB_NAME` — `woodshed` (or whatever you named it)
- `DB_USER` — your MySQL username
- `DB_PASS` — your MySQL password
- `JWT_SECRET` — change this to any long random string (at least 32 characters)
- `ALLOWED_ORIGIN` — set to your domain in production (e.g. `https://mywoodshedmusic.com`)

### 3. Upload to your webspace

Upload the entire `mywoodshedmusic/` folder to your web server. The structure should be:

```
public_html/  (or www/, or htdocs/)
├── index.html
└── api/
    ├── config.php
    ├── helpers.php
    ├── auth.php
    ├── students.php
    ├── content.php
    ├── assignments.php
    └── progress.php
```

### 4. Test it

Visit your domain (e.g. `https://yourdomain.com/`) and you should see the login screen. Register a new account, and you're in.

### 5. Add your first student

1. Go to **Students** tab → **Add Student**
2. The system generates a PIN for them — this is how students log in later

### 6. Quick test flow

1. Add a student
2. Go to **Library** → **Quick Capture** → add a piece of content
3. Go to **Assignments** → **New Assignment** → pick the student, add steps from your library, release it
4. Click **Student View** → pick the student → see their practice path

## Requirements

- PHP 7.4+ (8.x recommended)
- MySQL 5.7+ or MariaDB 10.3+
- PDO extension enabled (usually on by default)
- HTTPS recommended (required if using .app domain)

## Troubleshooting

**"Database connection failed"** — check your credentials in `config.php`

**CORS errors in browser console** — update `ALLOWED_ORIGIN` in `config.php` to match your domain exactly

**Blank page** — check your browser console for JavaScript errors. Make sure the `api/` folder is accessible from the web.

**500 errors on API calls** — check your PHP error log. Most likely a database connection issue or missing PDO extension.
