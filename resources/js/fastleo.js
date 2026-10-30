import Alpine from 'alpinejs';
import Sortable from 'sortablejs';

/**
 * Текущее значение поля формы по имени, как его отправит браузер: у галочки — «1»/«0»
 * (скрытое «0» и отмеченная галочка), у переключателей — выбранный. nested — искать `...[name]` внутри блока.
 */
function fieldValue(scope, field, nested = false) {
    let value = '';

    for (const input of scope?.querySelectorAll('[name]') ?? []) {
        // У form и fieldset свойство name — вложенный элемент, если у него есть ребёнок с именем «name».
        if (typeof input.name !== 'string') {
            continue;
        }

        const name = input.name.replace(/\[\]$/, '');

        if (nested ? !name.endsWith(`[${field}]`) : name !== field) {
            continue;
        }

        if (input.type === 'checkbox') {
            // Снятая галочка без скрытого «0» (readonly) — тоже «0», а не пустое значение.
            value = input.checked ? input.value : value === '' ? '0' : value;
        } else if (input.type === 'radio') {
            value = input.checked ? input.value : value;
        } else {
            value = input.value;
        }
    }

    return value;
}

/**
 * Следить за изменением поля формы (ввод и выбор).
 */
function watchField(scope, field, nested, callback) {
    const handler = (event) => {
        const name = (typeof event.target?.name === 'string' ? event.target.name : '').replace(/\[\]$/, '');

        if (nested ? name.endsWith(`[${field}]`) : name === field) {
            callback(fieldValue(scope, field, nested));
        }
    };

    scope?.addEventListener('input', handler);
    scope?.addEventListener('change', handler);
}

const STORAGE_KEY = 'fastleo-theme';
const MODES = ['light', 'dark', 'system'];

/**
 * Выбранная тема: из localStorage или по умолчанию из конфига (data-theme на <html>).
 */
function storedMode() {
    const mode = localStorage.getItem(STORAGE_KEY);

    return MODES.includes(mode) ? mode : document.documentElement.dataset.theme || 'system';
}

/**
 * Ставит или снимает класс .dark на <html> по выбранной теме.
 */
function applyMode(mode) {
    const dark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

    document.documentElement.classList.toggle('dark', dark);
}

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => applyMode(storedMode()));

/**
 * Переключатель темы в шапке: <div x-data="fastleoTheme">.
 */
Alpine.data('fastleoTheme', () => ({
    mode: storedMode(),

    set(mode) {
        this.mode = mode;
        localStorage.setItem(STORAGE_KEY, mode);

        // На 200 мс включаем плавную смену цветов, чтобы тема не «щёлкала».
        const root = document.documentElement;
        root.classList.add('theme-transition');
        applyMode(mode);
        setTimeout(() => root.classList.remove('theme-transition'), 200);
    },
}));

/**
 * Вход с кодом 2FA: <form x-data="fastleoLoginCode">. Шесть ячеек показывают цифры из поля поверх них;
 * «Код восстановления» переключает на обычное текстовое поле (оба поля называются code, отправляется активное).
 */
Alpine.data('fastleoLoginCode', () => ({
    digits: '',
    focused: false,
    recovery: false,

    toggleRecovery() {
        this.recovery = !this.recovery;
        this.$nextTick(() => (this.recovery ? this.$refs.recoveryCode : this.$refs.code).focus());
    },
}));

/**
 * Плотность таблиц: <div x-data="fastleoDensity">. «Компактные строки» (40 px вместо 52) хранятся в localStorage
 * и ставят класс fl-compact на <html> (вариант compact: в разметке); начальное состояние — скрипт в <head>.
 */
Alpine.data('fastleoDensity', () => ({
    compact: document.documentElement.classList.contains('fl-compact'),

    toggle() {
        this.compact = !this.compact;
        localStorage.setItem('fastleo-density', this.compact ? 'compact' : 'comfortable');
        document.documentElement.classList.toggle('fl-compact', this.compact);
    },
}));

const SIDEBAR_KEY = 'fastleo-sidebar';

/**
 * Каркас админки: выезжающее меню на телефоне и свёрнутое (полоса 64 px) на широком экране.
 * Выбор хранится в localStorage; без выбора меню свёрнуто на экранах уже 1280 px (начальное состояние
 * ставит скрипт в <head>, чтобы страница не дёргалась).
 */
Alpine.data('fastleoShell', () => ({
    menuOpen: false,
    collapsed: document.documentElement.classList.contains('fl-collapsed'),

    toggleCollapsed() {
        this.collapsed = !this.collapsed;
        localStorage.setItem(SIDEBAR_KEY, this.collapsed ? '1' : '0');
        document.documentElement.classList.toggle('fl-collapsed', this.collapsed);
    },
}));

/**
 * Форма записи: <form x-data="fastleoForm" data-unsaved="текст">. Признак изменений — также в $store.fastleoForm.dirty.
 * Ctrl/Cmd+S — «Сохранить»; при уходе со страницы с изменениями браузер переспрашивает.
 */
Alpine.store('fastleoForm', { dirty: false });

Alpine.data('fastleoForm', () => ({
    dirty: false,
    submitting: false,

    init() {
        // Бейдж «Есть несохранённые изменения» у заголовка страницы (вне формы) читает $store.fastleoForm.dirty.
        this.$watch('dirty', (dirty) => (Alpine.store('fastleoForm').dirty = dirty));
        this.dirty = this.$el.hasAttribute('data-dirty');
        Alpine.store('fastleoForm').dirty = this.dirty;
        this.$el.addEventListener('input', () => (this.dirty = true));
        this.$el.addEventListener('change', () => (this.dirty = true));
        this.$el.addEventListener('submit', () => (this.submitting = true));

        window.addEventListener('beforeunload', (event) => {
            if (this.dirty && !this.submitting) {
                event.preventDefault();
                event.returnValue = this.$el.dataset.unsaved || '';
            }
        });

        window.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
                event.preventDefault();
                const save = this.$el.querySelector('[data-save]');
                this.$el.requestSubmit(save || undefined);
            }
        });
    },
}));

/**
 * Спросить подтверждение своим окном (<dialog id="fastleo-confirm">) → Promise<boolean>.
 * button — подпись кнопки согласия (по умолчанию «Удалить»), danger — красная она или основного цвета.
 */
