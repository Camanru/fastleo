// Редактор поля RichText (HugeRTE, MIT-форк TinyMCE 6): один файл со скинами, плагинами и русским переводом.
// Грузится только на формах с RichText. Сборка: npm run build:hugerte.
import hugerte from 'hugerte';
import 'hugerte/models/dom';
import 'hugerte/icons/default';
import 'hugerte/themes/silver';
import 'hugerte/skins/ui/oxide/skin.js';
import 'hugerte/skins/ui/oxide/content.js';
import 'hugerte/skins/ui/oxide-dark/skin.js';
import 'hugerte/skins/ui/oxide-dark/content.js';
import 'hugerte/skins/content/default/content.js';
import 'hugerte/skins/content/dark/content.js';
import 'hugerte/plugins/autolink';
import 'hugerte/plugins/code';
import 'hugerte/plugins/fullscreen';
import 'hugerte/plugins/image';
import 'hugerte/plugins/link';
import 'hugerte/plugins/lists';
import 'hugerte/plugins/media';
import 'hugerte/plugins/searchreplace';
import 'hugerte/plugins/table';
import 'hugerte/plugins/wordcount';
import './hugerte-ru.js';

window.hugerte = hugerte;
