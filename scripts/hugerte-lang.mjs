// Русский перевод HugeRTE из языкового пакета TinyMCE 6 (npm tinymce-i18n, langs6/ru.js; лицензия TinyMCE 6 — MIT):
// tinymce.addI18n → hugerte.addI18n, переведены «Undo»/«Redo». Результат — resources/js/hugerte-ru.js.
import { readFileSync, writeFileSync } from 'node:fs';

const source = readFileSync('node_modules/tinymce-i18n/langs6/ru.js', 'utf8');
const version = JSON.parse(readFileSync('node_modules/tinymce-i18n/package.json', 'utf8')).version;

const translated = source
    .replace('tinymce.addI18n(', 'hugerte.addI18n(')
    .replace('"Redo": "Redo"', '"Redo": "Повторить"')
    .replace('"Undo": "Undo"', '"Undo": "Отменить"');

writeFileSync(
    'resources/js/hugerte-ru.js',
    `// Русский перевод HugeRTE: языковой пакет TinyMCE 6 (MIT) из npm tinymce-i18n ${version} (langs6/ru.js).\n// Файл собирается: npm run build:hugerte. Вручную не править.\n${translated}`,
);