export function confirmDialog(text, { button = null, danger = true } = {}) {
    const dialog = document.getElementById('fastleo-confirm');
    const dangerButton = dialog.querySelector('[data-confirm-ok]');
    const primaryButton = dialog.querySelector('[data-confirm-primary]');
    const active = danger ? dangerButton : primaryButton;

    dangerButton.hidden = !danger;
    primaryButton.hidden = danger;
    active.textContent = button ?? active.dataset.default;

    return new Promise((resolve) => {
        dialog.querySelector('[data-confirm-text]').textContent = text;
        dialog.returnValue = '';
        dialog.addEventListener('close', () => resolve(dialog.returnValue === 'confirm'), { once: true });
        dialog.showModal();
    });
}

/**
 * Действия над записями: выбор строк галочками и запуск действия.
 * <div x-data="fastleoActions({ ids, open, openIds })">: ids — ключи записей страницы;
 * open — ключ действия, чьё окно мини-формы открыть сразу (после ошибки проверки), openIds — его записи.
 * Формы действий: #fastleo-action-form-{key} (без полей) и <dialog id="fastleo-action-{key}"> (с полями);
 * ключи записей попадают в них из target.
 */
Alpine.data('fastleoActions', ({ ids = [], open = null, openIds = [] } = {}) => ({
    all: ids,
    selected: [],
    target: [],

    init() {
        if (open) {
            this.target = openIds;
            this.$nextTick(() => document.getElementById(`fastleo-action-${open}`)?.showModal());
        }
    },

    get allSelected() {
        return this.all.length > 0 && this.all.every((id) => this.selected.includes(id));
    },

    toggleAll(on) {
        this.selected = on ? [...this.all] : [];
    },

    async run(key, ids, { confirm = null, button = null, danger = false, form = false } = {}) {
        this.target = [...ids];

        if (form) {
            await this.$nextTick();
            document.getElementById(`fastleo-action-${key}`).showModal();

            return;
        }

        if (confirm && !(await confirmDialog(confirm, { button, danger }))) {
            return;
        }

        await this.$nextTick();
        document.getElementById(`fastleo-action-form-${key}`).requestSubmit();
    },
}));

/**
 * Прогресс импорта: <div x-data="fastleoImport({ url, state })"> — опрос состояния раз в секунду до завершения.
 */
Alpine.data('fastleoImport', ({ url, state }) => ({
    state,
    timer: null,

    init() {
        if (!this.state.finished) {
            this.timer = setInterval(() => this.poll(), 1000);
        }
    },

    destroy() {
        clearInterval(this.timer);
    },

    async poll() {
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });

            if (response.ok) {
                this.state = await response.json();
            }
        } finally {
            if (this.state.finished) {
                clearInterval(this.timer);
            }
        }
    },
}));

/**
 * Выпадающее меню (действия записи, экспорт, добавить блок): открывается под кнопкой или над ней, если внизу мало места;
 * position: fixed — таблица с прокруткой его не обрезает.
 */
Alpine.data('fastleoMenu', () => ({
    menu: false,
    style: '',

    toggle() {
        this.menu = !this.menu;
        this.place();
    },

    // Положение меню у кнопки; при прокрутке страницы меню едет вместе с ней.
    place() {
        if (!this.menu) {
            return;
        }

        const rect = this.$refs.button.getBoundingClientRect();
        const right = Math.max(8, window.innerWidth - rect.right);
        // Внизу экрана мало места — меню открывается вверх.
        const vertical = window.innerHeight - rect.bottom < 280 ? `bottom: ${window.innerHeight - rect.top + 4}px` : `top: ${rect.bottom + 4}px`;
        this.style = `${vertical}; right: ${right}px`;
    },
}));

/**
 * Колокольчик уведомлений: число непрочитанных в значке, список при открытии, «прочитано» при нажатии,
 * обновление числа раз в минуту, пока вкладка открыта.
 * readUrl содержит __id__ вместо номера уведомления.
 */
Alpine.data('fastleoBell', ({ url, readAllUrl, readUrl, unread = 0 }) => ({
    open: false,
    loaded: false,
    items: [],
    unread,
    timer: null,

    init() {
        this.timer = setInterval(() => {
            if (!document.hidden) {
                this.refreshCount();
            }
        }, 60000);
    },

    destroy() {
        clearInterval(this.timer);
    },

    async toggle() {
        this.open = !this.open;

        if (this.open) {
            await this.load();
        }
    },

    async load() {
        try {
            const data = await requestJson(url, 'GET');
            this.items = data.items;
            this.unread = data.unread;
        } catch {
            this.items = [];
        }

        this.loaded = true;
    },

    async refreshCount() {
        try {
            this.unread = (await requestJson(`${url}?count=1`, 'GET')).unread;
        } catch {
            // Нет связи или сеанс истёк — значок остаётся прежним.
        }
    },

    // Нажатие на уведомление: отметить прочитанным, затем перейти по ссылке (если она есть).
    async open_(item, event) {
        if (!item.url || !item.read) {
            event.preventDefault();
        }

        if (!item.read) {
            item.read = true;
            this.unread = Math.max(0, this.unread - 1);
            // Переход — только после отметки «прочитано»: иначе запрос теряется при уходе со страницы.
            await requestJson(readUrl.replace('__id__', item.id), 'POST', {}).catch(() => {});

            if (item.url) {
                window.location.href = item.url;
            }
        }
    },

    async readAll() {
        await requestJson(readAllUrl, 'POST', {}).catch(() => {});
        this.items.forEach((item) => {
            item.read = true;
        });
        this.unread = 0;
    },
}));

/**
 * Глобальный поиск: окно по Ctrl/⌘+K или кнопке в шапке, запрос через 250 мс после ввода,
 * стрелки выбирают результат, Enter открывает его, Esc закрывает.
 */
