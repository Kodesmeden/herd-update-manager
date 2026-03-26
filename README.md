# Herd Update Manager

A web dashboard for managing updates across all your Laravel projects running on [Laravel Herd](https://herd.laravel.com). Instead of opening a terminal for each project, you get a single interface to batch-update dependencies, manage git branches, push changes, and run diagnostics.

## Features

- **Batch updates** - Run `composer update`, `npm update`, `npm run build`, and cache clearing across all projects in parallel
- **Git management** - Fetch, switch branches, create branches, and view repository status per project
- **Push and commit** - Stage, commit, and push changes with a custom message for one or all projects
- **Pull requests** - Create, check status, and merge PRs directly from the dashboard (requires GitHub CLI)
- **Diagnostics** - Run system checks and view environment information
- **Real-time progress** - Watch each update step as it runs with live output logs

## Requirements

- [Laravel Herd](https://herd.laravel.com) (macOS or Windows)
- Composer
- [GitHub CLI](https://cli.github.com) (optional, only needed for pull request features)

## Installation

Clone this repository into your Herd directory:

**macOS:**

```bash
cd ~/Herd
git clone <repo-url> update
cd update
composer run setup
```

**Windows:**

```powershell
cd ~\Herd
git clone <repo-url> update
cd update
composer run setup
```

The `setup` script installs all dependencies, creates the `.env` file, generates an app key, runs migrations, and builds the frontend assets.

Since Herd automatically serves all projects in the Herd directory, the app is immediately available at `http://update.test`. To enable HTTPS:

```bash
herd secure update
```

The site is then available at `https://update.test`. No additional server configuration is needed.

## Development

Herd serves the site automatically. For frontend hot-reload during development, run:

```bash
npm run dev
```

Or use the full dev stack (queue worker, log viewer, and Vite):

```bash
composer run dev
```

## Testing

```bash
php artisan test --compact
```

## How it works

The app scans the Herd directory (`~/Herd` on macOS, `~\Herd` on Windows) for Laravel projects and stores them as installations in a local SQLite database. When you trigger an update or push, it spawns background processes using Herd's bundled PHP and Node binaries, so each project runs with the correct runtime versions. Progress and output are tracked in the database and polled by the frontend every two seconds.

## License

MIT
