// Копирует иконки Lucide из lucide-static в resources/icons в сжатом виде:
// без комментария, переносов строк и class="lucide ..." (класс задаёт компонент <x-fastleo::icon>).
import { copyFileSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';

const source = 'node_modules/lucide-static/icons';
const target = 'resources/icons';

rmSync(target, { recursive: true, force: true });
mkdirSync(target, { recursive: true });

let count = 0;

for (const file of readdirSync(source)) {
    if (!file.endsWith('.svg')) {
        continue;
    }

    const svg = readFileSync(`${source}/${file}`, 'utf8')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\s*\n\s*/g, ' ')
        .replace(/\s*class="[^"]*"/, '')
        .replace(/\s*\/>/g, '/>')
        .replace(/>\s+</g, '><')
        .replace(/<svg\s+/, '<svg ')
        .replace(/\s+>/g, '>')
        .trim();

    writeFileSync(`${target}/${file}`, svg + '\n');
    count++;
}

copyFileSync('node_modules/lucide-static/LICENSE', `${target}/LICENSE`);

console.log(`Скопировано иконок: ${count}`);
