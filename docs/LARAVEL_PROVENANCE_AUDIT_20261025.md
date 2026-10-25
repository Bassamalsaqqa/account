# Laravel provenance audit — 2026-10-25

This bounded engineering record addresses [issue #57](https://github.com/Bassamalsaqqa/account/issues/57). It preserves upstream notices; it does not change the commercial policy, determine legal ownership of every contribution, or revoke historical grants.

Project foundation: `ce5cf6679bdb25a365d76597464e960949b794c2` (2026-09-28). Its lockfile records Laravel Framework v13.33.0 at `91188a17ceaa3dbace6e8a5f7abd0d042e466359`. The retained [MIT license](../third-party/laravel/LICENSE.md) is byte-for-byte the [license at that framework revision](https://github.com/laravel/framework/blob/91188a17ceaa3dbace6e8a5f7abd0d042e466359/LICENSE.md), including Taylor Otwell's copyright notice. No current dependency license was removed.

The comparison skeleton is [13.x revision aa0cf127](https://github.com/laravel/laravel/tree/aa0cf127fc365a56ee016867144ddffabc2290ae), the nearest available commit preceding the foundation timestamp. Its [manifest](https://github.com/laravel/laravel/blob/aa0cf127fc365a56ee016867144ddffabc2290ae/composer.json) declares MIT; its [README](https://github.com/laravel/laravel/blob/aa0cf127fc365a56ee016867144ddffabc2290ae/README.md) links the MIT grant. There is no separate LICENSE file in that pinned skeleton tree. This comparison does not prove the precise installer revision: the project's Git history begins with a prepared application, without upstream ancestry or an installer receipt.

Of 55 common paths, 25 were byte-identical at foundation and 20 remain identical at accepted main `183b7074790bb6f1a25d8223116be4514055e405`. The current exact matches are:

- `.gitattributes`
- `app/Http/Controllers/Controller.php`
- `artisan`
- `bootstrap/cache/.gitignore`
- `config/filesystems.php`
- `config/mail.php`
- `database/.gitignore`
- `public/favicon.ico`
- `public/robots.txt`
- `storage/app/.gitignore`
- `storage/app/private/.gitignore`
- `storage/app/public/.gitignore`
- `storage/framework/cache/.gitignore`
- `storage/framework/cache/data/.gitignore`
- `storage/framework/sessions/.gitignore`
- `storage/framework/testing/.gitignore`
- `storage/framework/views/.gitignore`
- `storage/logs/.gitignore`
- `tests/TestCase.php`
- `tests/Unit/ExampleTest.php`

Other common paths already differed at foundation and may retain upstream-derived portions: `.editorconfig`; `.env.example`; `.gitignore`; `AGENTS.md`; `README.md`; `app/Models/User.php`; `bootstrap/app.php`; `bootstrap/providers.php`; `composer.json`; `config/app.php`; `config/auth.php`; `config/cache.php`; `config/database.php`; `config/logging.php`; `config/services.php`; `config/session.php`; `database/factories/UserFactory.php`; `database/migrations/0001_01_01_000000_create_users_table.php`; `database/migrations/0001_01_01_000001_create_cache_table.php`; `database/migrations/0001_01_01_000002_create_jobs_table.php`; `database/seeders/DatabaseSeeder.php`; `package.json`; `phpunit.xml`; `resources/css/app.css`; `resources/js/app.js`; `resources/views/welcome.blade.php`; `routes/web.php`; `storage/framework/.gitignore`; `tests/Feature/ExampleTest.php`; `vite.config.js`.

Additionally, foundation-identical files subsequently modified are `app/Providers/AppServiceProvider.php`, `config/queue.php`, `public/.htaccess`, `public/index.php` and `routes/console.php`. These modifications do not erase upstream rights. Formatting or generated placeholder files may have little protectable authorship; this inventory conservatively retains notice rather than deciding that legal question. It is not an exhaustive dependency or line-by-line authorship audit.

Original accounting/inventory actions, company isolation, AI governance and project-specific tests/documentation are subject to the root proprietary terms only to the extent owned by the stated holder. Shared or modified files may contain both original contributions and upstream material. The root LICENSE already explicitly excludes third-party material and preserves valid earlier permissions; it is unchanged. Do not assert proprietary-only ownership of the entire combined tree.

The root Composer name/description now identify `bassamalsaqqa/account` and its accounting purpose, rather than presenting the project as the upstream `laravel/laravel` skeleton. The package type stays project and license stays proprietary for project-owned material; the third-party exception remains documented. Only the lockfile content hash is regenerated to reflect root metadata; package versions, sources, requirements and installed dependencies are unchanged. No code, migrations, financial writers or runtime configuration changes are included.

Recommendation: accept this notice/metadata correction independently of the B.1.1 application fix, then recommend closing #57 as the bounded provenance follow-up. Owner/legal review remains appropriate for commercial distribution, contributors' title, exact installer origin or any claim of exclusive rights. This record is engineering provenance evidence, not a legal determination.