Alpine.data('fastleoSearch', ({ url }) => ({
    open: false,
    query: '',
    groups: [],
    active: 0,
    loading: false,
    failed: false,
    searched: false,
    timer: null,
    sequence: 0,

    get items() {
        return this.groups.flatMap((group) => group.items);
    },

    show() {
        this.open = true;
        this.$nextTick(() => this.$refs.input.focus());
    },

    hide() {
        this.open = false;
    },

    toggle() {
        this.open ? this.hide() : this.show();
    },

    input() {
        clearTimeout(this.timer);
        this.active = 0;

        if (this.query.trim().length < 2) {
            this.sequence++;
            this.groups = [];
            this.searched = false;
            this.loading = false;

            return;
        }

        this.timer = setTimeout(() => this.run(), 250);
    },

    async run() {
        const sequence = ++this.sequence;
        this.loading = true;
        this.failed = false;

        try {
            const data = await requestJson(`${url}?q=${encodeURIComponent(this.query.trim())}`, 'GET');

            // Ответ на уже устаревший запрос не показываем.
            if (sequence === this.sequence) {
                this.groups = data.groups;
                this.searched = true;
            }
        } catch {
            if (sequence === this.sequence) {
                this.groups = [];
                this.failed = true;
            }
        }

        if (sequence === this.sequence) {
            this.loading = false;
        }
    },

    move(step) {
        const count = this.items.length;

        if (count > 0) {
            this.active = (this.active + step + count) % count;
            this.$nextTick(() => this.$el.querySelector('[data-search-item][aria-selected="true"]')?.scrollIntoView({ block: 'nearest' }));
        }
    },

    go() {
        const item = this.items[this.active];

        if (item) {
            window.location.href = item.url;
        }
    },

    index(group, position) {
        return this.groups.slice(0, this.groups.indexOf(group)).reduce((sum, previous) => sum + previous.items.length, 0) + position;
    },
}));

/**
 * Поле Seo: счётчики длины и превью сниппета поиска. Значения берутся из полей формы `name[title]`
 * и `name[description]` и обновляются при вводе.
 */
Alpine.data('fastleoSeo', ({ name, titleMax, descriptionMax, emptyTitle, emptyDescription }) => ({
    title: '',
    description: '',
    titleMax,
    descriptionMax,
    emptyTitle,
    emptyDescription,

    init() {
        const read = () => {
            this.title = this.$el.querySelector(`[name="${name}[title]"]`)?.value ?? '';
            this.description = this.$el.querySelector(`[name="${name}[description]"]`)?.value ?? '';
        };

        read();
        this.$el.addEventListener('input', read);
    },
}));

/**
 * Подтверждение действия своим окном:
 * <form x-data="fastleoConfirm" data-confirm="Удалить запись?" @submit.prevent="ask">.
 * Необязательно: data-confirm-button — подпись кнопки согласия, data-confirm-danger="0" — кнопка основного цвета.
 */
Alpine.data('fastleoConfirm', () => ({
    async ask() {
        const { confirm, confirmButton, confirmDanger } = this.$el.dataset;

        if (await confirmDialog(confirm, { button: confirmButton ?? null, danger: confirmDanger !== '0' })) {
            this.$el.submit();
        }
    },
}));

/**
 * Быстрый переключатель галочки в списке: клик отправляет новое значение и меняет значок без перезагрузки.
 * <form x-data="fastleoQuickToggle({ on, failed })" @submit.prevent="send">. Ошибка — значок остаётся прежним, под ним сообщение сервера.
 */
Alpine.data('fastleoQuickToggle', ({ on, failed }) => ({
    on,
    busy: false,
    error: '',

    async send() {
        if (this.busy) {
            return;
        }

        this.busy = true;
        this.error = '';

        try {
            const data = await requestJson(this.$el.action, 'POST', { value: this.on ? 0 : 1 });

            this.on = data.value === true;
        } catch (error) {
            this.error = error.data?.message || failed;
        } finally {
            this.busy = false;
        }
    },
}));

/**
 * CSRF-токен страницы для запросов fetch.
 */
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Запрос к админке с CSRF и ответом JSON. Ошибка → исключение с полями status и data.
 */
async function requestJson(url, method, body) {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            ...(body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
        },
        body: body instanceof FormData ? body : JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw Object.assign(new Error(String(response.status)), { status: response.status, data });
    }

    return data;
}

/**
 * Подставить значения в текст: «:name больше :size» + { name, size }.
 */
function format(text, replace) {
    return Object.entries(replace).reduce((result, [key, value]) => result.replaceAll(`:${key}`, value), text);
}

/**
 * Размер для людей по подписям из lang: ['… Б', '… КБ', '… МБ', '… ГБ'].
 */
function humanSize(bytes, units) {
    const power = bytes > 0 ? Math.min(3, Math.floor(Math.log(bytes) / Math.log(1024))) : 0;
    const value = power === 0 ? bytes : Math.round((bytes / 1024 ** power) * 10) / 10;

    return format(units[power], { size: String(value).replace('.', ',') });
}

/**
 * Загрузить файлы в папку менеджера с проверкой лимитов PHP на клиенте.
 * → { files: [{ path, url, name, image }], errors: ['…'] }
 */
export async function uploadFiles(fileList, { url, folder, maxFile, maxTotal, messages }) {
    const errors = [];
    const accepted = [];
    let total = 0;

    for (const file of fileList) {
        if (maxFile > 0 && file.size > maxFile) {
            errors.push(format(messages.too_big, { name: file.name, size: humanSize(maxFile, messages.sizes) }));
        } else {
            accepted.push(file);
            total += file.size;
        }
    }

    if (maxTotal > 0 && total > maxTotal) {
        return { files: [], errors: [...errors, format(messages.too_big_total, { size: humanSize(maxTotal, messages.sizes) })] };
    }

    if (accepted.length === 0) {
        return { files: [], errors };
    }

    const body = new FormData();
    body.append('folder', folder);
    accepted.forEach((file) => body.append('files[]', file));

    const data = await requestJson(url, 'POST', body);

    return { files: data.files ?? [], errors: [...errors, ...(data.errors ?? [])] };
}

/**
 * Текст ошибки запроса: ошибки проверки Laravel, истёкшая сессия или общий текст.
 */
function requestErrors(error, messages) {
    if (error.status === 401 || error.status === 419) {
        return { errors: [messages.session_expired], expired: true };
    }

    const validation = Object.values(error.data?.errors ?? {}).flat();

    return { errors: validation.length > 0 ? validation : [messages.error], expired: false };
}

/**
 * Настройки загрузки и окна выбора со страницы (<dialog id="fastleo-picker" data-...>).
 */
