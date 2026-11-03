<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Product;
use Workbench\App\Models\ProductReview;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
    $this->product = Product::factory()->create(['title' => 'Кофемашина', 'price' => 1000, 'stock' => 5, 'status' => 'active', 'is_visible' => true, 'note' => null]);
});

/**
 * Ждём условие в браузере: окно и его iframe открываются и догружаются уже после клика, а assertScript не ждёт.
 */
function waitUntil(object $page, string $condition): void
{
    $page->script("new Promise((resolve) => { const started = Date.now(); const timer = setInterval(() => { if (({$condition}) || Date.now() - started > 5000) { clearInterval(timer); resolve(true); } }, 50); })");
}

it('edits the price in the cell and switches visibility with the quick toggle', function (): void {
    $page = signInAndVisit(route('fastleo.resources.index', 'products'));
    $price = 'tbody [data-inline="price"] input';

    $page->assertSee('Кофемашина')
        ->fill($price, '2500')
        ->click('tbody [data-inline="status"] select')
        ->waitForEvent('networkidle')
        ->click('tbody form[action*="/toggle/"] button')
        ->waitForEvent('networkidle')
        ->assertNoJavaScriptErrors();

    $this->product->refresh();

    expect((float) $this->product->price)->toBe(2500.0)
        ->and($this->product->is_visible)->toBeFalse();
});

it('hides the columns that are empty on the page', function (): void {
    signInAndVisit(route('fastleo.resources.index', 'products'))
        ->assertMissing('th[data-column="note"]')
        ->assertPresent('th[data-column="reviews_count"]')
        ->assertNoJavaScriptErrors();
});

it('adds a review from the table in its own tab of the product', function (): void {
    $page = signInAndVisit(route('fastleo.resources.edit', ['products', $this->product->id]));

    $page->click('[role=tab]:has-text("Отзывы")')
        ->fill('[data-quick-add="reviews"] input[name="data[author]"]', 'Мария')
        ->click('[data-quick-add="reviews"] button[type="submit"]')
        ->waitForEvent('networkidle')
        ->assertSee('Мария')
        ->assertNoJavaScriptErrors();

    expect(ProductReview::query()->where('product_id', $this->product->id)->where('author', 'Мария')->exists())->toBeTrue();
});

it('marks the field of the quick add that failed validation', function (): void {
    $page = signInAndVisit(route('fastleo.resources.edit', ['products', $this->product->id]));

    $page->click('[role=tab]:has-text("Отзывы")')
        ->click('[data-quick-add="reviews"] button[type="submit"]');

    waitUntil($page, "document.querySelector('[data-quick-add=\"reviews\"] [aria-invalid=true]') !== null");

    $page->assertScript("document.querySelector('[data-quick-add=\"reviews\"] input[name=\"data[author]\"]').getAttribute('aria-invalid')", 'true')
        ->assertScript('document.activeElement.name', 'data[author]')
        ->assertNoJavaScriptErrors();
});

it('edits a review of the table in a window and refreshes the row after saving', function (): void {
    $review = ProductReview::factory()->create(['product_id' => $this->product->id, 'author' => 'Старый автор', 'rating' => 3]);
    $form = "document.querySelector('#fastleo-record-modal iframe').contentDocument";

    $page = signInAndVisit(route('fastleo.resources.edit', ['products', $this->product->id]));

    $page->click('[role=tab]:has-text("Отзывы")')
        ->assertSee('Старый автор')
        ->click('[data-related-table="reviews"] a[title="Изменить"]');

    waitUntil($page, "!!{$form}?.querySelector('[data-save]')");

    $page->assertScript("document.getElementById('fastleo-record-modal').open", true)
        ->assertScript("document.getElementById('fastleo-record-modal').hasAttribute('data-frame')", true)
        ->assertScript("!!{$form}?.querySelector('[data-save]')", true)
        ->assertScript("{$form}.querySelector('[name=product_id]')?.value", (string) $this->product->id);

    $page->script("(() => { const d = {$form}; const input = d.querySelector('input[name=author]'); input.value = 'Новый автор'; input.dispatchEvent(new Event('input', { bubbles: true })); d.querySelector('[data-save]').click(); })()");

    $page->waitForEvent('networkidle')
        ->assertScript("document.getElementById('fastleo-record-modal').open", false)
        ->assertSee('Новый автор')
        ->assertNoJavaScriptErrors();

    expect($review->refresh()->author)->toBe('Новый автор');
});

