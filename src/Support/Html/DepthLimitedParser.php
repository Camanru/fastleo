<?php

declare(strict_types=1);

namespace Fastleo\Support\Html;

use Dom\Node;
use Override;
use Symfony\Component\HtmlSanitizer\Parser\NativeParser;
use Symfony\Component\HtmlSanitizer\Parser\ParserInterface;

/**
 * Разбор HTML для санитайзера с ограничением вложенности.
 *
 * `symfony/html-sanitizer` обходит DOM рекурсивно, и тысячи вложенных тегов (`<b>` × 16 000, около 48 КБ) роняют
 * PHP-процесс (SIGSEGV), а не бросают исключение. Разбор дерева такую глубину переживает, поэтому глубина
 * считается здесь, без рекурсии, до обхода: слишком глубокий текст не разбирается, санитайзер вернёт пустую строку.
 * Сам разбор дорог, если тегов много: стек открытых элементов растёт с каждым лишним закрывающим тегом, которому нечего
 * закрывать (`<div></x>` × 110 000 — 13 секунд, которые не прерывает `max_execution_time`), а незакрытое форматирование
 * разбор создаёт заново в каждом следующем блоке (190 вложенных `<b>` и 3000 абзацев — полмиллиона узлов из 25 КБ).
 * Квадратичен разбор и по числу атрибутов одного тега (40 000 имён в `<p a1 a2 …>` — 2 секунды, 160 000 — около минуты).
 * Поэтому до разбора текст проверяется на число тегов, на эту «пересоздаваемую» нагрузку и на число атрибутов в теге;
 * первые две не зависят от того, как именно разбор закрывает теги, а третья читает тег так же, как разбор, и потому
 * обойти их разметкой нельзя.
 *
 * ```php
 * new SymfonySanitizer($config, new DepthLimitedParser());
 * ```
 */