function pickerSettings() {
    const dialog = document.getElementById('fastleo-picker');

    return {
        uploadUrl: dialog.dataset.uploadUrl,
        thumbUrl: dialog.dataset.thumbUrl,
        maxFile: Number(dialog.dataset.maxFile),
        maxTotal: Number(dialog.dataset.maxTotal),
        messages: JSON.parse(dialog.dataset.messages),
    };
}

/**
 * Адрес миниатюры файла менеджера.
 */
function thumbUrl(path) {
    return `${pickerSettings().thumbUrl}?path=${encodeURIComponent(path)}`;
}

/**
 * Открыть файловый менеджер в окне выбора → Promise со списком файлов [{ path, url, name, image }]
 * или null, если окно закрыли. Сообщения принимаются только от своего iframe и своего сайта.
 */
export function openPicker(url) {
    const dialog = document.getElementById('fastleo-picker');
    const frame = dialog.querySelector('iframe');

    return new Promise((resolve) => {
        const onMessage = (event) => {
            if (event.origin !== location.origin || event.source !== frame.contentWindow) {
                return;
            }

            if (event.data?.type === 'fastleo:files') {
                resolve(Array.isArray(event.data.files) ? event.data.files : []);
                dialog.close();
            } else if (event.data?.type === 'fastleo:close') {
                dialog.close();
            }
        };

        window.addEventListener('message', onMessage);
        dialog.addEventListener(
            'close',
            () => {
                window.removeEventListener('message', onMessage);
                frame.src = 'about:blank';
                resolve(null);
            },
            { once: true },
        );
        frame.src = url;
        dialog.showModal();
    });
}

/**
 * Сообщить форме и зависимым полям, что значение скрытого поля поменял скрипт.
 */
function notifyChange(input) {
    input?.dispatchEvent(new Event('change', { bubbles: true }));
}

/**
 * Поле File / Image: <div x-data="fastleoFile({ path, url, image, folder, picker })">.
 * «Загрузить» отправляет файл в менеджер (папка поля), «Выбрать из файлов» — окно менеджера.
 */
Alpine.data('fastleoFile', ({ path, url, image, folder, picker, crop, cropUrl }) => ({
    path,
    url,
    uploading: false,
    errors: [],

    thumb(value) {
        return value ? thumbUrl(value) : '';
    },

    name() {
        return this.path ? this.path.split('/').pop() : '';
    },

    set(file) {
        this.path = file?.path ?? null;
        this.url = file?.url ?? null;
        this.errors = [];
        this.$nextTick(() => notifyChange(this.$refs.input));
    },

    async choose() {
        const target = new URL(picker, location.href);

        if (this.path) {
            target.searchParams.delete('folder');
            target.searchParams.set('current', this.path);
        }

        const files = await openPicker(target.toString());

        if (files?.length) {
            this.set(files[0]);
        }
    },

    /** «Обрезать»: рамка в пропорции поля (Cropper.js), сервер сохраняет копию, поле получает её. */
    async crop() {
        const dialog = document.getElementById('fastleo-cropper');
        const image = dialog.querySelector('[data-cropper-image]');
        const [width, height] = crop.split(':').map(Number);

        image.src = this.url;
        dialog.returnValue = '';
        dialog.showModal();

        const cropper = new window.Cropper(image, {
            aspectRatio: width / height,
            viewMode: 1,
            autoCropArea: 1,
            responsive: true,
            checkCrossOrigin: false,
            checkOrientation: false,
        });
        const confirmed = await new Promise((resolve) => {
            dialog.addEventListener('close', () => resolve(dialog.returnValue === 'confirm'), { once: true });
        });
        const box = cropper.getData(true);
        const { naturalWidth, naturalHeight } = cropper.getImageData();
        cropper.destroy();
        // После округления рамка может выйти за край на пиксель.
        box.x = Math.max(0, box.x);
        box.y = Math.max(0, box.y);
        box.width = Math.min(box.width, naturalWidth - box.x);
        box.height = Math.min(box.height, naturalHeight - box.y);

        if (!confirmed) {
            return;
        }

        this.errors = [];
        this.uploading = true;

        try {
            const result = await requestJson(cropUrl, 'POST', { path: this.path, x: box.x, y: box.y, width: box.width, height: box.height, ratio: crop });
            this.set(result.file);
        } catch (error) {
            this.errors = requestErrors(error, pickerSettings().messages).errors;
        } finally {
            this.uploading = false;
        }
    },

    async upload(fileList) {
        const settings = pickerSettings();
        this.errors = [];
        this.uploading = true;

        try {
            const result = await uploadFiles([...fileList].slice(0, 1), { url: settings.uploadUrl, folder, ...settings });
            this.errors = result.errors;

            if (result.files.length > 0) {
                this.set(result.files[0]);
            }
        } catch (error) {
            this.errors = requestErrors(error, settings.messages).errors;
        } finally {
            this.uploading = false;
        }
    },
}));

/**
 * Поле Gallery: <div x-data="fastleoGallery({ folder, picker, max })">. Картинки — <li data-gallery-item>
 * со скрытым полем; новые строятся из <template x-ref="item">, порядок — перетаскиванием.
 */
Alpine.data('fastleoGallery', ({ folder, picker, max }) => ({
    count: 0,
    uploading: false,
    errors: [],

    init() {
        this.count = this.items().length;

        if (this.$refs.item) {
            Sortable.create(this.$refs.list, {
                draggable: '[data-gallery-item]',
                animation: 150,
                onEnd: () => this.changed(),
            });
        }
    },

    items() {
        return [...this.$refs.list.querySelectorAll('[data-gallery-item]')];
    },

    paths() {
        return this.items().map((item) => item.querySelector('input')?.value);
    },

    add(files) {
        const settings = pickerSettings();
        const known = this.paths();
        this.errors = [];

        for (const file of files) {
            if (known.includes(file.path)) {
                continue;
            }

            if (max && this.count >= max) {
                this.errors.push(format(settings.messages.too_many, { max }));
                break;
            }

            const item = this.$refs.item.content.firstElementChild.cloneNode(true);
            item.querySelector('img').src = thumbUrl(file.path);
            item.querySelector('img').title = file.name ?? '';
            item.querySelector('input').value = file.path;
            this.$refs.list.querySelector('[data-gallery-empty]').before(item);
            known.push(file.path);
            this.count++;
        }

        this.changed();
    },

    remove(button) {
        button.closest('[data-gallery-item]')?.remove();
        this.count--;
        this.changed();
    },

    async choose() {
        const files = await openPicker(picker);

        if (files?.length) {
            this.add(files);
        }
    },

    async upload(fileList) {
        const settings = pickerSettings();
        this.errors = [];
        this.uploading = true;

        try {
            const result = await uploadFiles(fileList, { url: settings.uploadUrl, folder, ...settings });
            this.add(result.files);
            this.errors = [...result.errors, ...this.errors];
        } catch (error) {
            this.errors = requestErrors(error, settings.messages).errors;
        } finally {
            this.uploading = false;
        }
    },

    changed() {
        this.$root.closest('form')?.dispatchEvent(new Event('input'));
    },
}));