it('opens a review of the table as a card in the window, without a form or an edit button', function (): void {
    ProductReview::factory()->create(['product_id' => $this->product->id, 'author' => 'Старый автор', 'rating' => 3]);
    $card = "document.querySelector('#fastleo-record-modal [data-record-card]')";

    $page = signInAndVisit(route('fastleo.resources.edit', ['products', $this->product->id]));

    $page->click('[role=tab]:has-text("Отзывы")')
        ->click('[data-related-table="reviews"] a[title="Открыть"]');

    waitUntil($page, "document.querySelector('#fastleo-record-modal [data-record-card]')?.textContent.includes('Старый автор')");

    $page->assertScript("document.getElementById('fastleo-record-modal').open", true)
        ->assertScript("{$card}.textContent.includes('Старый автор')", true)
        ->assertScript("!!{$card}.querySelector('[data-save], #fastleo-menu, form')", false)
        ->assertScript("document.querySelector('#fastleo-record-modal iframe').classList.contains('hidden')", true)
        ->assertNoJavaScriptErrors();
});

it('opens a product for viewing in a window from the list', function (): void {
    $card = "document.querySelector('#fastleo-record-modal [data-record-card]')";

    $page = signInAndVisit(route('fastleo.resources.index', 'products'));

    $page->click('tr[data-record="'.$this->product->id.'"] a[title="Открыть"]');

    waitUntil($page, "document.querySelector('#fastleo-record-modal [data-record-card]')?.textContent.includes('{$this->product->title}')");

    $page->assertScript("document.getElementById('fastleo-record-modal').open", true)
        ->assertScript("{$card}.textContent.includes('{$this->product->title}')", true)
        ->assertPathIs('/fastleo/products')
        ->click('#fastleo-record-modal [data-modal-close]')
        ->assertScript("document.getElementById('fastleo-record-modal').open", false)
        ->assertNoJavaScriptErrors();
});

it('sizes the card window to its content, locks the page behind it and closes on a click outside', function (): void {
    $card = "document.querySelector('#fastleo-record-modal [data-record-card]')";
    $dialog = "document.getElementById('fastleo-record-modal')";

    $page = signInAndVisit(route('fastleo.resources.index', 'products'));

    $page->click('tr[data-record="'.$this->product->id.'"] a[title="Открыть"]');

    waitUntil($page, "{$card}?.textContent.includes('{$this->product->title}')");

    $page->assertScript("{$dialog}.getBoundingClientRect().height < window.innerHeight * 0.85 - 2", true)
        ->assertScript("{$dialog}.getAttribute('aria-label')", $this->product->title)
        ->assertScript('getComputedStyle(document.documentElement).overflow', 'hidden');

    // Нажатие внутри окна и отпускание за его границей (выделение текста) окно не закрывает.
    $page->script("(() => { const d = {$dialog}; d.querySelector('[data-record-card]').dispatchEvent(new MouseEvent('mousedown', { bubbles: true })); d.dispatchEvent(new MouseEvent('click', { bubbles: true })); })()");
    $page->assertScript("{$dialog}.open", true);

    // Нажатие и клик по самому окну — это фон за ним (содержимое окно целиком закрывает).
    $page->script("(() => { const d = {$dialog}; d.dispatchEvent(new MouseEvent('mousedown', { bubbles: true })); d.dispatchEvent(new MouseEvent('click', { bubbles: true })); })()");
    $page->assertScript("{$dialog}.open", false)
        ->assertScript("{$dialog}.getAttribute('aria-label')", 'Запись')
        ->assertScript('getComputedStyle(document.documentElement).overflow', 'visible')
        ->assertNoJavaScriptErrors();
});

