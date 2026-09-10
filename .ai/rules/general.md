---
paths:
  - '**'
---

# General

## Run composer ci:check before every commit and push
Always run `composer ci:check` and get a clean exit before committing or pushing. It chains `npm run lint:check`, `npm run format:check`, `npm run types:check` and `@test` (which itself runs `config:clear` and `pint --parallel --test`), so it is the only command that covers both the PHP and the JS/TS side.

Running `php artisan test` or `vendor/bin/pint` alone is not enough: neither catches Prettier or TypeScript regressions, and both leave a stale cached config in place.