/**
 * Адрес файла для вставки в текст: свой сайт — путь от корня (текст не привязан к домену).
 */
function relativeUrl(url) {
    try {
        const parsed = new URL(url, location.href);

        return parsed.origin === location.origin ? parsed.pathname + parsed.search : url;
    } catch {
        return url;
    }
}

/**
 * Поле RichText: <div x-data="fastleoRichText({ height, picker })"> с <textarea x-ref="text">.
 * HugeRTE грузится отдельным файлом, поэтому редактор запускается, когда он готов.
 * Изменения отмечают форму, Ctrl/Cmd+S сохраняет, картинку и файл выбирают в менеджере.
 */
Alpine.data('fastleoRichText', ({ height, picker }) => ({
    editor: null,

    init() {
        this.$root.addEventListener('fastleo-detach', () => this.stop());
        this.$root.addEventListener('fastleo-attach', () => this.start());

        if (window.hugerte) {
            this.start();
        } else {
            window.addEventListener('load', () => this.start(), { once: true });
        }
    },

    // Блок с редактором удалён из формы — редактор тоже убирается.
    destroy() {
        this.stop();
    },

    stop() {
        if (this.editor) {
            this.editor.save();
            this.editor.remove();
            this.editor = null;
        }
    },

    start() {
        if (this.editor) {
            return;
        }

        const textarea = this.$refs.text;
        const form = textarea.closest('form');
        const dark = document.documentElement.classList.contains('dark');

        window.hugerte.init({
            init_instance_callback: (editor) => {
                this.editor = editor;
                this.$root.dataset.ready = '1';
            },
            target: textarea,
            language: 'ru',
            height,
            menubar: false,
            branding: false,
            promotion: false,
            skin_url: dark ? 'dark' : 'default',
            content_css: dark ? 'dark' : 'default',
            plugins: 'autolink code fullscreen image link lists media searchreplace table wordcount',
            toolbar: 'undo redo | blocks | bold italic | alignleft aligncenter alignright | bullist numlist | link image media table blockquote | removeformat code fullscreen',
            toolbar_mode: 'wrap',
            block_formats: 'Абзац=p; Заголовок 2=h2; Заголовок 3=h3; Заголовок 4=h4',
            relative_urls: false,
            convert_urls: false,
            entity_encoding: 'raw',
            paste_data_images: false,
            automatic_uploads: false,
            image_caption: true,
            link_default_target: '',
            file_picker_types: 'image file',
            file_picker_callback: async (callback, value, meta) => {
                const target = new URL(picker, location.href);

                if (meta.filetype !== 'image') {
                    target.searchParams.delete('accept');
                }

                const files = await openPicker(target.toString());

                if (files?.length) {
                    callback(relativeUrl(files[0].url), meta.filetype === 'image' ? { alt: '' } : { text: files[0].name });
                }
            },
            setup: (editor) => {
                editor.on('change input undo redo', () => {
                    editor.save();
                    form?.dispatchEvent(new Event('input'));
                });
                editor.addShortcut('meta+s', '', () => {
                    editor.save();
                    form?.requestSubmit(form.querySelector('[data-save]') || undefined);
                });
            },
        });
    },
}));

/**
 * Файловый менеджер: <div x-data="fastleoFiles" data-folder data-upload-url ...>.
 * Фильтр по имени, выбор, загрузка (кнопкой и перетаскиванием), новая папка, удаление,
 * копирование ссылки. В окне выбора (data-picker) клик по файлу отправляет его окну-родителю.
 */
