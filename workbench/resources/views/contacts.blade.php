<!DOCTYPE html>
<html lang="ru">
<head><meta charset="utf-8"><title>Контакты</title></head>
<body>
    <h1>Контакты</h1>
    <p>Телефон: <a href="tel:{{ preg_replace('/[^+\d]/', '', (string) fastleo_setting('contacts.phone')) }}">{{ fastleo_setting('contacts.phone') }}</a></p>
    <p>Почта для заявок: {{ fastleo_setting('contacts.lead_email', 'info@example.com') }}</p>
    <p>{!! nl2br(e((string) fastleo_setting('contacts.working_hours'))) !!}</p>
    @foreach ((array) fastleo_setting('contacts.socials', []) as $social)
        <p><a href="{{ $social['url'] ?? '#' }}">{{ $social['title'] ?? '' }}</a></p>
    @endforeach
</body>
</html>
