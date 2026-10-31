# Fastleo

An admin panel for Laravel sites. Each admin section is described by a single resource class: fields, list, permissions. The interface is prebuilt (Blade, Tailwind, Alpine.js), so your site does not need npm.

> The admin interface and the documentation in [`docs/`](docs/) are in Russian.

What's included:

- **Sections**: a list with search, filters, sorting and pagination; a form with tabs and columns; a detail view, trash, manual ordering and trees; per-record and bulk actions; Excel/CSV export and import.
- **35 field types and custom fields**: text, numbers, dates, choices, relations (BelongsTo, BelongsToMany, HasMany, HasOne), images with WebP variants, files, gallery, rich text editor, Markdown, JSON fields (Repeater, KeyValue, the Blocks page builder), SEO, translatable fields.
- **Admin environment**: a dashboard with widgets, site settings, custom pages, an activity log with rollback, an error log, notifications with Telegram and Max bots, publishing to Telegram and Max channels, Ctrl+K search, sitemap and robots.txt, closing the site for visitors, a file manager, and an optional connection to the SoftNetica site maintenance service.
- **Security**: a separate login with rate limits, two-factor authentication, permissions via model Policies, only declared fields are written, upload protection ([details](docs/security.md)).
- **Claude**: a skill and Laravel Boost guidelines, the `fastleo:doctor` and `fastleo:inspect` commands, an MCP server — you can ask for a section in a single sentence ([details](docs/claude.md)).

Dark mode and mobile layout work out of the box. The activity log, the notification bots and publishing are off by default — [how to switch them on](docs/configuration.md).

Coming from 2.x or 3.0? 4.0 is a new admin panel, not an upgrade of the old one — sections are described again as classes and data formats change; see the [migration guide](docs/upgrade-from-3.md). Moving from another admin panel (MoonShine, a hand-made one)? See [moving to fastleo](docs/migrating.md). What's new — [CHANGELOG](CHANGELOG.md).

## Requirements

- PHP 8.4 or newer
- Laravel 13
- A queue is optional: with `php artisan queue:work` images, imports and notifications are processed in the background, without it — during the request

## Installation in 5 minutes

```bash
composer require camanru/fastleo
php artisan fastleo:install
```

`fastleo:install` connects your `app/Models/User.php` to the admin panel: it adds the `FastleoUser` interface and the `HasFastleoAccess` trait and keeps everything else. If the model is unusual and the command cannot change it, it says so — add them by hand ([example](docs/installation.md#2-модель-пользователя)):

```php
use Fastleo\Auth\FastleoUser;
use Fastleo\Auth\HasFastleoAccess;

class User extends Authenticatable implements FastleoUser
{
    use HasFactory, HasFastleoAccess, Notifiable;
}
```

Add the `fastleo_admin` column and the package tables, link the public disk (uploaded files open from `public/storage`) and create an administrator:

```bash
php artisan migrate
php artisan storage:link
php artisan fastleo:user admin@example.com
```

Open `https://example.com/fastleo` and log in.

## Your first section

A section is a class in `app/Fastleo` on top of an ordinary model and table. Create the model and the migration, migrate, then generate a stub with fields based on the table columns; adjust the labels and the icon — you will get something like this:

```bash
php artisan make:model Doctor -m                       # model and migration: add the columns, then migrate
php artisan migrate
php artisan fastleo:make-resource Doctor --generate    # app/Fastleo/DoctorResource.php
```

```php
// app/Fastleo/DoctorResource.php
namespace App\Fastleo;

use App\Models\Doctor;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;

final class DoctorResource extends Resource
{
    protected static string $model = Doctor::class;

    protected static ?string $label = 'Doctors';

    protected static ?string $singular = 'Doctor';

    protected static string $icon = 'stethoscope';

    public function fields(): array
    {
        return [
            Text::make('name', 'Name')->required()->searchable()->sortable(),
            Text::make('specialty', 'Specialty'),
            Toggle::make('is_active', 'Show on the site'),
        ];
    }
}
```

The section appears in the menu and opens at `/fastleo/doctors`. The fields must be in the model's `$fillable`.

Check the result with `php artisan fastleo:doctor` — it reports mistakes in sections and site setup ([commands](docs/claude.md#команды)). Without a Policy on the model, any admin has full access to the section ([authorization](docs/authorization.md)).

## Documentation

Reading order with short descriptions (in Russian): [docs/README.md](docs/README.md). Practical answers with code: [FAQ](docs/faq/README.md).

- [Installation and upgrading](docs/installation.md)
- [Configuration: `config/fastleo.php`](docs/configuration.md)
- [Sections (resources)](docs/resources.md)
- [Fields](docs/fields/README.md)
- [Form layout](docs/layout.md)
- [Validation](docs/validation.md)
- [Dashboard and widgets](docs/dashboard.md)
- [Report pages: filters and widgets](docs/reports.md)
- [Recipes: clients, leads, managers](docs/recipes.md)
- [Record list and filters](docs/list.md)
- [Related tables on a record page](docs/related.md)
- [Actions](docs/actions.md)
- [Export and import](docs/import-export.md)
- [Page builder](docs/blocks.md)
- [Translatable fields](docs/translations.md)
- [Authorization](docs/authorization.md)
- [Login, profile, password reset](docs/auth.md)
- [Activity log](docs/activity.md)
- [Site settings](docs/settings.md)
- [Closing the site for visitors](docs/site-closing.md)
- [Custom pages and the error log](docs/pages.md)
- [Notifications: bell, Telegram and Max](docs/notifications.md)
- [Publishing to Telegram and Max](docs/publishing.md)
- [SoftNetica connection](docs/softnetica.md)
- [SEO and sitemap](docs/seo.md)
- [Files and images](docs/files.md)
- [Theming and custom templates](docs/theming.md)
- [Security](docs/security.md)
- [Testing your sections](docs/testing.md)
- [Working with Claude](docs/claude.md)
- [Upgrading from 2.x and 3.0](docs/upgrade-from-3.md)
- [Moving from another admin panel](docs/migrating.md)
- [Package development](https://github.com/Camanru/fastleo/blob/master/docs/contributing.md)

## License

MIT. Icons — [Lucide](https://lucide.dev) (ISC).