Alpine.data('fastleoFiles', () => ({
    query: '',
    selected: [],
    chosen: {},
    uploading: false,
    dragging: false,
    folderOpen: false,
    folderName: '',
    errors: [],
    expired: false,
    notice: '',

    init() {
        this.messages = JSON.parse(this.$root.dataset.messages);
        this.$root.querySelector('[data-current]')?.scrollIntoView({ block: 'center' });

        // В окне выбора Escape закрывает окно (фокус внутри iframe, сам <dialog> его не видит).
        if (this.$root.dataset.picker) {
            window.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape' || document.querySelector('dialog[open]')) {
                    return;
                }

                if (this.query !== '') {
                    this.query = '';
                } else {
                    window.parent.postMessage({ type: 'fastleo:close' }, location.origin);
                }
            });
        }
    },

    matches(name) {
        return this.query === '' || name.toLowerCase().includes(this.query.toLowerCase());
    },

    noMatches() {
        return this.query !== '' && ![...this.$root.querySelectorAll('[data-entry]')].some((entry) => this.matches(entry.dataset.name));
    },

    toggle(path) {
        this.selected = this.selected.includes(path) ? this.selected.filter((item) => item !== path) : [...this.selected, path];
    },

    /** Переход по папке: в окне выбора — без записей в истории вкладки. */
    go(event) {
        if (this.$root.dataset.picker) {
            event.preventDefault();
            location.replace(event.currentTarget.href);
        }
    },

    /** Клик по файлу: в менеджере — выделить, в окне выбора — выбрать. */
    activate(file) {
        if (!this.$root.dataset.picker || this.$root.dataset.multiple) {
            this.toggle(file.path);
            this.chosen[file.path] = file;

            return;
        }

        this.send([file]);
    },

    chooseSelected() {
        this.send(this.selected.map((path) => this.chosen[path]).filter(Boolean));
    },

    /** Отдать выбранное окну-родителю (только своему сайту). */
    send(files) {
        const message = { type: 'fastleo:files', files: files.map(({ path, url, name, image }) => ({ path, url, name, image })) };

        window.parent.postMessage(message, location.origin);
    },

    drop(event) {
        this.dragging = false;

        if (event.dataTransfer?.files?.length) {
            this.upload(event.dataTransfer.files);
        }
    },

    async upload(fileList) {
        this.errors = [];
        this.notice = '';
        this.uploading = true;

        try {
            const result = await uploadFiles(fileList, {
                url: this.$root.dataset.uploadUrl,
                folder: this.$root.dataset.folder,
                maxFile: Number(this.$root.dataset.maxFile),
                maxTotal: Number(this.$root.dataset.maxTotal),
                messages: this.messages,
            });
            this.errors = result.errors;

            if (result.files.length > 0) {
                await this.refresh();
            }
        } catch (error) {
            this.fail(error);
        } finally {
            this.uploading = false;
        }
    },

    async createFolder() {
        this.errors = [];

        try {
            await requestJson(this.$root.dataset.folderUrl, 'POST', { folder: this.$root.dataset.folder, name: this.folderName });
            this.folderName = '';
            this.folderOpen = false;
            await this.refresh();
        } catch (error) {
            this.fail(error);
        }
    },

    async removeSelected() {
        if (!(await confirmDialog(format(this.messages.delete_confirm, { count: this.selected.length })))) {
            return;
        }

        this.errors = [];

        try {
            await requestJson(this.$root.dataset.deleteUrl, 'DELETE', { paths: this.selected });
            this.selected = [];
            await this.refresh();
        } catch (error) {
            this.fail(error);
        }
    },

    async copy(url) {
        try {
            await navigator.clipboard.writeText(url);
        } catch {
            // На http буфер обмена недоступен — копируем через скрытое поле.
            const field = Object.assign(document.createElement('textarea'), { value: url });
            document.body.append(field);
            field.select();
            document.execCommand('copy');
            field.remove();
        }

        this.notice = this.messages.copied;
        setTimeout(() => (this.notice = ''), 2000);
    },

    /** Перерисовать сетку файлов, не перезагружая страницу (ошибки и фильтр остаются). */
    async refresh() {
        const response = await fetch(location.href, { headers: { Accept: 'text/html' } });
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const content = page.querySelector('[data-files-content]');

        if (content) {
            this.$root.querySelector('[data-files-content]').innerHTML = content.innerHTML;
        }
    },

    fail(error) {
        const { errors, expired } = requestErrors(error, this.messages);
        this.errors = [...this.errors, ...errors];
        this.expired = expired;
    },
}));

const CYRILLIC = {
    а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'yo', ж: 'zh', з: 'z', и: 'i', й: 'y', к: 'k', л: 'l',
    м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't', у: 'u', ф: 'f', х: 'h', ц: 'ts', ч: 'ch', ш: 'sh',
    щ: 'sch', ъ: '', ы: 'y', ь: '', э: 'e', ю: 'yu', я: 'ya',
};

/**
 * Адрес из текста — как Str::slug($text, '-', 'ru') на сервере.
 */
export function slugify(text) {
    return [...text.toLowerCase()]
        .map((char) => CYRILLIC[char] ?? char)
        .join('')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/_+/g, '-')
        .replace(/@/g, '-at-')
        .replace(/[^a-z0-9\s-]+/g, '')
        .replace(/[\s-]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

/**
 * Поле Slug: <div x-data="fastleoSlug" data-source="title"><input x-ref="input">… кнопка regenerate().
 * Заполняется из поля-источника, пока адрес пустой и его не правили вручную; кнопка пересоздаёт адрес из источника.
 */
Alpine.data('fastleoSlug', () => ({
    init() {
        const input = this.$refs.input;
        // Заполненный адрес (в том числе у сохранённой записи) не меняется сам: ссылки на сайте не сломаются.
        let manual = input.value !== '';

        input.addEventListener('input', () => (manual = input.value !== ''));
        this.source()?.addEventListener('input', () => {
            if (!manual) {
                this.fill();
            }
        });
    },

    source() {
        return this.$refs.input.form?.elements.namedItem(this.$el.dataset.source);
    },

    fill() {
        const input = this.$refs.input;
        input.value = slugify(this.source()?.value ?? '').slice(0, input.maxLength > 0 ? input.maxLength : undefined);
    },

    regenerate() {
        this.fill();
        this.$refs.input.dispatchEvent(new Event('input', { bubbles: true }));
    },
}));

/**
 * Поле Tags: метки свободным вводом. Enter или запятая — добавить (можно вставить «а, б, в» разом),
 * Backspace в пустом вводе — убрать последнюю.
 */
Alpine.data('fastleoTags', (options) => tagsComponent(options));

/**
 * Поле ChatIds: номера чатов метками и «Найти чаты бота» — мессенджер и токен берутся из полей той же формы,
 * найденный чат добавляется кнопкой (с single — заменяет единственный).
 */
Alpine.data('fastleoChatIds', ({ tags, max, single, url, record, messengerField, tokenField, empty }) => ({
    ...tagsComponent({ tags, max }),
    found: [],
    error: '',
    loading: false,

    async find() {
        const form = this.$root.closest('form');
        const value = (name) => form?.querySelector(`[name="${name}"]:checked, select[name="${name}"], input[name="${name}"]:not([type="radio"])`)?.value ?? '';

        this.loading = true;
        this.error = '';
        this.found = [];

        try {
            const data = await requestJson(url, 'POST', { messenger: value(messengerField), token: value(tokenField), record });
            this.found = data.chats ?? [];
            this.error = this.found.length ? '' : empty;
        } catch (failure) {
            this.error = failure.data?.error ?? failure.data?.message ?? failure.message;
        } finally {
            this.loading = false;
        }
    },

    choose(id) {
        if (single) {
            this.tags = [];
        }

        this.push(id);
    },
}));

/**
 * Метки свободным вводом: общая часть полей Tags и ChatIds.
 */
function tagsComponent({ tags, max }) {
    return {
        tags,
        draft: '',

        input() {
            if (this.draft.includes(',')) {
                const parts = this.draft.split(',');
                this.draft = parts.pop();
                parts.forEach((part) => this.push(part));
            }
        },

        add() {
            this.draft.split(',').forEach((part) => this.push(part));
            this.draft = '';
        },

        push(text) {
            const tag = text.trim().slice(0, max);

            if (tag !== '' && !this.tags.includes(tag)) {
                this.tags.push(tag);
                this.changed();
            }
        },

        remove(index) {
            this.tags.splice(index, 1);
            this.changed();
        },

        removeLast() {
            if (this.tags.length > 0) {
                this.remove(this.tags.length - 1);
            }
        },

        changed() {
            this.$root.closest('form')?.dispatchEvent(new Event('input'));
        },
    };
}

/**
 * Поле Code: Tab вставляет отступ. Esc, затем Tab — обычный переход к следующему полю.
 */
Alpine.data('fastleoCode', () => ({
    init() {
        const area = this.$el;
        let escaped = false;

        area.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                escaped = true;

                return;
            }

            if (event.key !== 'Tab' || event.shiftKey || escaped || area.readOnly) {
                escaped = false;

                return;
            }

            event.preventDefault();
            area.setRangeText('    ', area.selectionStart, area.selectionEnd, 'end');
            area.dispatchEvent(new Event('input', { bubbles: true }));
        });
    },
}));