it('opens the ordinary record page instead of a bare card when loading the card fails', function (): void {
    $page = signInAndVisit(route('fastleo.resources.index', 'products'));

    $page->script("window.fetch = () => Promise.resolve(new Response('', { status: 503 }))");
    $page->click('tr[data-record="'.$this->product->id.'"] a[title="Открыть"]');

    waitUntil($page, "window.location.pathname === '/fastleo/products/{$this->product->id}'");

    $page->assertUrlIs(route('fastleo.resources.show', ['products', $this->product->id]))
        ->assertSee('Кофемашина')
        ->assertPresent('#fastleo-menu');
});

it('sends to the login page and back to the ordinary record page when the session has expired', function (): void {
    $page = signInAndVisit(route('fastleo.resources.index', 'products'));

    $page->script("fetch('/fastleo/logout', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } })");
    $page->click('tr[data-record="'.$this->product->id.'"] a[title="Открыть"]');

    waitUntil($page, "window.location.pathname === '/fastleo/login'");

    $page->assertPathIs('/fastleo/login')
        ->assertScript("!!document.querySelector('#fastleo-record-modal [data-record-card] form')", false)
        ->type('email', 'admin@example.com')
        ->type('password', 'password')
        ->press('Войти')
        ->assertUrlIs(route('fastleo.resources.show', ['products', $this->product->id]))
        ->assertPresent('#fastleo-menu');
});

it('closes the window with Escape and ignores a message from another site', function (): void {
    ProductReview::factory()->create(['product_id' => $this->product->id, 'author' => 'Старый автор']);
    $form = "document.querySelector('#fastleo-record-modal iframe').contentDocument";

    $page = signInAndVisit(route('fastleo.resources.edit', ['products', $this->product->id]));

    $page->click('[role=tab]:has-text("Отзывы")')
        ->click('[data-related-table="reviews"] a[title="Изменить"]');

    waitUntil($page, "{$form}?.body?._x_dataStack !== undefined");

    $page->assertScript("!!{$form}?.querySelector('[data-save]')", true)
        ->assertScript("{$form}.body._x_dataStack !== undefined", true);

    // Сообщение «сохранено» не от своего окна окно не закрывает.
    $page->script("window.postMessage({ type: 'fastleo:saved' }, window.location.origin)");
    $page->assertScript("document.getElementById('fastleo-record-modal').open", true);

    $page->script("{$form}.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))");
    $page->assertScript("document.getElementById('fastleo-record-modal').open", false)
        ->assertNoJavaScriptErrors();
});

it('asks before closing the window with unsaved changes', function (): void {
    ProductReview::factory()->create(['product_id' => $this->product->id, 'author' => 'Старый автор']);
    $form = "document.querySelector('#fastleo-record-modal iframe').contentDocument";
    $escape = "{$form}.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))";

    $page = signInAndVisit(route('fastleo.resources.edit', ['products', $this->product->id]));

    $page->click('[role=tab]:has-text("Отзывы")')
        ->click('[data-related-table="reviews"] a[title="Изменить"]');

    waitUntil($page, "{$form}?.body?._x_dataStack !== undefined");

    $page->assertScript("{$form}.body._x_dataStack !== undefined", true);

    $page->script("(() => { const input = {$form}.querySelector('input[name=author]'); input.value = 'Правка'; input.dispatchEvent(new Event('input', { bubbles: true })); window.confirm = () => false; })()");
    $page->script($escape);
    $page->assertScript("document.getElementById('fastleo-record-modal').open", true);

    $page->script('window.confirm = () => true');
    $page->script($escape);
    $page->assertScript("document.getElementById('fastleo-record-modal').open", false)
        ->assertNoJavaScriptErrors();
});

it('shows an empty frame instead of a broken picture when the photo file is missing', function (): void {
    $this->product->update(['photo' => 'uploads/products/missing.jpg']);

    signInAndVisit(route('fastleo.resources.index', 'products'))
        ->assertScript("document.querySelector('tbody img[data-title-thumb]').hidden", true)
        ->assertNoJavaScriptErrors();
});
