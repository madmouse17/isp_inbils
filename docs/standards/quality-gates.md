# Quality gates

PHP:
```bash
vendor/bin/pint
composer analyse
php artisan test
```

Frontend:
```bash
npm run lint
npm run typecheck
npm run build
npm run format
```

Current package scripts only define `build` and `dev`; missing scripts must be added before enforcing them. UI changes require Playwright browser verification where available.