/**
 * Поле Markdown: вкладка «Просмотр» запрашивает HTML у сервера (POST data-url, CSRF из <meta>).
 */
Alpine.data('fastleoMarkdown', () => ({
    preview: false,
    html: '',
    message: '',

    async show() {
        this.preview = true;
        this.html = '';
        this.message = '';
        const text = this.$refs.text.value;

        if (text.trim() === '') {
            this.message = this.$root.dataset.empty;

            return;
        }

        try {
            const response = await fetch(this.$root.dataset.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ text }),
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            this.html = (await response.json()).html;
        } catch {
            this.message = this.$root.dataset.failed;
        }
    },
}));

/**
 * Список с поиском для связей: <div x-data="fastleoCombobox({ multiple, options, selected, url })">.
 * Без remote ищет среди options в странице, с remote — запрашивает сервер (?q=текст&depends=...) с задержкой.
 * С depends при смене поля-зависимости выбор сбрасывается и варианты берутся с сервера.
 */
Alpine.data('fastleoCombobox', (config) => ({
    multiple: config.multiple,
    url: config.url,
    remote: config.remote,
    depends: config.depends,
    record: config.record,
    all: config.options,
    options: config.options,
    selected: config.selected,
    search: '',
    open: false,
    active: 0,
    loading: false,
    request: 0,
    timer: null,

    init() {
        if (this.depends) {
            const form = this.$root.closest('form');

            watchField(form, this.depends, false, () => {
                this.selected = [];
                this.all = [];
                this.options = [];
                this.remote = true;
                this.changed();
            });
        }
    },

    openList() {
        if (!this.open) {
            this.open = true;
            this.load();
        }

        this.$refs.search.focus();
    },

    close() {
        this.open = false;
        this.search = '';
    },

    typed() {
        if (!this.remote) {
            this.load();

            return;
        }

        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.load(), 250);
    },

    async load() {
        this.active = 0;

        if (!this.remote) {
            const search = this.search.trim().toLowerCase();
            this.options = search === '' ? this.all : this.all.filter((option) => option.label.toLowerCase().includes(search));

            return;
        }

        const request = ++this.request;
        this.loading = true;

        try {
            const params = { q: this.search.trim() };

            if (this.depends) {
                params.depends = fieldValue(this.$root.closest('form'), this.depends);
            }

            if (this.record) {
                params.record = this.record;
            }

            const response = await fetch(`${this.url}?${new URLSearchParams(params)}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const data = await response.json();

            if (request === this.request) {
                this.options = data.options;
            }
        } catch {
            if (request === this.request) {
                this.options = [];
            }
        } finally {
            if (request === this.request) {
                this.loading = false;
            }
        }
    },

    isSelected(option) {
        return this.selected.some((item) => item.value === option.value);
    },

    choose(index) {
        const option = this.options[index];

        if (!option) {
            return;
        }

        if (!this.multiple) {
            this.selected = [option];
            this.close();
        } else if (this.isSelected(option)) {
            this.remove(option.value);

            return;
        } else {
            this.selected.push(option);
        }

        this.changed();
    },

    remove(value) {
        this.selected = this.selected.filter((item) => item.value !== value);
        this.changed();
    },

    removeLast() {
        if (this.selected.length > 0) {
            this.remove(this.selected[this.selected.length - 1].value);
        }
    },

    clear() {
        this.selected = [];
        this.changed();
    },

    move(step) {
        if (!this.open) {
            this.openList();

            return;
        }

        const count = this.options.length;

        if (count > 0) {
            this.active = (this.active + step + count) % count;
            this.$nextTick(() => this.$root.querySelectorAll('[role=option]')[this.active]?.scrollIntoView({ block: 'nearest' }));
        }
    },

    changed() {
        // Событие от скрытого поля значения: на него реагируют зависимые поля и условия показа.
        this.$nextTick(() => {
            const input = this.$root.querySelector('input[type=hidden][name]');
            (input ?? this.$root).dispatchEvent(new Event('input', { bubbles: true }));
        });
    },
}));

/**
 * Вложенные блоки HasMany: добавить (из <template x-ref="template"> с ключом __KEY__), удалить, перетащить за ручку.
 * Порядок блоков в форме = порядок отправки = порядок сохранения.
 */
Alpine.data('fastleoBlocks', ({ min, max, sortable }) => ({
    min,
    max,
    count: 0,
    created: 0,

    init() {
        this.count = this.$refs.list.children.length;

        if (sortable) {
            // Редактор RichText живёт в iframe: при переносе блока его содержимое теряется —
            // перед перетаскиванием редактор снимается, после — запускается заново.
            const editors = (item, event) => item.querySelectorAll('[data-rich-text]').forEach((el) => el.dispatchEvent(new CustomEvent(event)));

            Sortable.create(this.$refs.list, {
                handle: '[data-block-handle]',
                animation: 150,
                onStart: (event) => editors(event.item, 'fastleo-detach'),
                onEnd: (event) => {
                    editors(event.item, 'fastleo-attach');
                    this.changed();
                },
            });
        }
    },

    add(type = null) {
        const key = `new-${Date.now()}-${++this.created}`;
        const template = type === null ? this.$refs.template : this.$refs[`template-${type}`];
        this.$refs.list.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__KEY__', key));
        this.count++;
        this.changed();
        this.$nextTick(() => this.$refs.list.lastElementChild?.querySelector('input:not([type=hidden]), textarea, select')?.focus());
    },

    remove(button) {
        button.closest('[data-block]')?.remove();
        this.count--;
        this.changed();
    },

    changed() {
        this.$root.closest('form')?.dispatchEvent(new Event('input'));
    },
}));

/**
 * Список раздела: сворачивание веток дерева (localStorage) и перетаскивание строк ручного порядка.
 * <div x-data="fastleoList({ key, url, tree })">: строки — <tr data-record data-parent data-ancestors>.
 * Строку можно бросить только среди соседей (тот же родитель); сервер получает before/after.
 */
Alpine.data('fastleoList', ({ key, url, tree }) => ({
    collapsed: JSON.parse(localStorage.getItem(key) || '[]'),

    init() {
        // Строка с адресом открывается кликом в любом месте, кроме кнопок, ссылок и полей.
        this.$root.addEventListener('click', (event) => {
            const row = event.target.closest('[data-url]');

            if (row && !event.target.closest('a, button, input, label, select, textarea, form') && !window.getSelection()?.toString()) {
                window.location.href = row.dataset.url;
            }
        });

        const rows = this.$refs.rows;

        if (!url || !rows) {
            return;
        }

        Sortable.create(rows, {
            handle: '[data-reorder-handle]',
            animation: 150,
            onMove: (event) => event.related.dataset.parent === event.dragged.dataset.parent,
            onEnd: (event) => {
                this.renumber();
                this.moved(event.item);
            },
        });
    },

    // После перетаскивания: номера строк и недоступные крайние стрелки (первая на первой странице, последняя на последней).
    renumber() {
        const rows = [...this.$refs.rows.children];
        const start = Number(this.$refs.rows.dataset.start || 1);

        rows.forEach((row, index) => {
            const position = row.querySelector('[data-position]');

            if (position) {
                position.textContent = start + index;
            }

            const up = row.querySelector('[data-reorder="up"]');
            const down = row.querySelector('[data-reorder="down"]');

            if (up) {
                up.disabled = index === 0 && this.$refs.rows.dataset.firstPage === '1';
            }

            if (down) {
                down.disabled = index === rows.length - 1 && this.$refs.rows.dataset.lastPage === '1';
            }
        });
    },

    visible(element) {
        const ancestors = element.dataset.ancestors;

        return !ancestors || !ancestors.split(' ').some((ancestor) => this.collapsed.includes(ancestor));
    },

    toggle(record) {
        this.collapsed = this.collapsed.includes(record) ? this.collapsed.filter((item) => item !== record) : [...this.collapsed, record];
        this.save();
    },

    collapseAll() {
        this.collapsed = [...this.$root.querySelectorAll('[data-tree-toggle]')]
            .map((button) => button.closest('[data-record]').dataset.record)
            .filter((record, index, all) => all.indexOf(record) === index);
        this.save();
    },

    expandAll() {
        this.collapsed = [];
        this.save();
    },

    save() {
        localStorage.setItem(key, JSON.stringify(this.collapsed));
        this.$dispatch('fastleo-tree-changed', this.collapsed.length === 0);
    },

    async moved(row) {
        const sibling = (step) => {
            let element = step < 0 ? row.previousElementSibling : row.nextElementSibling;

            while (element && element.dataset.parent !== row.dataset.parent) {
                element = step < 0 ? element.previousElementSibling : element.nextElementSibling;
            }

            return element;
        };
        const previous = sibling(-1);
        const next = sibling(1);
        const body = { id: row.dataset.record };

        if (previous) {
            body.after = previous.dataset.record;
        } else if (next) {
            body.before = next.dataset.record;
        } else {
            return;
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify(body),
            });

            if (!response.ok || tree) {
                window.location.reload();
            }
        } catch {
            window.location.reload();
        }
    },
}));

/**
 * Поле с условием показа (visibleWhen): <div x-data="fastleoVisible({ field, values })" x-show="shown">.
 * Управляющее поле ищется в том же вложенном блоке (имя `...[field]`) или в форме (имя `field`).
 */
Alpine.data('fastleoVisible', ({ field, values }) => ({
    shown: false,

    init() {
        const block = this.$root.closest('[data-block], [data-blocks]');
        const scope = block ?? this.$root.closest('form');
        const nested = block !== null;

        const refresh = () => (this.shown = values.includes(fieldValue(scope, field, nested)));

        refresh();
        // Управляющее поле ниже по странице (список связи) готово только после первого прохода Alpine.
        this.$nextTick(refresh);
        scope?.addEventListener('input', refresh);
        scope?.addEventListener('change', refresh);
    },
}));

/**
 * Зависимый Select: <select x-data="fastleoDependentSelect({ url, depends })">.
 * При смене поля-зависимости варианты запрашиваются с сервера, выбор сбрасывается.
 */
Alpine.data('fastleoDependentSelect', ({ url, depends }) => ({
    init() {
        const select = this.$el;
        const form = select.closest('form');

        watchField(form, depends, false, async (value) => {
            const placeholder = select.multiple ? null : select.options[0]?.cloneNode(true);

            try {
                const response = await fetch(`${url}?${new URLSearchParams({ depends: value })}`, { headers: { Accept: 'application/json' } });
                const options = response.ok ? (await response.json()).options : [];

                select.replaceChildren(...(placeholder ? [placeholder] : []), ...options.map((option) => new Option(option.label, option.value)));
            } catch {
                select.replaceChildren(...(placeholder ? [placeholder] : []));
            }

            select.value = '';
        });
    },
}));

window.Alpine = Alpine;
// На Mac сочетания клавиш показываются через ⌘: <kbd data-mac="⌘K">Ctrl K</kbd>.
if (/mac|iphone|ipad/i.test(navigator.userAgentData?.platform ?? navigator.platform)) {
    document.querySelectorAll('[data-mac]').forEach((element) => (element.textContent = element.dataset.mac));
}

Alpine.start();