final readonly class DepthLimitedParser implements ParserInterface
{
    /** Наибольшая вложенность тегов; в живом тексте — единицы и десятки уровней. */
    public const int MAX_DEPTH = 200;

    /**
     * Наибольшее число открывающих тегов (и комментариев) в тексте: каждый начинается с «<» без «/». Разбор вложенных
     * `<div>` или `<li>` квадратичен по числу открытых тегов, и на этом пределе занимает около полутора секунд;
     * закрывающие теги разбору почти ничего не стоят (250 000 лишних `</x>` — 0,02 секунды), но их тоже не больше `MAX_TAGS`: лишних у живого текста нет. Живой
     * текст далеко от предела: таблица 400 × 6 — около трёх тысяч тегов, таблица на 5000 строк по пять столбцов — 30 000,
     * абзац из Word — четыре тега.
     */
    public const int MAX_TAGS = 30_000;

    /**
     * Наибольшая оценка узлов, которые разбор создаёт заново: за каждый тег, не относящийся к форматированию,
     * прибавляется число незакрытых тегов форматирования (на пределе санитайзеру нужно около 50 МБ памяти при лимите PHP по умолчанию 128 МБ).
     */
    public const int MAX_RECREATED = 100_000;

    /** Наибольшее число атрибутов в одном открывающем теге; в живом тексте их единицы (у `<img>` и `<a>` — до десяти). */
    public const int MAX_ATTRIBUTES = 1_000;

    /** Начало открывающего тега: «<» и буква; закрывающие теги, комментарии и «<» в тексте не подходят. */
    private const string TAG_START = '~<[a-z][^\s/>]*+~i';

    /**
     * Один атрибут по правилам разбора: имя (может начинаться с «=»), затем необязательное значение в кавычках или без них;
     * разделитель — пробел, «/» или ничего (после значения в кавычках). «>» внутри кавычек тег не закрывает.
     */
    private const string ATTRIBUTE = '~\G[\s/]*+(?:=[^\s/>=]*+|[^\s/>=]++)(?:\s*+=\s*+(?:"[^"]*+"|\'[^\']*+\'|[^\s>]*+))?~';

    /** Теги форматирования: пока они не закрыты, разбор создаёт их заново в каждом следующем блоке. */
    private const array FORMATTING = ['a', 'b', 'big', 'code', 'em', 'font', 'i', 'nobr', 's', 'small', 'strike', 'strong', 'tt', 'u'];

    private NativeParser $parser;

    public function __construct()
    {
        $this->parser = new NativeParser();
    }

    /**
     * Разобрать HTML; дерево глубже `MAX_DEPTH` — null (как «не разобрано»).
     */
    #[Override]
    public function parse(string $html, string $context = 'body'): ?Node
    {
        if (self::markupTooHeavy($html)) {
            return null;
        }

        $tree = $this->parser->parse($html, $context);

        return $tree instanceof Node && self::depth($tree) > self::MAX_DEPTH ? null : $tree;
    }

    /**
     * Текст слишком тяжёл для разбора: открывающих тегов больше `MAX_TAGS`, оценка пересоздаваемых узлов больше `MAX_RECREATED`
     * или в одном теге больше `MAX_ATTRIBUTES` атрибутов. Проверка идёт по тексту, без разбора (он на таких текстах квадратичен),
     * и ошибиться в сторону «пропустить» не может: закрывающие теги, которые разбор мог бы проигнорировать, не уменьшают ни число
     * тегов, ни оценку.
     *
     * ```php
     * DepthLimitedParser::markupTooHeavy(str_repeat('<div></x>', 100000)); // true
     * ```
     */
    public static function markupTooHeavy(string $html): bool
    {
        // Открывающие теги и комментарии: «<» без «/» следом. Каждый тег начинается с «<», поэтому ни один не пропущен.
        // Закрывающих в разборном тексте не больше, чем открывающих; лишние тоже ограничены, иначе список совпадений ниже
        // занял бы десятки мегабайт на тексте из сотен тысяч `</x>`.
        $closing = substr_count($html, '</');

        if ($closing > self::MAX_TAGS || substr_count($html, '<') - $closing > self::MAX_TAGS || self::hasTagWithTooManyAttributes($html)) {
            return true;
        }

        preg_match_all('~<(/?)([a-z][a-z0-9]*)~i', $html, $tags, PREG_SET_ORDER);

        $open = [];
        $unclosed = 0;
        $recreated = 0;

        foreach ($tags as [, $slash, $name]) {
            $name = strtolower($name);

            if (! in_array($name, self::FORMATTING, true)) {
                $recreated += $slash === '' ? $unclosed : 0;
            } elseif ($slash === '') {
                $open[$name] = ($open[$name] ?? 0) + 1;
                $unclosed++;
            } elseif (($open[$name] ?? 0) > 0) {
                $open[$name]--;
                $unclosed--;
            }
        }

        return $recreated > self::MAX_RECREATED;
    }

    /**
     * В каком-то открывающем теге атрибутов больше `MAX_ATTRIBUTES`. Тег читается так же, как его читает разбор: значение в
     * кавычках может содержать «>» и пробелы, а имена атрибутов — любые знаки, кроме пробела, «/», «>» и «=». Счёт на одном теге
     * останавливается на пределе, поэтому работа ограничена длиной текста.
     */
    private static function hasTagWithTooManyAttributes(string $html): bool
    {
        $offset = 0;

        while (preg_match(self::TAG_START, $html, $tag, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $offset = $tag[0][1] + strlen($tag[0][0]);

            for ($count = 1; preg_match(self::ATTRIBUTE, $html, $attribute, 0, $offset) === 1; $count++) {
                if ($count > self::MAX_ATTRIBUTES) {
                    return true;
                }

                $offset += strlen($attribute[0]);
            }
        }

        return false;
    }

    /**
     * Глубина дерева (корень — 1), обход без рекурсии; прекращается, как только глубина превысила предел.
     *
     * ```php
     * DepthLimitedParser::depth($document); // 3
     * ```
     */
    public static function depth(Node $root): int
    {
        $deepest = 1;
        $stack = [[$root, 1]];

        while ($stack !== []) {
            [$node, $level] = array_pop($stack);
            $deepest = max($deepest, $level);

            if ($deepest > self::MAX_DEPTH) {
                break;
            }

            foreach ($node->childNodes as $child) {
                if ($child->hasChildNodes()) {
                    $stack[] = [$child, $level + 1];
                }
            }
        }

        return $deepest;
    }
}
