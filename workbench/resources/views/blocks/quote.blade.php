{{-- Блок «Цитата». --}}
<blockquote class="block-quote">
    <p>{{ $text ?? '' }}</p>
    @if (! empty($author))<footer>— {{ $author }}</footer>@endif
</blockquote>
