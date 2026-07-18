# Agent Notes

This repository uses Laravel 13 and Filament 5.

Project-specific guidance:

- The admin panel is defined in [app/Providers/Filament/AdminPanelProvider.php](app/Providers/Filament/AdminPanelProvider.php).
- Filament resources, pages, and widgets are auto-discovered from `app/Filament`.
- Before making Filament changes, read [README.md](README.md) and [docs/rfc/mvp.md](docs/rfc/mvp.md) for the domain model and workflow.
- Prefer Filament and Laravel generators over hand-written scaffolding when adding admin surfaces.
- Keep changes aligned with the existing MVP focus: queue jobs, operator assignment, outbox delivery, and operational controls.

AI-assisted development:

- Follow the Filament AI docs at https://filamentphp.com/docs/5.x/introduction/ai.
- If Laravel Boost is installed, use it to generate Filament-aware code and keep prompts specific to this app.
- When adding AI-related developer tooling, keep the setup lightweight and document any new commands here.

Validation:

- Run `php artisan test` after changes that affect app behavior.
- Run `vendor/bin/pint` after PHP edits if formatting drift appears.
