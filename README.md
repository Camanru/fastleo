# Fastleo

An admin panel for Laravel sites. Each admin section is described by a single resource class: fields, list, permissions. The interface is prebuilt (Blade, Tailwind, Alpine.js), so your site does not need npm.

> The admin interface and the documentation in [`docs/`](docs/) are in Russian.

What's included:

- **Sections**: a list with search, filters, sorting and pagination; a form with tabs and columns; a detail view, trash, manual ordering and trees; per-record and bulk actions; Excel/CSV export and import.
- **35 field types and custom fields**: text, numbers, dates, choices, relations (BelongsTo, BelongsToMany, HasMany, HasOne), images with WebP variants, files, gallery, rich text editor, Markdown, JSON fields (Repeater, KeyValue, the Blocks page builder), SEO, translatable fields.
- **Admin environment**: a dashboard with widgets, site settings, custom pages, an activity log with rollback, an error log, notifications with Telegram and Max bots, Ctrl+K search, sitemap and robots.txt, closing the site for visitors, a file manager, and an optional connection to the SoftNetica site maintenance service.
- **Security**: a separate login with rate limits, two-factor authentication, permissions via model Policies, only declared fields are written, upload protection ([details](docs/security.md)).
- **Claude**: a skill and Laravel Boost guidelines, the `fastleo:doctor` and `fastleo:inspect` commands, an MCP server — you can ask for a section in a single sentence ([details](docs/claude.md)).

Dark mode and mobile layout work out of the box.

Upgrading from 2.x and 3.0 — [guide](docs/upgrade-from-3.md); what's new — [CHANGELOG](CHANGELOG.md).

## Requirements

- PHP 8.4 or newer
- Laravel 13
- A queue is optional: with `php artisan queue:work` images, imports and notifications are processed in the background, without it — during the request

## Installation in 5 minutes

```bash
composer require camanru/fastleo
php artisan fastleo:install
```

Enable admin access in your user model:

```php
use Fastleo\Auth\FastleoUser;
use Fastleo\Auth\HasFastleoAccess;

class User extends Authenticatable implements FastleoUser
{
    use HasFastleoAccess;
}
```

Add the `fastleo_admin` column and create an administrator:

```bash
php artisan migrate
php artisan fastleo:user admin@example.com
```

Open `https://example.com/fastleo` and log in.

## Your first section

A section is a class in `app/Fastleo`. The command below creates a stub with fields based on the table columns; then adjust the labels and the icon — you will get something like this:

```bash
php artisan fastleo:make-resource Doctor --generate
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

## Documentation

- [Installation and upgrading](docs/installation.md)
- [Configuration: `config/fastleo.php`](docs/configuration.md)
- [Sections (resources)](docs/resources.md)
- [Fields](docs/fields/README.md)
- [Form layout](docs/layout.md)
- [Validation](docs/validation.md)
- [Dashboard and widgets](docs/dashboard.md)
- [Record list and filters](docs/list.md)
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
- [Package development](https://github.com/Camanru/fastleo/blob/master/docs/contributing.md)

## License

MIT. Icons — [Lucide](https://lucide.dev) (ISC).
