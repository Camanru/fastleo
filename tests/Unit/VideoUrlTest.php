<?php

declare(strict_types=1);

use Fastleo\Support\Html\VideoFrameSanitizer;
use Fastleo\Support\Html\VideoUrl;

it('turns a link to a video into the address of its player', function (string $link, string $embed): void {
    expect(VideoUrl::embed($link))->toBe($embed);
})->with([
    'youtube page' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
    'youtube mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
    'youtube short link' => ['https://youtu.be/dQw4w9WgXcQ?si=abc', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
    'youtube shorts' => ['https://youtube.com/shorts/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
    'youtube live' => ['https://www.youtube.com/live/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
    'youtube embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
    'http link' => ['http://youtu.be/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
    'with spaces around' => ['  https://youtu.be/dQw4w9WgXcQ ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
    'rutube page' => ['https://rutube.ru/video/0123456789abcdef0123456789abcdef/', 'https://rutube.ru/play/embed/0123456789abcdef0123456789abcdef'],
    'rutube embed' => ['https://rutube.ru/play/embed/0123456789abcdef0123456789abcdef', 'https://rutube.ru/play/embed/0123456789abcdef0123456789abcdef'],
    'vk page' => ['https://vk.com/video-22822305_456239026', 'https://vk.com/video_ext.php?oid=-22822305&id=456239026&hd=2'],
    'vk video page' => ['https://vkvideo.ru/video-22822305_456239026', 'https://vk.com/video_ext.php?oid=-22822305&id=456239026&hd=2'],
    'vk user video' => ['https://vk.com/video12345_6789', 'https://vk.com/video_ext.php?oid=12345&id=6789&hd=2'],
    'vk embed code address' => ['https://vk.com/video_ext.php?oid=-22822305&id=456239026&hash=0a1b2c&hd=2', 'https://vk.com/video_ext.php?oid=-22822305&id=456239026&hash=0a1b2c&hd=2'],
]);

it('refuses everything that is not a link to a video of a known service', function (mixed $link): void {
    expect(VideoUrl::embed($link))->toBeNull();
})->with([
    'null' => [null],
    'number' => [123],
    'empty' => [''],
    'other site' => ['https://example.com/watch?v=dQw4w9WgXcQ'],
    'host that only starts like youtube' => ['https://youtube.com.evil.ru/watch?v=dQw4w9WgXcQ'],
    'youtube as a subdomain of an evil site' => ['https://www.youtube.com@evil.ru/watch?v=dQw4w9WgXcQ'],
    'javascript' => ['javascript:alert(1)'],
    'data' => ['data:text/html,<iframe>'],
    'ftp' => ['ftp://youtu.be/dQw4w9WgXcQ'],
    'port' => ['https://youtu.be:8443/dQw4w9WgXcQ'],
    'short code' => ['https://youtu.be/short'],
    'code with a quote' => ['https://youtu.be/dQw4w9WgXc"'],
    'youtube channel page' => ['https://www.youtube.com/@channel'],
    'youtube without an id' => ['https://www.youtube.com/watch'],
    'rutube short code' => ['https://rutube.ru/video/abc/'],
    'rutube private page without a code' => ['https://rutube.ru/video/private/'],
    'rutube play without embed' => ['https://rutube.ru/play/0123456789abcdef0123456789abcdef'],
    'vk wall' => ['https://vk.com/wall-1_2'],
    'vk with a foreign oid' => ['https://vk.com/video_ext.php?oid=x&id=1'],
    'vk with a bad hash' => ['https://vk.com/video_ext.php?oid=1&id=2&hash=zz'],
    'vk with an array in the query' => ['https://vk.com/video_ext.php?oid[]=1&id=2'],
    'encoded line break after the code' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ%0A'],
    'encoded line break after the vk hash' => ['https://vk.com/video_ext.php?oid=1&id=2&hash=abc%0A'],
    'a space inside' => ['https://youtu.be/dQw4w9WgXcQ extra'],
    'html' => ['<iframe src="https://youtu.be/dQw4w9WgXcQ">'],
]);

it('gives player addresses that the html cleaner of rich text accepts too', function (): void {
    foreach (['https://youtu.be/dQw4w9WgXcQ', 'https://rutube.ru/video/0123456789abcdef0123456789abcdef/', 'https://vk.com/video-1_2'] as $link) {
        $embed = VideoUrl::embed($link) ?? '';

        expect(array_filter(VideoFrameSanitizer::PLAYERS, fn (string $player): bool => str_starts_with($embed, $player)))->not->toBeEmpty();
    }
});
